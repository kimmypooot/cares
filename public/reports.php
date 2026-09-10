<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
require_login();

$pdo = Database::getConnection();
// Own-agency-only, enforced server-side — never trust a request parameter
// for this; it's always current_agency_id(). Every query below that
// touches an agency dimension filters on $scopedAgencyId, never on any
// $_GET value, when this is not null.
$scopedAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;

$reportType   = clean($_GET['report_type'] ?? '');
$dateFrom     = clean($_GET['date_from'] ?? '');
$dateTo       = clean($_GET['date_to'] ?? '');
$filterYear   = clean($_GET['year'] ?? '');
$filterAgency = (int)($_GET['agency_id'] ?? 0);
$currentYear  = (int)date('Y');
$fromYear     = (int)($_GET['from_year'] ?? 0) ?: $currentYear - 4;
$toYear       = (int)($_GET['to_year'] ?? 0) ?: $currentYear;
if ($fromYear > $toYear) {
    [$fromYear, $toYear] = [$toYear, $fromYear];
}

$reportOptions = $scopedAgencyId !== null ? [
    'services_availed' => "Services Availed \xE2\x80\x94 My Agency's Applicants",
    'all_hired'         => 'Applicants Hired by My Agency',
    'not_hired'         => 'Applicants Not Hired by My Agency',
    'yearly_summary'    => "My Agency's Yearly Summary",
] : [
    'services_availed' => 'Services Availed',
    'all_hired'         => 'All Hired Applicants',
    'not_hired'         => 'Applicant that was not hired',
    'yearly_summary'    => 'Summarize per participant per year',
];

$agenciesList = $scopedAgencyId === null
    ? $pdo->query("SELECT id, agency_name FROM care_jf_partner_agencies WHERE status = 'Active' ORDER BY agency_name")->fetchAll()
    : [];

/** Display label for an applicant's eligibility, folding in "Other" text. */
function report_eligibility_label(array $r): string
{
    if (empty($r['eligibility_type'])) {
        return '—';
    }
    if ($r['eligibility_type'] === 'Other Eligibility' && !empty($r['other_eligibility_type'])) {
        return $r['other_eligibility_type'];
    }
    return $r['eligibility_type'];
}

/** Display label for an applicant's two service flags, e.g. "Job Seeker, Avail Agency Services". */
function report_services_label(array $r): string
{
    $svc = [];
    if (!empty($r['service_job_seeker']))      $svc[] = 'Job Seeker';
    if (!empty($r['service_agency_services'])) $svc[] = 'Avail Agency Services';
    return $svc ? implode(', ', $svc) : '—';
}

$scalar = function (string $sql, array $params) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

/** Reconstructs a vacancy's originally-posted position count from its
 * current (already-decremented-on-hire) vacant_count plus however many
 * Hired rows already reference it — vacant_count alone only reflects
 * what's still open right now, not what was posted. */
$vacanciesPostedInYear = function (int $year, ?int $agencyId) use ($pdo): int {
    $sql = "SELECT COALESCE(SUM(
                v.vacant_count + (
                    SELECT COUNT(*) FROM care_jf_employment_records er2
                    WHERE er2.vacancy_id = v.id AND er2.employment_status = 'Hired'
                )
            ), 0)
            FROM care_jf_job_vacancies v
            WHERE YEAR(v.created_at) = :y";
    $params = [':y' => $year];
    if ($agencyId !== null) {
        $sql .= " AND v.agency_id = :aid";
        $params[':aid'] = $agencyId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

$reportLabel = '';
$svcBuckets = null;
$hiredGroups = null;
$hiredTotal = 0;
$notHiredGroups = null;
$notHiredTotal = 0;
$registeredNoApply = null;
$yearlyRows = null;

if ($reportType && isset($reportOptions[$reportType])) {
    $reportLabel = $reportOptions[$reportType];

    switch ($reportType) {
        case 'services_availed':
            $sql = "SELECT a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
                           a.sex, a.service_job_seeker, a.service_agency_services, a.created_at
                    FROM care_jf_applicants a";
            $params = [];
            if ($scopedAgencyId !== null) {
                $sql .= " JOIN care_jf_employment_records er ON er.applicant_id = a.id AND er.agency_id = :aid";
                $params[':aid'] = $scopedAgencyId;
            }
            $sql .= " WHERE a.is_deleted = 0";
            if ($dateFrom) { $sql .= " AND DATE(a.created_at) >= :df"; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $sql .= " AND DATE(a.created_at) <= :dt"; $params[':dt'] = $dateTo; }
            $sql .= " GROUP BY a.id ORDER BY a.last_name, a.first_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $svcBuckets = ['job_seeker_only' => [], 'agency_only' => [], 'both' => []];
            foreach ($stmt->fetchAll() as $r) {
                if ($r['service_job_seeker'] && $r['service_agency_services']) {
                    $svcBuckets['both'][] = $r;
                } elseif ($r['service_job_seeker']) {
                    $svcBuckets['job_seeker_only'][] = $r;
                } elseif ($r['service_agency_services']) {
                    $svcBuckets['agency_only'][] = $r;
                }
                // Applicants with neither flag set (legacy/temp data) are
                // intentionally excluded from every bucket and the total —
                // current registration flows always require at least one.
            }
            break;

        case 'all_hired':
            $sql = "SELECT a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
                           a.sex, a.service_job_seeker, a.service_agency_services, a.eligibility_type, a.other_eligibility_type,
                           er.date_hired, er.employment_status, pa.id AS agency_id, pa.agency_name
                    FROM care_jf_employment_records er
                    JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0
                    JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
                    WHERE er.employment_status = 'Hired' AND er.is_current = 1 AND er.status = 'Active'";
            $params = [];
            if ($scopedAgencyId !== null) {
                $sql .= " AND er.agency_id = :aid"; $params[':aid'] = $scopedAgencyId;
            } elseif ($filterAgency) {
                $sql .= " AND er.agency_id = :fa"; $params[':fa'] = $filterAgency;
            }
            if ($filterYear !== '') { $sql .= " AND YEAR(er.date_hired) = :yr"; $params[':yr'] = (int)$filterYear; }
            if ($dateFrom) { $sql .= " AND er.date_hired >= :df"; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $sql .= " AND er.date_hired <= :dt"; $params[':dt'] = $dateTo; }
            $sql .= " ORDER BY pa.agency_name, er.date_hired";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $hiredGroups = [];
            foreach ($stmt->fetchAll() as $r) {
                $hiredGroups[$r['agency_name']][] = $r;
                $hiredTotal++;
            }
            break;

        case 'not_hired':
            $sqlA = "SELECT a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
                            a.sex, a.service_job_seeker, a.service_agency_services, a.eligibility_type, a.other_eligibility_type,
                            pa.id AS agency_id, pa.agency_name
                     FROM care_jf_employment_records er
                     JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0
                     JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
                     WHERE er.employment_status = 'For Review' AND er.status = 'Active'";
            $paramsA = [];
            if ($scopedAgencyId !== null) {
                $sqlA .= " AND er.agency_id = :aid"; $paramsA[':aid'] = $scopedAgencyId;
            } elseif ($filterAgency) {
                $sqlA .= " AND er.agency_id = :fa"; $paramsA[':fa'] = $filterAgency;
            }
            if ($filterYear !== '') { $sqlA .= " AND YEAR(er.created_at) = :yr"; $paramsA[':yr'] = (int)$filterYear; }
            $sqlA .= " ORDER BY pa.agency_name, a.last_name";
            $stmtA = $pdo->prepare($sqlA);
            $stmtA->execute($paramsA);

            $notHiredGroups = [];
            foreach ($stmtA->fetchAll() as $r) {
                $notHiredGroups[$r['agency_name']][] = $r;
                $notHiredTotal++;
            }

            // Agency-agnostic by definition — an applicant who applied to
            // no one belongs to no agency's report, so this section is
            // only meaningful in the cross-agency (admin) view.
            if ($scopedAgencyId === null) {
                $sqlB = "SELECT a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
                                a.sex, a.service_job_seeker, a.service_agency_services, a.eligibility_type, a.other_eligibility_type
                         FROM care_jf_applicants a
                         WHERE a.is_deleted = 0 AND a.service_job_seeker = 1
                           AND NOT EXISTS (SELECT 1 FROM care_jf_employment_records er WHERE er.applicant_id = a.id)
                         ORDER BY a.last_name, a.first_name";
                $registeredNoApply = $pdo->query($sqlB)->fetchAll();
            }
            break;

        case 'yearly_summary':
            $yearlyRows = [];
            for ($y = $toYear; $y >= $fromYear; $y--) {
                if ($scopedAgencyId === null) {
                    $yearlyRows[$y] = [
                        'primary_label' => 'Total Job Seeker',
                        'primary'   => $scalar("SELECT COUNT(DISTINCT id) FROM care_jf_applicants WHERE is_deleted = 0 AND service_job_seeker = 1 AND YEAR(created_at) = :y", [':y' => $y]),
                        'hired'     => $scalar("SELECT COUNT(DISTINCT er.applicant_id) FROM care_jf_employment_records er JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0 WHERE er.employment_status = 'Hired' AND er.is_current = 1 AND er.status = 'Active' AND YEAR(er.date_hired) = :y", [':y' => $y]),
                        'not_hired' => $scalar("SELECT COUNT(DISTINCT er.applicant_id) FROM care_jf_employment_records er JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0 WHERE er.employment_status = 'For Review' AND er.status = 'Active' AND YEAR(er.created_at) = :y", [':y' => $y]),
                        'agencies'  => $scalar("SELECT COUNT(DISTINCT agency_id) FROM care_jf_employment_records WHERE YEAR(created_at) = :y", [':y' => $y]),
                        'vacancies' => $vacanciesPostedInYear($y, null),
                        'filled'    => $scalar("SELECT COUNT(*) FROM care_jf_employment_records WHERE employment_status = 'Hired' AND vacancy_id IS NOT NULL AND YEAR(date_hired) = :y", [':y' => $y]),
                    ];
                } else {
                    $aid = $scopedAgencyId;
                    $yearlyRows[$y] = [
                        'primary_label' => 'Total Applicants Engaged',
                        'primary'   => $scalar("SELECT COUNT(DISTINCT applicant_id) FROM care_jf_employment_records WHERE agency_id = :aid AND YEAR(created_at) = :y", [':aid' => $aid, ':y' => $y]),
                        'hired'     => $scalar("SELECT COUNT(DISTINCT er.applicant_id) FROM care_jf_employment_records er JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0 WHERE er.agency_id = :aid AND er.employment_status = 'Hired' AND er.is_current = 1 AND er.status = 'Active' AND YEAR(er.date_hired) = :y", [':aid' => $aid, ':y' => $y]),
                        'not_hired' => $scalar("SELECT COUNT(DISTINCT er.applicant_id) FROM care_jf_employment_records er JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0 WHERE er.agency_id = :aid AND er.employment_status = 'For Review' AND er.status = 'Active' AND YEAR(er.created_at) = :y", [':aid' => $aid, ':y' => $y]),
                        'agencies'  => null,
                        'vacancies' => $vacanciesPostedInYear($y, $aid),
                        'filled'    => $scalar("SELECT COUNT(*) FROM care_jf_employment_records WHERE agency_id = :aid AND employment_status = 'Hired' AND vacancy_id IS NOT NULL AND YEAR(date_hired) = :y", [':aid' => $aid, ':y' => $y]),
                    ];
                }
            }
            break;
    }

    // Excel (.xlsx) export
    if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
        $filename = preg_replace('/\W+/', '_', $reportLabel) . '_' . date('Ymd') . '.xlsx';
        $metaLines = [
            'Civil Service Commission RO VIII',
            'Job Applicants ' . date('Y'),
            'Generated: ' . date('F j, Y g:i A'),
        ];
        $exportRows = [];

        switch ($reportType) {
            case 'services_availed':
                $headers = ['Category', 'Applicant ID', 'Full Name', 'Sex', 'Services Availed', 'Date Registered'];
                $labels = ['job_seeker_only' => 'Job Seeker Only', 'agency_only' => 'Agency Services Only', 'both' => 'Both Services'];
                $grand = 0;
                foreach ($labels as $key => $label) {
                    foreach ($svcBuckets[$key] as $r) {
                        $exportRows[] = [$label, $r['applicant_code'], full_name($r), $r['sex'], report_services_label($r), format_date($r['created_at'])];
                    }
                    $exportRows[] = [$label . ' Total: ' . count($svcBuckets[$key]), '', '', '', '', ''];
                    $grand += count($svcBuckets[$key]);
                }
                $exportRows[] = ['Total Registered Participants: ' . $grand, '', '', '', '', ''];
                break;

            case 'all_hired':
                $headers = ['Agency/Office', 'Seq', 'Applicant ID', 'Full Name', 'Sex', 'Services Availed', 'Eligibility Type', 'Date Hired', 'Employment Status'];
                foreach ($hiredGroups ?? [] as $agencyName => $rows) {
                    $seq = 0;
                    foreach ($rows as $r) {
                        $seq++;
                        $exportRows[] = [$agencyName, $seq, $r['applicant_code'], full_name($r), $r['sex'], report_services_label($r), report_eligibility_label($r), format_date($r['date_hired']), 'Hired'];
                    }
                    $exportRows[] = [$agencyName . ' Total Hired: ' . count($rows), '', '', '', '', '', '', '', ''];
                }
                $exportRows[] = ['TOTAL HIRED APPLICANTS: ' . $hiredTotal, '', '', '', '', '', '', '', ''];
                break;

            case 'not_hired':
                $headers = ['Agency/Office', 'Seq', 'Applicant ID', 'Full Name', 'Sex', 'Services Availed', 'Eligibility Type', 'Date Hired', 'Employment Status'];
                foreach ($notHiredGroups ?? [] as $agencyName => $rows) {
                    $seq = 0;
                    foreach ($rows as $r) {
                        $seq++;
                        $exportRows[] = [$agencyName, $seq, $r['applicant_code'], full_name($r), $r['sex'], report_services_label($r), report_eligibility_label($r), 'N/A', 'Not Hired'];
                    }
                    $exportRows[] = [$agencyName . ' Total Not Hired: ' . count($rows), '', '', '', '', '', '', '', ''];
                }
                $exportRows[] = ['TOTAL NOT HIRED: ' . $notHiredTotal, '', '', '', '', '', '', '', ''];
                if ($registeredNoApply !== null) {
                    $exportRows[] = ['REGISTERED BUT DID NOT APPLY', '', '', '', '', '', '', '', ''];
                    $seq = 0;
                    foreach ($registeredNoApply as $r) {
                        $seq++;
                        $exportRows[] = ['N/A', $seq, $r['applicant_code'], full_name($r), $r['sex'], report_services_label($r), report_eligibility_label($r), 'N/A', 'Registered — Did Not Apply'];
                    }
                }
                break;

            case 'yearly_summary':
                $headers = ['Year', $scopedAgencyId === null ? 'Total Job Seeker' : 'Total Applicants Engaged', 'Total Hired', 'Total Not Hired', 'Total Participating Agency', 'Total Job Vacancies', 'Total Vacancies Filled'];
                foreach ($yearlyRows ?? [] as $y => $m) {
                    $exportRows[] = [$y, $m['primary'], $m['hired'], $m['not_hired'], $m['agencies'] ?? '—', $m['vacancies'], $m['filled']];
                }
                break;

            default:
                $headers = [];
        }

        stream_xlsx($filename, $reportLabel, $metaLines, $headers, $exportRows);
    }
}

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6" x-data="{ rt: <?= e(json_encode($reportType)) ?> }">
  <div class="print:hidden">
    <h1 class="text-2xl font-bold text-slate-800">Reports</h1>
    <p class="text-sm text-slate-500">Generate applicant, hiring, and yearly summary reports, with Excel export.</p>
  </div>

  <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 grid md:grid-cols-4 gap-4 items-end print:hidden">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-slate-700 mb-1">Report Type</label>
      <select name="report_type" x-model="rt" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Select a report...</option>
        <?php foreach ($reportOptions as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $reportType===$key?'selected':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div x-show="rt === 'services_availed'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Date Registered From</label>
      <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div x-show="rt === 'services_availed'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Date Registered To</label>
      <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div x-show="rt === 'all_hired'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Year</label>
      <select name="year" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">All Years</option>
        <?php for ($y = $currentYear; $y >= $currentYear - 9; $y--): ?>
          <option value="<?= $y ?>" <?= $filterYear === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <?php if ($agenciesList): ?>
    <div x-show="rt === 'all_hired' || rt === 'not_hired'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency</label>
      <select name="agency_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="0">All Agencies</option>
        <?php foreach ($agenciesList as $ag): ?>
          <option value="<?= (int)$ag['id'] ?>" <?= $filterAgency === (int)$ag['id'] ? 'selected' : '' ?>><?= e($ag['agency_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div x-show="rt === 'all_hired'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Date Hired From</label>
      <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div x-show="rt === 'all_hired'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Date Hired To</label>
      <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div x-show="rt === 'not_hired'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">Year Tagged</label>
      <select name="year" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">All Years</option>
        <?php for ($y = $currentYear; $y >= $currentYear - 9; $y--): ?>
          <option value="<?= $y ?>" <?= $filterYear === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div x-show="rt === 'yearly_summary'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">From Year</label>
      <input type="number" name="from_year" value="<?= (int)$fromYear ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div x-show="rt === 'yearly_summary'" x-cloak>
      <label class="block text-sm font-medium text-slate-700 mb-1">To Year</label>
      <input type="number" name="to_year" value="<?= (int)$toYear ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div class="md:col-span-4 flex justify-end gap-2">
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-chart-column mr-1"></i> Generate Report
      </button>
    </div>
  </form>

  <?php if ($reportType && isset($reportOptions[$reportType])): ?>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:border-0 print:shadow-none">
    <div class="flex items-center justify-between p-5 border-b border-slate-100 flex-wrap gap-3 print:hidden">
      <div>
        <h2 class="font-semibold text-slate-800"><?= e($reportLabel) ?></h2>
        <p class="text-xs text-slate-500">Generated <?= date('F j, Y g:i A') ?></p>
      </div>
      <div class="flex gap-2">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-file-excel mr-1"></i> Export Excel</a>
        <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-print mr-1"></i> Print</button>
      </div>
    </div>

    <!-- Print-only letterhead: hidden on screen, shown only when printing. -->
    <div class="hidden print:block text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Job Applicants <?= date('Y') ?></p>
      <p class="text-sm text-slate-600 mt-2"><?= e($reportLabel) ?></p>
      <?php if ($reportType === 'yearly_summary'): ?>
        <p class="text-xs text-slate-500 mt-1">Reporting Period: <?= (int)$fromYear ?>&ndash;<?= (int)$toYear ?></p>
      <?php elseif ($filterYear !== ''): ?>
        <p class="text-xs text-slate-500 mt-1">Year: <?= e($filterYear) ?></p>
      <?php endif; ?>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>

    <div class="p-5 space-y-8">

    <?php if ($reportType === 'services_availed'): ?>
      <?php
        $labels = ['job_seeker_only' => 'Job Seeker Only', 'agency_only' => 'Agency Services Only', 'both' => 'Both Services'];
        $grandTotal = count($svcBuckets['job_seeker_only']) + count($svcBuckets['agency_only']) + count($svcBuckets['both']);
      ?>
      <div class="grid sm:grid-cols-4 gap-3">
        <?php foreach ($labels as $key => $label): ?>
          <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
            <p class="text-xs text-slate-500"><?= e($label) ?></p>
            <p class="text-xl font-bold text-slate-800"><?= count($svcBuckets[$key]) ?></p>
          </div>
        <?php endforeach; ?>
        <div class="rounded-lg border border-brand-100 bg-brand-50 p-3">
          <p class="text-xs text-brand-600">Total Registered Participants</p>
          <p class="text-xl font-bold text-brand-800"><?= $grandTotal ?></p>
        </div>
      </div>

      <?php foreach ($labels as $key => $label): ?>
        <div>
          <h3 class="font-semibold text-slate-700 mb-2">Table <?= ['job_seeker_only'=>'A','agency_only'=>'B','both'=>'C'][$key] ?> &mdash; <?= e($label) ?> (<?= count($svcBuckets[$key]) ?>)</h3>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">Seq.</th>
                  <th class="px-3 py-2 text-left">Applicant ID</th>
                  <th class="px-3 py-2 text-left">Full Name</th>
                  <th class="px-3 py-2 text-left">Sex</th>
                  <th class="px-3 py-2 text-left">Services Availed</th>
                  <th class="px-3 py-2 text-left">Date Registered</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <?php if (!$svcBuckets[$key]): ?>
                  <tr><td colspan="6" class="px-3 py-4 text-center text-slate-400">No records.</td></tr>
                <?php endif; ?>
                <?php foreach ($svcBuckets[$key] as $i => $r): ?>
                <tr>
                  <td class="px-3 py-2"><?= $i + 1 ?></td>
                  <td class="px-3 py-2 font-medium text-brand-700"><?= e($r['applicant_code']) ?></td>
                  <td class="px-3 py-2"><?= e(full_name($r)) ?></td>
                  <td class="px-3 py-2"><?= e($r['sex']) ?></td>
                  <td class="px-3 py-2"><?= e(report_services_label($r)) ?></td>
                  <td class="px-3 py-2"><?= format_date($r['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    <?php elseif ($reportType === 'all_hired'): ?>
      <?php if (!$hiredGroups): ?>
        <p class="text-center text-slate-400 py-8">No records match this report.</p>
      <?php endif; ?>
      <?php foreach ($hiredGroups ?? [] as $agencyName => $rows): ?>
        <div>
          <h3 class="font-semibold text-slate-700 mb-2"><?= e($agencyName) ?></h3>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">Seq.</th>
                  <th class="px-3 py-2 text-left">Applicant ID</th>
                  <th class="px-3 py-2 text-left">Full Name</th>
                  <th class="px-3 py-2 text-left">Sex</th>
                  <th class="px-3 py-2 text-left">Services Availed</th>
                  <th class="px-3 py-2 text-left">Eligibility Type</th>
                  <th class="px-3 py-2 text-left">Date Hired</th>
                  <th class="px-3 py-2 text-left">Employment Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $i => $r): ?>
                <tr>
                  <td class="px-3 py-2"><?= $i + 1 ?></td>
                  <td class="px-3 py-2 font-medium text-brand-700"><?= e($r['applicant_code']) ?></td>
                  <td class="px-3 py-2"><?= e(full_name($r)) ?></td>
                  <td class="px-3 py-2"><?= e($r['sex']) ?></td>
                  <td class="px-3 py-2"><?= e(report_services_label($r)) ?></td>
                  <td class="px-3 py-2"><?= e(report_eligibility_label($r)) ?></td>
                  <td class="px-3 py-2"><?= format_date($r['date_hired']) ?></td>
                  <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Hired</span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="bg-slate-50 font-semibold text-slate-700">
                  <td colspan="8" class="px-3 py-2"><?= e($agencyName) ?> Total Hired: <?= count($rows) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($hiredGroups): ?>
        <div class="text-right font-bold text-slate-800 border-t border-slate-200 pt-3">TOTAL HIRED APPLICANTS: <?= $hiredTotal ?></div>
      <?php endif; ?>

    <?php elseif ($reportType === 'not_hired'): ?>
      <div>
        <h3 class="font-semibold text-slate-700 mb-3">Applicants Not Hired</h3>
        <?php if (!$notHiredGroups): ?>
          <p class="text-center text-slate-400 py-4">No records match this section.</p>
        <?php endif; ?>
        <?php foreach ($notHiredGroups ?? [] as $agencyName => $rows): ?>
          <div class="mb-6">
            <h4 class="font-medium text-slate-700 mb-2"><?= e($agencyName) ?></h4>
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                  <tr>
                    <th class="px-3 py-2 text-left">Seq.</th>
                    <th class="px-3 py-2 text-left">Applicant ID</th>
                    <th class="px-3 py-2 text-left">Full Name</th>
                    <th class="px-3 py-2 text-left">Sex</th>
                    <th class="px-3 py-2 text-left">Services Availed</th>
                    <th class="px-3 py-2 text-left">Eligibility Type</th>
                    <th class="px-3 py-2 text-left">Date Hired</th>
                    <th class="px-3 py-2 text-left">Employment Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php foreach ($rows as $i => $r): ?>
                  <tr>
                    <td class="px-3 py-2"><?= $i + 1 ?></td>
                    <td class="px-3 py-2 font-medium text-brand-700"><?= e($r['applicant_code']) ?></td>
                    <td class="px-3 py-2"><?= e(full_name($r)) ?></td>
                    <td class="px-3 py-2"><?= e($r['sex']) ?></td>
                    <td class="px-3 py-2"><?= e(report_services_label($r)) ?></td>
                    <td class="px-3 py-2"><?= e(report_eligibility_label($r)) ?></td>
                    <td class="px-3 py-2">N/A</td>
                    <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Not Hired</span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr class="bg-slate-50 font-semibold text-slate-700">
                    <td colspan="8" class="px-3 py-2"><?= e($agencyName) ?> Total Not Hired: <?= count($rows) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($notHiredGroups): ?>
          <div class="text-right font-bold text-slate-800 border-t border-slate-200 pt-3">TOTAL NOT HIRED: <?= $notHiredTotal ?></div>
        <?php endif; ?>
      </div>

      <?php if ($registeredNoApply !== null): ?>
      <div>
        <h3 class="font-semibold text-slate-700 mb-2">Registered but Didn't Apply (<?= count($registeredNoApply) ?>)</h3>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
              <tr>
                <th class="px-3 py-2 text-left">Seq.</th>
                <th class="px-3 py-2 text-left">Applicant ID</th>
                <th class="px-3 py-2 text-left">Full Name</th>
                <th class="px-3 py-2 text-left">Sex</th>
                <th class="px-3 py-2 text-left">Services Availed</th>
                <th class="px-3 py-2 text-left">Office/Agency</th>
                <th class="px-3 py-2 text-left">Eligibility Type</th>
                <th class="px-3 py-2 text-left">Date Hired</th>
                <th class="px-3 py-2 text-left">Employment Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!$registeredNoApply): ?>
                <tr><td colspan="9" class="px-3 py-4 text-center text-slate-400">No records.</td></tr>
              <?php endif; ?>
              <?php foreach ($registeredNoApply as $i => $r): ?>
              <tr>
                <td class="px-3 py-2"><?= $i + 1 ?></td>
                <td class="px-3 py-2 font-medium text-brand-700"><?= e($r['applicant_code']) ?></td>
                <td class="px-3 py-2"><?= e(full_name($r)) ?></td>
                <td class="px-3 py-2"><?= e($r['sex']) ?></td>
                <td class="px-3 py-2"><?= e(report_services_label($r)) ?></td>
                <td class="px-3 py-2">N/A</td>
                <td class="px-3 py-2"><?= e(report_eligibility_label($r)) ?></td>
                <td class="px-3 py-2">N/A</td>
                <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Registered &mdash; Did Not Apply</span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    <?php elseif ($reportType === 'yearly_summary'): ?>
      <?php foreach ($yearlyRows ?? [] as $y => $m): ?>
        <div class="rounded-lg border border-slate-100 p-4">
          <h3 class="font-bold text-slate-800 mb-3">YEAR: <?= $y ?></h3>
          <div class="grid sm:grid-cols-3 gap-3 mb-3">
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500"><?= e($m['primary_label']) ?></p><p class="text-lg font-bold text-slate-800"><?= $m['primary'] ?></p></div>
            <div class="rounded-lg bg-green-50 p-3"><p class="text-xs text-green-700">Total Participant Hired</p><p class="text-lg font-bold text-green-800"><?= $m['hired'] ?></p></div>
            <div class="rounded-lg bg-amber-50 p-3"><p class="text-xs text-amber-700">Total Participant Not Hired</p><p class="text-lg font-bold text-amber-800"><?= $m['not_hired'] ?></p></div>
          </div>
          <div class="grid sm:grid-cols-3 gap-3">
            <?php if ($m['agencies'] !== null): ?>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">Total Participating Agency</p><p class="text-lg font-bold text-slate-800"><?= $m['agencies'] ?></p></div>
            <?php endif; ?>
            <div class="rounded-lg bg-blue-50 p-3"><p class="text-xs text-blue-700">Total Job Vacancies</p><p class="text-lg font-bold text-blue-800"><?= $m['vacancies'] ?></p></div>
            <div class="rounded-lg bg-purple-50 p-3"><p class="text-xs text-purple-700">Total Job Vacancies Filled</p><p class="text-lg font-bold text-purple-800"><?= $m['filled'] ?></p></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

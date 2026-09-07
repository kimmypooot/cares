<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
require_login();

$pdo = Database::getConnection();
$scopedAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;

$reportType = clean($_GET['report_type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$results = null;
$reportLabel = '';

$reportOptions = [
    // Applicant reports
    'all_applicants'      => 'All Registered Applicants',
    'male_applicants'     => 'Male Applicants',
    'female_applicants'   => 'Female Applicants',
    'by_civil_status'     => 'Applicants by Civil Status',
    'by_registration_date'=> 'Applicants by Registration Date',
    // Employment reports
    'hired'          => 'All Hired Applicants',
    'not_yet_hired'  => 'For Further Review Applicants',
    'job_order'      => 'Job Order Applicants',
    'cos'            => 'COS Applicants',
    'temporary'      => 'Temporary Applicants',
    'casual'         => 'Casual Applicants',
    'permanent'      => 'Permanent Applicants',
    'by_agency'      => 'Applicants by Agency/Company',
    'hired_in_range' => 'Applicants Hired Within a Date Range',
    // Services Availed reports
    'job_seeker_applicants'      => 'Job Seeker Applicants',
    'agency_services_applicants' => 'Agency Services Applicants',
];

// Partner Agency accounts never see the cross-agency "by_agency" report —
// they're already scoped to one agency, so it would be meaningless. They
// also never see "not_yet_hired": that report's own filter (er.id IS NULL)
// and the agency scope filter (er.agency_id = :scoped_agency_id) both read
// er.* from the same LEFT JOIN row, so when er.id IS NULL every er.* column
// including er.agency_id is NULL too — NULL = :scoped_agency_id can never
// be TRUE, making the combination permanently unsatisfiable (always "0
// record(s)" regardless of real data).
if ($scopedAgencyId !== null) {
    unset($reportOptions['by_agency']);
    unset($reportOptions['not_yet_hired']);
}

if ($reportType && isset($reportOptions[$reportType])) {
    $reportLabel = $reportOptions[$reportType];

    // Only an is_current=1 AND status='Active' row counts as the applicant's
    // present employment — matches the logic used on the Dashboard and
    // Applicant Profile pages.
    $base = "
        SELECT a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
               a.sex, a.date_of_birth, a.contact_number, a.civil_status, a.created_at,
               a.service_job_seeker, a.service_agency_services,
               COALESCE(pa.agency_name, er.agency_company_name) AS agency_company_name,
               er.date_hired, er.employment_status
        FROM applicants a
        LEFT JOIN employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
        LEFT JOIN partner_agencies pa ON pa.id = er.agency_id
        WHERE a.is_deleted = 0
    ";
    $params = [];
    if ($scopedAgencyId !== null) {
        // Own-agency-only, enforced server-side — never trust a request
        // parameter for this; it's always current_agency_id().
        $base .= " AND er.agency_id = :scoped_agency_id";
        $params[':scoped_agency_id'] = $scopedAgencyId;
    }

    switch ($reportType) {
        case 'male_applicants':   $base .= " AND a.sex = 'MALE'"; break;
        case 'female_applicants': $base .= " AND a.sex = 'FEMALE'"; break;
        case 'hired':             $base .= " AND er.id IS NOT NULL"; break;
        case 'not_yet_hired':     $base .= " AND er.id IS NULL"; break;
        case 'job_order':  $base .= " AND er.employment_status = 'Job Order'"; break;
        case 'cos':        $base .= " AND er.employment_status = 'COS'"; break;
        case 'temporary':  $base .= " AND er.employment_status = 'Temporary'"; break;
        case 'casual':     $base .= " AND er.employment_status = 'Casual'"; break;
        case 'permanent':  $base .= " AND er.employment_status = 'Permanent'"; break;
        case 'by_registration_date':
            if ($dateFrom) { $base .= " AND DATE(a.created_at) >= :df"; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $base .= " AND DATE(a.created_at) <= :dt"; $params[':dt'] = $dateTo; }
            break;
        case 'hired_in_range':
            $base .= " AND er.id IS NOT NULL";
            if ($dateFrom) { $base .= " AND er.date_hired >= :df"; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $base .= " AND er.date_hired <= :dt"; $params[':dt'] = $dateTo; }
            break;
        case 'by_agency':
            $base .= " AND er.id IS NOT NULL ORDER BY agency_company_name, a.last_name";
            break;
        case 'job_seeker_applicants':      $base .= " AND a.service_job_seeker = 1"; break;
        case 'agency_services_applicants': $base .= " AND a.service_agency_services = 1"; break;
    }

    if ($reportType !== 'by_agency') {
        $base .= " ORDER BY a.last_name, a.first_name";
    }

    $stmt = $pdo->prepare($base);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if ($reportType === 'by_civil_status') {
        usort($results, fn($a, $b) => strcmp($a['civil_status'], $b['civil_status']));
    }

    // Excel (.xlsx) export
    if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
        $filename = preg_replace('/\W+/', '_', $reportLabel) . '_' . date('Ymd') . '.xlsx';
        $metaLines = [
            'Civil Service Commission RO VIII',
            'Job Applicants ' . date('Y'),
            'Generated: ' . date('F j, Y g:i A'),
            'Total Records: ' . count($results),
        ];
        $headers = ['Applicant ID','Full Name','Sex','Date of Birth','Contact','Civil Status','Services Availed','Agency/Company','Date Hired','Employment Status','Date Registered'];
        $exportRows = [];
        foreach ($results as $r) {
            $svc = [];
            if ($r['service_job_seeker']) $svc[] = 'Job Seeker';
            if ($r['service_agency_services']) $svc[] = 'Agency Services';
            $exportRows[] = [
                $r['applicant_code'], full_name($r), $r['sex'], format_date($r['date_of_birth']),
                $r['contact_number'], $r['civil_status'], $svc ? implode(', ', $svc) : '—', $r['agency_company_name'] ?: '—',
                format_date($r['date_hired'] ?? null), $r['employment_status'] ?: 'For Further Review', format_date($r['created_at']),
            ];
        }
        stream_xlsx($filename, $reportLabel, $metaLines, $headers, $exportRows);
    }
}

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6">
  <div class="print:hidden">
    <h1 class="text-2xl font-bold text-slate-800">Reports</h1>
    <p class="text-sm text-slate-500">Generate applicant and employment reports, with Excel export.</p>
  </div>

  <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 grid md:grid-cols-4 gap-4 items-end print:hidden">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-slate-700 mb-1">Report Type</label>
      <select name="report_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Select a report...</option>
        <optgroup label="Applicant Reports">
          <?php foreach (['all_applicants','male_applicants','female_applicants','by_civil_status','by_registration_date'] as $key): ?>
            <option value="<?= $key ?>" <?= $reportType===$key?'selected':'' ?>><?= e($reportOptions[$key]) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Employment Reports">
          <?php foreach (['hired','not_yet_hired','job_order','cos','temporary','casual','permanent','by_agency','hired_in_range'] as $key): ?>
            <?php if (!isset($reportOptions[$key])) continue; ?>
            <option value="<?= $key ?>" <?= $reportType===$key?'selected':'' ?>><?= e($reportOptions[$key]) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Services Availed Reports">
          <?php foreach (['job_seeker_applicants','agency_services_applicants'] as $key): ?>
            <option value="<?= $key ?>" <?= $reportType===$key?'selected':'' ?>><?= e($reportOptions[$key]) ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">From</label>
      <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">To</label>
      <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="md:col-span-4 flex justify-end gap-2">
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-chart-column mr-1"></i> Generate Report
      </button>
    </div>
  </form>

  <?php if ($results !== null): ?>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:border-0 print:shadow-none">
    <div class="flex items-center justify-between p-5 border-b border-slate-100 flex-wrap gap-3 print:hidden">
      <div>
        <h2 class="font-semibold text-slate-800"><?= e($reportLabel) ?></h2>
        <p class="text-xs text-slate-500">Generated <?= date('F j, Y g:i A') ?> &middot; <?= count($results) ?> record(s)</p>
      </div>
      <div class="flex gap-2">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-file-excel mr-1"></i> Export Excel</a>
        <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-print mr-1"></i> Print</button>
      </div>
    </div>

    <!-- Print-only letterhead: hidden on screen, shown only when printing.
         Screen users see the header block above instead. -->
    <div class="hidden print:block text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Job Applicants <?= date('Y') ?></p>
      <p class="text-sm text-slate-600 mt-2"><?= e($reportLabel) ?></p>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase print:bg-transparent">
          <tr>
            <th class="px-4 py-2.5 text-left">Applicant ID</th>
            <th class="px-4 py-2.5 text-left">Full Name</th>
            <th class="px-4 py-2.5 text-left">Sex</th>
            <th class="px-4 py-2.5 text-left">Civil Status</th>
            <th class="px-4 py-2.5 text-left">Services Availed</th>
            <th class="px-4 py-2.5 text-left">Agency/Company</th>
            <th class="px-4 py-2.5 text-left">Date Hired</th>
            <th class="px-4 py-2.5 text-left">Employment Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$results): ?>
            <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No records match this report.</td></tr>
          <?php endif; ?>
          <?php foreach ($results as $r): ?>
          <tr>
            <td class="px-4 py-2.5 font-medium text-brand-700"><?= e($r['applicant_code']) ?></td>
            <td class="px-4 py-2.5"><?= e(full_name($r)) ?></td>
            <td class="px-4 py-2.5"><?= e($r['sex']) ?></td>
            <td class="px-4 py-2.5"><?= e($r['civil_status']) ?></td>
            <td class="px-4 py-2.5 uppercase">
              <?php
                $svc = [];
                if ($r['service_job_seeker']) $svc[] = 'Job Seeker';
                if ($r['service_agency_services']) $svc[] = 'Agency Services';
              ?>
              <?= $svc ? e(implode(', ', $svc)) : '<span class="text-slate-300">—</span>' ?>
            </td>
            <td class="px-4 py-2.5"><?= e($r['agency_company_name'] ?: '—') ?></td>
            <td class="px-4 py-2.5"><?= format_date($r['date_hired'] ?? null) ?></td>
            <td class="px-4 py-2.5 uppercase"><?= e($r['employment_status'] ?: 'For Further Review') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
/**
 * api/applicants-export.php — streams the Registered Applicants list as
 * an .xlsx download, honoring the exact same search/filter state (and
 * Partner Agency restriction) as api/applicants.php's live table, via
 * the shared build_applicant_filters()/map_applicant_row() helpers in
 * functions.php. Unlike the paginated JSON endpoint, this exports every
 * matching row (no LIMIT/OFFSET) — clicking "Export to Excel" downloads
 * the full filtered result set, not just the current page.
 *
 * A plain browser navigation (triggered via window.location.href, not
 * fetch), so a 401 JSON blob would be poor UX for an unauthenticated
 * hit — redirect to login instead, same as any other page.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_login();

$pdo = Database::getConnection();

$search = clean($_GET['search'] ?? '');
$sex = clean($_GET['sex'] ?? '');
$civilStatus = clean($_GET['civil_status'] ?? '');
$service = clean($_GET['service'] ?? '');
$serviceAvailed = clean($_GET['service_availed'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$sortCol = clean($_GET['sort'] ?? 'created_at');
$sortDir = strtolower(clean($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSort = ['applicant_code', 'last_name', 'sex', 'date_of_birth', 'created_at'];
if (!in_array($sortCol, $allowedSort, true)) {
    $sortCol = 'created_at';
}

$built = build_applicant_filters($pdo, [
    'search' => $search, 'sex' => $sex, 'civil_status' => $civilStatus,
    'service' => $service, 'service_availed' => $serviceAvailed,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
]);

$sql = "
    SELECT
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.date_of_birth, a.contact_number, a.address, a.civil_status, a.created_at,
        a.service_job_seeker, a.service_agency_services,
        er.employment_status AS current_status
    FROM care_jf_applicants a
    LEFT JOIN care_jf_employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
    WHERE {$built['where']}
    ORDER BY a.$sortCol $sortDir
";

$stmt = $pdo->prepare($sql);
foreach ($built['params'] as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$rows = array_map('map_applicant_row', $stmt->fetchAll());

$headers = [
    'Applicant ID', 'Full Name', 'Sex', 'Date of Birth', 'Contact', 'Civil Status',
    'Services Availed', 'Employment Status', 'Date Registered',
];
$exportRows = array_map(fn(array $r) => [
    $r['applicant_code'], $r['full_name'], $r['sex'], $r['date_of_birth'], $r['contact_number'],
    $r['civil_status'], implode(', ', array_map('mb_strtoupper', $r['services_availed'])),
    mb_strtoupper($r['employment_status']), $r['date_registered'],
], $rows);

$metaLines = ['Generated: ' . date('F j, Y g:i A'), 'Total records: ' . count($rows)];
if (is_partner_agency()) {
    $metaLines[] = 'Agency: ' . current_agency_name($pdo);
}

// Admin/Employee's Clients page (public/clients.php) reuses this exact
// endpoint with service=agency_services permanently set, rather than a
// separate export file — this is the only place that distinguishes it,
// same as the JSON endpoint's title never changing based on the filter.
$title = $service === 'agency_services' ? 'Clients' : 'Registered Applicants';
$filenamePrefix = $service === 'agency_services' ? 'Clients' : 'Registered_Applicants';
$filename = $filenamePrefix . '_' . date('Ymd_His') . '.xlsx';
stream_xlsx($filename, $title, $metaLines, $headers, $exportRows);

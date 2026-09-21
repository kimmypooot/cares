<?php
/**
 * api/employment-export.php — streams the Employment Records list as an
 * .xlsx download, honoring the exact same search/status/classification/
 * agency filter state as employment-list.php's table (same $where/$params
 * construction, tracking rows excluded the same way). Exports every
 * matching row (no LIMIT/OFFSET), not just the current page.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_login();

if (is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — Partner Agency accounts do not have access to the Employment module.</h2>');
}

$pdo = Database::getConnection();

$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');
$classFilter = clean($_GET['classification'] ?? 'All');
$agencyFilter = clean($_GET['agency'] ?? 'All');

$where = ['a.is_deleted = 0', "er.employment_status NOT IN ('For Review', 'Withdrawn', 'Superseded')"];
$params = [];

if ($search !== '') {
    $where[] = "(a.applicant_code LIKE :search1 OR CONCAT(a.last_name,' ',a.first_name) LIKE :search2
                 OR er.agency_company_name LIKE :search3 OR pa.agency_name LIKE :search4)";
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
    $params[':search4'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 'er.status = :status';
    $params[':status'] = $statusFilter;
}
if ($classFilter !== '' && $classFilter !== 'All') {
    $where[] = 'er.employment_status = :class';
    $params[':class'] = $classFilter;
}
if ($agencyFilter !== '' && $agencyFilter !== 'All') {
    $where[] = "COALESCE(pa.agency_name, er.agency_company_name) = :agency";
    $params[':agency'] = $agencyFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT er.*, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
            COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name
     FROM care_jf_employment_records er
     JOIN care_jf_applicants a ON a.id = er.applicant_id
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE $whereSql
     ORDER BY er.date_hired DESC, er.id DESC"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$records = $stmt->fetchAll();

$headers = ['Applicant', 'Applicant ID', 'Agency/Company', 'Date Hired', 'Classification', 'Current?', 'Record Status'];
$exportRows = array_map(fn(array $r) => [
    full_name($r),
    $r['applicant_code'],
    $r['agency_display_name'],
    format_date($r['date_hired']),
    mb_strtoupper($r['employment_status']),
    $r['is_current'] ? 'CURRENT' : '',
    $r['status'],
], $records);

$metaLines = ['Generated: ' . date('F j, Y g:i A'), 'Total records: ' . count($records)];

$filename = 'Employment_Records_' . date('Ymd_His') . '.xlsx';
stream_xlsx($filename, 'Employment Records', $metaLines, $headers, $exportRows);

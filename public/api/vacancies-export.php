<?php
/**
 * api/vacancies-export.php — streams the Job Vacancies list as an .xlsx
 * download, honoring the same search/status filter state and Partner
 * Agency scoping as vacancies.php's table.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_login();

if (!can_manage_employment() && !is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this page.</h2>');
}

$pdo = Database::getConnection();
$scopedAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;

$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');
$where = [];
$params = [];

if ($scopedAgencyId !== null) {
    $where[] = 'jv.agency_id = :agid';
    $params[':agid'] = $scopedAgencyId;
}
if ($search !== '') {
    $where[] = '(jv.position LIKE :s1 OR jv.title LIKE :s2)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 'jv.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT jv.*, pa.agency_name,
            (SELECT COUNT(*) FROM care_jf_employment_records er
              WHERE er.vacancy_id = jv.id AND er.employment_status = 'Hired') AS filled_count
     FROM care_jf_job_vacancies jv
     JOIN care_jf_partner_agencies pa ON pa.id = jv.agency_id
     $whereSql ORDER BY jv.created_at DESC"
);
$stmt->execute($params);
$vacancies = $stmt->fetchAll();

$headers = $scopedAgencyId === null
    ? ['Agency', 'Position', 'Title', 'Job Level', 'Salary Grade', 'Vacant', 'Filled', 'Status']
    : ['Position', 'Title', 'Job Level', 'Salary Grade', 'Vacant', 'Filled', 'Status'];

$exportRows = array_map(function (array $v) use ($scopedAgencyId) {
    $row = [];
    if ($scopedAgencyId === null) {
        $row[] = $v['agency_name'];
    }
    $row[] = $v['position'];
    $row[] = $v['title'];
    $row[] = $v['job_level'];
    $row[] = $v['salary_grade'] ?: '';
    $row[] = (int)$v['vacant_count'];
    $row[] = (int)$v['filled_count'];
    $row[] = $v['status'];
    return $row;
}, $vacancies);

$metaLines = ['Generated: ' . date('F j, Y g:i A'), 'Total records: ' . count($vacancies)];
if (is_partner_agency()) {
    $metaLines[] = 'Agency: ' . current_agency_name($pdo);
}

$filename = 'Job_Vacancies_' . date('Ymd_His') . '.xlsx';
stream_xlsx($filename, 'Job Vacancies', $metaLines, $headers, $exportRows);

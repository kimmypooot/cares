<?php
/**
 * api/services-export.php — streams the Services list as an .xlsx
 * download, honoring the same search/status filter state and Partner
 * Agency scoping as services.php's table.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_login();

$pdo = Database::getConnection();

$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');

$where = [];
$params = [];
if (is_partner_agency()) {
    $where[] = 's.agency_id = :aid';
    $params[':aid'] = current_agency_id($pdo);
}
if ($search !== '') {
    $where[] = '(s.service_name LIKE :s1 OR pa.agency_name LIKE :s2)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 's.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSql = is_partner_agency() ? 's.service_name' : 'pa.agency_name, s.service_name';

$stmt = $pdo->prepare(
    "SELECT s.*, pa.agency_name FROM care_jf_agency_services s
     JOIN care_jf_partner_agencies pa ON pa.id = s.agency_id
     $whereSql ORDER BY $orderSql"
);
$stmt->execute($params);
$services = $stmt->fetchAll();

$headers = is_partner_agency()
    ? ['Service Name', 'Description', 'Status']
    : ['Agency', 'Service Name', 'Description', 'Status'];

$exportRows = array_map(function (array $s) {
    $row = [];
    if (!is_partner_agency()) {
        $row[] = $s['agency_name'];
    }
    $row[] = $s['service_name'];
    $row[] = $s['description'] ?: '';
    $row[] = $s['status'];
    return $row;
}, $services);

$metaLines = ['Generated: ' . date('F j, Y g:i A'), 'Total records: ' . count($services)];
if (is_partner_agency()) {
    $metaLines[] = 'Agency: ' . current_agency_name($pdo);
}

$filename = 'Services_' . date('Ymd_His') . '.xlsx';
stream_xlsx($filename, 'Services', $metaLines, $headers, $exportRows);

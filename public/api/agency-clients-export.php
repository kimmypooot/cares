<?php
/**
 * api/agency-clients-export.php — streams a Partner Agency's own Clients
 * list as an .xlsx download, honoring the exact same search/filter state
 * as api/agency-clients.php's live table, via the shared
 * build_agency_client_filters()/map_agency_client_row() helpers in
 * functions.php. Exports every matching row (no LIMIT/OFFSET).
 *
 * A plain browser navigation (triggered via window.location.href, not
 * fetch), so a 401/403 JSON blob would be poor UX — redirect to login
 * (require_login()) and 403 via require_role() the same way the page
 * itself is protected, rather than returning JSON.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_login();

if (!is_partner_agency()) {
    http_response_code(403);
    die('Forbidden.');
}

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);

$search = clean($_GET['search'] ?? '');
$serviceAvailed = clean($_GET['service_availed'] ?? '');
$sortCol = clean($_GET['sort'] ?? 'sa.updated_at');
$sortDir = strtolower(clean($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSort = ['a.applicant_code', 'a.last_name', 'a.sex', 'sa.updated_at'];
if (!in_array($sortCol, $allowedSort, true)) {
    $sortCol = 'sa.updated_at';
}

$built = build_agency_client_filters($agencyId, ['search' => $search, 'service_availed' => $serviceAvailed]);

$sql = "
    SELECT
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.service_job_seeker, a.service_agency_services,
        sa.status AS availment_status, sa.updated_at AS date_availed,
        COALESCE(asv.service_name, sa.custom_service_name) AS service_availed
    FROM care_jf_service_availments sa
    JOIN care_jf_applicants a ON a.id = sa.applicant_id AND a.is_deleted = 0
    LEFT JOIN care_jf_agency_services asv ON asv.id = sa.service_id
    WHERE {$built['where']}
    ORDER BY $sortCol $sortDir
";

$stmt = $pdo->prepare($sql);
foreach ($built['params'] as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$rows = array_map('map_agency_client_row', $stmt->fetchAll());

$headers = ['Application ID', 'Full Name', 'Sex', 'Services Registered', 'Service Availed', 'Status', 'Date Availed'];
$exportRows = array_map(fn(array $r) => [
    $r['applicant_code'], $r['full_name'], $r['sex'],
    implode(', ', array_map('mb_strtoupper', $r['services_registered'])),
    $r['service_availed'], $r['status'], $r['date_availed'],
], $rows);

$metaLines = [
    'Generated: ' . date('F j, Y g:i A'),
    'Total records: ' . count($rows),
    'Agency: ' . current_agency_name($pdo),
];

$filename = 'Clients_' . date('Ymd_His') . '.xlsx';
stream_xlsx($filename, 'Clients', $metaLines, $headers, $exportRows);

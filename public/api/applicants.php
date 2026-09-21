<?php
/**
 * api/applicants.php — AJAX search/filter/pagination for the Registered Applicants table.
 * Returns JSON.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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

[$limit, $offset, $page] = paginate_params();

$built = build_applicant_filters($pdo, [
    'search' => $search, 'sex' => $sex, 'civil_status' => $civilStatus,
    'service' => $service, 'service_availed' => $serviceAvailed,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
]);
$whereSql = $built['where'];
$params = $built['params'];

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.date_of_birth, a.contact_number, a.address, a.civil_status, a.created_at,
        a.service_job_seeker, a.service_agency_services,
        er.employment_status AS current_status
    FROM care_jf_applicants a
    LEFT JOIN care_jf_employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
    WHERE $whereSql
    ORDER BY a.$sortCol $sortDir
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$total = (int)$pdo->query('SELECT FOUND_ROWS()')->fetchColumn();

$data = array_map('map_applicant_row', $rows);

echo json_encode([
    'data'  => $data,
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);

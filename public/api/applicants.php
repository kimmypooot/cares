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
$employmentStatus = clean($_GET['employment_status'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$sortCol = clean($_GET['sort'] ?? 'created_at');
$sortDir = strtolower(clean($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSort = ['applicant_code', 'last_name', 'sex', 'date_of_birth', 'created_at'];
if (!in_array($sortCol, $allowedSort, true)) {
    $sortCol = 'created_at';
}

[$limit, $offset, $page] = paginate_params();

$where = ['a.is_deleted = 0'];
$params = [];

if ($search !== '') {
    $where[] = "(a.applicant_code LIKE :search1 OR a.contact_number LIKE :search2
                 OR CONCAT(a.last_name,' ',a.first_name,' ',IFNULL(a.middle_name,'')) LIKE :search3)";
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}
if ($sex !== '' && $sex !== 'All') {
    $where[] = 'a.sex = :sex';
    $params[':sex'] = $sex;
}
if ($civilStatus !== '' && $civilStatus !== 'All') {
    $where[] = 'a.civil_status = :civil_status';
    $params[':civil_status'] = $civilStatus;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(a.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(a.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}
if (is_partner_agency()) {
    // Once hired, an applicant disappears from every OTHER agency's
    // pool — but the hiring agency keeps seeing their own hire. This
    // reuses the query's existing LEFT JOIN against the current
    // active employment record (already present for current_status
    // below) rather than adding a second join. Not hired at all
    // (er.id IS NULL) is always visible; hired by this same agency is
    // always visible; hired by anyone else is excluded.
    $where[] = '(er.id IS NULL OR er.agency_id = :my_agency_id)';
    $params[':my_agency_id'] = current_agency_id($pdo);
}

$whereSql = implode(' AND ', $where);

// Employment status filter requires join against current record
$havingSql = '';
if ($employmentStatus !== '' && $employmentStatus !== 'All') {
    if ($employmentStatus === 'For Further Review') {
        $havingSql = "HAVING current_status IS NULL";
    } else {
        $havingSql = "HAVING current_status = :emp_status";
        $params[':emp_status'] = $employmentStatus;
    }
}

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.date_of_birth, a.contact_number, a.address, a.civil_status, a.created_at,
        a.service_job_seeker, a.service_agency_services,
        er.employment_status AS current_status
    FROM care_jf_applicants a
    LEFT JOIN care_jf_employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
    WHERE $whereSql
    $havingSql
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

$data = array_map(function ($row) {
    $status = $row['current_status'] ?: 'For Further Review';
    $services = [];
    if ($row['service_job_seeker']) $services[] = 'Job Seeker';
    if ($row['service_agency_services']) $services[] = 'Agency Services';
    return [
        'id'                => (int)$row['id'],
        'applicant_code'    => $row['applicant_code'],
        'full_name'         => full_name($row),
        'sex'               => $row['sex'],
        'date_of_birth'     => format_date($row['date_of_birth']),
        'contact_number'    => $row['contact_number'],
        'address'           => $row['address'],
        'civil_status'      => $row['civil_status'],
        'employment_status' => $status,
        'services_availed'  => $services,
        'date_registered'   => format_date($row['created_at']),
    ];
}, $rows);

echo json_encode([
    'data'  => $data,
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);

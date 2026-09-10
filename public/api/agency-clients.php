<?php
/**
 * api/agency-clients.php — AJAX search/filter/pagination for a Partner
 * Agency's own Clients list (public/clients.php's Partner-Agency-role
 * branch). Scoped exclusively to the authenticated agency's own
 * care_jf_service_availments rows — never trusts a posted/URL agency id.
 * Mirrors api/applicants.php's shape (SQL_CALC_FOUND_ROWS, whitelisted
 * sort) for consistency, but the underlying data (Service Availed, Date
 * Availed, per-agency Status) lives on care_jf_service_availments, not
 * on the applicant row, so this is a separate query rather than a filter
 * added to api/applicants.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!is_partner_agency()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);

$search = clean($_GET['search'] ?? '');
$sex = clean($_GET['sex'] ?? '');
$sortCol = clean($_GET['sort'] ?? 'sa.updated_at');
$sortDir = strtolower(clean($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSort = ['a.applicant_code', 'a.last_name', 'a.sex', 'sa.updated_at'];
if (!in_array($sortCol, $allowedSort, true)) {
    $sortCol = 'sa.updated_at';
}

[$limit, $offset, $page] = paginate_params();

$where = ['sa.agency_id = :agid'];
$params = [':agid' => $agencyId];

if ($search !== '') {
    $where[] = "(a.applicant_code LIKE :search1 OR CONCAT(a.last_name,' ',a.first_name,' ',IFNULL(a.middle_name,'')) LIKE :search2)";
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}
if ($sex !== '' && $sex !== 'All') {
    $where[] = 'a.sex = :sex';
    $params[':sex'] = $sex;
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.service_job_seeker, a.service_agency_services,
        sa.status AS availment_status, sa.updated_at AS date_availed,
        COALESCE(asv.service_name, sa.custom_service_name) AS service_availed
    FROM care_jf_service_availments sa
    JOIN care_jf_applicants a ON a.id = sa.applicant_id AND a.is_deleted = 0
    LEFT JOIN care_jf_agency_services asv ON asv.id = sa.service_id
    WHERE $whereSql
    ORDER BY $sortCol $sortDir
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
    $services = [];
    if ($row['service_job_seeker']) $services[] = 'Job Seeker';
    if ($row['service_agency_services']) $services[] = 'Avail Agency Services';
    return [
        'id'                 => (int)$row['id'],
        'applicant_code'     => $row['applicant_code'],
        'full_name'          => full_name($row),
        'sex'                => $row['sex'],
        'services_registered' => $services,
        'service_availed'   => $row['service_availed'] ?: '—',
        'status'             => $row['availment_status'] === 'Active' ? 'AVAILING SERVICES' : 'WITHDRAWN',
        'date_availed'       => format_date($row['date_availed']),
    ];
}, $rows);

echo json_encode([
    'data'  => $data,
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);

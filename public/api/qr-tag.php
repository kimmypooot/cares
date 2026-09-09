<?php
/**
 * api/qr-tag.php — Partner Agency QR auto-tag endpoint.
 * Called by the scanner modal's JS before navigating to an applicant's
 * profile: associates the applicant with the logged-in agency (never
 * with an agency named in the request). See
 * docs/superpowers/specs/2026-09-10-qr-auto-tagging-design.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'status' => 'unauthorized']);
    exit;
}

if (!is_partner_agency()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'status' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'status' => 'method_not_allowed']);
    exit;
}

csrf_require();

$pdo = Database::getConnection();

$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput !== '' ? json_decode($rawInput, true) : null;
$code = clean((string)(($jsonInput['code'] ?? null) ?? ($_POST['code'] ?? '')));

if ($code === '') {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, applicant_code FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0");
$stmt->execute([':code' => $code]);
$applicant = $stmt->fetch();

if (!$applicant) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$applicantId = (int)$applicant['id'];
$agencyId = current_agency_id($pdo);

if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid']);
    exit;
}

$result = tag_applicant_for_agency(
    $pdo, $applicantId, $applicant['applicant_code'], $agencyId, (int)current_user()['id'], 'qr_scan'
);

if ($result === 'created') {
    flash_set('success', 'Applicant successfully associated with your agency.');
} elseif ($result === 'duplicate') {
    flash_set('success', 'Applicant is already associated with your agency.');
}

if (in_array($result, ['created', 'duplicate', 'already_hired'], true)) {
    echo json_encode(['ok' => true, 'status' => $result, 'applicant_id' => $applicantId]);
} else {
    echo json_encode(['ok' => false, 'status' => $result]);
}

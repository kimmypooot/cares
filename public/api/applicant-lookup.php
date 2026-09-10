<?php
/**
 * api/applicant-lookup.php — read-only applicant-code lookup.
 * Used by the QR scanner modal to show the applicant's full name in the
 * Partner Agency auto-tag confirm dialog before the code is submitted for
 * tagging (see includes/qr-scanner-modal.php / qrScanner() in app.js).
 * Read-only: no CSRF needed, and it exposes nothing a logged-in user
 * couldn't already see via the Applicants search or applicant-view.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'status' => 'unauthorized']);
    exit;
}

$pdo = Database::getConnection();
$code = clean($_GET['code'] ?? '');

if ($code === '') {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT applicant_code, last_name, first_name, middle_name, extension_name
     FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0"
);
$stmt->execute([':code' => $code]);
$applicant = $stmt->fetch();

if (!$applicant) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'applicant_code' => $applicant['applicant_code'],
    'full_name' => full_name($applicant),
]);

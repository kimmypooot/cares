<?php
/**
 * api/service-availment-confirm.php — sets which specific service a
 * Client's already-tagged service-availment row represents (Step 2 of
 * the two-step tagging flow — Step 1 is tag_applicant_for_service(),
 * already run by api/qr-tag.php before this modal ever opens). Called
 * by the Service Availment modal's Confirm button. See
 * docs/superpowers/specs/2026-09-10-client-service-modal-and-nav-design.md §5.3.
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
$applicantId = (int)($jsonInput['applicant_id'] ?? 0);
$rawServiceId = $jsonInput['service_id'] ?? null;
$rawCustomName = $jsonInput['custom_service_name'] ?? '';

if (!$applicantId) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$agencyId = current_agency_id($pdo);
if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid']);
    exit;
}

if ($rawServiceId === 'others') {
    $serviceId = null;
    $customServiceName = mb_strtoupper(trim(clean(is_string($rawCustomName) ? $rawCustomName : '')), 'UTF-8');
    if ($customServiceName === '') {
        echo json_encode(['ok' => false, 'status' => 'custom_name_required']);
        exit;
    }
    if (mb_strlen($customServiceName) > 200) {
        echo json_encode(['ok' => false, 'status' => 'custom_name_too_long']);
        exit;
    }
} elseif (is_numeric($rawServiceId) && (int)$rawServiceId > 0) {
    $serviceId = (int)$rawServiceId;
    $customServiceName = null;
} else {
    echo json_encode(['ok' => false, 'status' => 'service_required']);
    exit;
}

$result = set_service_availment_selection(
    $pdo, $applicantId, $agencyId, $serviceId, $customServiceName, (int)current_user()['id']
);

if ($result === 'updated') {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'status' => $result]);
}

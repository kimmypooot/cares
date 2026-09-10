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

// Checked directly (not via require_role()) because require_role() fails
// with an HTML die() — wrong for a JSON API, which needs a clean JSON 403.
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
$rawCode = ($jsonInput['code'] ?? null) ?? ($_POST['code'] ?? '');
$code = clean(is_string($rawCode) ? $rawCode : '');
$via = ($jsonInput['via'] ?? '') === 'manual' ? 'manual' : 'camera';

if ($code === '') {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, applicant_code, service_job_seeker, service_agency_services
     FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0"
);
$stmt->execute([':code' => $code]);
$applicant = $stmt->fetch();

if (!$applicant) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$applicantId = (int)$applicant['id'];
$agencyId = current_agency_id($pdo);

if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid', 'applicant_id' => $applicantId]);
    exit;
}

$actingUserId = (int)current_user()['id'];
$results = [];

if ($applicant['service_job_seeker']) {
    $results['employment'] = tag_applicant_for_agency(
        $pdo, $applicantId, $applicant['applicant_code'], $agencyId, $actingUserId, 'qr_scan', $via
    );
}
if ($applicant['service_agency_services']) {
    $results['service'] = tag_applicant_for_service(
        $pdo, $applicantId, $applicant['applicant_code'], $agencyId, $actingUserId, 'qr_scan', $via
    );
}

// agency_invalid is identical for both calls (same $agencyId every time) —
// checking either is representative of "the scanning agency's own
// account is not valid right now."
$anyAgencyInvalid = in_array('agency_invalid', $results, true);
if ($anyAgencyInvalid) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid', 'applicant_id' => $applicantId]);
    exit;
}

$onlyEmployment = isset($results['employment']) && !isset($results['service']);

if ($onlyEmployment) {
    // Byte-for-byte identical to this endpoint's pre-existing behavior —
    // the only branch combination that has real prior production text.
    if ($results['employment'] === 'created') {
        flash_set('success', 'Applicant successfully associated with your agency.');
    } elseif ($results['employment'] === 'duplicate') {
        flash_set('success', 'Applicant is already associated with your agency.');
    }
} elseif (!$results) {
    flash_set('error', 'This applicant did not register for any service — nothing to tag.');
} else {
    $flashParts = [];
    if (isset($results['employment'])) {
        if ($results['employment'] === 'created') {
            $flashParts[] = 'tagged for review';
        } elseif ($results['employment'] === 'duplicate') {
            $flashParts[] = 'already associated with your agency';
        }
    }
    if (isset($results['service'])) {
        if ($results['service'] === 'created') {
            $flashParts[] = 'service availed logged with your agency';
        } elseif ($results['service'] === 'duplicate') {
            $flashParts[] = 'service availment already on file with your agency';
        }
    }
    if ($flashParts) {
        flash_set('success', 'Applicant ' . implode(' and ', $flashParts) . '.');
    }
}

echo json_encode(array_merge(
    ['ok' => true, 'applicant_id' => $applicantId],
    isset($results['employment']) ? ['employment_status' => $results['employment']] : [],
    isset($results['service']) ? ['service_status' => $results['service']] : []
));

<?php
/**
 * Shared helper functions — includes/functions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/** Escape a string for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Trim + strip tags from raw user input (does NOT replace prepared statements). */
function clean(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/** Redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Flash message helpers (stored one request in session). */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/** Build "Last Name, First Name Middle Name Extension" */
function full_name(array $applicant): string
{
    $parts = [$applicant['first_name']];
    if (!empty($applicant['middle_name'])) {
        $parts[] = $applicant['middle_name'];
    }
    $name = $applicant['last_name'] . ', ' . implode(' ', $parts);
    if (!empty($applicant['extension_name']) && $applicant['extension_name'] !== 'NONE') {
        $name .= ' ' . $applicant['extension_name'];
    }
    return $name;
}

/** Generate the next sequential applicant code, e.g. APP-000001 */
/**
 * Generate the next sequential applicant code for the current calendar
 * month, in the format APP-YYYYMM-NNNNNN (e.g. APP-202609-000001). The
 * 6-digit sequence resets to 000001 at the start of each new month.
 */
function generate_applicant_code(PDO $pdo): string
{
    $yearMonth = date('Ym');
    $prefix = 'APP-' . $yearMonth . '-';

    $stmt = $pdo->prepare(
        "SELECT applicant_code FROM applicants
         WHERE applicant_code LIKE :prefix
         ORDER BY applicant_code DESC LIMIT 1"
    );
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

/** Format a date consistently, e.g. "January 15, 2026" */
function format_date(?string $date): string
{
    if (empty($date)) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('F j, Y', $ts) : '—';
}

/** Basic Philippine mobile number validation: 09XXXXXXXXX or +639XXXXXXXXX */
function is_valid_ph_number(string $number): bool
{
    $number = preg_replace('/\s+/', '', $number);
    return (bool)preg_match('/^(09\d{9}|\+639\d{9})$/', $number);
}

/**
 * Record an entry in audit_logs.
 */
function audit_log(PDO $pdo, ?int $userId, string $action, string $table, ?int $recordId, string $description): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, description, ip_address)
         VALUES (:user_id, :action, :table_name, :record_id, :description, :ip)"
    );
    $stmt->execute([
        ':user_id'     => $userId,
        ':action'      => $action,
        ':table_name'  => $table,
        ':record_id'   => $recordId,
        ':description' => $description,
        ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/** Determine an applicant's current employment status label + badge color.
 * A row only counts as "current" if is_current=1 AND status='Active' —
 * a disabled employment record does not make the applicant "Hired".
 * Returns ['label' => string, 'color' => tailwind classes]
 */
function current_employment_status(PDO $pdo, int $applicantId): array
{
    $stmt = $pdo->prepare(
        "SELECT employment_status FROM employment_records
         WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'
         ORDER BY date_hired DESC LIMIT 1"
    );
    $stmt->execute([':id' => $applicantId]);
    $status = $stmt->fetchColumn();

    if (!$status) {
        return ['label' => 'For Further Review', 'color' => 'bg-gray-100 text-gray-700'];
    }

    $colors = [
        'Job Order'  => 'bg-yellow-100 text-yellow-800',
        'Temporary'  => 'bg-purple-100 text-purple-800',
        'COS'        => 'bg-blue-100 text-blue-800',
        'Permanent'  => 'bg-green-100 text-green-800',
        'Casual'     => 'bg-orange-100 text-orange-800',
        'Other'      => 'bg-gray-100 text-gray-700',
        'Hired'      => 'bg-emerald-100 text-emerald-800',
    ];

    return ['label' => $status, 'color' => $colors[$status] ?? 'bg-gray-100 text-gray-700'];
}

/** Is this applicant currently "Hired" (has an active current employment record)? */
function is_applicant_hired(PDO $pdo, int $applicantId): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
    );
    $stmt->execute([':id' => $applicantId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Basic email validation. */
function is_valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Fetch active partner agencies for dropdowns. Pass $includeId to also
 * include one specific (possibly disabled) agency, e.g. when editing a
 * record that references a now-disabled agency. */
function active_agencies(PDO $pdo, ?int $includeId = null): array
{
    if ($includeId) {
        $stmt = $pdo->prepare(
            "SELECT id, agency_name, status FROM partner_agencies
             WHERE status = 'Active' OR id = :id
             ORDER BY agency_name"
        );
        $stmt->execute([':id' => $includeId]);
    } else {
        $stmt = $pdo->query("SELECT id, agency_name, status FROM partner_agencies WHERE status = 'Active' ORDER BY agency_name");
    }
    return $stmt->fetchAll();
}

/** Is this agency name already used by a different partner agency? Pass $excludeId when editing an existing one. */
function agency_name_taken(PDO $pdo, string $agencyName, ?int $excludeId = null): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partner_agencies WHERE agency_name = :n AND id <> :id");
    $stmt->execute([':n' => $agencyName, ':id' => $excludeId ?? 0]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Paginate: returns [limit, offset, page] from $_GET, sanitized. */
function paginate_params(): array
{
    $allowed = [10, 25, 50, 100];
    $limit = (int)($_GET['per_page'] ?? 25);
    if (!in_array($limit, $allowed, true)) {
        $limit = 25;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    return [$limit, $offset, $page];
}

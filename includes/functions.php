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

/**
 * Cache-busting query string ("?v=<mtime>") for one of this app's own
 * first-party static assets (public/assets/css/app.build.css,
 * public/assets/js/app.js) — these change with every deploy, but their
 * <link>/<script> tags carry no other cache-busting signal, so a
 * browser that already cached an old copy keeps serving it forever.
 * $relativePath is relative to public/, e.g. 'assets/js/app.js'.
 * Not used for vendored third-party libraries — those are pinned and
 * only ever change via the documented, deliberate vendoring-refresh
 * process, so long-lived caching there is desired, not a bug.
 */
function asset_version(string $relativePath): string
{
    $fullPath = __DIR__ . '/../public/' . ltrim($relativePath, '/');
    $mtime = @filemtime($fullPath);
    return $mtime !== false ? '?v=' . $mtime : '';
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

/**
 * Generate the next sequential applicant code for the current calendar
 * month, in the format APP-YYYYMM-NNNNNN (e.g. APP-202609-000001). The
 * 6-digit sequence resets to 000001 at the start of each new month.
 *
 * Uses the same atomic-counter pattern as generate_employer_id() (the
 * shared care_jf_id_sequences table, keyed here by sequence_name =
 * 'applicant_code' and year_key = the YYYYMM integer) rather than
 * SELECT...ORDER BY DESC LIMIT 1 + increment in PHP. That read-then-write
 * approach was a genuine TOCTOU race on this function's busiest caller,
 * public/register-applicant.php — the unauthenticated, high-concurrency
 * self-registration form used during a live job fair — where two
 * concurrent submissions could read the same "last code so far" and
 * compute the same next code, so one registration would fail outright
 * on the applicant_code UNIQUE constraint instead of both succeeding.
 */
function generate_applicant_code(PDO $pdo): string
{
    $yearMonth = (int)date('Ym');
    $prefix = 'APP-' . $yearMonth . '-';
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pdo->prepare(
                "INSERT INTO care_jf_id_sequences (sequence_name, year_key, last_value) VALUES ('applicant_code', :y, 1)
                 ON DUPLICATE KEY UPDATE last_value = last_value + 1"
            )->execute([':y' => $yearMonth]);

            $stmt = $pdo->prepare("SELECT last_value FROM care_jf_id_sequences WHERE sequence_name = 'applicant_code' AND year_key = :y");
            $stmt->execute([':y' => $yearMonth]);
            $next = (int)$stmt->fetchColumn();
            $candidate = $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_applicants WHERE applicant_code = :code");
            $existsStmt->execute([':code' => $candidate]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                if ($ownTransaction) { $pdo->commit(); }
                return $candidate;
            }

            $maxStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(applicant_code, '-', -1) AS UNSIGNED)), 0)
                 FROM care_jf_applicants WHERE applicant_code LIKE :pattern"
            );
            $maxStmt->execute([':pattern' => $prefix . '%']);
            $realMax = (int)$maxStmt->fetchColumn();
            $pdo->prepare(
                "UPDATE care_jf_id_sequences SET last_value = :v WHERE sequence_name = 'applicant_code' AND year_key = :y"
            )->execute([':v' => $realMax, ':y' => $yearMonth]);
        }
        throw new RuntimeException('Unable to generate a unique applicant code after multiple attempts.');
    } catch (Throwable $e) {
        if ($ownTransaction) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Generate the next Employer ID for a newly-activated Partner Agency,
 * in the format EMP-YYYY-NNNNNNN. Uses an atomic counter (never
 * COUNT(*) + 1) so concurrent activations can never collide.
 */
function generate_employer_id(PDO $pdo): string
{
    $year = date('Y');
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pdo->prepare(
                "INSERT INTO care_jf_id_sequences (sequence_name, year_key, last_value) VALUES ('employer_id', :y, 1)
                 ON DUPLICATE KEY UPDATE last_value = last_value + 1"
            )->execute([':y' => $year]);

            $stmt = $pdo->prepare("SELECT last_value FROM care_jf_id_sequences WHERE sequence_name = 'employer_id' AND year_key = :y");
            $stmt->execute([':y' => $year]);
            $next = (int)$stmt->fetchColumn();
            $candidate = 'EMP-' . $year . '-' . str_pad((string)$next, 7, '0', STR_PAD_LEFT);

            // Defense in depth: if this counter ever falls out of sync
            // with reality (its row lost/reset independently of the
            // agencies that already consumed IDs from it -- observed once
            // in real operation, root cause not fully traced), a
            // generated candidate could collide with one already
            // assigned. Detect that here, resync the counter to the true
            // max in-use number for this year, and retry, instead of
            // letting the caller's UPDATE fail on the UNIQUE constraint.
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_partner_agencies WHERE employer_id = :eid");
            $existsStmt->execute([':eid' => $candidate]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return $candidate;
            }

            $maxStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(employer_id, '-', -1) AS UNSIGNED)), 0)
                 FROM care_jf_partner_agencies WHERE employer_id LIKE :pattern"
            );
            $maxStmt->execute([':pattern' => "EMP-{$year}-%"]);
            $realMax = (int)$maxStmt->fetchColumn();
            $pdo->prepare(
                "UPDATE care_jf_id_sequences SET last_value = :v WHERE sequence_name = 'employer_id' AND year_key = :y"
            )->execute([':v' => $realMax, ':y' => $year]);
        }
        throw new RuntimeException('Unable to generate a unique Employer ID after multiple attempts.');
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
        "INSERT INTO care_jf_audit_logs (user_id, action, table_name, record_id, description, ip_address)
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

/**
 * Auto-closes every OTHER agency's still-open "For Review" tag on an
 * applicant once a real hire is recorded — regardless of which path
 * created it (Tag for Review's confirm_hired, the internal Employment
 * module, or Edit Applicant's employment sync). Must be called inside
 * the same transaction as the hire itself where one exists.
 */
function supersede_other_reviews(PDO $pdo, int $applicantId, int $winningRecordId, ?int $actorUserId): void
{
    $stmt = $pdo->prepare(
        "SELECT id FROM care_jf_employment_records WHERE applicant_id = :aid AND employment_status = 'For Review' AND id != :rid"
    );
    $stmt->execute([':aid' => $applicantId, ':rid' => $winningRecordId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $supersededId) {
        $pdo->prepare("UPDATE care_jf_employment_records SET employment_status = 'Superseded' WHERE id = :id")
            ->execute([':id' => $supersededId]);
        audit_log($pdo, $actorUserId, 'APPLICANT_REVIEW_SUPERSEDED', 'care_jf_employment_records', (int)$supersededId,
            'Superseded by another confirmed hire');
    }
}

/** Determine an applicant's current employment status label + badge color.
 * A row only counts as "current" if is_current=1 AND status='Active' —
 * a disabled employment record does not make the applicant "Hired".
 * Returns ['label' => string, 'color' => tailwind classes]
 */
function current_employment_status(PDO $pdo, int $applicantId): array
{
    $stmt = $pdo->prepare(
        "SELECT employment_status FROM care_jf_employment_records
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
        "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
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
            "SELECT id, agency_name, status FROM care_jf_partner_agencies
             WHERE status = 'Active' OR id = :id
             ORDER BY agency_name"
        );
        $stmt->execute([':id' => $includeId]);
    } else {
        $stmt = $pdo->query("SELECT id, agency_name, status FROM care_jf_partner_agencies WHERE status = 'Active' ORDER BY agency_name");
    }
    return $stmt->fetchAll();
}

/** Is this agency name already used by a different partner agency? Pass $excludeId when editing an existing one. */
function agency_name_taken(PDO $pdo, string $agencyName, ?int $excludeId = null): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_partner_agencies WHERE agency_name = :n AND id <> :id");
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

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

/** The logged-in Partner Agency's own name, for display contexts where
 * only the session-derived agency (never a request-supplied one) may be
 * shown — e.g. the Service Availment modal's header. Empty string if not
 * a Partner Agency session or the agency record is missing. */
function current_agency_name(PDO $pdo): string
{
    if (!is_partner_agency()) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $stmt->execute([':id' => current_agency_id($pdo)]);
    return (string)($stmt->fetchColumn() ?: '');
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
 * Deletes care_jf_audit_logs rows older than 1 year, so the table
 * doesn't grow unbounded. Exempts every 'DATABASE_RESET' row (Account
 * Settings' "Reset Records" truncates this table and immediately
 * writes exactly one such entry — see reset_application_records() in
 * includes/db_admin.php — so at most one exists at a time) from
 * age-based deletion, so an Administrator can always see when the
 * system was last reset no matter how long ago, even past the normal
 * 1-year retention window. This app has no cron/scheduled-task runner,
 * so it's called opportunistically from public/audit-logs.php on each
 * page view rather than on a schedule. Returns the number of rows
 * deleted.
 */
function purge_old_audit_logs(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "DELETE FROM care_jf_audit_logs WHERE created_at < (NOW() - INTERVAL 1 YEAR) AND action <> 'DATABASE_RESET'"
    );
    $stmt->execute();
    return $stmt->rowCount();
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

/**
 * Associates an applicant with a Partner Agency by inserting a 'For
 * Review' employment record, reusing the exact duplicate-prevention
 * and hired-applicant guard the manual "Tag for Review" flow already
 * used. $source is 'manual' (the existing button) or 'qr_scan' (QR
 * auto-tag) — it only changes which audit action string is recorded.
 * $sourceDetail further distinguishes a qr_scan's origin ('camera' or
 * 'manual' code entry) for the audit description; ignored when $source
 * isn't 'qr_scan'.
 *
 * NOTE: $agencyId is trusted as-is — this function does not verify it
 * belongs to the acting user. Callers for a Partner Agency user MUST
 * derive it via current_agency_id(), never from request input.
 */
function tag_applicant_for_agency(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source, string $sourceDetail = ''
): string {
    if (is_applicant_hired($pdo, $applicantId)) {
        return 'already_hired';
    }

    $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id AND status = 'Active'");
    $agStmt->execute([':id' => $agencyId]);
    $agencyRow = $agStmt->fetch();
    if (!$agencyRow) {
        return 'agency_invalid';
    }

    // No duplicate concurrent tags from the same agency — re-tagging is
    // fine once a prior tag has moved to Withdrawn/Superseded/Hired,
    // since none of those match this check.
    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND agency_id = :agid AND employment_status = 'For Review'"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        return 'duplicate';
    }

    $tagStmt = $pdo->prepare(
        "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
         VALUES (:aid, :agid, :agency, :address, NULL, 'For Review', 0, 'Active')"
    );
    $tagStmt->execute([
        ':aid' => $applicantId, ':agid' => $agencyId,
        ':agency' => $agencyRow['agency_name'], ':address' => $agencyRow['address'],
    ]);
    $newReviewId = (int)$pdo->lastInsertId();

    $actionCode = $source === 'qr_scan' ? 'APPLICANT_AUTO_TAGGED_QR' : 'APPLICANT_TAGGED_FOR_REVIEW';
    if ($source === 'qr_scan') {
        if ($sourceDetail === 'camera') {
            $verb = 'auto-tagged via QR camera scan by';
        } elseif ($sourceDetail === 'manual') {
            $verb = 'auto-tagged via confirmed manual code entry by';
        } else {
            $verb = 'auto-tagged via QR scan by';
        }
    } else {
        $verb = 'tagged For Review by';
    }
    audit_log($pdo, $actingUserId, $actionCode, 'care_jf_employment_records', $newReviewId,
        "Applicant {$applicantCode} {$verb} {$agencyRow['agency_name']}");

    return 'created';
}

/**
 * Associates a client (an applicant with service_agency_services=1) with
 * a Partner Agency by inserting a care_jf_service_availments row. Mirrors
 * tag_applicant_for_agency()'s agency-validity and duplicate checks, but
 * has no "already hired" gate — employment status has no bearing on
 * whether someone can avail a Partner Agency's service — and no Hired/
 * vacancy lifecycle to advance later.
 *
 * NOTE: $agencyId is trusted as-is — this function does not verify it
 * belongs to the acting user. Callers for a Partner Agency user MUST
 * derive it via current_agency_id(), never from request input.
 */
function tag_applicant_for_service(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source, string $sourceDetail = ''
): string {
    $agStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id AND status = 'Active'");
    $agStmt->execute([':id' => $agencyId]);
    $agencyRow = $agStmt->fetch();
    if (!$agencyRow) {
        return 'agency_invalid';
    }

    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_service_availments WHERE applicant_id = :id AND agency_id = :agid"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        // Not an error in Phase 2: re-scanning an already-tagged Client
        // reopens the service-selection modal against their existing row
        // (see set_service_availment_selection() below) instead of being
        // rejected — this row already exists, nothing new to insert.
        return 'reused';
    }

    $tagStmt = $pdo->prepare(
        "INSERT INTO care_jf_service_availments (applicant_id, agency_id, source, status)
         VALUES (:aid, :agid, :source, 'Active')"
    );
    $tagStmt->execute([':aid' => $applicantId, ':agid' => $agencyId, ':source' => $source]);
    $newId = (int)$pdo->lastInsertId();

    $actionCode = $source === 'qr_scan' ? 'SERVICE_AVAILED_AUTO_TAGGED_QR' : 'SERVICE_AVAILED_TAGGED';
    if ($source === 'qr_scan') {
        if ($sourceDetail === 'camera') {
            $verb = 'auto-tagged (service availed) via QR camera scan by';
        } elseif ($sourceDetail === 'manual') {
            $verb = 'auto-tagged (service availed) via confirmed manual code entry by';
        } else {
            $verb = 'auto-tagged (service availed) via QR scan by';
        }
    } else {
        $verb = 'tagged (service availed) by';
    }
    audit_log($pdo, $actingUserId, $actionCode, 'care_jf_service_availments', $newId,
        "Applicant {$applicantCode} {$verb} {$agencyRow['agency_name']}");

    return 'created';
}

/**
 * Sets which specific service (from the agency's own catalog, or free
 * text when "OTHERS" was chosen) a Client's service-availment row
 * represents. Called only after tag_applicant_for_service() has already
 * created-or-reused that row (Step 1) — this is purely Step 2, an UPDATE,
 * never an INSERT. Shared by the async QR/manual-code scanner flow
 * (api/service-availment-confirm.php) and the same-page "Tag for
 * Service" form on applicant-view.php, so both use identical logic.
 *
 * Exactly one of $serviceId/$customServiceName should be non-null —
 * callers validate this themselves before calling (the caller knows
 * whether "OTHERS" was chosen); this function's own job is to re-verify
 * $serviceId's agency ownership, never to infer caller intent.
 *
 * NOTE: $agencyId is trusted as-is — callers for a Partner Agency user
 * MUST derive it via current_agency_id(), never from request input.
 */
function set_service_availment_selection(
    PDO $pdo, int $applicantId, int $agencyId,
    ?int $serviceId, ?string $customServiceName, int $actingUserId
): string {
    $resolvedName = $customServiceName;
    if ($serviceId !== null) {
        $svcStmt = $pdo->prepare(
            "SELECT service_name FROM care_jf_agency_services WHERE id = :id AND agency_id = :agid AND status = 'Active'"
        );
        $svcStmt->execute([':id' => $serviceId, ':agid' => $agencyId]);
        $resolvedName = $svcStmt->fetchColumn();
        if ($resolvedName === false) {
            return 'service_invalid';
        }
    }

    $updStmt = $pdo->prepare(
        "UPDATE care_jf_service_availments SET service_id = :sid, custom_service_name = :csn
         WHERE applicant_id = :aid AND agency_id = :agid"
    );
    $updStmt->execute([
        ':sid' => $serviceId, ':csn' => $customServiceName,
        ':aid' => $applicantId, ':agid' => $agencyId,
    ]);
    if ($updStmt->rowCount() === 0) {
        return 'not_found';
    }

    $availmentIdStmt = $pdo->prepare(
        "SELECT id FROM care_jf_service_availments WHERE applicant_id = :aid AND agency_id = :agid"
    );
    $availmentIdStmt->execute([':aid' => $applicantId, ':agid' => $agencyId]);
    $availmentId = (int)$availmentIdStmt->fetchColumn();

    $codeStmt = $pdo->prepare("SELECT applicant_code FROM care_jf_applicants WHERE id = :id");
    $codeStmt->execute([':id' => $applicantId]);
    $applicantCode = (string)$codeStmt->fetchColumn();

    audit_log($pdo, $actingUserId, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', $availmentId,
        "Applicant {$applicantCode}'s availed service set to \"{$resolvedName}\"");

    return 'updated';
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

/** Active care_jf_agency_services rows for one agency (or empty if
 * $agencyId is null — the staff/no-agency-picked case). */
function active_agency_services(PDO $pdo, ?int $agencyId): array
{
    if (!$agencyId) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT id, service_name FROM care_jf_agency_services WHERE agency_id = :agid AND status = 'Active' ORDER BY service_name"
    );
    $stmt->execute([':agid' => $agencyId]);
    return $stmt->fetchAll();
}

/** Is this agency name already used by a different partner agency? Pass $excludeId when editing an existing one. */
function agency_name_taken(PDO $pdo, string $agencyName, ?int $excludeId = null): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_partner_agencies WHERE agency_name = :n AND id <> :id");
    $stmt->execute([':n' => $agencyName, ':id' => $excludeId ?? 0]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Canonical status-badge color classes, shared across every table's
 * simple Active/Disabled/Pending-style status pill so the same meaning
 * always renders the same color app-wide (before this, each page had
 * its own slightly different green/gray shade picked independently).
 * Pass the semantic MEANING of the status, not the literal status
 * string — e.g. 'Active' -> 'success', 'Pending' -> 'warning',
 * 'Disabled'/'Withdrawn' -> 'neutral'. Categorical (non-binary) badges
 * that distinguish several distinct values from each other — employment
 * classification (Job Order/COS/Temporary/...), Job Vacancy status
 * (Active/Filled/Closed) — intentionally keep their own multi-color
 * mapping instead of collapsing onto this 4-tone scale, since they're
 * telling values apart rather than signaling good/bad/waiting.
 */
function badge_class(string $tone): string
{
    return match ($tone) {
        'success' => 'bg-green-100 text-green-800',
        'warning' => 'bg-amber-100 text-amber-800',
        'info'    => 'bg-blue-100 text-blue-800',
        'danger'  => 'bg-red-100 text-red-700',
        default   => 'bg-gray-100 text-gray-600', // 'neutral'
    };
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

/**
 * Shared WHERE builder for the Registered Applicants list query — used
 * by both api/applicants.php (paginated JSON for the live table) and
 * api/applicants-export.php (.xlsx export of the full filtered set), so
 * the two can never drift out of sync with each other. $filters keys
 * (all optional, already clean()ed by the caller): search, sex,
 * civil_status, service, service_availed, date_from, date_to. Enforces
 * the same Partner Agency restriction as before (an applicant hired by
 * a DIFFERENT agency is excluded) — the query this feeds must alias the
 * applicants table "a" and LEFT JOIN care_jf_employment_records as "er"
 * (er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active')
 * so :my_agency_id resolves correctly (that join is also still needed
 * for the table/export's own Employment Status *column*, which is
 * display-only now — there is no Employment Status filter). Returns
 * ['where' => sql, 'params' => bind params].
 */
function build_applicant_filters(PDO $pdo, array $filters): array
{
    $where = ['a.is_deleted = 0'];
    $params = [];

    $search = $filters['search'] ?? '';
    if ($search !== '') {
        $where[] = "(a.applicant_code LIKE :search1 OR a.contact_number LIKE :search2
                     OR CONCAT(a.last_name,' ',a.first_name,' ',IFNULL(a.middle_name,'')) LIKE :search3)";
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
        $params[':search3'] = '%' . $search . '%';
    }
    $sex = $filters['sex'] ?? '';
    if ($sex !== '' && $sex !== 'All') {
        $where[] = 'a.sex = :sex';
        $params[':sex'] = $sex;
    }
    $civilStatus = $filters['civil_status'] ?? '';
    if ($civilStatus !== '' && $civilStatus !== 'All') {
        $where[] = 'a.civil_status = :civil_status';
        $params[':civil_status'] = $civilStatus;
    }
    $dateFrom = $filters['date_from'] ?? '';
    if ($dateFrom !== '') {
        $where[] = 'DATE(a.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    $dateTo = $filters['date_to'] ?? '';
    if ($dateTo !== '') {
        $where[] = 'DATE(a.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    $myAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;
    if ($myAgencyId !== null) {
        // Once hired, an applicant disappears from every OTHER agency's
        // pool — but the hiring agency keeps seeing their own hire.
        // Not hired at all (er.id IS NULL) is always visible; hired by
        // this same agency is always visible; hired by anyone else is
        // excluded.
        $where[] = '(er.id IS NULL OR er.agency_id = :my_agency_id)';
        $params[':my_agency_id'] = $myAgencyId;
    }
    $service = $filters['service'] ?? '';
    if ($service === 'job_seeker') {
        $where[] = 'a.service_job_seeker = 1';
    } elseif ($service === 'agency_services') {
        $where[] = 'a.service_agency_services = 1';
    }
    // The Service Availed filter (distinct from $service above, which is
    // clients.php's own page-scoping param): a three-way, mutually
    // exclusive split of the same service_job_seeker / service_agency_services
    // registration flags on care_jf_applicants.
    $serviceAvailed = $filters['service_availed'] ?? '';
    if ($serviceAvailed === 'job_seeker') {
        $where[] = 'a.service_job_seeker = 1 AND a.service_agency_services = 0';
    } elseif ($serviceAvailed === 'agency_services') {
        $where[] = 'a.service_job_seeker = 0 AND a.service_agency_services = 1';
    } elseif ($serviceAvailed === 'both') {
        $where[] = 'a.service_job_seeker = 1 AND a.service_agency_services = 1';
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}

/** Map one raw Applicant+current-employment SQL row (see
 * build_applicant_filters()'s expected joins) into the shape the
 * Applicant list's table and .xlsx export both use. */
function map_applicant_row(array $row): array
{
    $status = $row['current_status'] ?: 'For Further Review';
    $services = [];
    if ($row['service_job_seeker']) $services[] = 'Job Seeker';
    if ($row['service_agency_services']) $services[] = 'Avail Agency Services';
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
}

/**
 * Shared WHERE builder for a Partner Agency's own Clients list query —
 * used by both api/agency-clients.php (paginated JSON) and
 * api/agency-clients-export.php (.xlsx export of the full filtered set).
 * Always scoped to $agencyId (pass current_agency_id()'s result — never
 * a caller-supplied id) via care_jf_service_availments, so an agency can
 * never see another agency's clients. $filters keys: search,
 * service_availed ('agency_services' | 'both' | '' for no extra
 * narrowing — 'job_seeker' isn't offered here since every row already
 * has an agency-services availment record). Returns ['where' => sql,
 * 'params' => bind params].
 */
function build_agency_client_filters(int $agencyId, array $filters): array
{
    $where = ['sa.agency_id = :agid'];
    $params = [':agid' => $agencyId];

    $search = $filters['search'] ?? '';
    if ($search !== '') {
        $where[] = "(a.applicant_code LIKE :search1 OR CONCAT(a.last_name,' ',a.first_name,' ',IFNULL(a.middle_name,'')) LIKE :search2)";
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    $serviceAvailed = $filters['service_availed'] ?? '';
    if ($serviceAvailed === 'agency_services') {
        $where[] = 'a.service_job_seeker = 0 AND a.service_agency_services = 1';
    } elseif ($serviceAvailed === 'both') {
        $where[] = 'a.service_job_seeker = 1 AND a.service_agency_services = 1';
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}

/** Map one raw Partner-Agency-Clients SQL row (see
 * build_agency_client_filters()'s expected joins) into the shape the
 * Clients list's table and .xlsx export both use. */
function map_agency_client_row(array $row): array
{
    $services = [];
    if ($row['service_job_seeker']) $services[] = 'Job Seeker';
    if ($row['service_agency_services']) $services[] = 'Avail Agency Services';
    return [
        'id'                  => (int)$row['id'],
        'applicant_code'      => $row['applicant_code'],
        'full_name'           => full_name($row),
        'sex'                 => $row['sex'],
        'services_registered' => $services,
        'service_availed'     => $row['service_availed'] ?: '—',
        'status'              => $row['availment_status'] === 'Active' ? 'AVAILING SERVICES' : 'WITHDRAWN',
        'date_availed'        => format_date($row['date_availed']),
    ];
}

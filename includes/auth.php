<?php
/**
 * Authentication & authorization — includes/auth.php
 * Include this at the very top of every protected page (before any output).
 */

declare(strict_types=1);

// --- Secure session configuration (must run before session_start) ---
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Uncomment when serving over HTTPS in production:
    // ini_set('session.cookie_secure', '1');
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes
const REAUTH_WINDOW = 900; // 15 minutes — how long a password re-confirmation stays valid

/**
 * Step-up authentication: has the current session already re-confirmed its
 * password for this sensitive scope (e.g. "users") recently?
 */
function has_valid_reauth(string $scope): bool
{
    $key = 'reauth_' . $scope . '_expires';
    return !empty($_SESSION[$key]) && $_SESSION[$key] > time();
}

/** Mark the given scope as re-confirmed for REAUTH_WINDOW seconds. */
function grant_reauth(string $scope): void
{
    $_SESSION['reauth_' . $scope . '_expires'] = time() + REAUTH_WINDOW;
}

/** Clear a scope's re-auth (e.g. on logout, or to force re-confirmation). */
function clear_reauth(string $scope): void
{
    unset($_SESSION['reauth_' . $scope . '_expires']);
}

/** Is a user currently logged in (and session not expired)? */
function is_logged_in(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }

    // The session's user must still exist and be Active — an account
    // deleted or disabled after this session was created (e.g. by an
    // Administrator, or by a data reset) must lose access immediately
    // rather than linger until the idle timeout, and must never reach
    // an audit_log() call whose user_id foreign key now points at
    // nothing (care_jf_audit_logs.user_id references care_jf_users.id
    // — see do_logout()'s own defensive check for the same reason).
    $stmt = Database::getConnection()->prepare("SELECT status FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== 'Active') {
        session_unset();
        session_destroy();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

/** Force login; call at the top of every protected page. */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Restrict a page to one or more roles. Call after require_login(). */
function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this page.</h2>');
    }
}

function current_user(): array
{
    return [
        'id'        => $_SESSION['user_id'] ?? null,
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role'] ?? 'Viewer',
    ];
}

function can_edit(): bool
{
    return in_array(current_user()['role'], ['Administrator', 'Employee'], true);
}

/** Full delete rights (applicants, employment, partner agencies, users). Administrator only. */
function can_delete(): bool
{
    return current_user()['role'] === 'Administrator';
}

/** Employee + Administrator can add/edit/enable/disable employment records; only Administrator can permanently delete one. */
function can_manage_employment(): bool
{
    return in_array(current_user()['role'], ['Administrator', 'Employee'], true);
}

/** Employee + Administrator can add/edit/enable/disable agencies; only Administrator can permanently delete one. */
function can_manage_agency(): bool
{
    return in_array(current_user()['role'], ['Administrator', 'Employee'], true);
}

function can_manage_users(): bool
{
    return current_user()['role'] === 'Administrator';
}

function can_view_audit_logs(): bool
{
    return current_user()['role'] === 'Administrator';
}

/** Is the current session's role Partner Agency? */
function is_partner_agency(): bool
{
    return current_user()['role'] === 'Partner Agency';
}

/**
 * The authenticated user's agency_id, always re-read from the database
 * by the trusted session user_id — never cached in $_SESSION and never
 * taken from a request parameter. This is the only source of truth for
 * "which agency does this request belong to."
 */
function current_agency_id(PDO $pdo): ?int
{
    $userId = current_user()['id'];
    if (!$userId) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $agencyId = $stmt->fetchColumn();
    return $agencyId !== null && $agencyId !== false ? (int)$agencyId : null;
}

/**
 * 403s unless $recordAgencyId belongs to the current session's own
 * agency. Call this before any Partner Agency read/write that targets a
 * specific record by id, to close the IDOR gap (a Partner Agency user
 * changing a URL/POST id to target another agency's data).
 */
function require_own_agency_record(PDO $pdo, ?int $recordAgencyId): void
{
    if ($recordAgencyId === null || $recordAgencyId !== current_agency_id($pdo)) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this record.</h2>');
    }
}

/** Set a user's account status, keeping the legacy is_active column in sync. */
function set_user_status(PDO $pdo, int $userId, string $status): void
{
    $pdo->prepare("UPDATE care_jf_users SET status = :s, is_active = :a WHERE id = :id")
        ->execute([':s' => $status, ':a' => $status === 'Active' ? 1 : 0, ':id' => $userId]);
}

/** Safeguard: prevent removing/disabling/demoting the last remaining active Administrator. */
function is_last_active_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT role, is_active FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch();
    if (!$target || $target['role'] !== 'Administrator' || !$target['is_active']) {
        return false;
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM care_jf_users WHERE role = 'Administrator' AND is_active = 1")->fetchColumn();
    return $count <= 1;
}

/**
 * Attempt to authenticate a user. Returns true on success.
 * Applies a simple rate limit via session to slow brute-force attempts.
 */
function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
    $_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

    if (time() < $_SESSION['login_locked_until']) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT u.*, pa.status AS agency_status
         FROM care_jf_users u
         LEFT JOIN care_jf_partner_agencies pa ON pa.id = u.agency_id
         WHERE u.username = :u LIMIT 1"
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // Agency-based access control: a Partner Agency account additionally
    // needs its agency record to be Active — an admin can lock out an
    // entire agency by disabling the agency record, independent of the
    // individual account's own status.
    $accountUsable = $user
        && $user['status'] === 'Active'
        && ($user['role'] !== 'Partner Agency' || $user['agency_status'] === 'Active');

    if ($accountUsable && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_attempts'] = 0;

        audit_log($pdo, $user['id'], 'LOGIN', 'care_jf_users', $user['id'], 'User logged in');
        return true;
    }

    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60; // 60s lockout after 5 failed attempts
        $_SESSION['login_attempts'] = 0;
    }

    return false;
}

function do_logout(PDO $pdo): void
{
    $user = current_user();
    if ($user['id']) {
        // Defense in depth alongside is_logged_in()'s own existence
        // check: logging out must never fail just because the audit
        // trail couldn't record who did it (care_jf_audit_logs.user_id
        // has a foreign key to care_jf_users.id — inserting against a
        // since-deleted id throws, and a user must always be able to
        // leave regardless).
        try {
            audit_log($pdo, (int)$user['id'], 'LOGOUT', 'care_jf_users', (int)$user['id'], 'User logged out');
        } catch (Throwable $e) {
            error_log('Logout audit_log failed (non-fatal): ' . $e->getMessage());
        }
    }
    $_SESSION = [];
    session_destroy();
}

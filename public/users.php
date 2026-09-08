<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator']);

$pdo = Database::getConnection();
$errors = [];
$currentUserId = (int)current_user()['id'];

// ---------------------------------------------------------------------
// Step-up authentication: before showing anything on this page (or acting
// on any POST to it), require the currently logged-in administrator to
// re-confirm their own password. This must run before ALL other POST
// handling below — a password check that only gated the initial page view
// could be bypassed by posting straight to action=create/edit/delete/etc.
// ---------------------------------------------------------------------
$reauthScope = 'users';
$reauthError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reauth_confirm') {
    csrf_require();
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare("SELECT password FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => $currentUserId]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password'])) {
        grant_reauth($reauthScope);
        audit_log($pdo, $currentUserId, 'REAUTH', 'care_jf_users', $currentUserId, 'Confirmed password to access User Management');
        redirect('users.php');
    } else {
        $reauthError = 'Incorrect password. Please try again.';
    }
}

if (!has_valid_reauth($reauthScope)) {
    $pageTitle = 'Confirm Access';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <div class="max-w-sm mx-auto mt-10 sm:mt-16">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8 text-center">
        <i class="fa-solid fa-shield-halved text-3xl text-brand-600 mb-3"></i>
        <h1 class="text-lg font-bold text-slate-800 mb-1">Confirm Your Password</h1>
        <p class="text-sm text-slate-500 mb-5">For security, please re-enter your password to access User Management.</p>

        <?php if ($reauthError): ?>
          <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2.5 text-left">
            <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
            <span><?= e($reauthError) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reauth_confirm">
          <input type="password" name="password" required autofocus placeholder="Your current password"
                 class="w-full px-3 py-2.5 rounded-lg border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 outline-none text-sm mb-4">
          <button type="submit" class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm text-sm">
            <i class="fa-solid fa-unlock mr-1"></i> Confirm
          </button>
        </form>
        <a href="dashboard.php" class="inline-block mt-4 text-xs text-slate-500 hover:text-slate-700">Cancel and go back</a>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Field order per spec: Full Name, Username, Temporary Password, Role
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = clean($_POST['role'] ?? '');

        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($username === '') $errors['username'] = 'Username is required.';
        if (!in_array($role, ['Administrator','Employee','Viewer'], true)) $errors['role'] = 'Select a valid role.';
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';

        if (!$errors) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM care_jf_users WHERE username = :u");
            $dup->execute([':u' => $username]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("INSERT INTO care_jf_users (username, password, full_name, role) VALUES (:u, :p, :f, :r)");
            $stmt->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':f' => $fullName, ':r' => $role]);
            audit_log($pdo, $currentUserId, 'CREATE', 'care_jf_users', (int)$pdo->lastInsertId(), "Created user $username ($role)");
            flash_set('success', 'User account created successfully.');
            redirect('users.php');
        }
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $role = clean($_POST['role'] ?? '');

        if ($fullName === '') $errors['edit_full_name'] = 'Full name is required.';
        if (!in_array($role, ['Administrator','Employee','Viewer'], true)) $errors['edit_role'] = 'Select a valid role.';

        // Safeguard: don't let the last active Administrator demote themselves
        if (!$errors && $role !== 'Administrator' && is_last_active_admin($pdo, $userId)) {
            $errors['edit_role'] = 'This is the only active Administrator account — change another account to Administrator first.';
        }

        if (!$errors) {
            $pdo->prepare("UPDATE care_jf_users SET full_name = :f, role = :r WHERE id = :id")
                ->execute([':f' => $fullName, ':r' => $role, ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_users', $userId, "Updated user #$userId (role: $role)");
            flash_set('success', 'User updated successfully.');
        } else {
            flash_set('error', reset($errors));
        }
        redirect('users.php');
    } elseif ($action === 'toggle') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot disable your own account.');
        } elseif (is_last_active_admin($pdo, $userId)) {
            flash_set('error', 'Cannot disable the only active Administrator account.');
        } else {
            $statusStmt = $pdo->prepare("SELECT status FROM care_jf_users WHERE id = :id");
            $statusStmt->execute([':id' => $userId]);
            $currentStatus = $statusStmt->fetchColumn();
            set_user_status($pdo, $userId, $currentStatus === 'Active' ? 'Disabled' : 'Active');
            audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_users', $userId, 'Toggled user active status');
            flash_set('success', 'User status updated.');
        }
        redirect('users.php');
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot delete your own account.');
        } elseif (is_last_active_admin($pdo, $userId)) {
            flash_set('error', 'Cannot delete the only active Administrator account.');
        } else {
            $pdo->prepare("DELETE FROM care_jf_users WHERE id = :id")->execute([':id' => $userId]);
            audit_log($pdo, $currentUserId, 'DELETE', 'care_jf_users', $userId, 'User deleted');
            flash_set('success', 'User deleted.');
        }
        redirect('users.php');
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'Temporary password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE care_jf_users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_users', $userId, 'Password reset by administrator');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('users.php');
    } elseif ($action === 'activate_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $stmt->execute([':id' => $userId]);
        $agencyId = $stmt->fetchColumn();
        if (!$agencyId) {
            flash_set('error', 'Partner Agency account not found.');
        } else {
            $pdo->beginTransaction();
            try {
                set_user_status($pdo, $userId, 'Active');

                // Employer ID is assigned exactly once, ever — never
                // regenerated on a later re-activation.
                $empStmt = $pdo->prepare("SELECT employer_id FROM care_jf_partner_agencies WHERE id = :id");
                $empStmt->execute([':id' => $agencyId]);
                if (!$empStmt->fetchColumn()) {
                    $employerId = generate_employer_id($pdo);
                    $pdo->prepare("UPDATE care_jf_partner_agencies SET employer_id = :eid WHERE id = :id")
                        ->execute([':eid' => $employerId, ':id' => $agencyId]);
                    audit_log($pdo, $currentUserId, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', $agencyId, "Employer ID {$employerId} generated");
                }

                audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', $userId, 'Partner Agency account activated');
                $pdo->commit();
                flash_set('success', 'Partner Agency account activated.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Partner Agency activation failed: ' . $e->getMessage());
                flash_set('error', 'Activation failed due to a system error. Please try again.');
            }
        }
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'disable_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $stmt->execute([':id' => $userId]);
        if (!$stmt->fetchColumn()) {
            flash_set('error', 'Partner Agency account not found.');
        } else {
            set_user_status($pdo, $userId, 'Disabled');
            audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_DISABLE', 'care_jf_users', $userId, 'Partner Agency account disabled');
            flash_set('success', 'Partner Agency account disabled.');
        }
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'reenable_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $stmt->execute([':id' => $userId]);
        if (!$stmt->fetchColumn()) {
            flash_set('error', 'Partner Agency account not found.');
        } else {
            set_user_status($pdo, $userId, 'Active');
            audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_REENABLE', 'care_jf_users', $userId, 'Partner Agency account re-enabled');
            flash_set('success', 'Partner Agency account re-enabled.');
        }
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'delete_agency_account') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $stmt->execute([':id' => $userId]);
        $agencyId = $stmt->fetchColumn();
        if (!$agencyId) {
            flash_set('error', 'Partner Agency account not found.');
        } else {
            // Safety pre-check: deleting this account must not permanently
            // strand the agency. Block if it's the agency's only account
            // (agency name would already be taken, so it could never
            // re-register, and there's no admin UI to create a replacement
            // Partner Agency account) or if the agency has employment
            // history — in both cases, Disable instead of Delete.
            $onlyAccountStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_users WHERE agency_id = :aid");
            $onlyAccountStmt->execute([':aid' => $agencyId]);
            $historyStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_employment_records WHERE agency_id = :aid");
            $historyStmt->execute([':aid' => $agencyId]);

            if ((int)$onlyAccountStmt->fetchColumn() <= 1) {
                flash_set('error', 'This is the only account for this agency — deleting it would strand the agency with no way to log in or re-register. Disable the account instead.');
            } elseif ((int)$historyStmt->fetchColumn() > 0) {
                flash_set('error', 'This agency has employment history linked to it — the account cannot be deleted. Disable it instead.');
            } else {
                $pdo->prepare("DELETE FROM care_jf_users WHERE id = :id")->execute([':id' => $userId]);
                audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_ACCOUNT_DELETE', 'care_jf_users', $userId, 'Partner Agency account deleted');
                flash_set('success', 'Partner Agency account deleted.');
            }
        }
        redirect('users.php?tab=partner-agencies');
    }
}

$users = $pdo->query("SELECT * FROM care_jf_users WHERE role <> 'Partner Agency' ORDER BY created_at DESC")->fetchAll();
$agencyAccounts = $pdo->query(
    "SELECT u.id, u.username, u.status AS account_status, u.created_at,
            pa.agency_name, pa.contact_person, pa.contact_no, pa.email, pa.status AS agency_status, pa.employer_id
     FROM care_jf_users u
     JOIN care_jf_partner_agencies pa ON pa.id = u.agency_id
     WHERE u.role = 'Partner Agency'
     ORDER BY u.created_at DESC"
)->fetchAll();
$initialTab = ($_GET['tab'] ?? '') === 'partner-agencies' ? 'partner-agencies' : 'users';

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6" x-data="{ tab: '<?= $initialTab ?>', showCreate: false, editingId: null, resettingId: null, resettingAgencyId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Manage Users</h1>
      <p class="text-sm text-slate-500">Manage login accounts, role-based permissions, and Partner Agency approvals.</p>
    </div>
    <button x-show="tab === 'users'" @click="showCreate = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> New User
    </button>
  </div>

  <div class="flex gap-2 border-b border-slate-200">
    <button @click="tab = 'users'" :class="tab==='users' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">
      System Users
    </button>
    <button @click="tab = 'partner-agencies'" :class="tab==='partner-agencies' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">
      Partner Agency Accounts
      <?php $pendingCount = count(array_filter($agencyAccounts, fn($a) => $a['account_status'] === 'Pending')); ?>
      <?php if ($pendingCount > 0): ?>
        <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800"><?= $pendingCount ?></span>
      <?php endif; ?>
    </button>
  </div>

  <div x-show="tab === 'partner-agencies'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">Agency Name</th>
          <th class="px-4 py-2.5 text-left">Employer ID</th>
          <th class="px-4 py-2.5 text-left">Contact Person</th>
          <th class="px-4 py-2.5 text-left">Contact No</th>
          <th class="px-4 py-2.5 text-left">Email</th>
          <th class="px-4 py-2.5 text-left">Username</th>
          <th class="px-4 py-2.5 text-left">Registered</th>
          <th class="px-4 py-2.5 text-left">Account Status</th>
          <th class="px-4 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$agencyAccounts): ?>
          <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-building text-2xl mb-2 block"></i> No Partner Agency registrations yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($agencyAccounts as $a):
          $statusColors = ['Pending' => 'bg-amber-100 text-amber-800', 'Active' => 'bg-green-100 text-green-700', 'Disabled' => 'bg-gray-100 text-gray-600'];
        ?>
        <tr>
          <td class="px-4 py-2.5 font-medium" data-label="Agency"><?= e($a['agency_name']) ?></td>
          <td class="px-4 py-2.5 font-mono text-xs" data-label="Employer ID"><?= e($a['employer_id'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Contact Person"><?= e($a['contact_person'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Contact No"><?= e($a['contact_no'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Email"><?= e($a['email'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Username"><?= e($a['username']) ?></td>
          <td class="px-4 py-2.5 text-slate-500" data-label="Registered"><?= format_date($a['created_at']) ?></td>
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$a['account_status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= e($a['account_status']) ?></span>
            <?php if ($a['agency_status'] === 'Disabled'): ?>
              <span class="block text-[10px] text-red-500 mt-0.5">Agency record disabled</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2.5 text-right" data-label="Actions">
            <?php if ($a['account_status'] === 'Pending'): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-medium">Activate</button>
              </form>
            <?php elseif ($a['account_status'] === 'Active'): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disable_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg border border-slate-300 hover:bg-slate-50 text-xs font-medium">Disable</button>
              </form>
            <?php else: ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reenable_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium">Re-enable</button>
              </form>
            <?php endif; ?>
            <button @click="resettingAgencyId = resettingAgencyId === <?= (int)$a['id'] ?> ? null : <?= (int)$a['id'] ?>" class="text-slate-500 hover:text-purple-600 px-1.5" title="Reset Password"><i class="fa-solid fa-key"></i></button>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_agency_account">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <button type="button" data-confirm-delete="<?= e($a['agency_name'] . ' (' . $a['username'] . ')') ?>" class="text-slate-500 hover:text-red-600 px-1.5" title="Delete Account"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <!-- Inline reset password row -->
        <tr x-show="resettingAgencyId === <?= (int)$a['id'] ?>" x-cloak>
          <td colspan="9" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">New Temporary Password</label>
                <input type="password" name="new_password" required minlength="8" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Reset Password</button>
              <button type="button" @click="resettingAgencyId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div x-show="tab === 'users'" x-cloak>

  <!-- Create form: real modal, does not close on outside click. Field order = Full Name, Username, Temporary Password, Role -->
  <div x-show="showCreate" x-cloak
       class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"
       @keydown.escape.window="showCreate = false">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">New User</h2>
        <button type="button" @click="showCreate = false" class="text-slate-400 hover:text-slate-600" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form method="POST" onsubmit="return validateForm(this);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" required class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['full_name']) ? 'hidden' : '' ?>" data-error-for="full_name"><?= e($errors['full_name'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['username']) ? 'hidden' : '' ?>" data-error-for="username"><?= e($errors['username'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Temporary Password <span class="text-red-500">*</span></label>
            <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['password']) ? 'hidden' : '' ?>" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
            <select name="role" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="Administrator">Administrator</option>
              <option value="Employee">Employee</option>
              <option value="Viewer">Viewer</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="showCreate = false" class="px-5 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Create User</button>
        </div>
      </form>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">Full Name</th>
          <th class="px-4 py-2.5 text-left">Username</th>
          <th class="px-4 py-2.5 text-left">Role</th>
          <th class="px-4 py-2.5 text-left">Status</th>
          <th class="px-4 py-2.5 text-left">Created</th>
          <th class="px-4 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($users as $u): $isSelf = (int)$u['id'] === $currentUserId; ?>
        <tr>
          <td class="px-4 py-2.5 font-medium" data-label="Name"><?= e($u['full_name']) ?><?= $isSelf ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></td>
          <td class="px-4 py-2.5" data-label="Username"><?= e($u['username']) ?></td>
          <td class="px-4 py-2.5" data-label="Role"><?= e($u['role']) ?></td>
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['status'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= e($u['status']) ?>
            </span>
          </td>
          <td class="px-4 py-2.5 text-slate-500" data-label="Created"><?= format_date($u['created_at']) ?></td>
          <td class="px-4 py-2.5 text-right" data-label="Actions">
            <button @click="editingId = editingId === <?= (int)$u['id'] ?> ? null : <?= (int)$u['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <button @click="resettingId = resettingId === <?= (int)$u['id'] ?> ? null : <?= (int)$u['id'] ?>" class="text-slate-500 hover:text-purple-600 px-1" title="Reset Password"><i class="fa-solid fa-key"></i></button>
            <?php if (!$isSelf): ?>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $u['is_active'] ? 'Disable' : 'Enable' ?>">
                <i class="fa-solid <?= $u['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
              </button>
            </form>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="button" data-confirm-delete="<?= e($u['full_name']) ?>" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php else: ?>
              <span class="text-xs text-slate-300 px-1">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <!-- Inline edit row -->
        <tr x-show="editingId === <?= (int)$u['id'] ?>" x-cloak>
          <td colspan="6" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Full Name</label>
                <input type="text" name="full_name" required value="<?= e($u['full_name']) ?>" class="uppercase-field uppercase rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Role</label>
                <select name="role" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                  <option value="Administrator" <?= $u['role']==='Administrator'?'selected':'' ?>>Administrator</option>
                  <option value="Employee" <?= $u['role']==='Employee'?'selected':'' ?>>Employee</option>
                  <option value="Viewer" <?= $u['role']==='Viewer'?'selected':'' ?>>Viewer</option>
                </select>
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Save</button>
              <button type="button" @click="editingId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <!-- Inline reset password row -->
        <tr x-show="resettingId === <?= (int)$u['id'] ?>" x-cloak>
          <td colspan="6" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">New Temporary Password</label>
                <input type="password" name="new_password" required minlength="8" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Reset Password</button>
              <button type="button" @click="resettingId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

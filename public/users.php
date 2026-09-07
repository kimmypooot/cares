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
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $currentUserId]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password'])) {
        grant_reauth($reauthScope);
        audit_log($pdo, $currentUserId, 'REAUTH', 'users', $currentUserId, 'Confirmed password to access User Management');
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
        $fullName = clean($_POST['full_name'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = clean($_POST['role'] ?? '');

        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($username === '') $errors['username'] = 'Username is required.';
        if (!in_array($role, ['Administrator','Employee','Viewer'], true)) $errors['role'] = 'Select a valid role.';
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';

        if (!$errors) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $dup->execute([':u' => $username]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (:u, :p, :f, :r)");
            $stmt->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':f' => $fullName, ':r' => $role]);
            audit_log($pdo, $currentUserId, 'CREATE', 'users', (int)$pdo->lastInsertId(), "Created user $username ($role)");
            flash_set('success', 'User account created successfully.');
            redirect('users.php');
        }
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = clean($_POST['full_name'] ?? '');
        $role = clean($_POST['role'] ?? '');

        if ($fullName === '') $errors['edit_full_name'] = 'Full name is required.';
        if (!in_array($role, ['Administrator','Employee','Viewer'], true)) $errors['edit_role'] = 'Select a valid role.';

        // Safeguard: don't let the last active Administrator demote themselves
        if (!$errors && $role !== 'Administrator' && is_last_active_admin($pdo, $userId)) {
            $errors['edit_role'] = 'This is the only active Administrator account — change another account to Administrator first.';
        }

        if (!$errors) {
            $pdo->prepare("UPDATE users SET full_name = :f, role = :r WHERE id = :id")
                ->execute([':f' => $fullName, ':r' => $role, ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, "Updated user #$userId (role: $role)");
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
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = :id")->execute([':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Toggled user active status');
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
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
            audit_log($pdo, $currentUserId, 'DELETE', 'users', $userId, 'User deleted');
            flash_set('success', 'User deleted.');
        }
        redirect('users.php');
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'Temporary password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Password reset by administrator');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('users.php');
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6" x-data="{ showCreate: false, editingId: null, resettingId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">System Users</h1>
      <p class="text-sm text-slate-500">Manage login accounts and role-based permissions.</p>
    </div>
    <button @click="showCreate = !showCreate" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> New User
    </button>
  </div>

  <!-- Create form: field order = Full Name, Username, Temporary Password, Role -->
  <div x-show="showCreate" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <form method="POST" onsubmit="return validateForm(this);">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
          <input type="text" name="full_name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
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
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Create User</button>
      </div>
    </form>
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
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= $u['is_active'] ? 'Active' : ($u['role'] === 'Viewer' ? 'Pending/Disabled' : 'Disabled') ?>
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
                <input type="text" name="full_name" required value="<?= e($u['full_name']) ?>" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

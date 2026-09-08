<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Partner Agency']);

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);
$currentUserId = (int)current_user()['id'];
$errors = [];

if (!$agencyId) {
    flash_set('error', 'Your agency record could not be found. Please contact the Administrator.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');
        $status   = clean($_POST['status'] ?? 'Active');

        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, underscores, and periods (3-50 characters).';
        }
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';
        if (!in_array($status, ['Active', 'Disabled'], true)) $errors['status'] = 'Select a valid status.';

        if (!$errors) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM care_jf_users WHERE username = :u");
            $dup->execute([':u' => $username]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            // agency_id is always the acting user's own — never posted, never trusted from the request.
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_users (username, password, full_name, role, status, is_active, agency_id, is_primary)
                 VALUES (:u, :p, :f, 'Partner Agency', :s, :ia, :aid, 0)"
            );
            $stmt->execute([
                ':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':f' => $fullName,
                ':s' => $status, ':ia' => $status === 'Active' ? 1 : 0, ':aid' => $agencyId,
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, $currentUserId, 'AGENCY_USER_CREATE', 'care_jf_users', $newId, "Created agency user $username ($status)");
            flash_set('success', 'User added successfully.');
            redirect('agency-users.php');
        }
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $targetAgencyId = $targetStmt->fetchColumn();
        require_own_agency_record($pdo, $targetAgencyId !== false && $targetAgencyId !== null ? (int)$targetAgencyId : null);

        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $status = clean($_POST['status'] ?? '');

        if ($fullName === '') $errors['edit_full_name'] = 'Full name is required.';
        if (!in_array($status, ['Active', 'Disabled'], true)) $errors['edit_status'] = 'Select a valid status.';
        if ($status === 'Disabled' && $userId === $currentUserId) {
            $errors['edit_status'] = 'You cannot disable your own account.';
        }
        if ($status === 'Disabled' && is_last_active_agency_user($pdo, $userId)) {
            $errors['edit_status'] = 'This is the only active user for your agency — add or enable another user first.';
        }

        if (!$errors) {
            $pdo->prepare("UPDATE care_jf_users SET full_name = :f, status = :s, is_active = :ia WHERE id = :id")
                ->execute([':f' => $fullName, ':s' => $status, ':ia' => $status === 'Active' ? 1 : 0, ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'AGENCY_USER_UPDATE', 'care_jf_users', $userId, "Updated agency user #$userId (status: $status)");
            flash_set('success', 'User updated successfully.');
        } else {
            flash_set('error', reset($errors));
        }
        redirect('agency-users.php');
    } elseif ($action === 'toggle') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id, status FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $target = $targetStmt->fetch();
        require_own_agency_record($pdo, $target ? (int)$target['agency_id'] : null);

        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot disable your own account.');
        } elseif ($target['status'] === 'Active' && is_last_active_agency_user($pdo, $userId)) {
            flash_set('error', 'Cannot disable the only active user for your agency.');
        } else {
            $newStatus = $target['status'] === 'Active' ? 'Disabled' : 'Active';
            $pdo->prepare("UPDATE care_jf_users SET status = :s, is_active = :ia WHERE id = :id")
                ->execute([':s' => $newStatus, ':ia' => $newStatus === 'Active' ? 1 : 0, ':id' => $userId]);
            audit_log($pdo, $currentUserId, $newStatus === 'Active' ? 'AGENCY_USER_ENABLE' : 'AGENCY_USER_DISABLE', 'care_jf_users', $userId, "Agency user $newStatus");
            flash_set('success', "User $newStatus.");
        }
        redirect('agency-users.php');
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $targetAgencyId = $targetStmt->fetchColumn();
        require_own_agency_record($pdo, $targetAgencyId !== false && $targetAgencyId !== null ? (int)$targetAgencyId : null);

        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'New password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE care_jf_users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'AGENCY_USER_PASSWORD_RESET', 'care_jf_users', $userId, 'Password reset by agency user');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('agency-users.php');
    }
}

$agencyUsersStmt = $pdo->prepare("SELECT * FROM care_jf_users WHERE agency_id = :aid AND role = 'Partner Agency' ORDER BY is_primary DESC, created_at ASC");
$agencyUsersStmt->execute([':aid' => $agencyId]);
$agencyUsers = $agencyUsersStmt->fetchAll();

$pageTitle = 'Agency Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5" x-data="{ showAdd: <?= $errors ? 'true' : 'false' ?>, editingId: null, resettingId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Agency Users</h1>
      <p class="text-sm text-slate-500">Manage the accounts authorized to work on your agency's behalf.</p>
    </div>
    <button type="button" @click="showAdd = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> Add User
    </button>
  </div>

  <!-- Add User modal: real modal, does not close on outside click -->
  <div x-show="showAdd" x-cloak
       class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"
       @keydown.escape.window="showAdd = false">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Add User</h2>
        <button type="button" @click="showAdd = false" class="text-slate-400 hover:text-slate-600" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form method="POST" onsubmit="return validateForm(this);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['full_name']) ? 'hidden' : '' ?>" data-error-for="full_name"><?= e($errors['full_name'] ?? '') ?></p>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['username']) ? 'hidden' : '' ?>" data-error-for="username"><?= e($errors['username'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
            <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['password']) ? 'hidden' : '' ?>" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
            <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['confirm_password']) ? 'hidden' : '' ?>" data-error-for="confirm_password"><?= e($errors['confirm_password'] ?? '') ?></p>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="Active" <?= ($_POST['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
              <option value="Disabled" <?= ($_POST['status'] ?? '') === 'Disabled' ? 'selected' : '' ?>>Disabled</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="showAdd = false" class="px-5 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Add User</button>
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
          <th class="px-4 py-2.5 text-left">Account</th>
          <th class="px-4 py-2.5 text-left">Status</th>
          <th class="px-4 py-2.5 text-left">Date Created</th>
          <th class="px-4 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$agencyUsers): ?>
          <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-users text-2xl mb-2 block"></i> No agency users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($agencyUsers as $u): $isSelf = (int)$u['id'] === $currentUserId; ?>
        <tr>
          <td class="px-4 py-2.5 font-medium" data-label="Name"><?= e($u['full_name']) ?><?= $isSelf ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></td>
          <td class="px-4 py-2.5" data-label="Username"><?= e($u['username']) ?></td>
          <td class="px-4 py-2.5" data-label="Account">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['is_primary'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= $u['is_primary'] ? 'Primary' : 'Member' ?>
            </span>
          </td>
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
              <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $u['status'] === 'Active' ? 'Disable' : 'Enable' ?>">
                <i class="fa-solid <?= $u['status'] === 'Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
              </button>
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
                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                <select name="status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                  <option value="Active" <?= $u['status']==='Active'?'selected':'' ?>>Active</option>
                  <option value="Disabled" <?= $u['status']==='Disabled'?'selected':'' ?>>Disabled</option>
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
                <label class="block text-xs font-medium text-slate-600 mb-1">New Password</label>
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

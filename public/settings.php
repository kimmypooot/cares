<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => current_user()['id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password'])) {
        $errors['current_password'] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors) {
        $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")
            ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        audit_log($pdo, (int)$user['id'], 'UPDATE', 'users', (int)$user['id'], 'Changed own password');
        flash_set('success', 'Password updated successfully.');
        redirect('settings.php');
    }
}

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-lg mx-auto space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Settings</h1>
    <p class="text-sm text-slate-500">Manage your account preferences.</p>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Account</h2>
    <dl class="text-sm space-y-2 mb-6">
      <div class="flex justify-between"><dt class="text-slate-500">Username</dt><dd class="font-medium"><?= e(current_user()['username']) ?></dd></div>
      <div class="flex justify-between"><dt class="text-slate-500">Full Name</dt><dd class="font-medium"><?= e(current_user()['full_name']) ?></dd></div>
      <div class="flex justify-between"><dt class="text-slate-500">Role</dt><dd class="font-medium"><?= e(current_user()['role']) ?></dd></div>
    </dl>

    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Change Password</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Current Password</label>
          <input type="password" name="current_password" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['current_password'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
          <input type="password" name="new_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['new_password'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['confirm_password'] ?? '') ?></p>
        </div>
      </div>
      <button type="submit" class="mt-4 px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Update Password</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

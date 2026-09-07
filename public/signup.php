<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$pdo = Database::getConnection();
$errors = [];
$success = false;
$old = ['full_name' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $old['full_name'] = clean($_POST['full_name'] ?? '');
    $old['username']  = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($old['full_name'] === '') $errors['full_name'] = 'Full name is required.';
    if ($old['username'] === '') {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $old['username'])) {
        $errors['username'] = 'Username may only contain letters, numbers, underscores, and periods (3-50 characters).';
    }
    if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';

    if (!$errors) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
        $dup->execute([':u' => $old['username']]);
        if ((int)$dup->fetchColumn() > 0) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    if (!$errors) {
        // Public sign-ups are always created as Viewer, disabled/pending approval.
        // An Administrator must enable the account (see public/users.php).
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, full_name, role, is_active)
             VALUES (:u, :p, :f, 'Viewer', 0)"
        );
        $stmt->execute([
            ':u' => $old['username'],
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':f' => $old['full_name'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        audit_log($pdo, null, 'CREATE', 'users', $newId, "Public Viewer sign-up: {$old['username']} (pending approval)");
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up · CARE</title>
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="min-h-screen flex items-center justify-center p-4" style="background: radial-gradient(circle at top, #1e2a5e 0%, #0f172a 70%);">
<div class="w-full max-w-md">
  <div class="text-center mb-6 text-white">
    <img src="assets/images/csc-logo.png" alt="CSC Logo" width="48" height="48" class="h-12 w-12 object-contain mx-auto mb-2">
    <h1 class="mt-1 text-xl font-bold tracking-wide">SIGN UP AS A VIEWER</h1>
    <p class="text-slate-300 text-sm mt-1">Create an account to browse applicant reports.</p>
  </div>

  <div class="bg-white rounded-2xl shadow-2xl p-8">
    <a href="login.php" class="text-xs text-slate-400 hover:text-slate-600 mb-3 inline-block"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Login</a>

    <?php if ($success): ?>
      <div class="text-center py-4">
        <i class="fa-solid fa-circle-check text-4xl text-green-500 mb-3"></i>
        <h2 class="font-semibold text-slate-800 mb-2">Account Created</h2>
        <p class="text-sm text-slate-600">Your account has been created with <strong>Viewer</strong> access and is
          <strong>pending administrator approval</strong>. You'll be able to log in once an administrator enables it.</p>
        <a href="login.php" class="inline-block mt-5 px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Back to Login</a>
      </div>
    <?php else: ?>
      <h2 class="text-lg font-semibold text-slate-800 mb-1">Create a Viewer account</h2>
      <p class="text-sm text-slate-500 mb-2">New accounts are created with <strong>Viewer</strong> access and require administrator approval before you can log in.</p>

      <form method="POST" class="mt-4" onsubmit="return validateForm(this);">
        <?= csrf_field() ?>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" required value="<?= e($old['full_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1"><?= e($errors['full_name'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" required value="<?= e($old['username']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1"><?= e($errors['username'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
            <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1"><?= e($errors['password'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
            <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1"><?= e($errors['confirm_password'] ?? '') ?></p>
          </div>
        </div>
        <button type="submit" class="w-full mt-5 bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
          Create Account
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>

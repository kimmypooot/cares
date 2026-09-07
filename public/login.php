<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    csrf_require();
    $username = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $target = $stmt->fetch();

        if (attempt_login($pdo, $username, $password)) {
            redirect('dashboard.php');
        } elseif ($target && !$target['is_active']) {
            $error = 'Your account is pending administrator approval or has been disabled.';
        } else {
            $error = 'Invalid username or password, or your account is temporarily locked.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · CARE</title>
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="min-h-screen" style="background: radial-gradient(circle at top, #1e2a5e 0%, #0f172a 70%); background-repeat: no-repeat; background-attachment: fixed; background-size: cover;">

<div class="min-h-screen grid lg:grid-cols-2">

  <!-- LEFT: system branding. Stacks on top on mobile via the grid's default
       single-column flow; becomes a true left column at the lg breakpoint.
       Background now lives on <body> so it's continuous with the right
       panel instead of stopping at the column boundary. -->
  <div class="flex flex-col justify-center items-center lg:items-start text-center lg:text-left
              px-8 py-10 lg:py-0 lg:px-24 xl:px-32 lg:translate-x-[25px]  text-white">
    <div class="flex items-center gap-4 mb-5">
      <img src="assets/images/csc-logo.png" alt="Civil Service Commission Logo" width="128" height="128" class="h-32 w-32 object-contain">
      <img src="assets/images/bagong-pilipinas.png" alt="Bagong Pilipinas Logo" width="128" height="128" class="h-32 w-32 object-contain">
      <img src="assets/images/lingkod-bayani.png" alt="Lingkod Bayani Logo" width="128" height="128" class="h-32 w-32 object-contain">
    </div>
    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight leading-tight max-w-md">
      Civil Service Commission Regional Office VIII
    </h1>
    <p class="text-lg sm:text-xl font-semibold text-blue-300 tracking-wide mt-2 max-w-md">
      Candidate Application &amp; Registration for Employment
    </p>
    <p class="text-slate-300 mt-4 text-sm lg:text-base max-w-md leading-relaxed">Managing the lifecycle of a job fair applicant from registration to placement.</p>
  </div>

  <!-- RIGHT: login panel -->
  <div class="flex items-center justify-center p-4 sm:p-8 lg:p-12">
    <div class="w-full max-w-md">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
        <h2 class="text-lg font-semibold text-slate-800 mb-1">Login</h2>
        <p class="text-sm text-slate-500 mb-6">Enter your credentials to continue.</p>

        <?php if ($error): ?>
          <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2.5">
            <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="login">
          <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
          <div class="relative mb-4">
            <i class="fa-solid fa-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input type="text" name="username" required autofocus
                   class="w-full pl-9 pr-3 py-2.5 rounded-lg border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 outline-none text-sm transition">
          </div>

          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <div class="relative mb-6">
            <i class="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input type="password" name="password" required
                   class="w-full pl-9 pr-3 py-2.5 rounded-lg border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 outline-none text-sm transition">
          </div>

          <button type="submit"
                  class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
            <i class="fa-solid fa-right-to-bracket mr-1"></i> Login
          </button>
        </form>

        <div class="mt-6 pt-6 border-t border-slate-100 text-center">
          <p class="text-sm text-slate-500 mb-3">New applicant?</p>
          <a href="register-applicant.php"
             class="block w-full text-center px-4 py-2.5 rounded-lg border-2 border-brand-600 text-brand-700 font-semibold text-sm hover:bg-brand-50 transition">
            <i class="fa-solid fa-user-plus mr-1"></i> Register as Job Applicant
          </a>
        </div>
      </div>

      <p class="text-center text-slate-400 text-xs mt-5">
        Want read-only access to reports? <a href="signup.php" class="text-brand-600 hover:text-brand-700 font-medium">Sign Up as a Viewer</a>
      </p>

      <p class="text-center text-slate-400 text-xs mt-6">© <?= date('Y') ?> CARE — Candidate Application & Registration for Employment. All rights reserved.</p>
    </div>
  </div>
</div>
</body>
</html>

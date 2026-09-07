<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pdo = Database::getConnection();
$error = null;
$activePanel = 'login';

$regErrors = [];
$regOld = ['agency_name' => '', 'address' => '', 'contact_person' => '', 'contact_no' => '', 'email' => '', 'username' => ''];
$regSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    csrf_require();
    $username = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (attempt_login($pdo, $username, $password)) {
        redirect('dashboard.php');
    } else {
        $stmt = $pdo->prepare(
            "SELECT u.status, u.role, pa.status AS agency_status
             FROM users u LEFT JOIN partner_agencies pa ON pa.id = u.agency_id
             WHERE u.username = :u LIMIT 1"
        );
        $stmt->execute([':u' => $username]);
        $target = $stmt->fetch();

        if ($target && $target['role'] === 'Partner Agency' && $target['status'] === 'Pending') {
            $error = 'Your Partner Agency account is still pending Administrator approval.';
        } elseif ($target && $target['role'] === 'Partner Agency' && ($target['status'] === 'Disabled' || $target['agency_status'] === 'Disabled')) {
            $error = 'Your Partner Agency account has been disabled. Please contact the Administrator.';
        } elseif ($target && $target['status'] !== 'Active') {
            $error = 'Your account is pending administrator approval or has been disabled.';
        } else {
            $error = 'Invalid username or password, or your account is temporarily locked.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'register_agency') {
    csrf_require();
    $activePanel = 'register';

    $regOld['agency_name']    = clean($_POST['agency_name'] ?? '');
    $regOld['address']        = clean($_POST['address'] ?? '');
    $regOld['contact_person'] = clean($_POST['contact_person'] ?? '');
    $regOld['contact_no']     = clean($_POST['contact_no'] ?? '');
    $regOld['email']          = clean($_POST['email'] ?? '');
    $regOld['username']       = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($regOld['agency_name'] === '')    $regErrors['agency_name'] = 'Agency Name is required.';
    if ($regOld['address'] === '')        $regErrors['address'] = 'Address is required.';
    if ($regOld['contact_person'] === '') $regErrors['contact_person'] = 'Contact Person is required.';
    if ($regOld['contact_no'] === '')     $regErrors['contact_no'] = 'Contact No is required.';
    if ($regOld['email'] === '') {
        $regErrors['email'] = 'Email Address is required.';
    } elseif (!is_valid_email($regOld['email'])) {
        $regErrors['email'] = 'Enter a valid email address.';
    }
    if ($regOld['username'] === '') {
        $regErrors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $regOld['username'])) {
        $regErrors['username'] = 'Username may only contain letters, numbers, underscores, and periods (3-50 characters).';
    }
    if (strlen($password) < 8) $regErrors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $regErrors['confirm_password'] = 'Passwords do not match.';

    if (!$regErrors && agency_name_taken($pdo, $regOld['agency_name'])) {
        $regErrors['agency_name'] = 'A Partner Agency with this name is already registered.';
    }
    if (!$regErrors) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
        $dup->execute([':u' => $regOld['username']]);
        if ((int)$dup->fetchColumn() > 0) {
            $regErrors['username'] = 'That username is already taken.';
        }
    }
    if (!$regErrors) {
        $dupEmail = $pdo->prepare("SELECT COUNT(*) FROM partner_agencies WHERE email = :e");
        $dupEmail->execute([':e' => $regOld['email']]);
        if ((int)$dupEmail->fetchColumn() > 0) {
            $regErrors['email'] = 'That email address is already registered to a Partner Agency.';
        }
    }

    if (!$regErrors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status)
                 VALUES (:n, :a, :cp, :cn, :e, 'Active')"
            );
            $stmt->execute([
                ':n' => $regOld['agency_name'], ':a' => $regOld['address'],
                ':cp' => $regOld['contact_person'], ':cn' => $regOld['contact_no'], ':e' => $regOld['email'],
            ]);
            $agencyId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, role, status, is_active, agency_id)
                 VALUES (:u, :p, :f, 'Partner Agency', 'Pending', 0, :aid)"
            );
            $stmt->execute([
                ':u' => $regOld['username'],
                ':p' => password_hash($password, PASSWORD_DEFAULT),
                ':f' => $regOld['contact_person'],
                ':aid' => $agencyId,
            ]);

            audit_log($pdo, null, 'PARTNER_AGENCY_REGISTER', 'partner_agencies', $agencyId,
                "Partner Agency registration: {$regOld['agency_name']} (user: {$regOld['username']}), pending approval");

            $pdo->commit();
            $regSuccess = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $regErrors['general'] = 'Registration failed due to a system error. Please try again.';
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
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
</head>
<body class="min-h-screen" style="background: radial-gradient(circle at top, #1e2a5e 0%, #0f172a 70%); background-repeat: no-repeat; background-attachment: fixed; background-size: cover;">

<div class="min-h-screen grid lg:grid-cols-2" x-data="{ panel: '<?= $activePanel ?>' }">

  <!-- LEFT: system branding (unchanged) -->
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

  <!-- RIGHT: login / registration panel -->
  <div class="flex items-center justify-center p-4 sm:p-8 lg:p-12">
    <div class="w-full max-w-md">

      <!-- LOGIN PANEL -->
      <div x-show="panel === 'login'" class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
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

        <div class="mt-4 text-center">
          <button type="button" @click="panel = 'register'" class="text-sm text-brand-600 hover:text-brand-700 font-medium">
            <i class="fa-solid fa-building mr-1"></i> Partner Agency Registration
          </button>
        </div>
      </div>

      <!-- PARTNER AGENCY REGISTRATION PANEL -->
      <div x-show="panel === 'register'" x-cloak class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
        <?php if ($regSuccess): ?>
          <div class="text-center py-4">
            <i class="fa-solid fa-circle-check text-4xl text-green-500 mb-3"></i>
            <h2 class="font-semibold text-slate-800 mb-2">Registration Submitted</h2>
            <p class="text-sm text-slate-600">Your Partner Agency registration has been submitted successfully. Your account is currently pending Administrator approval. You will be able to log in once your account has been activated.</p>
            <button type="button" @click="panel = 'login'" class="inline-block mt-5 px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Back to Login</button>
          </div>
        <?php else: ?>
          <button type="button" @click="panel = 'login'" class="text-xs text-slate-400 hover:text-slate-600 mb-3 inline-block"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Login</button>
          <h2 class="text-lg font-semibold text-slate-800 mb-1">Partner Agency Registration</h2>
          <p class="text-sm text-slate-500 mb-4">Register your agency to participate in the system. Your account will be reviewed by an Administrator before you can log in.</p>

          <?php if (!empty($regErrors['general'])): ?>
            <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2.5">
              <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
              <span><?= e($regErrors['general']) ?></span>
            </div>
          <?php endif; ?>

          <form method="POST" x-data="{ show1:false, show2:false, submitting:false }" @submit="submitting = true" onsubmit="return validateForm(this);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="register_agency">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2 mt-1">Agency Information</p>
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Agency Name <span class="text-red-500">*</span></label>
                <input type="text" name="agency_name" required value="<?= e($regOld['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['agency_name'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
                <input type="text" name="address" required value="<?= e($regOld['address']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['address'] ?? '') ?></p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person <span class="text-red-500">*</span></label>
                  <input type="text" name="contact_person" required value="<?= e($regOld['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <p class="text-xs text-red-500 mt-1"><?= e($regErrors['contact_person'] ?? '') ?></p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Contact No <span class="text-red-500">*</span></label>
                  <input type="text" name="contact_no" required value="<?= e($regOld['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <p class="text-xs text-red-500 mt-1"><?= e($regErrors['contact_no'] ?? '') ?></p>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                <input type="email" name="email" required value="<?= e($regOld['email']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['email'] ?? '') ?></p>
              </div>
            </div>

            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2 mt-4">Account Information</p>
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
                <input type="text" name="username" required value="<?= e($regOld['username']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['username'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                <div class="relative">
                  <input :type="show1 ? 'text' : 'password'" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-9 text-sm">
                  <button type="button" @click="show1 = !show1" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid" :class="show1 ? 'fa-eye-slash' : 'fa-eye'"></i></button>
                </div>
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['password'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                <div class="relative">
                  <input :type="show2 ? 'text' : 'password'" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-9 text-sm">
                  <button type="button" @click="show2 = !show2" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid" :class="show2 ? 'fa-eye-slash' : 'fa-eye'"></i></button>
                </div>
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['confirm_password'] ?? '') ?></p>
              </div>
            </div>

            <button type="submit" :disabled="submitting" :class="submitting ? 'opacity-60 cursor-not-allowed' : ''" class="w-full mt-5 bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
              <span x-show="!submitting">Submit Registration</span>
              <span x-show="submitting"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Submitting...</span>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <p class="text-center text-slate-400 text-xs mt-6">© <?= date('Y') ?> CARE — Candidate Application & Registration for Employment. All rights reserved.</p>
    </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>

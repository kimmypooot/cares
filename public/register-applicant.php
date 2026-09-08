<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php'; // for session/csrf/functions only — no login required to view

$pdo = Database::getConnection();
$errors = [];
$success = isset($_GET['success']) && $_GET['success'] === '1';
$applicantCode = $success ? clean($_GET['code'] ?? '') : null;

$old = [
    'last_name' => '', 'first_name' => '', 'middle_name' => '', 'extension_name' => 'NONE',
    'address' => '', 'sex' => '', 'civil_status' => '', 'date_of_birth' => '', 'place_of_birth' => '',
    'contact_number' => '', 'email_address' => '', 'service_job_seeker' => false, 'service_agency_services' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach ($old as $key => $_) {
        $old[$key] = clean($_POST[$key] ?? '');
    }
    $old['service_job_seeker'] = isset($_POST['service_job_seeker']);
    $old['service_agency_services'] = isset($_POST['service_agency_services']);

    // Normalize to uppercase server-side too — defense in depth in case
    // JavaScript is disabled or the form is submitted directly (e.g. via
    // API). Email is deliberately excluded (kept as typed).
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'place_of_birth'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }

    // ---- Server-side validation ----
    if (!$old['service_job_seeker'] && !$old['service_agency_services']) {
        $errors['services_availed'] = 'Please select at least one service availed.';
    }
    if ($old['last_name'] === '')  $errors['last_name'] = 'Last name is required.';
    if ($old['first_name'] === '') $errors['first_name'] = 'Given name is required.';
    if ($old['address'] === '')    $errors['address'] = 'Complete address is required.';
    if (!in_array($old['sex'], ['MALE', 'FEMALE'], true)) $errors['sex'] = 'Please select sex.';

    $validCivil = ['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED', 'DIVORCED', 'OTHER'];
    if (!in_array($old['civil_status'], $validCivil, true)) $errors['civil_status'] = 'Please select civil status.';

    if ($old['date_of_birth'] === '' || !strtotime($old['date_of_birth'])) {
        $errors['date_of_birth'] = 'A valid date of birth is required.';
    } elseif (strtotime($old['date_of_birth']) > time()) {
        $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
    }

    if ($old['contact_number'] === '') {
        $errors['contact_number'] = 'Contact number is required.';
    } elseif (!is_valid_ph_number($old['contact_number'])) {
        $errors['contact_number'] = 'Enter a valid PH mobile number, e.g. 09171234567.';
    }

    if ($old['email_address'] === '') {
        $errors['email_address'] = 'Email address is required.';
    } elseif (!is_valid_email($old['email_address'])) {
        $errors['email_address'] = 'Enter a valid email address.';
    }

    // ---- Duplicate check ----
    // Rule: an applicant with the SAME NAME who registered within the past
    // month is blocked (likely a duplicate/accidental resubmission).
    // A shared date of birth or place of birth with a DIFFERENT name is
    // explicitly allowed — those alone never indicate a duplicate, since
    // many applicants can legitimately share a birthdate or hometown.
    // Email is no longer an independent duplicate trigger.
    if (!$errors) {
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_applicants
             WHERE is_deleted = 0
               AND last_name = :ln AND first_name = :fn
               AND created_at >= (NOW() - INTERVAL 1 MONTH)"
        );
        $dupStmt->execute([':ln' => $old['last_name'], ':fn' => $old['first_name']]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            $errors['duplicate'] = 'An applicant with this name has already registered within the past month.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $code = generate_applicant_code($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth,
                     place_of_birth, contact_number, email_address, address, civil_status, source,
                     service_job_seeker, service_agency_services)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :pob, :contact, :email, :address, :civil, 'Public',
                     :svc_js, :svc_as)"
            );
            $stmt->execute([
                ':code'    => $code,
                ':ln'      => $old['last_name'],
                ':fn'      => $old['first_name'],
                ':mn'      => $old['middle_name'] ?: null,
                ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex'     => $old['sex'],
                ':dob'     => $old['date_of_birth'],
                ':pob'     => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'],
                ':email'   => $old['email_address'],
                ':address' => $old['address'],
                ':civil'   => $old['civil_status'],
                ':svc_js'  => $old['service_job_seeker'] ? 1 : 0,
                ':svc_as'  => $old['service_agency_services'] ? 1 : 0,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // No employment info is collected at public registration time —
            // every applicant registered here starts as "For Further Review" and
            // gets an employment record added later via the internal
            // Employment module or Edit Applicant.

            audit_log($pdo, null, 'CREATE', 'care_jf_applicants', $newId, "Public self-registration: applicant $code");
            $pdo->commit();

            // Post/Redirect/Get: send the browser back to this same form with
            // a success flag in the URL, so a page refresh doesn't resubmit
            // the form, and the person stays on the registration page
            // (ready to register the next applicant) instead of landing on
            // the login/landing page.
            redirect('register-applicant.php?success=1&code=' . urlencode($code));
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Public applicant registration failed: ' . $e->getMessage());
            $errors['general'] = 'A system error occurred while saving your registration. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Job Applicant Registration · CARE</title>
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
<script src="assets/vendor/qrcode-generator/qrcode.js"></script>
</head>
<body class="min-h-screen" style="background: radial-gradient(circle at top, #1e2a5e 0%, #0f172a 70%); background-repeat: no-repeat; background-attachment: fixed; background-size: cover;" x-data="{ showSuccess: <?= $success ? 'true' : 'false' ?>, showPrivacy: <?= (!empty($errors) || $success) ? 'false' : 'true' ?> }">

<?php if ($success): ?>
<!-- Success popup: shown as an overlay on top of the (blank, ready-to-fill) form, instead of pushing page content down. -->
<div x-show="showSuccess" x-cloak
     x-transition.opacity
     class="fixed inset-0 bg-black/40 z-[100] flex items-center justify-center p-4"
     @keydown.escape.window="showSuccess = false">
  <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-8 text-center relative"
       @click.outside="showSuccess = false">
    <button @click="showSuccess = false" class="absolute top-3 right-3 text-slate-400 hover:text-slate-600" aria-label="Close">
      <i class="fa-solid fa-xmark"></i>
    </button>
    <i class="fa-solid fa-circle-check text-4xl text-green-500 mb-3"></i>
    <h1 class="text-lg font-bold text-slate-800 mb-1">Registration Successful</h1>
    <p class="text-slate-600 text-sm mb-3">Your applicant record has been saved.</p>
    <div class="inline-block bg-slate-50 border border-slate-200 rounded-lg px-6 py-3">
      <p class="text-xs text-slate-500 uppercase tracking-wide">Your Applicant ID</p>
      <p class="text-2xl font-bold text-brand-700"><?= e($applicantCode) ?></p>
    </div>
    <?php if ($applicantCode): ?>
    <div class="mt-4 flex flex-col items-center">
      <canvas id="regQrCanvas" x-init="renderApplicantQr($el, <?= e(json_encode($applicantCode)) ?>)"
              class="rounded-lg border border-slate-200"></canvas>
      <button type="button" onclick="downloadQrPng(document.getElementById('regQrCanvas'), <?= e(json_encode($applicantCode . '-qr.png')) ?>)"
              class="mt-2 text-xs font-medium text-brand-600 hover:text-brand-800">
        <i class="fa-solid fa-download mr-1"></i> Download QR
      </button>
    </div>
    <?php endif; ?>
    <p class="text-xs text-slate-500 mt-3">Please keep this ID for your reference. You may present it when following up with the office.</p>
    <button @click="showSuccess = false; showPrivacy = true" class="mt-5 w-full px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">
      Register Another Applicant
    </button>
  </div>
</div>
<?php endif; ?>

<!-- Privacy Notice popup: mandatory read-through before every registration
     attempt (fresh visits and "Register Another Applicant"). No close/X
     button or click-outside dismiss — "Proceed" is the only way through,
     since this is a required consent step, not just an FYI message. -->
<div x-show="showPrivacy" x-cloak
     x-transition.opacity
     class="fixed inset-0 bg-black/50 z-[110] flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
    <img src="assets/images/privacy-notice.jpg" alt="Civil Service Commission Privacy Notice" class="w-full h-auto block">
    <div class="p-6 sm:p-8 pt-4">
      <button type="button" @click="showPrivacy = false" class="w-full px-5 py-3 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        Proceed <i class="fa-solid fa-arrow-right ml-1"></i>
      </button>
      <a href="login.php" class="block text-center text-xs text-slate-400 hover:text-slate-600 mt-3">Back to Login</a>
    </div>
  </div>
</div>

<div class="min-h-screen lg:grid lg:grid-cols-2">

  <!-- LEFT: system branding, matching login.php for a consistent identity
       across the two entry points. Stacks on top on mobile. Background now
       lives on <body> so it's continuous with the right panel instead of
       stopping at the column boundary. -->
  <div class="flex flex-col justify-center items-center lg:items-start text-center lg:text-left
              px-8 py-10 lg:py-0 lg:px-24 xl:px-32 text-white lg:sticky lg:top-0 lg:h-screen">
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

  <!-- RIGHT: registration form panel -->
  <div class="p-4 sm:p-8 lg:p-12">
  <div class="max-w-2xl mx-auto">

  <a href="login.php" class="text-sm text-slate-300 hover:text-white"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Login</a>

  <div class="mt-4 mb-6">
    <h1 class="text-2xl font-bold text-white">Job Applicant Registration</h1>
    <p class="text-sm text-slate-300">Fields marked with <span class="text-red-400">*</span> are required. No account is needed to register.</p>
  </div>

  <?php if (!empty($errors['duplicate'])): ?>
    <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
      <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span><?= e($errors['duplicate']) ?></span>
    </div>
  <?php endif; ?>
  <?php if (!empty($errors['general'])): ?>
    <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
      <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span><?= e($errors['general']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" @submit="if (!validateForm($el)) { $event.preventDefault(); }">
    <?= csrf_field() ?>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-1">Services Availed <span class="text-red-500">*</span></h2>
      <p class="text-xs text-slate-400 mb-3">Select at least one. You may select both.</p>
      <div class="space-y-2">
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="service_job_seeker" value="1" <?= $old['service_job_seeker'] ? 'checked' : '' ?> class="rounded border-slate-300">
          Job Seeker
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="service_agency_services" value="1" <?= $old['service_agency_services'] ? 'checked' : '' ?> class="rounded border-slate-300">
          Agency Services
        </label>
      </div>
      <p class="text-xs text-red-500 mt-2 <?= empty($errors['services_availed']) ? 'hidden' : '' ?>"><?= e($errors['services_availed'] ?? '') ?></p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Personal Information</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Last Name <span class="text-red-500">*</span></label>
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['last_name']) ? 'hidden' : '' ?>" data-error-for="last_name"><?= e($errors['last_name'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Given Name <span class="text-red-500">*</span></label>
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['first_name']) ? 'hidden' : '' ?>" data-error-for="first_name"><?= e($errors['first_name'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Middle Name</label>
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Ext Name</label>
          <select name="extension_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <?php foreach (['NONE','JR.','SR.','I','II','III','IV','OTHER'] as $opt): ?>
              <option <?= $old['extension_name'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Complete Address <span class="text-red-500">*</span></label>
          <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Sex <span class="text-red-500">*</span></label>
          <select name="sex" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <option <?= $old['sex']==='MALE'?'selected':'' ?>>MALE</option>
            <option <?= $old['sex']==='FEMALE'?'selected':'' ?>>FEMALE</option>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['sex']) ? 'hidden' : '' ?>" data-error-for="sex"><?= e($errors['sex'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Civil Status <span class="text-red-500">*</span></label>
          <select name="civil_status" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach (['SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER'] as $opt): ?>
              <option <?= $old['civil_status']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['civil_status']) ? 'hidden' : '' ?>" data-error-for="civil_status"><?= e($errors['civil_status'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Date of Birth <span class="text-red-500">*</span></label>
          <input type="date" name="date_of_birth" required max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_of_birth']) ? 'hidden' : '' ?>" data-error-for="date_of_birth"><?= e($errors['date_of_birth'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Place of Birth</label>
          <input type="text" name="place_of_birth" value="<?= e($old['place_of_birth']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Number <span class="text-red-500">*</span></label>
          <input type="text" name="contact_number" required placeholder="09XXXXXXXXX" value="<?= e($old['contact_number']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['contact_number']) ? 'hidden' : '' ?>" data-error-for="contact_number"><?= e($errors['contact_number'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email Address <span class="text-red-500">*</span></label>
          <input type="email" name="email_address" required value="<?= e($old['email_address']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['email_address']) ? 'hidden' : '' ?>" data-error-for="email_address"><?= e($errors['email_address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="flex justify-end gap-3">
      <a href="login.php" class="px-4 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-paper-plane mr-1"></i> Submit Registration
      </button>
    </div>
  </form>
  </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>

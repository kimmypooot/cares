<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Employee']);

$pdo = Database::getConnection();
$errors = [];
$old = [
    'last_name' => '', 'first_name' => '', 'middle_name' => '', 'extension_name' => 'NONE',
    'sex' => '', 'date_of_birth' => '', 'contact_number' => '', 'address' => '', 'civil_status' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach ($old as $key => $_) {
        $old[$key] = clean($_POST[$key] ?? '');
    }

    // Normalize to uppercase server-side too — defense in depth in case
    // JavaScript is disabled or the form is submitted directly.
    foreach (['last_name', 'first_name', 'middle_name', 'address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }

    // ---- Server-side validation ----
    if ($old['last_name'] === '')  $errors['last_name'] = 'Last name is required.';
    if ($old['first_name'] === '') $errors['first_name'] = 'First name is required.';
    if (!in_array($old['sex'], ['MALE', 'FEMALE'], true)) $errors['sex'] = 'Please select sex.';

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

    if ($old['address'] === '') $errors['address'] = 'Address is required.';

    $validCivil = ['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED', 'DIVORCED', 'OTHER'];
    if (!in_array($old['civil_status'], $validCivil, true)) $errors['civil_status'] = 'Please select civil status.';

    // ---- Duplicate check: same name + date of birth ----
    if (!$errors) {
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_applicants
             WHERE is_deleted = 0 AND last_name = :ln AND first_name = :fn AND date_of_birth = :dob"
        );
        $dupStmt->execute([
            ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':dob' => $old['date_of_birth'],
        ]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            $errors['duplicate'] = 'An applicant with the same name and date of birth is already registered.';
        }
    }

    if (!$errors) {
        $code = generate_applicant_code($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO care_jf_applicants
                (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, contact_number, address, civil_status)
             VALUES
                (:code, :ln, :fn, :mn, :ext, :sex, :dob, :contact, :address, :civil)"
        );
        $stmt->execute([
            ':code'    => $code,
            ':ln'      => $old['last_name'],
            ':fn'      => $old['first_name'],
            ':mn'      => $old['middle_name'] ?: null,
            ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
            ':sex'     => $old['sex'],
            ':dob'     => $old['date_of_birth'],
            ':contact' => $old['contact_number'],
            ':address' => $old['address'],
            ':civil'   => $old['civil_status'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_applicants', $newId, "Registered applicant $code");
        flash_set('success', "Applicant $code successfully registered.");
        redirect('applicant-view.php?id=' . $newId);
    }
}

$pageTitle = 'Register Applicant';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-3xl mx-auto">
  <div class="mb-6">
    <a href="applicants.php" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Applicants</a>
    <h1 class="text-2xl font-bold text-slate-800 mt-2">Applicant Registration</h1>
    <p class="text-sm text-slate-500">Fields marked with <span class="text-red-500">*</span> are required.</p>
  </div>

  <?php if (!empty($errors['duplicate'])): ?>
    <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
      <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span><?= e($errors['duplicate']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" id="registerForm" x-data="{ confirming: false }"
        @submit="if (!validateForm($el)) { $event.preventDefault(); } else if (!confirming) { $event.preventDefault(); confirming = true; }">
    <?= csrf_field() ?>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Personal Information</h2>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Last Name <span class="text-red-500">*</span></label>
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['last_name']) ? 'hidden' : '' ?>" data-error-for="last_name"><?= e($errors['last_name'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">First Name <span class="text-red-500">*</span></label>
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['first_name']) ? 'hidden' : '' ?>" data-error-for="first_name"><?= e($errors['first_name'] ?? '') ?></p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Middle Name</label>
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Extension Name</label>
          <select name="extension_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <?php foreach (['NONE','JR.','SR.','I','II','III','IV','OTHER'] as $opt): ?>
              <option <?= $old['extension_name'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
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
          <label class="block text-sm font-medium text-slate-700 mb-1">Date of Birth <span class="text-red-500">*</span></label>
          <input type="date" name="date_of_birth" required max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_of_birth']) ? 'hidden' : '' ?>" data-error-for="date_of_birth"><?= e($errors['date_of_birth'] ?? '') ?></p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Number <span class="text-red-500">*</span></label>
          <input type="text" name="contact_number" required placeholder="09XXXXXXXXX" value="<?= e($old['contact_number']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['contact_number']) ? 'hidden' : '' ?>" data-error-for="contact_number"><?= e($errors['contact_number'] ?? '') ?></p>
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

        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
          <textarea name="address" required rows="2"
                    class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none"><?= e($old['address']) ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="flex justify-end gap-3 mt-5">
      <a href="applicants.php" class="px-4 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Register Applicant
      </button>
    </div>

    <!-- Confirmation modal -->
    <div x-show="confirming" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="confirming=false">
        <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-circle-question text-brand-600 mr-1"></i> Confirm Registration</h3>
        <p class="text-sm text-slate-600 mb-5">Save this applicant's information? Please review the details before confirming.</p>
        <div class="flex justify-end gap-2">
          <button type="button" @click="confirming=false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Review Again</button>
          <button type="button" @click="$el.closest('form').submit()" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium">Confirm &amp; Save</button>
        </div>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

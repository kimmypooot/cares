<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Employee']);

$pdo = Database::getConnection();
$errors = [];
$old = [
    'last_name' => '', 'first_name' => '', 'middle_name' => '', 'extension_name' => 'NONE',
    'sex' => '', 'date_of_birth' => '', 'contact_number' => '', 'address' => '', 'civil_status' => '',
    'educational_level' => '', 'completion_status' => '', 'highest_year_level_units' => '',
    'date_graduated' => '', 'course_degree' => '', 'school_name' => '', 'school_address' => '',
    'eligibility_status' => '', 'eligibility_type' => '', 'other_eligibility_type' => '',
];

$educLevelOptions = ['High School/Senior High School Graduate', 'Technical/Vocational', 'College Graduate', 'Postgraduate (Master/Doctorate)'];
$eligibilityTypeOptions = ['Civil Service Professional', 'Civil Service Subprofessional', 'Civil Service Professional (Preference Rating)', 'Civil Service Subprofessional (Preference Rating)', 'Basic Competency on Local Treasury', 'Barangay Official', 'Honor Graduate Eligibility', 'Fire Officer', 'Penology Officer', 'Skills Eligibility (MC 11)', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach ($old as $key => $_) {
        $old[$key] = clean($_POST[$key] ?? '');
    }

    $old['educational_level'] = clean($_POST['educational_level'] ?? '');
    $old['completion_status'] = clean($_POST['completion_status'] ?? '');
    $old['highest_year_level_units'] = clean($_POST['highest_year_level_units'] ?? '');
    $old['date_graduated'] = clean($_POST['date_graduated'] ?? '');
    $old['course_degree'] = clean($_POST['course_degree'] ?? '');
    $old['school_name'] = clean($_POST['school_name'] ?? '');
    $old['school_address'] = clean($_POST['school_address'] ?? '');
    $old['eligibility_status'] = clean($_POST['eligibility_status'] ?? '');
    $old['eligibility_type'] = clean($_POST['eligibility_type'] ?? '');
    $old['other_eligibility_type'] = mb_strtoupper(trim(clean($_POST['other_eligibility_type'] ?? '')), 'UTF-8');

    // Normalize to uppercase server-side too — defense in depth in case
    // JavaScript is disabled or the form is submitted directly.
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'school_name', 'school_address'] as $upperKey) {
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

    if (!in_array($old['educational_level'], $educLevelOptions, true)) {
        $errors['educational_level'] = 'Please select an educational level.';
    }

    if (!in_array($old['completion_status'], ['Not Graduate', 'Graduate'], true)) {
        $errors['completion_status'] = 'Please select completion status.';
    } elseif ($old['completion_status'] === 'Not Graduate') {
        if ($old['highest_year_level_units'] === '') {
            $errors['highest_year_level_units'] = 'Highest year/level/units earned is required.';
        }
        $old['date_graduated'] = '';
        $old['course_degree'] = '';
        $old['school_name'] = '';
        $old['school_address'] = '';
    } else {
        if ($old['date_graduated'] === '' || !strtotime($old['date_graduated'])) {
            $errors['date_graduated'] = 'A valid graduation date is required.';
        } elseif (strtotime($old['date_graduated']) > time()) {
            $errors['date_graduated'] = 'Date graduated cannot be in the future.';
        }
        if ($old['course_degree'] === '') $errors['course_degree'] = 'Complete title of course/degree is required.';
        if ($old['school_name'] === '') $errors['school_name'] = 'Name of school is required.';
        if ($old['school_address'] === '') $errors['school_address'] = 'School address is required.';
        $old['highest_year_level_units'] = '';
    }

    if (!in_array($old['eligibility_status'], ['Eligible', 'Not Eligible'], true)) {
        $errors['eligibility_status'] = 'Please select eligibility status.';
    } elseif ($old['eligibility_status'] === 'Not Eligible') {
        $old['eligibility_type'] = '';
        $old['other_eligibility_type'] = '';
    } else {
        if (!in_array($old['eligibility_type'], $eligibilityTypeOptions, true)) {
            $errors['eligibility_type'] = 'Please select an eligibility type.';
        }
        if ($old['eligibility_type'] === 'Other') {
            if ($old['other_eligibility_type'] === '') {
                $errors['other_eligibility_type'] = 'Please specify the other eligibility type.';
            } elseif (mb_strlen($old['other_eligibility_type']) > 150) {
                $errors['other_eligibility_type'] = 'Other eligibility type must be 150 characters or fewer.';
            }
        } else {
            $old['other_eligibility_type'] = '';
        }
    }

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
        $pdo->beginTransaction();
        try {
            $code = generate_applicant_code($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, contact_number, address, civil_status,
                     educational_level, completion_status, highest_year_level_units, date_graduated,
                     course_degree, school_name, school_address,
                     eligibility_status, eligibility_type, other_eligibility_type)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :contact, :address, :civil,
                     :educ_level, :completion, :hylu, :date_grad, :course, :school_name, :school_addr,
                     :elig_status, :elig_type, :other_elig)"
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
                ':educ_level' => $old['educational_level'],
                ':completion' => $old['completion_status'],
                ':hylu'       => $old['highest_year_level_units'] ?: null,
                ':date_grad'  => $old['date_graduated'] ?: null,
                ':course'     => $old['course_degree'] ?: null,
                ':school_name' => $old['school_name'] ?: null,
                ':school_addr' => $old['school_address'] ?: null,
                ':elig_status' => $old['eligibility_status'],
                ':elig_type'   => $old['eligibility_type'] ?: null,
                ':other_elig'  => $old['other_eligibility_type'] ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_applicants', $newId, "Registered applicant $code");
            $pdo->commit();
            flash_set('success', "Applicant $code successfully registered.");
            redirect('applicant-view.php?id=' . $newId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Applicant registration failed: ' . $e->getMessage());
            $errors['general'] = 'A system error occurred while saving this applicant. Please try again.';
        }
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

  <?php if (!empty($errors['general'])): ?>
    <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
      <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span><?= e($errors['general']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" id="registerForm" x-data="{ confirming: false, completion: '<?= e($old['completion_status']) ?>', eligibility: '<?= e($old['eligibility_status']) ?>', eligType: '<?= e($old['eligibility_type']) ?>' }"
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

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mt-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Educational Attainment</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Educational Level <span class="text-red-500">*</span></label>
          <select name="educational_level" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
            <option value="">Select</option>
            <?php foreach ($educLevelOptions as $opt): ?>
              <option <?= $old['educational_level']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['educational_level']) ? 'hidden' : '' ?>" data-error-for="educational_level"><?= e($errors['educational_level'] ?? '') ?></p>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Completion <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Not Graduate" x-model="completion" <?= $old['completion_status']==='Not Graduate'?'checked':'' ?> required> Not Graduate</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Graduate" x-model="completion" <?= $old['completion_status']==='Graduate'?'checked':'' ?>> Graduate</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['completion_status']) ? 'hidden' : '' ?>" data-error-for="completion_status"><?= e($errors['completion_status'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Not Graduate'" x-cloak :data-conditional-hidden="completion !== 'Not Graduate' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Highest Year/Level/Units Earned <span class="text-red-500">*</span></label>
          <input type="text" name="highest_year_level_units" :required="completion === 'Not Graduate'" placeholder="e.g. Grade 12, 3rd Year College, 72 Units" value="<?= e($old['highest_year_level_units']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['highest_year_level_units']) ? 'hidden' : '' ?>" data-error-for="highest_year_level_units"><?= e($errors['highest_year_level_units'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Date Graduated <span class="text-red-500">*</span></label>
          <input type="date" name="date_graduated" :required="completion === 'Graduate'" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_graduated']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_graduated']) ? 'hidden' : '' ?>" data-error-for="date_graduated"><?= e($errors['date_graduated'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Complete Title of Course/Degree <span class="text-red-500">*</span></label>
          <input type="text" name="course_degree" :required="completion === 'Graduate'" value="<?= e($old['course_degree']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['course_degree']) ? 'hidden' : '' ?>" data-error-for="course_degree"><?= e($errors['course_degree'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Name of School <span class="text-red-500">*</span></label>
          <input type="text" name="school_name" :required="completion === 'Graduate'" value="<?= e($old['school_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_name']) ? 'hidden' : '' ?>" data-error-for="school_name"><?= e($errors['school_name'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">School Address <span class="text-red-500">*</span></label>
          <textarea name="school_address" :required="completion === 'Graduate'" rows="2"
                    class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none"><?= e($old['school_address']) ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_address']) ? 'hidden' : '' ?>" data-error-for="school_address"><?= e($errors['school_address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mt-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Eligibility</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Status <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Eligible" x-model="eligibility" <?= $old['eligibility_status']==='Eligible'?'checked':'' ?> required> Eligible</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Not Eligible" x-model="eligibility" <?= $old['eligibility_status']==='Not Eligible'?'checked':'' ?>> Not Eligible</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_status']) ? 'hidden' : '' ?>" data-error-for="eligibility_status"><?= e($errors['eligibility_status'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible'" x-cloak :data-conditional-hidden="eligibility !== 'Eligible' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Type <span class="text-red-500">*</span></label>
          <select name="eligibility_type" x-model="eligType" :required="eligibility === 'Eligible'" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
            <option value="">Select</option>
            <?php foreach ($eligibilityTypeOptions as $opt): ?>
              <option <?= $old['eligibility_type']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_type']) ? 'hidden' : '' ?>" data-error-for="eligibility_type"><?= e($errors['eligibility_type'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible' && eligType === 'Other'" x-cloak :data-conditional-hidden="!(eligibility === 'Eligible' && eligType === 'Other') ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Other Eligibility Type <span class="text-red-500">*</span></label>
          <input type="text" name="other_eligibility_type" :required="eligibility === 'Eligible' && eligType === 'Other'" maxlength="150" value="<?= e($old['other_eligibility_type']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['other_eligibility_type']) ? 'hidden' : '' ?>" data-error-for="other_eligibility_type"><?= e($errors['other_eligibility_type'] ?? '') ?></p>
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
      <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
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

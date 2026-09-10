<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Employee']);

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE id = :id AND is_deleted = 0");
$stmt->execute([':id' => $id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

// Current employment record (if any), used to prefill the Employment section
$curStmt = $pdo->prepare("SELECT * FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 ORDER BY date_hired DESC LIMIT 1");
$curStmt->execute([':id' => $id]);
$currentEmployment = $curStmt->fetch();

$errors = [];
$old = $applicant;

$educLevelOptions = ['High School/Senior High Level', 'Technical/Vocational', 'College Level', 'Postgraduate (Master/Doctorate)'];
$eligibilityTypeOptions = ['Civil Service Professional', 'Civil Service Subprofessional', 'Civil Service Professional (Preference Rating)', 'Civil Service Subprofessional (Preference Rating)', 'Basic Competency on Local Treasury', 'Barangay Official', 'Honor Graduate Eligibility', 'Fire Officer', 'Penology Officer', 'Skills Eligibility (MC 11)', 'RA 1080', 'Other Eligibility'];

$old['extension_name'] = $old['extension_name'] ?: 'NONE';
$old['employment_status_flag'] = $currentEmployment ? 'Employed' : 'Not Yet';
$old['agency_id'] = $currentEmployment['agency_id'] ?? '';
$old['agency_free_text'] = (!$currentEmployment || $currentEmployment['agency_id']) ? '' : $currentEmployment['agency_company_name'];
$old['date_hired'] = $currentEmployment['date_hired'] ?? '';
$old['employment_classification'] = $currentEmployment['employment_status'] ?? '';
// Note: $old['remarks'] is NOT overwritten here — it comes from $applicant
// (the applicants.remarks column) via `$old = $applicant;` above. Remarks
// is a general note about the applicant, independent of employment status,
// so it must not depend on whether a current employment record exists.

$classOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other', 'Hired'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach (['last_name','first_name','middle_name','extension_name','sex','date_of_birth','place_of_birth','contact_number','email_address','address','civil_status'] as $key) {
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

    // Normalize to uppercase server-side too — defense in depth. Email is
    // deliberately excluded (kept as typed).
    foreach (['last_name', 'first_name', 'middle_name', 'place_of_birth', 'address', 'course_degree', 'school_name', 'school_address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }
    $old['service_job_seeker'] = isset($_POST['service_job_seeker']);
    $old['service_agency_services'] = isset($_POST['service_agency_services']);
    $old['employment_status_flag'] = clean($_POST['employment_status_flag'] ?? 'Not Yet');
    $old['agency_id'] = clean($_POST['agency_id'] ?? '');
    $old['agency_free_text'] = mb_strtoupper(clean($_POST['agency_free_text'] ?? ''), 'UTF-8');
    $old['date_hired'] = clean($_POST['date_hired'] ?? '');
    $old['employment_classification'] = clean($_POST['employment_classification'] ?? '');
    $old['remarks'] = clean($_POST['remarks'] ?? '');

    if (!$old['service_job_seeker'] && !$old['service_agency_services']) {
        $errors['services_availed'] = 'Please select at least one service availed.';
    }

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

    if ($old['email_address'] !== '' && !is_valid_email($old['email_address'])) {
        $errors['email_address'] = 'Enter a valid email address.';
    }

    if ($old['address'] === '') $errors['address'] = 'Address is required.';

    // Educational Attainment and Eligibility are only required when
    // registering as a Job Seeker — an Avail-Agency-Service-only
    // registrant can still optionally fill them in (nothing below
    // clears $old for these fields when Job Seeker is unchecked), they
    // just aren't validated or required.
    if ($old['service_job_seeker']) {
        if (!in_array($old['educational_level'], $educLevelOptions, true)) {
            $errors['educational_level'] = 'Please select an educational level.';
        }

        if (!in_array($old['completion_status'], ['Not Graduated', 'Graduated'], true)) {
            $errors['completion_status'] = 'Please select completion status.';
        } elseif ($old['completion_status'] === 'Not Graduated') {
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
            if ($old['eligibility_type'] === 'Other Eligibility') {
                if ($old['other_eligibility_type'] === '') {
                    $errors['other_eligibility_type'] = 'Please specify the other eligibility type.';
                } elseif (mb_strlen($old['other_eligibility_type']) > 150) {
                    $errors['other_eligibility_type'] = 'Other eligibility type must be 150 characters or fewer.';
                }
            } else {
                $old['other_eligibility_type'] = '';
            }
        }
    }

    $validCivil = ['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED', 'DIVORCED', 'OTHER'];
    if (!in_array($old['civil_status'], $validCivil, true)) $errors['civil_status'] = 'Please select civil status.';

    // ---- Conditional employment validation ----
    $isEmployed = $old['employment_status_flag'] === 'Employed';
    if ($isEmployed) {
        if ($old['agency_id'] === '' && $old['agency_free_text'] === '') {
            $errors['agency'] = 'Select a Partner Agency or enter an agency name.';
        }
        if ($old['date_hired'] === '' || !strtotime($old['date_hired'])) {
            $errors['date_hired'] = 'A valid date hired is required.';
        } elseif (strtotime($old['date_hired']) > time()) {
            $errors['date_hired'] = 'Date hired cannot be in the future.';
        }
        if (!in_array($old['employment_classification'], $classOptions, true)) {
            $errors['employment_classification'] = 'Please select an employment classification.';
        }
    }

    if (!$errors) {
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_applicants
             WHERE is_deleted = 0 AND id <> :id AND last_name = :ln AND first_name = :fn AND date_of_birth = :dob"
        );
        $dupStmt->execute([':id' => $id, ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':dob' => $old['date_of_birth']]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            $errors['duplicate'] = 'Another applicant with the same name and date of birth already exists.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE care_jf_applicants SET last_name=:ln, first_name=:fn, middle_name=:mn, extension_name=:ext,
                 sex=:sex, date_of_birth=:dob, place_of_birth=:pob, contact_number=:contact, email_address=:email,
                 address=:address, civil_status=:civil, remarks=:remarks,
                 service_job_seeker=:svc_js, service_agency_services=:svc_as,
                 educational_level=:educ_level, completion_status=:completion, highest_year_level_units=:hylu,
                 date_graduated=:date_grad, course_degree=:course, school_name=:school_name, school_address=:school_addr,
                 eligibility_status=:elig_status, eligibility_type=:elig_type, other_eligibility_type=:other_elig
                 WHERE id=:id"
            );
            $stmt->execute([
                ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':mn' => $old['middle_name'] ?: null,
                ':ext' => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex' => $old['sex'], ':dob' => $old['date_of_birth'], ':pob' => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'], ':email' => $old['email_address'] ?: null,
                ':address' => $old['address'], ':civil' => $old['civil_status'], ':remarks' => $old['remarks'] ?: null,
                ':svc_js' => $old['service_job_seeker'] ? 1 : 0, ':svc_as' => $old['service_agency_services'] ? 1 : 0,
                ':educ_level' => $old['educational_level'] ?: null,
                ':completion' => $old['completion_status'] ?: null,
                ':hylu'       => $old['highest_year_level_units'] ?: null,
                ':date_grad'  => $old['date_graduated'] ?: null,
                ':course'     => $old['course_degree'] ?: null,
                ':school_name' => $old['school_name'] ?: null,
                ':school_addr' => $old['school_address'] ?: null,
                ':elig_status' => $old['eligibility_status'] ?: null,
                ':elig_type'   => $old['eligibility_type'] ?: null,
                ':other_elig'  => $old['other_eligibility_type'] ?: null,
                ':id' => $id,
            ]);

            // ---- Sync Employment Status / Agency / Date Hired with employment_records ----
            // This form does NOT duplicate employment data into a new column;
            // it upserts the applicant's single "current" employment_records row.
            // Remarks is handled separately above (applicants.remarks), NOT here —
            // it must persist regardless of whether an employment record exists.
            if ($isEmployed) {
                $agencyId = $old['agency_id'] !== '' ? (int)$old['agency_id'] : null;
                $agencyName = $agencyId ? null : $old['agency_free_text'];
                $agencyAddress = null;
                if ($agencyId) {
                    $aStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id");
                    $aStmt->execute([':id' => $agencyId]);
                    $aRow = $aStmt->fetch();
                    if ($aRow) { $agencyName = $aRow['agency_name']; $agencyAddress = $aRow['address']; }
                }
                if (!$agencyAddress) $agencyAddress = 'Not specified';

                // Clear any other current flag first (app-level, not a trigger — see database.sql notes)
                $pdo->prepare("UPDATE care_jf_employment_records SET is_current = 0 WHERE applicant_id = :aid")->execute([':aid' => $id]);

                if ($currentEmployment) {
                    $pdo->prepare(
                        "UPDATE care_jf_employment_records SET agency_id=:agid, agency_company_name=:agency, agency_company_address=:addr,
                         date_hired=:date, employment_status=:cls, is_current=1, status='Active' WHERE id=:rid"
                    )->execute([
                        ':agid' => $agencyId, ':agency' => $agencyName, ':addr' => $agencyAddress,
                        ':date' => $old['date_hired'], ':cls' => $old['employment_classification'], ':rid' => $currentEmployment['id'],
                    ]);
                    audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_employment_records', (int)$currentEmployment['id'], "Employment synced via Edit Applicant for {$applicant['applicant_code']}");
                    supersede_other_reviews($pdo, $id, (int)$currentEmployment['id'], (int)current_user()['id']);
                } else {
                    $newEmpStmt = $pdo->prepare(
                        "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
                         VALUES (:aid, :agid, :agency, :addr, :date, :cls, 1, 'Active')"
                    );
                    $newEmpStmt->execute([
                        ':aid' => $id, ':agid' => $agencyId, ':agency' => $agencyName, ':addr' => $agencyAddress,
                        ':date' => $old['date_hired'], ':cls' => $old['employment_classification'],
                    ]);
                    $newEmpId = (int)$pdo->lastInsertId();
                    audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_employment_records', $newEmpId, "Employment created via Edit Applicant for {$applicant['applicant_code']}");
                    supersede_other_reviews($pdo, $id, $newEmpId, (int)current_user()['id']);
                }
            } elseif ($currentEmployment) {
                // Switched to "Not Yet": un-mark current, but keep the row as history (non-destructive).
                $pdo->prepare("UPDATE care_jf_employment_records SET is_current = 0 WHERE id = :rid")
                    ->execute([':rid' => $currentEmployment['id']]);
                audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_employment_records', (int)$currentEmployment['id'], "Marked no longer current via Edit Applicant for {$applicant['applicant_code']}");
            }

            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_applicants', $id, "Updated applicant {$applicant['applicant_code']}");
            $pdo->commit();
            flash_set('success', 'Applicant information updated successfully.');
            redirect('applicant-view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Applicant edit failed: ' . $e->getMessage());
            $errors['general'] = 'A system error occurred while saving changes. Please try again.';
        }
    }
}

$agencies = active_agencies($pdo, $old['agency_id'] !== '' ? (int)$old['agency_id'] : null);

$pageTitle = 'Edit Applicant';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-3xl mx-auto">
  <div class="mb-6">
    <a href="applicant-view.php?id=<?= (int)$id ?>" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Profile</a>
    <h1 class="text-2xl font-bold text-slate-800 mt-2">Edit Applicant — <?= e($applicant['applicant_code']) ?></h1>
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

  <form method="POST" x-data="{ employmentFlag: <?= e(json_encode($old['employment_status_flag'])) ?>, agencySel: '<?= $old['agency_id'] !== '' ? (int)$old['agency_id'] : '' ?>', completion: <?= e(json_encode($old['completion_status'] ?? '')) ?>, eligibility: <?= e(json_encode($old['eligibility_status'] ?? '')) ?>, eligType: <?= e(json_encode($old['eligibility_type'] ?? '')) ?> }"
        @submit="if (!validateForm($el)) { $event.preventDefault(); } else if (!confirm('Save changes to this applicant record?')) { $event.preventDefault(); }">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-3">Services Availed</h2>
      <div class="space-y-2">
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="service_job_seeker" value="1" <?= !empty($old['service_job_seeker']) ? 'checked' : '' ?> class="rounded border-slate-300">
          Job Seeker
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="service_agency_services" value="1" <?= !empty($old['service_agency_services']) ? 'checked' : '' ?> class="rounded border-slate-300">
          Avail Agency Services
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
          <label class="block text-sm font-medium text-slate-700 mb-1">First Name <span class="text-red-500">*</span></label>
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['first_name']) ? 'hidden' : '' ?>" data-error-for="first_name"><?= e($errors['first_name'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Middle Name</label>
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
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
            <option <?= $old['sex']==='MALE'?'selected':'' ?>>MALE</option>
            <option <?= $old['sex']==='FEMALE'?'selected':'' ?>>FEMALE</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Civil Status <span class="text-red-500">*</span></label>
          <select name="civil_status" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <?php foreach (['SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER'] as $opt): ?>
              <option <?= $old['civil_status']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Date of Birth <span class="text-red-500">*</span></label>
          <input type="date" name="date_of_birth" required max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_of_birth']) ? 'hidden' : '' ?>" data-error-for="date_of_birth"><?= e($errors['date_of_birth'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Place of Birth</label>
          <input type="text" name="place_of_birth" value="<?= e($old['place_of_birth'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Number <span class="text-red-500">*</span></label>
          <input type="text" name="contact_number" required value="<?= e($old['contact_number']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['contact_number']) ? 'hidden' : '' ?>" data-error-for="contact_number"><?= e($errors['contact_number'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
          <input type="email" name="email_address" value="<?= e($old['email_address'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['email_address']) ? 'hidden' : '' ?>" data-error-for="email_address"><?= e($errors['email_address'] ?? '') ?></p>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
          <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Educational Attainment</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Educational Level <span class="text-red-500">*</span></label>
          <select name="educational_level" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach ($educLevelOptions as $opt): ?>
              <option <?= ($old['educational_level'] ?? '')===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['educational_level']) ? 'hidden' : '' ?>" data-error-for="educational_level"><?= e($errors['educational_level'] ?? '') ?></p>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Completion <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Not Graduated" x-model="completion" <?= ($old['completion_status'] ?? '')==='Not Graduated'?'checked':'' ?> required> Not Graduated</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Graduated" x-model="completion" <?= ($old['completion_status'] ?? '')==='Graduated'?'checked':'' ?>> Graduated</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['completion_status']) ? 'hidden' : '' ?>" data-error-for="completion_status"><?= e($errors['completion_status'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Not Graduated'" x-cloak :data-conditional-hidden="completion !== 'Not Graduated' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Highest Year/Level/Units Earned <span class="text-red-500">*</span></label>
          <input type="text" name="highest_year_level_units" :required="completion === 'Not Graduated'" placeholder="e.g. Grade 12, 3rd Year College, 72 Units" value="<?= e($old['highest_year_level_units'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['highest_year_level_units']) ? 'hidden' : '' ?>" data-error-for="highest_year_level_units"><?= e($errors['highest_year_level_units'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Graduated'" x-cloak :data-conditional-hidden="completion !== 'Graduated' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Date Graduated <span class="text-red-500">*</span></label>
          <input type="date" name="date_graduated" :required="completion === 'Graduated'" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_graduated'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_graduated']) ? 'hidden' : '' ?>" data-error-for="date_graduated"><?= e($errors['date_graduated'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduated'" x-cloak :data-conditional-hidden="completion !== 'Graduated' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Complete Title of Course/Degree <span class="text-red-500">*</span></label>
          <input type="text" name="course_degree" :required="completion === 'Graduated'" value="<?= e($old['course_degree'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['course_degree']) ? 'hidden' : '' ?>" data-error-for="course_degree"><?= e($errors['course_degree'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduated'" x-cloak :data-conditional-hidden="completion !== 'Graduated' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Name of School <span class="text-red-500">*</span></label>
          <input type="text" name="school_name" :required="completion === 'Graduated'" value="<?= e($old['school_name'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_name']) ? 'hidden' : '' ?>" data-error-for="school_name"><?= e($errors['school_name'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduated'" x-cloak :data-conditional-hidden="completion !== 'Graduated' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">School Address <span class="text-red-500">*</span></label>
          <textarea name="school_address" :required="completion === 'Graduated'" rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['school_address'] ?? '') ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_address']) ? 'hidden' : '' ?>" data-error-for="school_address"><?= e($errors['school_address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Eligibility</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Status <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Eligible" x-model="eligibility" <?= ($old['eligibility_status'] ?? '')==='Eligible'?'checked':'' ?> required> Eligible</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Not Eligible" x-model="eligibility" <?= ($old['eligibility_status'] ?? '')==='Not Eligible'?'checked':'' ?>> Not Eligible</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_status']) ? 'hidden' : '' ?>" data-error-for="eligibility_status"><?= e($errors['eligibility_status'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible'" x-cloak :data-conditional-hidden="eligibility !== 'Eligible' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Type <span class="text-red-500">*</span></label>
          <select name="eligibility_type" x-model="eligType" :required="eligibility === 'Eligible'" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach ($eligibilityTypeOptions as $opt): ?>
              <option <?= ($old['eligibility_type'] ?? '')===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_type']) ? 'hidden' : '' ?>" data-error-for="eligibility_type"><?= e($errors['eligibility_type'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible' && eligType === 'Other Eligibility'" x-cloak :data-conditional-hidden="!(eligibility === 'Eligible' && eligType === 'Other Eligibility') ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Other Eligibility Type <span class="text-red-500">*</span></label>
          <input type="text" name="other_eligibility_type" :required="eligibility === 'Eligible' && eligType === 'Other Eligibility'" maxlength="150" value="<?= e($old['other_eligibility_type'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['other_eligibility_type']) ? 'hidden' : '' ?>" data-error-for="other_eligibility_type"><?= e($errors['other_eligibility_type'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Employment Information</h2>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Employment Status <span class="text-red-500">*</span></label>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-sm"><input type="radio" name="employment_status_flag" value="Employed" x-model="employmentFlag" <?= $old['employment_status_flag']==='Employed'?'checked':'' ?>> Employed</label>
          <label class="flex items-center gap-2 text-sm"><input type="radio" name="employment_status_flag" value="Not Yet" x-model="employmentFlag" <?= $old['employment_status_flag']!=='Employed'?'checked':'' ?>> Not Yet</label>
        </div>
      </div>

      <div x-show="employmentFlag === 'Employed'" x-cloak :data-conditional-hidden="employmentFlag !== 'Employed' ? '1' : null" class="mt-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Agency</label>
          <select name="agency_id" x-model="agencySel" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">— Enter agency/company manually below —</option>
            <?php foreach ($agencies as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>" <?= (string)$old['agency_id'] === (string)$ag['id'] ? 'selected' : '' ?>>
                <?= e($ag['agency_name']) ?><?= $ag['status'] !== 'Active' ? ' (Disabled — historical)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency']) ? 'hidden' : '' ?>" data-error-for="agency"><?= e($errors['agency'] ?? '') ?></p>
        </div>
        <div x-show="agencySel === ''">
          <label class="block text-sm font-medium text-slate-700 mb-1">Agency Name (manual)</label>
          <input type="text" name="agency_free_text" value="<?= e($old['agency_free_text']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Date Hired</label>
            <input type="date" name="date_hired" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_hired']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_hired']) ? 'hidden' : '' ?>" data-error-for="date_hired"><?= e($errors['date_hired'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Employment Classification</label>
            <select name="employment_classification" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">Select</option>
              <?php foreach ($classOptions as $opt): ?>
                <option <?= $old['employment_classification']===$opt?'selected':'' ?>><?= e($opt) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['employment_classification']) ? 'hidden' : '' ?>" data-error-for="employment_classification"><?= e($errors['employment_classification'] ?? '') ?></p>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mt-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Remarks</h2>
      <p class="text-xs text-slate-400 mb-3">General notes about this applicant. Independent of employment status — always saved regardless of whether they're Employed or Not Yet.</p>
      <textarea name="remarks" rows="3" placeholder="Optional notes about this applicant..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['remarks'] ?? '') ?></textarea>
    </div>

    <div class="flex justify-end gap-3 mt-5">
      <a href="applicant-view.php?id=<?= (int)$id ?>" class="px-4 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

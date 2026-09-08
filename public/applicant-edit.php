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

    // Normalize to uppercase server-side too — defense in depth. Email is
    // deliberately excluded (kept as typed).
    foreach (['last_name', 'first_name', 'middle_name', 'place_of_birth', 'address'] as $upperKey) {
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
                 service_job_seeker=:svc_js, service_agency_services=:svc_as
                 WHERE id=:id"
            );
            $stmt->execute([
                ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':mn' => $old['middle_name'] ?: null,
                ':ext' => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex' => $old['sex'], ':dob' => $old['date_of_birth'], ':pob' => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'], ':email' => $old['email_address'] ?: null,
                ':address' => $old['address'], ':civil' => $old['civil_status'], ':remarks' => $old['remarks'] ?: null,
                ':svc_js' => $old['service_job_seeker'] ? 1 : 0, ':svc_as' => $old['service_agency_services'] ? 1 : 0,
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

  <form method="POST" x-data="{ employmentFlag: '<?= e($old['employment_status_flag']) ?>', agencySel: '<?= $old['agency_id'] !== '' ? (int)$old['agency_id'] : '' ?>' }"
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
          Agency Services
        </label>
      </div>
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

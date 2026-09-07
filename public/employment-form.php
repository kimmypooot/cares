<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Employee']);

$pdo = Database::getConnection();
$statusOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other', 'Hired'];
$errors = [];

// ---------------------------------------------------------------------
// Handle POST: add / edit
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    $agencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
    $agencyFreeText = mb_strtoupper(clean($_POST['agency_free_text'] ?? ''), 'UTF-8');
    $dateHired = clean($_POST['date_hired'] ?? '');
    $status = clean($_POST['employment_status'] ?? '');
    $isCurrent = isset($_POST['is_current']) ? 1 : 0;
    $remarks = clean($_POST['remarks'] ?? '');

    // Resolve the agency name/address either from the selected partner
    // agency or, if none selected, from the free-text fallback field.
    $agencyName = '';
    $agencyAddress = '';
    if ($agencyId) {
        $aStmt = $pdo->prepare("SELECT agency_name, address FROM partner_agencies WHERE id = :id");
        $aStmt->execute([':id' => $agencyId]);
        $agencyRow = $aStmt->fetch();
        if ($agencyRow) {
            $agencyName = $agencyRow['agency_name'];
            $agencyAddress = $agencyRow['address'];
        }
    }
    if ($agencyName === '') {
        $agencyName = $agencyFreeText;
        $agencyAddress = clean($_POST['agency_company_address'] ?? '');
    }

    if ($agencyName === '') $errors['agency'] = 'Select a Partner Agency or enter an agency/company name.';
    if ($agencyId === null && $agencyAddress === '') $errors['agency_company_address'] = 'Agency/Company address is required when not selecting a Partner Agency.';
    if ($dateHired === '' || !strtotime($dateHired)) {
        $errors['date_hired'] = 'A valid date hired is required.';
    } elseif (strtotime($dateHired) > time() && empty($_POST['allow_future'])) {
        $errors['date_hired'] = 'Date hired cannot be in the future.';
    }
    if (!in_array($status, $statusOptions, true)) $errors['employment_status'] = 'Please select an employment classification.';

    if (!$errors) {
        // Only one "current" record per applicant: clear any existing
        // current flag first, in application code (see database.sql notes
        // on why this is no longer a trigger).
        if ($isCurrent) {
            $pdo->prepare("UPDATE employment_records SET is_current = 0 WHERE applicant_id = :aid")
                ->execute([':aid' => $applicantId]);
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status, remarks)
                 VALUES (:aid, :agid, :agency, :address, :date, :status, :current, 'Active', :remarks)"
            );
            $stmt->execute([
                ':aid' => $applicantId, ':agid' => $agencyId, ':agency' => $agencyName, ':address' => $agencyAddress,
                ':date' => $dateHired, ':status' => $status, ':current' => $isCurrent, ':remarks' => $remarks ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'employment_records', $newId, "Added employment record for applicant #$applicantId");
            flash_set('success', 'Employment record added successfully.');
        } elseif ($action === 'edit') {
            $recordId = (int)($_POST['record_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE employment_records SET agency_id=:agid, agency_company_name=:agency, agency_company_address=:address,
                 date_hired=:date, employment_status=:status, is_current=:current, remarks=:remarks WHERE id=:id"
            );
            $stmt->execute([
                ':agid' => $agencyId, ':agency' => $agencyName, ':address' => $agencyAddress,
                ':date' => $dateHired, ':status' => $status, ':current' => $isCurrent, ':remarks' => $remarks ?: null, ':id' => $recordId,
            ]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'employment_records', $recordId, "Updated employment record for applicant #$applicantId");
            flash_set('success', 'Employment record updated successfully.');
        }
        redirect('applicant-view.php?id=' . $applicantId);
    }
}

// ---------------------------------------------------------------------
// GET: show add/edit form
// ---------------------------------------------------------------------
$applicantId = (int)($_GET['applicant_id'] ?? ($_POST['applicant_id'] ?? 0));
$action = $_GET['action'] ?? ($_POST['action'] ?? 'add');
$record = ['agency_id' => null, 'agency_company_name' => '', 'agency_company_address' => '', 'date_hired' => '', 'employment_status' => '', 'is_current' => 1, 'remarks' => ''];

$stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = :id AND is_deleted = 0");
$stmt->execute([':id' => $applicantId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

if ($action === 'edit') {
    $recordId = (int)($_GET['record_id'] ?? ($_POST['record_id'] ?? 0));
    $rStmt = $pdo->prepare("SELECT * FROM employment_records WHERE id = :id AND applicant_id = :aid");
    $rStmt->execute([':id' => $recordId, ':aid' => $applicantId]);
    $found = $rStmt->fetch();
    if ($found) {
        $record = $found;
    }
}

// preserve submitted values on validation failure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $record = array_merge($record, [
        'agency_id' => $agencyId, 'agency_company_name' => $agencyName ?: $agencyFreeText,
        'agency_company_address' => $agencyAddress, 'date_hired' => $dateHired,
        'employment_status' => $status, 'is_current' => $isCurrent, 'remarks' => $remarks,
    ]);
    if ($action === 'edit') $record['id'] = $_POST['record_id'];
}

$agencies = active_agencies($pdo, $record['agency_id'] ?: null);

$pageTitle = 'Employment Record';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
  <a href="applicant-view.php?id=<?= (int)$applicantId ?>" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Profile</a>
  <h1 class="text-2xl font-bold text-slate-800 mt-2 mb-1"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Employment Record</h1>
  <p class="text-sm text-slate-500 mb-6">For applicant: <span class="font-medium text-slate-700"><?= e(full_name($applicant)) ?> (<?= e($applicant['applicant_code']) ?>)</span></p>

  <?php if (!empty($errors['agency'])): ?>
    <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
      <i class="fa-solid fa-circle-exclamation mt-0.5"></i><span><?= e($errors['agency']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" action="employment-form.php" x-data="{ agencySel: '<?= $record['agency_id'] ? (int)$record['agency_id'] : '' ?>' }" onsubmit="return validateForm(this) && confirm('Save this employment record?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $action === 'edit' ? 'edit' : 'add' ?>">
    <input type="hidden" name="applicant_id" value="<?= (int)$applicantId ?>">
    <?php if ($action === 'edit'): ?><input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>"><?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Agency <span class="text-red-500">*</span></label>
        <select name="agency_id" x-model="agencySel" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">— Enter agency/company manually below —</option>
          <?php foreach ($agencies as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>" <?= (int)$record['agency_id'] === (int)$ag['id'] ? 'selected' : '' ?>>
              <?= e($ag['agency_name']) ?><?= $ag['status'] !== 'Active' ? ' (Disabled — historical)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-400 mt-1">Only active Partner Agencies are offered for new selections. If the agency you need isn't listed, use the free-text fields below or ask an administrator to add it under Partner Agency.</p>
      </div>

      <div class="grid sm:grid-cols-2 gap-4" x-show="agencySel === ''">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Agency / Company Name (manual)</label>
          <input type="text" name="agency_free_text" value="<?= $record['agency_id'] ? '' : e($record['agency_company_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Agency / Company Address (manual)</label>
          <input type="text" name="agency_company_address" value="<?= $record['agency_id'] ? '' : e($record['agency_company_address']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency_company_address']) ? 'hidden' : '' ?>" data-error-for="agency_company_address"><?= e($errors['agency_company_address'] ?? '') ?></p>
        </div>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Date Hired <span class="text-red-500">*</span></label>
          <input type="date" name="date_hired" required value="<?= e($record['date_hired']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_hired']) ? 'hidden' : '' ?>" data-error-for="date_hired"><?= e($errors['date_hired'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Employment Classification <span class="text-red-500">*</span></label>
          <select name="employment_status" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach ($statusOptions as $opt): ?>
              <option <?= $record['employment_status']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['employment_status']) ? 'hidden' : '' ?>" data-error-for="employment_status"><?= e($errors['employment_status'] ?? '') ?></p>
        </div>
      </div>
      <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_current" value="1" <?= !empty($record['is_current']) ? 'checked' : '' ?> class="rounded border-slate-300">
        Mark as current employment record
      </label>
      <p class="text-xs text-slate-400">Marking this as current will automatically un-mark any other current record for this applicant.</p>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Remarks</label>
        <textarea name="remarks" rows="2" placeholder="Optional notes about this employment record..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($record['remarks'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="flex justify-end gap-3 mt-5">
      <a href="applicant-view.php?id=<?= (int)$applicantId ?>" class="px-4 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Employment Record
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

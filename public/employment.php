<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Staff']);

$pdo = Database::getConnection();
$statusOptions = ['Job Order', 'Contract of Service', 'Casual', 'Permanent', 'Not Yet Hired', 'Other'];

// ---------------------------------------------------------------------
// Handle POST actions: add / edit / delete
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Administrator', 'Staff']);
    csrf_require();
    $action = $_POST['action'] ?? '';
    $applicantId = (int)($_POST['applicant_id'] ?? 0);

    if ($action === 'delete') {
        require_role(['Administrator']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $pdo->prepare("DELETE FROM employment_records WHERE id = :id")->execute([':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'employment_records', $recordId, 'Deleted employment record');
        flash_set('success', 'Employment record deleted.');
        redirect('applicant-view.php?id=' . $applicantId);
    }

    $agency = clean($_POST['agency_company_name'] ?? '');
    $address = clean($_POST['agency_company_address'] ?? '');
    $dateHired = clean($_POST['date_hired'] ?? '');
    $status = clean($_POST['employment_status'] ?? '');
    $isCurrent = isset($_POST['is_current']) ? 1 : 0;
    $errors = [];

    if ($agency === '') $errors['agency_company_name'] = 'Agency/Company name is required.';
    if ($address === '') $errors['agency_company_address'] = 'Agency/Company address is required.';
    if ($dateHired === '' || !strtotime($dateHired)) {
        $errors['date_hired'] = 'A valid date hired is required.';
    } elseif (strtotime($dateHired) > time() && empty($_POST['allow_future'])) {
        $errors['date_hired'] = 'Date hired cannot be in the future.';
    }
    if (!in_array($status, $statusOptions, true)) $errors['employment_status'] = 'Please select an employment status.';

    if (!$errors) {
        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO employment_records (applicant_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current)
                 VALUES (:aid, :agency, :address, :date, :status, :current)"
            );
            $stmt->execute([':aid' => $applicantId, ':agency' => $agency, ':address' => $address, ':date' => $dateHired, ':status' => $status, ':current' => $isCurrent]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'employment_records', $newId, "Added employment record for applicant #$applicantId");
            flash_set('success', 'Employment record added successfully.');
        } elseif ($action === 'edit') {
            $recordId = (int)($_POST['record_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE employment_records SET agency_company_name=:agency, agency_company_address=:address,
                 date_hired=:date, employment_status=:status, is_current=:current WHERE id=:id"
            );
            $stmt->execute([':agency' => $agency, ':address' => $address, ':date' => $dateHired, ':status' => $status, ':current' => $isCurrent, ':id' => $recordId]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'employment_records', $recordId, "Updated employment record for applicant #$applicantId");
            flash_set('success', 'Employment record updated successfully.');
        }
        redirect('applicant-view.php?id=' . $applicantId);
    }
} else {
    $errors = [];
}

// ---------------------------------------------------------------------
// GET: show add/edit form
// ---------------------------------------------------------------------
$applicantId = (int)($_GET['applicant_id'] ?? 0);
$action = $_GET['action'] ?? 'add';
$record = ['agency_company_name'=>'','agency_company_address'=>'','date_hired'=>'','employment_status'=>'','is_current'=>1];

$stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = :id AND is_deleted = 0");
$stmt->execute([':id' => $applicantId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

if ($action === 'edit') {
    $recordId = (int)($_GET['record_id'] ?? 0);
    $rStmt = $pdo->prepare("SELECT * FROM employment_records WHERE id = :id AND applicant_id = :aid");
    $rStmt->execute([':id' => $recordId, ':aid' => $applicantId]);
    $found = $rStmt->fetch();
    if ($found) $record = $found;
}
// preserve submitted values on validation failure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $record = array_merge($record, [
        'agency_company_name' => $agency ?? '', 'agency_company_address' => $address ?? '',
        'date_hired' => $dateHired ?? '', 'employment_status' => $status ?? '', 'is_current' => $isCurrent ?? 1,
    ]);
    $action = $_POST['action'];
    if ($action === 'edit') $record['id'] = $_POST['record_id'];
}

$pageTitle = 'Employment Record';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
  <a href="applicant-view.php?id=<?= (int)$applicantId ?>" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Profile</a>
  <h1 class="text-2xl font-bold text-slate-800 mt-2 mb-1"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Employment Record</h1>
  <p class="text-sm text-slate-500 mb-6">For applicant: <span class="font-medium text-slate-700"><?= e(full_name($applicant)) ?> (<?= e($applicant['applicant_code']) ?>)</span></p>

  <form method="POST" action="employment.php" onsubmit="return validateForm(this) && confirm('Save this employment record?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $action === 'edit' ? 'edit' : 'add' ?>">
    <input type="hidden" name="applicant_id" value="<?= (int)$applicantId ?>">
    <?php if ($action === 'edit'): ?><input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>"><?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Agency / Company Name <span class="text-red-500">*</span></label>
        <input type="text" name="agency_company_name" required value="<?= e($record['agency_company_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency_company_name']) ? 'hidden' : '' ?>" data-error-for="agency_company_name"><?= e($errors['agency_company_name'] ?? '') ?></p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Agency / Company Address <span class="text-red-500">*</span></label>
        <textarea name="agency_company_address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($record['agency_company_address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency_company_address']) ? 'hidden' : '' ?>" data-error-for="agency_company_address"><?= e($errors['agency_company_address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Date Hired <span class="text-red-500">*</span></label>
          <input type="date" name="date_hired" required value="<?= e($record['date_hired']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_hired']) ? 'hidden' : '' ?>" data-error-for="date_hired"><?= e($errors['date_hired'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Employment Status <span class="text-red-500">*</span></label>
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

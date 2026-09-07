<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator', 'Employee']);

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$old = ['agency_name' => '', 'address' => '', 'contact_person' => '', 'contact_no' => '', 'email' => ''];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM partner_agencies WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $agency = $stmt->fetch();
    if (!$agency) {
        flash_set('error', 'Partner Agency not found.');
        redirect('partner-agency.php');
    }
    $old = $agency;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['agency_name']    = clean($_POST['agency_name'] ?? '');
    $old['address']        = clean($_POST['address'] ?? '');
    $old['contact_person'] = clean($_POST['contact_person'] ?? '');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');

    if ($old['agency_name'] === '') $errors['agency_name'] = 'Name of Agency/Office is required.';
    if ($old['address'] === '') $errors['address'] = 'Address is required.';
    if ($old['email'] !== '' && !is_valid_email($old['email'])) $errors['email'] = 'Enter a valid email address.';

    if (!$errors && agency_name_taken($pdo, $old['agency_name'], $isEdit ? $id : null)) {
        $errors['agency_name'] = 'A Partner Agency with this name already exists.';
    }

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE partner_agencies SET agency_name = :n, address = :a, contact_person = :cp, contact_no = :cn, email = :e WHERE id = :id")
                ->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null, ':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'partner_agencies', $id, "Updated Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email) VALUES (:n, :a, :cp, :cn, :e)");
            $stmt->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'partner_agencies', $newId, "Created Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency added successfully.');
        }
        redirect('partner-agency.php');
    }
}

$pageTitle = $isEdit ? 'Edit Partner Agency' : 'Add Partner Agency';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-xl mx-auto">
  <a href="partner-agency.php" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Partner Agency</a>
  <h1 class="text-2xl font-bold text-slate-800 mt-2 mb-6"><?= $isEdit ? 'Edit' : 'Add' ?> Partner Agency</h1>

  <form method="POST" onsubmit="return validateForm(this) && confirm('Save this Partner Agency?');">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Name of Agency/Office <span class="text-red-500">*</span></label>
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency_name']) ? 'hidden' : '' ?>" data-error-for="agency_name"><?= e($errors['agency_name'] ?? '') ?></p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person</label>
          <input type="text" name="contact_person" value="<?= e($old['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact No</label>
          <input type="text" name="contact_no" value="<?= e($old['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
        <input type="email" name="email" value="<?= e($old['email']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['email']) ? 'hidden' : '' ?>" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5">
      <a href="partner-agency.php" class="px-4 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Partner Agency
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

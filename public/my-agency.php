<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Partner Agency']);

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);
$errors = [];

$stmt = $pdo->prepare("SELECT * FROM partner_agencies WHERE id = :id");
$stmt->execute([':id' => $agencyId]);
$agency = $stmt->fetch();

if (!$agency) {
    flash_set('error', 'Your agency record could not be found. Please contact the Administrator.');
    redirect('dashboard.php');
}

$userStmt = $pdo->prepare("SELECT username, status, created_at FROM users WHERE id = :id");
$userStmt->execute([':id' => current_user()['id']]);
$accountRow = $userStmt->fetch();

$old = $agency;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Partner Agency']);
    csrf_require();

    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
    $old['address']        = mb_strtoupper(clean($_POST['address'] ?? ''), 'UTF-8');
    $old['contact_person'] = mb_strtoupper(clean($_POST['contact_person'] ?? ''), 'UTF-8');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');

    if ($old['agency_name'] === '')    $errors['agency_name'] = 'Agency Name is required.';
    if ($old['address'] === '')        $errors['address'] = 'Address is required.';
    if ($old['contact_person'] === '') $errors['contact_person'] = 'Contact Person is required.';
    if ($old['contact_no'] === '')     $errors['contact_no'] = 'Contact No is required.';
    if ($old['email'] !== '' && !is_valid_email($old['email'])) $errors['email'] = 'Enter a valid email address.';

    if (!$errors && agency_name_taken($pdo, $old['agency_name'], $agencyId)) {
        $errors['agency_name'] = 'A Partner Agency with this name already exists.';
    }

    if (!$errors) {
        // $agencyId is server-derived (current_agency_id()) — a Partner
        // Agency user cannot target another agency's record no matter
        // what this form posts.
        $pdo->prepare(
            "UPDATE partner_agencies SET agency_name = :n, address = :a, contact_person = :cp, contact_no = :cn, email = :e
             WHERE id = :id"
        )->execute([
            ':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'],
            ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null, ':id' => $agencyId,
        ]);
        audit_log($pdo, (int)current_user()['id'], 'PARTNER_AGENCY_PROFILE_UPDATE', 'partner_agencies', $agencyId, 'Partner Agency profile updated');
        flash_set('success', 'Partner Agency profile updated successfully.');
        redirect('my-agency.php');
    }
}

$pageTitle = 'My Partner Agency';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold text-slate-800 mb-1">My Partner Agency</h1>
  <p class="text-sm text-slate-500 mb-6">View and update your agency's profile.</p>

  <form method="POST" onsubmit="return validateForm(this) && confirm('Save changes to your agency profile?');">
    <?= csrf_field() ?>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div class="grid sm:grid-cols-2 gap-3 text-sm border-b border-slate-100 pb-4 mb-2">
        <div><dt class="text-slate-500 inline">Account Username:</dt> <dd class="font-medium text-slate-800 inline"><?= e($accountRow['username']) ?></dd></div>
        <div><dt class="text-slate-500 inline">Account Status:</dt> <dd class="font-medium text-slate-800 inline"><?= e($accountRow['status']) ?></dd></div>
        <div class="sm:col-span-2"><dt class="text-slate-500 inline">Registration Date:</dt> <dd class="font-medium text-slate-800 inline"><?= format_date($accountRow['created_at']) ?></dd></div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Agency Name <span class="text-red-500">*</span></label>
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1" data-error-for="agency_name"><?= e($errors['agency_name'] ?? '') ?></p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person <span class="text-red-500">*</span></label>
          <input type="text" name="contact_person" required value="<?= e($old['contact_person']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1" data-error-for="contact_person"><?= e($errors['contact_person'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact No <span class="text-red-500">*</span></label>
          <input type="text" name="contact_no" required value="<?= e($old['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1" data-error-for="contact_no"><?= e($errors['contact_no'] ?? '') ?></p>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
        <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5">
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

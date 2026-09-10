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
    $stmt = $pdo->prepare("SELECT * FROM care_jf_partner_agencies WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $agency = $stmt->fetch();
    if (!$agency) {
        flash_set('error', 'Partner Agency not found.');
        redirect('partner-agency.php');
    }
    $old = $agency;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_agency') === 'save_agency') {
    csrf_require();
    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
    $old['address']        = mb_strtoupper(clean($_POST['address'] ?? ''), 'UTF-8');
    $old['contact_person'] = mb_strtoupper(clean($_POST['contact_person'] ?? ''), 'UTF-8');
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
            $pdo->prepare("UPDATE care_jf_partner_agencies SET agency_name = :n, address = :a, contact_person = :cp, contact_no = :cn, email = :e WHERE id = :id")
                ->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null, ':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_partner_agencies', $id, "Updated Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO care_jf_partner_agencies (agency_name, address, contact_person, contact_no, email) VALUES (:n, :a, :cp, :cn, :e)");
            $stmt->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_partner_agencies', $newId, "Created Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency added successfully.');
        }
        redirect('partner-agency.php');
    }
}

if ($isEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    require_role(['Administrator', 'Employee']);
    csrf_require();
    $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
    $serviceDesc = clean($_POST['description'] ?? '');
    if ($serviceName === '') {
        flash_set('error', 'Service name is required.');
    } elseif (mb_strlen($serviceName) > 200) {
        flash_set('error', 'Service name must be 200 characters or fewer.');
    } else {
        $svcStmt = $pdo->prepare(
            "INSERT INTO care_jf_agency_services (agency_id, service_name, description) VALUES (:aid, :n, :d)"
        );
        $svcStmt->execute([':aid' => $id, ':n' => $serviceName, ':d' => $serviceDesc ?: null]);
        $newServiceId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_agency_services', $newServiceId, "Added service \"$serviceName\" to agency #$id");
        flash_set('success', 'Service added.');
    }
    redirect('partner-agency-form.php?id=' . $id);
} elseif ($isEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['toggle_service', 'edit_service'], true)) {
    require_role(['Administrator', 'Employee']);
    csrf_require();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $ownStmt = $pdo->prepare("SELECT id, status FROM care_jf_agency_services WHERE id = :id AND agency_id = :aid");
    $ownStmt->execute([':id' => $serviceId, ':aid' => $id]);
    $svcRow = $ownStmt->fetch();
    if (!$svcRow) {
        flash_set('error', 'Service not found for this agency.');
    } elseif (($_POST['action'] ?? '') === 'toggle_service') {
        $newStatus = $svcRow['status'] === 'Active' ? 'Disabled' : 'Active';
        $pdo->prepare("UPDATE care_jf_agency_services SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $serviceId]);
        audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Service $newStatus");
        flash_set('success', "Service $newStatus.");
    } else {
        $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
        $serviceDesc = clean($_POST['description'] ?? '');
        if ($serviceName === '') {
            flash_set('error', 'Service name is required.');
        } else {
            $pdo->prepare("UPDATE care_jf_agency_services SET service_name = :n, description = :d WHERE id = :id")
                ->execute([':n' => $serviceName, ':d' => $serviceDesc ?: null, ':id' => $serviceId]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Updated service \"$serviceName\"");
            flash_set('success', 'Service updated.');
        }
    }
    redirect('partner-agency-form.php?id=' . $id);
}

$agencyServices = [];
if ($isEdit) {
    $svcListStmt = $pdo->prepare("SELECT * FROM care_jf_agency_services WHERE agency_id = :id ORDER BY service_name");
    $svcListStmt->execute([':id' => $id]);
    $agencyServices = $svcListStmt->fetchAll();
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
    <input type="hidden" name="action" value="save_agency">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Name of Agency/Office <span class="text-red-500">*</span></label>
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['agency_name']) ? 'hidden' : '' ?>" data-error-for="agency_name"><?= e($errors['agency_name'] ?? '') ?></p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person</label>
          <input type="text" name="contact_person" value="<?= e($old['contact_person']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
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

<?php if ($isEdit): ?>
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mt-5" x-data="{ showAddService: false, editingServiceId: null }">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Services Offered</h2>
    <button type="button" @click="showAddService = true" class="text-xs font-medium text-brand-600 hover:text-brand-800"><i class="fa-solid fa-plus mr-1"></i> Add Service</button>
  </div>

  <div x-show="showAddService" x-cloak class="mb-4 p-4 bg-slate-50 rounded-lg">
    <form method="POST" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_service">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Service Name <span class="text-red-500">*</span></label>
        <input type="text" name="service_name" required maxlength="200" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Description</label>
        <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" @click="showAddService = false" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
        <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Add</button>
      </div>
    </form>
  </div>

  <?php if (!$agencyServices): ?>
    <p class="text-sm text-slate-400">No services on file for this agency.</p>
  <?php else: ?>
  <div class="divide-y divide-slate-100">
    <?php foreach ($agencyServices as $svc): ?>
    <div class="py-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-medium text-slate-800"><?= e($svc['service_name']) ?>
            <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $svc['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($svc['status']) ?></span>
          </p>
          <?php if ($svc['description']): ?><p class="text-xs text-slate-500 mt-0.5"><?= e($svc['description']) ?></p><?php endif; ?>
        </div>
        <div class="flex gap-1 shrink-0">
          <button type="button" @click="editingServiceId = editingServiceId === <?= (int)$svc['id'] ?> ? null : <?= (int)$svc['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
          <form method="POST" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_service">
            <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
            <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $svc['status']==='Active' ? 'Disable' : 'Enable' ?>">
              <i class="fa-solid <?= $svc['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
            </button>
          </form>
        </div>
      </div>
      <div x-show="editingServiceId === <?= (int)$svc['id'] ?>" x-cloak class="mt-2 p-3 bg-slate-50 rounded-lg">
        <form method="POST" class="space-y-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="edit_service">
          <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
          <input type="text" name="service_name" required maxlength="200" value="<?= e($svc['service_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
          <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"><?= e($svc['description'] ?? '') ?></textarea>
          <div class="flex justify-end gap-2">
            <button type="button" @click="editingServiceId = null" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
            <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Save</button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$currentUserId = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    // Additive defense, matching every other POST handler in this codebase.
    if (!can_manage_agency() && !is_partner_agency()) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
    }

    if ($action === 'add_service') {
        $agencyId = is_partner_agency() ? current_agency_id($pdo) : (int)($_POST['agency_id'] ?? 0);
        $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
        $serviceDesc = clean($_POST['description'] ?? '');

        if (!$agencyId) {
            flash_set('error', 'Select a Partner Agency.');
        } elseif ($serviceName === '') {
            flash_set('error', 'Service name is required.');
        } elseif (mb_strlen($serviceName) > 200) {
            flash_set('error', 'Service name must be 200 characters or fewer.');
        } elseif (mb_strlen($serviceDesc) > 500) {
            flash_set('error', 'Description must be 500 characters or fewer.');
        } else {
            $svcStmt = $pdo->prepare(
                "INSERT INTO care_jf_agency_services (agency_id, service_name, description) VALUES (:aid, :n, :d)"
            );
            $svcStmt->execute([':aid' => $agencyId, ':n' => $serviceName, ':d' => $serviceDesc ?: null]);
            $newServiceId = (int)$pdo->lastInsertId();
            audit_log($pdo, $currentUserId, 'CREATE', 'care_jf_agency_services', $newServiceId, "Added service \"$serviceName\"");
            flash_set('success', 'Service added.');
        }
        redirect('services.php');
    } elseif (in_array($action, ['toggle_service', 'edit_service'], true)) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $ownershipWhere = "id = :id";
        $ownershipParams = [':id' => $serviceId];
        if (is_partner_agency()) {
            $ownershipWhere .= " AND agency_id = :aid";
            $ownershipParams[':aid'] = current_agency_id($pdo);
        }
        $ownStmt = $pdo->prepare("SELECT id, status FROM care_jf_agency_services WHERE $ownershipWhere");
        $ownStmt->execute($ownershipParams);
        $svcRow = $ownStmt->fetch();

        if (!$svcRow) {
            flash_set('error', 'Service not found.');
        } elseif ($action === 'toggle_service') {
            $newStatus = $svcRow['status'] === 'Active' ? 'Disabled' : 'Active';
            $pdo->prepare("UPDATE care_jf_agency_services SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $serviceId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_agency_services', $serviceId, "Service $newStatus");
            flash_set('success', "Service $newStatus.");
        } else {
            $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
            $serviceDesc = clean($_POST['description'] ?? '');
            if ($serviceName === '') {
                flash_set('error', 'Service name is required.');
            } elseif (mb_strlen($serviceName) > 200) {
                flash_set('error', 'Service name must be 200 characters or fewer.');
            } elseif (mb_strlen($serviceDesc) > 500) {
                flash_set('error', 'Description must be 500 characters or fewer.');
            } else {
                $pdo->prepare("UPDATE care_jf_agency_services SET service_name = :n, description = :d WHERE id = :id")
                    ->execute([':n' => $serviceName, ':d' => $serviceDesc ?: null, ':id' => $serviceId]);
                audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_agency_services', $serviceId, "Updated service \"$serviceName\"");
                flash_set('success', 'Service updated.');
            }
        }
        redirect('services.php');
    }
}

if (is_partner_agency()) {
    $svcListStmt = $pdo->prepare(
        "SELECT s.*, pa.agency_name FROM care_jf_agency_services s
         JOIN care_jf_partner_agencies pa ON pa.id = s.agency_id
         WHERE s.agency_id = :aid ORDER BY s.service_name"
    );
    $svcListStmt->execute([':aid' => current_agency_id($pdo)]);
} else {
    $svcListStmt = $pdo->query(
        "SELECT s.*, pa.agency_name FROM care_jf_agency_services s
         JOIN care_jf_partner_agencies pa ON pa.id = s.agency_id
         ORDER BY pa.agency_name, s.service_name"
    );
}
$services = $svcListStmt->fetchAll();
$agencyOptions = is_partner_agency() ? [] : active_agencies($pdo);
$canManage = can_manage_agency() || is_partner_agency();

$pageTitle = 'Services';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5" x-data="{ showAddService: false, editingServiceId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Services</h1>
      <p class="text-sm text-slate-500"><?= is_partner_agency() ? 'Manage the services your agency offers Job Fair participants.' : 'Services offered by all participating Partner Agencies.' ?></p>
    </div>
    <?php if ($canManage): ?>
    <button type="button" @click="showAddService = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-plus"></i> Add Service
    </button>
    <?php endif; ?>
  </div>

  <div x-show="showAddService" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <form method="POST" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_service">
      <?php if (!is_partner_agency()): ?>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
        <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">Select a Partner Agency</option>
          <?php foreach ($agencyOptions as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Service Name <span class="text-red-500">*</span></label>
        <input type="text" name="service_name" required maxlength="200" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
        <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" @click="showAddService = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
        <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium">Add</button>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <?php if (!is_partner_agency()): ?><th class="px-4 py-2.5 text-left">Agency</th><?php endif; ?>
          <th class="px-4 py-2.5 text-left">Service Name</th>
          <th class="px-4 py-2.5 text-left">Description</th>
          <th class="px-4 py-2.5 text-left">Status</th>
          <?php if ($canManage): ?><th class="px-4 py-2.5 text-right">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$services): ?>
          <tr><td colspan="<?= (!is_partner_agency() ? 1 : 0) + 3 + ($canManage ? 1 : 0) ?>" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-list-check text-2xl mb-2 block"></i> No services on file.</td></tr>
        <?php endif; ?>
        <?php foreach ($services as $svc): ?>
        <tr>
          <?php if (!is_partner_agency()): ?><td class="px-4 py-3" data-label="Agency"><?= e($svc['agency_name']) ?></td><?php endif; ?>
          <td class="px-4 py-3 font-medium" data-label="Service"><?= e($svc['service_name']) ?></td>
          <td class="px-4 py-3 text-slate-500" data-label="Description"><?= e($svc['description'] ?: '—') ?></td>
          <td class="px-4 py-3" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $svc['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($svc['status']) ?></span>
          </td>
          <?php if ($canManage): ?>
          <td class="px-4 py-3 text-right" data-label="Actions">
            <button type="button" @click="editingServiceId = editingServiceId === <?= (int)$svc['id'] ?> ? null : <?= (int)$svc['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_service">
              <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
              <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $svc['status']==='Active' ? 'Disable' : 'Enable' ?>">
                <i class="fa-solid <?= $svc['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($canManage): ?>
        <tr x-show="editingServiceId === <?= (int)$svc['id'] ?>" x-cloak>
          <td colspan="<?= (!is_partner_agency() ? 1 : 0) + 3 + 1 ?>" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="space-y-2 max-w-md">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit_service">
              <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
              <input type="text" name="service_name" required maxlength="200" value="<?= e($svc['service_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"><?= e($svc['description'] ?? '') ?></textarea>
              <div class="flex justify-end gap-2">
                <button type="button" @click="editingServiceId = null" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
                <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Save</button>
              </div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

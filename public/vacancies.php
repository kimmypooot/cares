<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!can_manage_employment() && !is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this page.</h2>');
}

$pdo = Database::getConnection();
$jobLevels = ['Plantilla Level 1', 'Plantilla Level 2', 'Job Order', 'COS'];
$statusOptions = ['Active', 'Disabled', 'Filled', 'Closed'];
$addStatusOptions = ['Active' => 'Active', 'Disabled' => 'Inactive'];
$scopedAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;

if (is_partner_agency() && !$scopedAgencyId) {
    flash_set('error', 'Your agency record could not be found. Please contact the Administrator.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // Additive defense: re-check the same roles the top-of-file gate
    // already enforces. Should never trigger given that gate, but every
    // POST/state-change handler in this codebase re-checks independently.
    if (!can_manage_employment() && !is_partner_agency()) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this page.</h2>');
    }

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // Actions that target an existing row must have a valid id — otherwise
    // edit reads an undefined $ownerAgencyId and enable/disable/delete
    // would run a no-op WHERE id = 0 while still writing an audit_log entry
    // and flashing success.
    if (in_array($action, ['edit', 'enable', 'disable', 'delete'], true) && $id <= 0) {
        flash_set('error', 'Invalid vacancy.');
        redirect('vacancies.php');
    }

    // Resolve ownership for any action targeting an existing row. Partner
    // Agency's own agency is always server-derived — never a posted value.
    if ($id > 0) {
        $ownerStmt = $pdo->prepare("SELECT agency_id FROM care_jf_job_vacancies WHERE id = :id");
        $ownerStmt->execute([':id' => $id]);
        $ownerAgencyId = (int)($ownerStmt->fetchColumn() ?: 0);
        if (!$ownerAgencyId || (is_partner_agency() && $ownerAgencyId !== $scopedAgencyId)) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to modify this vacancy.</h2>');
        }
    }

    if (in_array($action, ['add', 'edit'], true)) {
        if ($action === 'edit') {
            // A vacancy's owning agency is never changed by an edit — reuse
            // the value already resolved (and ownership-checked) above,
            // regardless of role. This also means the edit form doesn't
            // need an agency_id field at all.
            $agencyId = $ownerAgencyId;
        } else {
            $agencyId = is_partner_agency() ? $scopedAgencyId : (int)($_POST['agency_id'] ?? 0);
        }
        $title = clean($_POST['title'] ?? '') ?: 'Job Available';
        $position = clean($_POST['position'] ?? '');
        $jobLevel = clean($_POST['job_level'] ?? '');
        $salaryGrade = clean($_POST['salary_grade'] ?? '');
        $occupationalOption = clean($_POST['occupational_option'] ?? '');
        $vacantCountRaw = trim((string)($_POST['vacant_count'] ?? ''));
        $status = clean($_POST['status'] ?? 'Active');

        $errors = [];
        if (!$agencyId) $errors[] = 'A Partner Agency is required.';
        if ($position === '') $errors[] = 'Position is required.';
        if (!in_array($jobLevel, $jobLevels, true)) $errors[] = 'Select a valid Job Level.';
        if (!in_array($status, $statusOptions, true)) $errors[] = 'Select a valid status.';
        if ($vacantCountRaw === '' || !ctype_digit($vacantCountRaw)) {
            $errors[] = 'No. of Vacant Positions must be a non-negative whole number.';
            $vacantCount = 0;
        } else {
            $vacantCount = (int)$vacantCountRaw;
        }

        if ($errors) {
            flash_set('error', implode(' ', $errors));
        } elseif ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_job_vacancies (agency_id, title, position, job_level, salary_grade, occupational_option, vacant_count, status)
                 VALUES (:agid, :title, :pos, :lvl, :sal, :occ, :vac, :status)"
            );
            $stmt->execute([
                ':agid' => $agencyId, ':title' => $title, ':pos' => $position, ':lvl' => $jobLevel,
                ':sal' => $salaryGrade ?: null, ':occ' => $occupationalOption ?: null, ':vac' => $vacantCount, ':status' => $status,
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_CREATE', 'care_jf_job_vacancies', $newId, "Created vacancy: {$position}");
            flash_set('success', 'Job vacancy added successfully.');
        } else {
            $pdo->prepare(
                "UPDATE care_jf_job_vacancies SET title=:title, position=:pos, job_level=:lvl, salary_grade=:sal,
                 occupational_option=:occ, vacant_count=:vac, status=:status WHERE id=:id"
            )->execute([
                ':title' => $title, ':pos' => $position, ':lvl' => $jobLevel, ':sal' => $salaryGrade ?: null,
                ':occ' => $occupationalOption ?: null, ':vac' => $vacantCount, ':status' => $status, ':id' => $id,
            ]);
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_UPDATE', 'care_jf_job_vacancies', $id, "Updated vacancy: {$position}");
            flash_set('success', 'Job vacancy updated successfully.');
        }
    } elseif (in_array($action, ['enable', 'disable'], true)) {
        $newStatus = $action === 'enable' ? 'Active' : 'Disabled';
        $pdo->prepare("UPDATE care_jf_job_vacancies SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $id]);
        audit_log($pdo, (int)current_user()['id'], $action === 'enable' ? 'VACANCY_ENABLE' : 'VACANCY_DISABLE', 'care_jf_job_vacancies', $id, "Vacancy {$newStatus}");
        flash_set('success', "Vacancy {$newStatus}.");
    } elseif ($action === 'delete') {
        // Administrator can delete any vacancy; a Partner Agency can delete
        // their own — already ownership-verified above ($ownerAgencyId check,
        // lines 47-55), so reaching this branch as a Partner Agency already
        // means the vacancy belongs to their own agency.
        if (!can_delete() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to delete this vacancy.</h2>');
        }
        $historyStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_employment_records WHERE vacancy_id = :id");
        $historyStmt->execute([':id' => $id]);
        if ((int)$historyStmt->fetchColumn() > 0) {
            flash_set('error', 'This vacancy has hire history linked to it and cannot be deleted. Disable it instead.');
        } else {
            $pdo->prepare("DELETE FROM care_jf_job_vacancies WHERE id = :id")->execute([':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_DELETE', 'care_jf_job_vacancies', $id, 'Job vacancy deleted');
            flash_set('success', 'Job vacancy deleted.');
        }
    }
    redirect('vacancies.php');
}

$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');
$where = [];
$params = [];

if ($scopedAgencyId !== null) {
    $where[] = 'jv.agency_id = :agid';
    $params[':agid'] = $scopedAgencyId;
}
if ($search !== '') {
    $where[] = '(jv.position LIKE :s1 OR jv.title LIKE :s2)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 'jv.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT jv.*, pa.agency_name
     FROM care_jf_job_vacancies jv
     JOIN care_jf_partner_agencies pa ON pa.id = jv.agency_id
     $whereSql ORDER BY jv.created_at DESC"
);
$stmt->execute($params);
$vacancies = $stmt->fetchAll();

$agencyOptions = can_manage_employment() ? active_agencies($pdo) : [];

// Print summary: all vacancies grouped by agency (Admin/Employee only —
// a Partner Agency's own list is already scoped to a single agency).
$vacanciesByAgency = [];
if ($scopedAgencyId === null) {
    foreach ($vacancies as $v) {
        $vacanciesByAgency[$v['agency_name']][] = $v;
    }
    ksort($vacanciesByAgency);
}

$pageTitle = 'Job Vacancies';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5" x-data="{ showCreate: false, editingId: null, deletingId: null, deletingPosition: '' }">
  <div class="flex items-center justify-between flex-wrap gap-3 print:hidden">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Job Vacancies</h1>
      <p class="text-sm text-slate-500"><?= $scopedAgencyId !== null ? 'Manage your agency\'s job openings.' : 'Manage job openings across all Partner Agencies.' ?></p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <?php if ($scopedAgencyId === null): ?>
      <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-print"></i> Print
      </button>
      <?php endif; ?>
      <button type="button" @click="showCreate = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
        <i class="fa-solid fa-plus"></i> Add Vacancy
      </button>
    </div>
  </div>

  <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex flex-wrap gap-3 print:hidden">
    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search position or title..." class="flex-1 min-w-[200px] rounded-lg border border-slate-300 text-sm py-2 px-3">
    <select name="status" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
      <option value="All" <?= $statusFilter==='All'?'selected':'' ?>>All Status</option>
      <?php foreach ($statusOptions as $opt): ?>
        <option value="<?= e($opt) ?>" <?= $statusFilter===$opt?'selected':'' ?>><?= e($opt) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium">Search</button>
    <a href="vacancies.php" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Reset</a>
  </form>

  <!-- Add form: real modal, does not close on outside click -->
  <div x-show="showCreate" x-cloak
       class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"
       @keydown.escape.window="showCreate = false">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Add Vacancy</h2>
        <button type="button" @click="showCreate = false" class="text-slate-400 hover:text-slate-600" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form method="POST" onsubmit="return validateForm(this) && confirm('Save this job vacancy?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid sm:grid-cols-2 gap-4">
          <?php if (can_manage_employment()): ?>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
            <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">Select a Partner Agency</option>
              <?php foreach ($agencyOptions as $ag): ?>
                <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Position <span class="text-red-500">*</span></label>
            <input type="text" name="position" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Salary Grade / Pay Grade</label>
            <input type="text" name="salary_grade" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Job Level <span class="text-red-500">*</span></label>
            <select name="job_level" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">Select</option>
              <?php foreach ($jobLevels as $lvl): ?>
                <option><?= e($lvl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">No. of Vacancies <span class="text-red-500">*</span></label>
            <input type="number" name="vacant_count" value="1" min="1" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Position Status</label>
            <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <?php foreach ($addStatusOptions as $value => $label): ?>
                <option value="<?= e($value) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="showCreate = false" class="px-5 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Save Vacancy</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete confirmation modal: real modal, does not close on outside click -->
  <div x-show="deletingId !== null" x-cloak
       class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"
       @keydown.escape.window="deletingId = null">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
      <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-triangle-exclamation text-red-500 mr-1"></i> Delete Job Vacancy?</h3>
      <p class="text-sm text-slate-600 mb-5">Are you sure you want to delete <strong x-text="deletingPosition"></strong>? This action may affect vacancy and application records.</p>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" :value="deletingId">
        <div class="flex justify-end gap-2">
          <button type="button" @click="deletingId = null" class="px-5 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold">Delete</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($scopedAgencyId === null): ?>
  <!-- Print-only summary: grouped by agency. Hidden on screen, shown only when printing. -->
  <div class="hidden print:block">
    <div class="text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Job Vacancies Summary — By Partner Agency</p>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>
    <?php if (!$vacanciesByAgency): ?>
      <p class="text-center text-slate-400 py-6">No job vacancies found.</p>
    <?php endif; ?>
    <?php foreach ($vacanciesByAgency as $agencyName => $agencyVacancies): ?>
      <div class="px-5 py-3">
        <h3 class="text-sm font-bold text-slate-800 uppercase mb-2"><?= e($agencyName) ?></h3>
        <table class="min-w-full text-xs border border-slate-300 mb-4">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-2 py-1.5 text-left border border-slate-300">Position</th>
              <th class="px-2 py-1.5 text-left border border-slate-300">Job Level</th>
              <th class="px-2 py-1.5 text-left border border-slate-300">Salary Grade</th>
              <th class="px-2 py-1.5 text-left border border-slate-300">Vacant</th>
              <th class="px-2 py-1.5 text-left border border-slate-300">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agencyVacancies as $av): ?>
              <tr>
                <td class="px-2 py-1.5 border border-slate-300"><?= e($av['position']) ?></td>
                <td class="px-2 py-1.5 border border-slate-300"><?= e($av['job_level']) ?></td>
                <td class="px-2 py-1.5 border border-slate-300"><?= e($av['salary_grade'] ?: '—') ?></td>
                <td class="px-2 py-1.5 border border-slate-300"><?= (int)$av['vacant_count'] ?></td>
                <td class="px-2 py-1.5 border border-slate-300"><?= e($av['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
          <tr>
            <?php if ($scopedAgencyId === null): ?><th class="px-4 py-2.5 text-left">Agency</th><?php endif; ?>
            <th class="px-4 py-2.5 text-left">Position</th>
            <th class="px-4 py-2.5 text-left">Job Level</th>
            <th class="px-4 py-2.5 text-left">Vacant</th>
            <th class="px-4 py-2.5 text-left">Status</th>
            <th class="px-4 py-2.5 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$vacancies): ?>
            <tr><td colspan="<?= $scopedAgencyId === null ? 6 : 5 ?>" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No job vacancies found.</td></tr>
          <?php endif; ?>
          <?php foreach ($vacancies as $v): ?>
          <tr>
            <?php if ($scopedAgencyId === null): ?><td class="px-4 py-3" data-label="Agency"><?= e($v['agency_name']) ?></td><?php endif; ?>
            <td class="px-4 py-3 font-medium" data-label="Position"><?= e($v['position']) ?><p class="text-xs text-slate-400 font-normal"><?= e($v['title']) ?></p></td>
            <td class="px-4 py-3" data-label="Job Level"><?= e($v['job_level']) ?></td>
            <td class="px-4 py-3" data-label="Vacant"><?= (int)$v['vacant_count'] ?></td>
            <td class="px-4 py-3" data-label="Status">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $v['status']==='Active' ? 'bg-green-100 text-green-700' : ($v['status']==='Filled' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') ?>"><?= e($v['status']) ?></span>
            </td>
            <td class="px-4 py-3 text-right" data-label="Actions">
              <button type="button" @click="editingId = editingId === <?= (int)$v['id'] ?> ? null : <?= (int)$v['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <input type="hidden" name="action" value="<?= $v['status']==='Active' ? 'disable' : 'enable' ?>">
                <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $v['status']==='Active' ? 'Disable' : 'Enable' ?>">
                  <i class="fa-solid <?= $v['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                </button>
              </form>
              <?php if (can_delete() || is_partner_agency()): ?>
              <button type="button" @click="deletingId = <?= (int)$v['id'] ?>; deletingPosition = <?= e(json_encode($v['position'])) ?>" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <!-- Inline edit row -->
          <tr x-show="editingId === <?= (int)$v['id'] ?>" x-cloak>
            <td colspan="<?= $scopedAgencyId === null ? 6 : 5 ?>" class="px-4 py-4 bg-slate-50">
              <form method="POST" class="grid sm:grid-cols-3 gap-3" onsubmit="return validateForm(this);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Position</label>
                  <input type="text" name="position" required value="<?= e($v['position']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Job Level</label>
                  <select name="job_level" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                    <?php foreach ($jobLevels as $lvl): ?>
                      <option <?= $v['job_level']===$lvl?'selected':'' ?>><?= e($lvl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Title</label>
                  <input type="text" name="title" value="<?= e($v['title']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Salary/Pay Grade</label>
                  <input type="text" name="salary_grade" value="<?= e($v['salary_grade'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Occupational Option</label>
                  <input type="text" name="occupational_option" value="<?= e($v['occupational_option'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">No. of Vacant Positions</label>
                  <input type="number" name="vacant_count" min="0" value="<?= (int)$v['vacant_count'] ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                  <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                    <?php foreach ($statusOptions as $opt): ?>
                      <option <?= $v['status']===$opt?'selected':'' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="sm:col-span-3 flex gap-2">
                  <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Save</button>
                  <button type="button" @click="editingId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
                </div>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

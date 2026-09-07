<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
require_login();
// Partner Agency accounts don't get this module at all (not just hidden
// from their sidebar) — they see their own agency info via My Partner
// Agency instead. POST actions below are already role-checked per-action,
// but page rendering needs its own gate too.
if (is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — Partner Agency accounts do not have access to this page. See My Partner Agency instead.</h2>');
}

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (in_array($action, ['enable', 'disable'], true)) {
        require_role(['Administrator', 'Employee']);
        $newStatus = $action === 'enable' ? 'Active' : 'Disabled';
        $pdo->prepare("UPDATE care_jf_partner_agencies SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $id]);
        audit_log($pdo, (int)current_user()['id'], strtoupper($action), 'care_jf_partner_agencies', $id, "Partner Agency {$newStatus}");
        flash_set('success', "Partner Agency {$newStatus}.");
    } elseif ($action === 'delete') {
        require_role(['Administrator']);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_employment_records WHERE agency_id = :id");
        $countStmt->execute([':id' => $id]);
        $userCountStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_users WHERE agency_id = :id");
        $userCountStmt->execute([':id' => $id]);
        $vacancyCountStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_job_vacancies WHERE agency_id = :id");
        $vacancyCountStmt->execute([':id' => $id]);
        if ((int)$countStmt->fetchColumn() > 0) {
            flash_set('error', 'This agency has employment history linked to it and cannot be deleted. Disable it instead to preserve historical records.');
        } elseif ((int)$userCountStmt->fetchColumn() > 0) {
            flash_set('error', 'This agency still has a Partner Agency account linked to it and cannot be deleted. Delete or reassign that account first, or disable the agency instead.');
        } elseif ((int)$vacancyCountStmt->fetchColumn() > 0) {
            flash_set('error', 'This agency has job vacancies linked to it and cannot be deleted. Disable it instead.');
        } else {
            $pdo->prepare("DELETE FROM care_jf_partner_agencies WHERE id = :id")->execute([':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'DELETE', 'care_jf_partner_agencies', $id, 'Partner Agency deleted');
            flash_set('success', 'Partner Agency deleted.');
        }
    }
    redirect('partner-agency.php');
}

$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(agency_name LIKE :s1 OR address LIKE :s2)';
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT pa.*, (SELECT COUNT(*) FROM care_jf_employment_records er WHERE er.agency_id = pa.id) AS record_count
                        FROM care_jf_partner_agencies pa $whereSql ORDER BY agency_name");
$stmt->execute($params);
$agencies = $stmt->fetchAll();

// Excel (.xlsx) export
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $metaLines = [
        'Civil Service Commission RO VIII',
        'Partner Agencies',
        'Generated: ' . date('F j, Y g:i A'),
        'Total Records: ' . count($agencies),
    ];
    $headers = ['Agency / Office Name', 'Address', 'Employment Records', 'Status'];
    $exportRows = [];
    foreach ($agencies as $ag) {
        $exportRows[] = [$ag['agency_name'], $ag['address'], (int)$ag['record_count'], $ag['status']];
    }
    stream_xlsx('Partner_Agencies_' . date('Ymd') . '.xlsx', 'Partner Agencies', $metaLines, $headers, $exportRows);
}

$pageTitle = 'Partner Agency';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5">
  <div class="flex items-center justify-between flex-wrap gap-3 print:hidden">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Partner Agency</h1>
      <p class="text-sm text-slate-500">Manage the agencies/offices applicants can be hired into.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-file-excel"></i> Export Excel
      </a>
      <button onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-print"></i> Print
      </button>
      <?php if (can_manage_agency()): ?>
      <a href="partner-agency-form.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
        <i class="fa-solid fa-plus"></i> Add Partner Agency
      </a>
      <?php endif; ?>
    </div>
  </div>

  <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex flex-wrap gap-3 print:hidden">
    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search agency name or address..." class="flex-1 min-w-[200px] rounded-lg border border-slate-300 text-sm py-2 px-3">
    <select name="status" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
      <option value="All" <?= $statusFilter==='All'?'selected':'' ?>>All Status</option>
      <option value="Active" <?= $statusFilter==='Active'?'selected':'' ?>>Active</option>
      <option value="Disabled" <?= $statusFilter==='Disabled'?'selected':'' ?>>Disabled</option>
    </select>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium">Search</button>
    <a href="partner-agency.php" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Reset</a>
  </form>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:border-0 print:shadow-none">
    <!-- Print-only letterhead: hidden on screen, shown only when printing. -->
    <div class="hidden print:block text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Partner Agencies</p>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase print:bg-transparent">
          <tr>
            <th class="px-4 py-2.5 text-left">Agency / Office Name</th>
            <th class="px-4 py-2.5 text-left">Contact Person</th>
            <th class="px-4 py-2.5 text-left">Contact No</th>
            <th class="px-4 py-2.5 text-left">Email</th>
            <th class="px-4 py-2.5 text-left">Employment Records</th>
            <th class="px-4 py-2.5 text-left">Status</th>
            <th class="px-4 py-2.5 text-right print:hidden">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$agencies): ?>
            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-building text-2xl mb-2 block"></i> No partner agencies found.</td></tr>
          <?php endif; ?>
          <?php foreach ($agencies as $ag): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium" data-label="Agency"><?= e($ag['agency_name']) ?><p class="text-xs text-slate-400 font-normal"><?= e($ag['address']) ?></p></td>
            <td class="px-4 py-3 text-slate-500" data-label="Contact Person"><?= e($ag['contact_person'] ?: '—') ?></td>
            <td class="px-4 py-3 text-slate-500" data-label="Contact No"><?= e($ag['contact_no'] ?: '—') ?></td>
            <td class="px-4 py-3 text-slate-500" data-label="Email"><?= e($ag['email'] ?: '—') ?></td>
            <td class="px-4 py-3" data-label="Records"><?= (int)$ag['record_count'] ?></td>
            <td class="px-4 py-3" data-label="Status">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $ag['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($ag['status']) ?></span>
            </td>
            <td class="px-4 py-3 text-right print:hidden" data-label="Actions">
              <?php if (can_manage_agency()): ?>
              <a href="partner-agency-form.php?id=<?= (int)$ag['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                <input type="hidden" name="action" value="<?= $ag['status']==='Active' ? 'disable' : 'enable' ?>">
                <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $ag['status']==='Active' ? 'Disable' : 'Enable' ?>">
                  <i class="fa-solid <?= $ag['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if (can_delete()): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="button" data-confirm-delete="<?= e($ag['agency_name']) ?>" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

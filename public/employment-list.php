<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
// Server-side enforcement: Viewer may look at this page (read-only), but has
// no write actions available anywhere below. Manage actions are gated per-button.
// Partner Agency accounts don't get this module at all (not just hidden from
// their sidebar) — they see read-only employment history via an applicant's
// own profile page instead.
if (is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — Partner Agency accounts do not have access to the Employment module.</h2>');
}

$pdo = Database::getConnection();

// ---------------------------------------------------------------------
// Enable / Disable / Delete actions (POST only, role-checked server-side)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $recordId = (int)($_POST['record_id'] ?? 0);

    if (in_array($action, ['enable', 'disable'], true)) {
        require_role(['Administrator', 'Employee']);
        $newStatus = $action === 'enable' ? 'Active' : 'Disabled';
        $stmt = $pdo->prepare("UPDATE care_jf_employment_records SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $newStatus, ':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], strtoupper($action), 'care_jf_employment_records', $recordId, "Employment record " . strtolower($newStatus));
        flash_set('success', "Employment record {$newStatus}.");
    } elseif ($action === 'delete') {
        require_role(['Administrator']);
        $stmt = $pdo->prepare("DELETE FROM care_jf_employment_records WHERE id = :id");
        $stmt->execute([':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'care_jf_employment_records', $recordId, 'Employment record deleted');
        flash_set('success', 'Employment record deleted.');
    }
    redirect('employment-list.php');
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$search = clean($_GET['search'] ?? '');
$statusFilter = clean($_GET['status'] ?? 'All');
$classFilter = clean($_GET['classification'] ?? 'All');
$agencyFilter = clean($_GET['agency'] ?? 'All');
[$limit, $offset, $page] = paginate_params();

$where = ['a.is_deleted = 0'];
$params = [];

if ($search !== '') {
    $where[] = "(a.applicant_code LIKE :search1 OR CONCAT(a.last_name,' ',a.first_name) LIKE :search2
                 OR er.agency_company_name LIKE :search3 OR pa.agency_name LIKE :search4)";
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
    $params[':search4'] = '%' . $search . '%';
}
if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = 'er.status = :status';
    $params[':status'] = $statusFilter;
}
if ($classFilter !== '' && $classFilter !== 'All') {
    $where[] = 'er.employment_status = :class';
    $params[':class'] = $classFilter;
}
if ($agencyFilter !== '' && $agencyFilter !== 'All') {
    // Match against the same COALESCE used for display, so this covers both
    // records linked to a Partner Agency and legacy free-text agency names.
    $where[] = "COALESCE(pa.agency_name, er.agency_company_name) = :agency";
    $params[':agency'] = $agencyFilter;
}

$whereSql = implode(' AND ', $where);

// Distinct list of agency names actually referenced by employment records
// (not just the Partner Agency table), so the filter also covers legacy
// free-text agencies and never shows an agency with zero records.
$agencyOptions = $pdo->query(
    "SELECT DISTINCT COALESCE(pa.agency_name, er.agency_company_name) AS agency_name
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     ORDER BY agency_name"
)->fetchAll(PDO::FETCH_COLUMN);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM care_jf_employment_records er
     JOIN care_jf_applicants a ON a.id = er.applicant_id
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT er.*, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
            COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name
     FROM care_jf_employment_records er
     JOIN care_jf_applicants a ON a.id = er.applicant_id
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE $whereSql
     ORDER BY er.date_hired DESC, er.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();
$pages = (int)ceil($total / max($limit, 1));

$pageTitle = 'Employment';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5">
  <div class="flex items-center justify-between flex-wrap gap-3 print:hidden">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Employment Records</h1>
      <p class="text-sm text-slate-500">View, add, and manage applicant employment history.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <button onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-print"></i> Print
      </button>
      <?php if (can_manage_employment()): ?>
      <a href="applicants.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
        <i class="fa-solid fa-plus"></i> Add Employment Record
      </a>
      <?php endif; ?>
    </div>
  </div>
  <p class="text-xs text-slate-400 -mt-3 print:hidden">To add a record, open an applicant's profile and use "Add Employment Record" there.</p>

  <!-- Filters -->
  <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex flex-wrap items-center gap-3 print:hidden">
    <div class="relative flex-1 min-w-[200px]">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
      <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search applicant, agency..."
             class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm">
    </div>
    <select name="status" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
      <option value="All" <?= $statusFilter==='All'?'selected':'' ?>>All Record Status</option>
      <option value="Active" <?= $statusFilter==='Active'?'selected':'' ?>>Active</option>
      <option value="Disabled" <?= $statusFilter==='Disabled'?'selected':'' ?>>Disabled</option>
    </select>
    <select name="classification" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
      <option value="All" <?= $classFilter==='All'?'selected':'' ?>>All Classifications</option>
      <?php foreach (['Job Order','Temporary','COS','Permanent','Casual','Other','Hired'] as $opt): ?>
        <option <?= $classFilter===$opt?'selected':'' ?>><?= e($opt) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="agency" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
      <option value="All" <?= $agencyFilter==='All'?'selected':'' ?>>All Agencies</option>
      <?php foreach ($agencyOptions as $agName): ?>
        <option value="<?= e($agName) ?>" <?= $agencyFilter===$agName?'selected':'' ?>><?= e($agName) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="flex gap-2 ml-auto">
      <a href="employment-list.php" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Reset</a>
      <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium">Search</button>
    </div>
  </form>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:border-0 print:shadow-none">
    <!-- Print-only letterhead: hidden on screen, shown only when printing. -->
    <div class="hidden print:block text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Employment Records</p>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase print:bg-transparent">
          <tr>
            <th class="px-4 py-2.5 text-left">Applicant</th>
            <th class="px-4 py-2.5 text-left">Agency/Company</th>
            <th class="px-4 py-2.5 text-left">Date Hired</th>
            <th class="px-4 py-2.5 text-left">Classification</th>
            <th class="px-4 py-2.5 text-left">Current?</th>
            <th class="px-4 py-2.5 text-left">Record Status</th>
            <th class="px-4 py-2.5 text-right print:hidden">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$records): ?>
            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No employment records found.
            </td></tr>
          <?php endif; ?>
          <?php foreach ($records as $r): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3" data-label="Applicant">
              <a href="applicant-view.php?id=<?= (int)$r['applicant_id'] ?>" class="text-brand-700 font-medium hover:underline"><?= e(full_name($r)) ?></a>
              <p class="text-xs text-slate-400"><?= e($r['applicant_code']) ?></p>
            </td>
            <td class="px-4 py-3" data-label="Agency"><?= e($r['agency_display_name']) ?></td>
            <td class="px-4 py-3" data-label="Date Hired"><?= format_date($r['date_hired']) ?></td>
            <td class="px-4 py-3 uppercase" data-label="Classification"><?= e($r['employment_status']) ?></td>
            <td class="px-4 py-3" data-label="Current">
              <?php if ($r['is_current']): ?><span class="text-[10px] font-semibold text-green-700 bg-green-100 px-1.5 py-0.5 rounded-full">CURRENT</span><?php endif; ?>
            </td>
            <td class="px-4 py-3" data-label="Status">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $r['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($r['status']) ?></span>
            </td>
            <td class="px-4 py-3 text-right print:hidden" data-label="Actions">
              <?php if (can_manage_employment()): ?>
              <a href="employment-form.php?applicant_id=<?= (int)$r['applicant_id'] ?>&action=edit&record_id=<?= (int)$r['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="<?= $r['status']==='Active' ? 'disable' : 'enable' ?>">
                <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $r['status']==='Active' ? 'Disable' : 'Enable' ?>">
                  <i class="fa-solid <?= $r['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if (can_delete()): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="button" data-confirm-delete="this employment record" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 flex-wrap gap-3 print:hidden">
      <p class="text-xs text-slate-500">Showing page <?= $page ?> of <?= max($pages,1) ?> (<?= number_format($total) ?> records)</p>
      <div class="flex gap-1">
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page<=1?'pointer-events-none opacity-40':'' ?>">Previous</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($pages,$page+1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page>=$pages?'pointer-events-none opacity-40':'' ?>">Next</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

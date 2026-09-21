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

    // Tracking rows (For Review/Withdrawn/Superseded) are managed
    // exclusively through applicant-view.php's Tag for Review / Confirm
    // Hired / Withdraw actions — refuse to Enable/Disable/Delete one here,
    // even via a crafted POST with a guessed record_id that bypassed the
    // listing query's filter.
    $stateStmt = $pdo->prepare("SELECT employment_status FROM care_jf_employment_records WHERE id = :id");
    $stateStmt->execute([':id' => $recordId]);
    $existingStatus = $stateStmt->fetchColumn();
    if (in_array($existingStatus, ['For Review', 'Withdrawn', 'Superseded'], true)) {
        flash_set('error', 'This record is part of an in-progress or closed application review and cannot be managed here.');
        redirect('employment-list.php');
    }

    if (in_array($action, ['enable', 'disable'], true)) {
        require_role(['Administrator', 'Employee']);
        $newStatus = $action === 'enable' ? 'Active' : 'Disabled';
        $stmt = $pdo->prepare("UPDATE care_jf_employment_records SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $newStatus, ':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], strtoupper($action), 'care_jf_employment_records', $recordId, "Employment record " . strtolower($newStatus));
        flash_set('success', "Employment record {$newStatus}.");
    } elseif ($action === 'delete') {
        require_role(['Administrator']);

        // If this is a confirmed hire tied to a Job Vacancy that was
        // auto-marked Filled, reopen it before deleting — otherwise it
        // stays Filled despite genuinely having an opening again
        // (vacant_count itself is never touched; Filled is derived from a
        // live COUNT of Hired records). Only Delete reopens it (not
        // Disable/Enable) — see applicant-view.php's delete_employment
        // action for the same rule and its rationale.
        $vacancyToRestore = null;
        if ($existingStatus === 'Hired') {
            $vacStmt = $pdo->prepare("SELECT vacancy_id FROM care_jf_employment_records WHERE id = :id");
            $vacStmt->execute([':id' => $recordId]);
            $vacancyToRestore = $vacStmt->fetchColumn();
        }

        $pdo->beginTransaction();
        try {
            if ($vacancyToRestore) {
                $reopenStmt = $pdo->prepare(
                    "UPDATE care_jf_job_vacancies SET status = 'Active' WHERE id = :id AND status = 'Filled'"
                );
                $reopenStmt->execute([':id' => $vacancyToRestore]);
                if ($reopenStmt->rowCount() > 0) {
                    audit_log($pdo, (int)current_user()['id'], 'VACANCY_RESTORE', 'care_jf_job_vacancies', (int)$vacancyToRestore,
                        'Vacancy reopened (hire record deleted)');
                }
            }
            $stmt = $pdo->prepare("DELETE FROM care_jf_employment_records WHERE id = :id");
            $stmt->execute([':id' => $recordId]);
            audit_log($pdo, (int)current_user()['id'], 'DELETE', 'care_jf_employment_records', $recordId, 'Employment record deleted');
            $pdo->commit();
            flash_set('success', 'Employment record deleted.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Delete employment record failed: ' . $e->getMessage());
            flash_set('error', 'Deleting this record failed due to a system error. Please try again.');
        }
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

// Tracking rows (For Review/Withdrawn/Superseded — see CLAUDE.md's note
// on care_jf_employment_records) are managed exclusively through
// applicant-view.php's Tag for Review / Confirm Hired / Withdraw actions,
// not through this generic list. Excluded here so they can't be reached
// and silently mutated via the Edit/Enable/Disable/Delete actions below.
$where = ['a.is_deleted = 0', "er.employment_status NOT IN ('For Review', 'Withdrawn', 'Superseded')"];
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

// Distinct list of agency names with at least one confirmed Hired
// employment record (not just any employment_records row — a For
// Review/Withdrawn/Superseded tracking row, or a Job Order/Temporary/
// etc. classification, doesn't count) — so the filter never lists an
// agency with zero actual hires. Also not limited to the Partner
// Agency table, so it still covers legacy free-text agencies.
$agencyOptions = $pdo->query(
    "SELECT DISTINCT COALESCE(pa.agency_name, er.agency_company_name) AS agency_name
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE er.employment_status = 'Hired'
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
  <div class="print:hidden">
    <h1 class="text-2xl font-bold text-slate-800">Employment Records</h1>
    <p class="text-sm text-slate-500">View, add, and manage applicant employment history.</p>
  </div>

  <!-- Employment Actions, Search & Filters -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 print:hidden">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Employment Actions</h2>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <div class="flex flex-col sm:flex-row gap-2">
        <?php if (can_manage_employment()): ?>
        <a href="applicants.php" class="inline-flex items-center justify-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
          <i class="fa-solid fa-plus"></i> Add Employment Record
        </a>
        <?php endif; ?>
      </div>
      <a href="api/employment-export.php?<?= e(http_build_query(['search' => $search, 'status' => $statusFilter, 'classification' => $classFilter, 'agency' => $agencyFilter])) ?>"
         class="inline-flex items-center justify-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-file-excel"></i> Export to Excel
      </a>
    </div>
    <p class="text-xs text-slate-400 mt-2">To add a record, open an applicant's profile and use "Add Employment Record" there.</p>

    <hr class="my-4 border-slate-100">

    <h2 class="text-sm font-semibold text-slate-700 mb-3">Search Employment</h2>
    <form method="GET" class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search applicant, agency..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm">
      </div>
      <select name="status" class="rounded-lg border border-slate-300 text-sm py-2 px-3 sm:w-48">
        <option value="All" <?= $statusFilter==='All'?'selected':'' ?>>All Record Status</option>
        <option value="Active" <?= $statusFilter==='Active'?'selected':'' ?>>Active</option>
        <option value="Disabled" <?= $statusFilter==='Disabled'?'selected':'' ?>>Disabled</option>
      </select>
      <select name="classification" class="rounded-lg border border-slate-300 text-sm py-2 px-3 sm:w-48">
        <option value="All" <?= $classFilter==='All'?'selected':'' ?>>All Classifications</option>
        <?php foreach (['Job Order','Temporary','COS','Permanent','Casual','Other','Hired'] as $opt): ?>
          <option <?= $classFilter===$opt?'selected':'' ?>><?= e($opt) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="agency" class="rounded-lg border border-slate-300 text-sm py-2 px-3 sm:w-48">
        <option value="All" <?= $agencyFilter==='All'?'selected':'' ?>>All Agencies</option>
        <?php foreach ($agencyOptions as $agName): ?>
          <option value="<?= e($agName) ?>" <?= $agencyFilter===$agName?'selected':'' ?>><?= e($agName) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium">Search</button>
    </form>
    <div class="flex justify-end mt-3">
      <a href="employment-list.php" class="text-sm text-slate-500 hover:text-slate-700 font-medium">
        <i class="fa-solid fa-rotate-left mr-1"></i> Reset Filters
      </a>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden print:border-0 print:shadow-none">
    <!-- Print-only letterhead: hidden on screen, shown only when printing. -->
    <div class="hidden print:block text-center px-5 pt-6 pb-4">
      <img src="assets/images/csc-logo.png" alt="CSC Logo" width="64" height="64" class="h-16 w-16 object-contain mx-auto mb-2">
      <p class="text-lg font-bold text-slate-900 uppercase tracking-wide">Civil Service Commission RO VIII</p>
      <p class="text-base font-semibold text-slate-800 mt-1">Employment Records</p>
      <p class="text-xs text-slate-500 mt-1">Printed on <?= date('F j, Y g:i A') ?></p>
      <hr class="mt-4 border-slate-300">
    </div>

    <div class="w-full min-w-0 overflow-x-auto overflow-y-auto max-h-[65vh] print:max-h-none print:overflow-visible">
      <table class="min-w-max w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide print:bg-transparent sticky top-0 z-10 print:static">
          <tr>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Applicant</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Agency/Company</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Date Hired</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Classification</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Current?</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Record Status</th>
            <th class="px-4 py-2.5 text-right whitespace-nowrap min-w-[90px] print:hidden">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
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
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $r['status']==='Active' ? badge_class('success') : badge_class('neutral') ?>"><?= e($r['status']) ?></span>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap min-w-[90px] print:hidden" data-label="Actions">
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
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex gap-1">
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page<=1?'pointer-events-none opacity-40':'' ?>">Previous</a>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($pages,$page+1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page>=$pages?'pointer-events-none opacity-40':'' ?>">Next</a>
        </div>
        <form method="GET" class="flex items-center gap-1">
          <?php foreach ($_GET as $paramName => $paramValue): if ($paramName === 'page' || !is_scalar($paramValue)) continue; ?>
          <input type="hidden" name="<?= e($paramName) ?>" value="<?= e((string)$paramValue) ?>">
          <?php endforeach; ?>
          <label class="sr-only" for="employment-go-to-page">Go to page</label>
          <input id="employment-go-to-page" type="number" name="page" min="1" max="<?= max($pages,1) ?>" value="<?= $page ?>"
                 class="w-20 rounded-lg border border-slate-300 text-sm px-2 py-1.5">
          <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Go</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

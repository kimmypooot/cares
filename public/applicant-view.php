<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = :id AND is_deleted = 0");
$stmt->execute([':id' => $id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    csrf_require();

    if ($action === 'delete') {
        require_role(['Administrator']);
        $pdo->prepare("UPDATE applicants SET is_deleted = 1 WHERE id = :id")->execute([':id' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'applicants', $id, "Deleted applicant {$applicant['applicant_code']}");
        flash_set('success', 'Applicant record deleted.');
        redirect('applicants.php');
    } elseif (in_array($action, ['enable_employment', 'disable_employment'], true)) {
        require_role(['Administrator', 'Employee']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $newStatus = $action === 'enable_employment' ? 'Active' : 'Disabled';
        $pdo->prepare("UPDATE employment_records SET status = :s WHERE id = :id AND applicant_id = :aid")
            ->execute([':s' => $newStatus, ':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], strtoupper(str_replace('_employment', '', $action)), 'employment_records', $recordId, "Employment record {$newStatus}");
        flash_set('success', "Employment record {$newStatus}.");
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'delete_employment') {
        require_role(['Administrator']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $pdo->prepare("DELETE FROM employment_records WHERE id = :id AND applicant_id = :aid")
            ->execute([':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'employment_records', $recordId, 'Employment record deleted');
        flash_set('success', 'Employment record deleted.');
        redirect('applicant-view.php?id=' . $id);
    }
}

$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name
     FROM employment_records er
     LEFT JOIN partner_agencies pa ON pa.id = er.agency_id
     WHERE er.applicant_id = :id ORDER BY er.date_hired DESC, er.id DESC"
);
$empStmt->execute([':id' => $id]);
$employmentRecords = $empStmt->fetchAll();

$status = current_employment_status($pdo, $id);

$pageTitle = 'Applicant Profile';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto">
  <a href="applicants.php" class="text-sm text-slate-500 hover:text-slate-700"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Applicants</a>

  <div class="flex items-start justify-between flex-wrap gap-3 mt-3 mb-6">
    <div>
      <h1 class="text-2xl font-bold text-slate-800"><?= e(full_name($applicant)) ?></h1>
      <p class="text-sm text-slate-500">Applicant ID: <span class="font-medium text-brand-700"><?= e($applicant['applicant_code']) ?></span>
        &middot; Registered <?= format_date($applicant['created_at']) ?>
        &middot; Source: <?= e($applicant['source'] ?? 'Internal') ?></p>
    </div>
    <div class="flex gap-2 flex-wrap print:hidden">
      <?php if (can_edit()): ?>
      <a href="applicant-edit.php?id=<?= (int)$id ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-pen mr-1"></i> Edit Applicant</a>
      <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium"><i class="fa-solid fa-briefcase mr-1"></i> Add Employment Record</a>
      <?php endif; ?>
      <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-print mr-1"></i> Print Profile</button>
      <?php if (can_delete()): ?>
      <form method="POST" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button type="button" data-confirm-delete="<?= e(full_name($applicant)) ?>" class="px-3 py-2 rounded-lg border border-red-200 text-red-600 text-sm font-medium hover:bg-red-50"><i class="fa-solid fa-trash mr-1"></i> Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid md:grid-cols-3 gap-6">
    <div class="md:col-span-2 space-y-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Personal Information</h2>
        <dl class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Sex</dt><dd class="font-medium text-slate-800"><?= e($applicant['sex']) ?></dd></div>
          <div><dt class="text-slate-500">Date of Birth</dt><dd class="font-medium text-slate-800"><?= format_date($applicant['date_of_birth']) ?></dd></div>
          <div><dt class="text-slate-500">Place of Birth</dt><dd class="font-medium text-slate-800"><?= e($applicant['place_of_birth'] ?: '—') ?></dd></div>
          <div><dt class="text-slate-500">Civil Status</dt><dd class="font-medium text-slate-800"><?= e($applicant['civil_status']) ?></dd></div>
          <div><dt class="text-slate-500">Contact Number</dt><dd class="font-medium text-slate-800"><?= e($applicant['contact_number']) ?></dd></div>
          <div><dt class="text-slate-500">Email Address</dt><dd class="font-medium text-slate-800"><?= e($applicant['email_address'] ?: '—') ?></dd></div>
          <div class="sm:col-span-2"><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-800"><?= nl2br(e($applicant['address'])) ?></dd></div>
          <div class="sm:col-span-2">
            <dt class="text-slate-500">Services Availed</dt>
            <dd class="font-medium text-slate-800 mt-1">
              <?php
                $svc = [];
                if (!empty($applicant['service_job_seeker'])) $svc[] = 'Job Seeker';
                if (!empty($applicant['service_agency_services'])) $svc[] = 'Agency Services';
              ?>
              <?php if ($svc): ?>
                <?php foreach ($svc as $s): ?>
                  <span class="inline-block px-2 py-0.5 mr-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700"><?= e($s) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </dd>
          </div>
        </dl>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Employment History</h2>
          <?php if (can_edit()): ?>
          <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="text-xs font-medium text-brand-600 hover:text-brand-800 print:hidden"><i class="fa-solid fa-plus mr-1"></i> Add Record</a>
          <?php endif; ?>
        </div>

        <?php if (!$employmentRecords): ?>
          <div class="text-center py-8 text-slate-400">
            <i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No employment records yet.
          </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-xs uppercase text-slate-500 border-b border-slate-100">
              <tr>
                <th class="text-left py-2 pr-3">Agency / Company</th>
                <th class="text-left py-2 pr-3">Date Hired</th>
                <th class="text-left py-2 pr-3">Classification</th>
                <th class="text-left py-2 pr-3">Record Status</th>
                <th class="text-right py-2 print:hidden">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($employmentRecords as $rec): ?>
              <tr>
                <td class="py-2.5 pr-3">
                  <?= e($rec['agency_display_name']) ?>
                  <?php if ($rec['is_current']): ?>
                    <span class="ml-1 text-[10px] font-semibold text-green-700 bg-green-100 px-1.5 py-0.5 rounded-full align-middle">CURRENT</span>
                  <?php endif; ?>
                  <p class="text-xs text-slate-400"><?= e($rec['agency_company_address']) ?></p>
                  <?php if (!empty($rec['remarks'])): ?>
                    <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-note-sticky text-slate-400 mr-1"></i><?= e($rec['remarks']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 pr-3"><?= format_date($rec['date_hired']) ?></td>
                <td class="py-2.5 pr-3"><?= e($rec['employment_status']) ?></td>
                <td class="py-2.5 pr-3">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $rec['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($rec['status']) ?></span>
                </td>
                <td class="py-2.5 text-right print:hidden">
                  <?php if (can_edit()): ?>
                  <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=edit&record_id=<?= (int)$rec['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                  <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                    <input type="hidden" name="action" value="<?= $rec['status']==='Active' ? 'disable_employment' : 'enable_employment' ?>">
                    <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $rec['status']==='Active' ? 'Disable' : 'Enable' ?>">
                      <i class="fa-solid <?= $rec['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if (can_delete()): ?>
                  <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="delete_employment">
                    <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                    <button type="button" data-confirm-delete="this employment record" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Remarks</h2>
        <?php if (!empty($applicant['remarks'])): ?>
          <p class="text-sm text-slate-700 whitespace-pre-line"><?= e($applicant['remarks']) ?></p>
        <?php else: ?>
          <p class="text-sm text-slate-400">No remarks yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="space-y-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-3">Employment Status</h2>
        <span class="inline-block px-3 py-1.5 rounded-full text-sm font-semibold <?= $status['color'] ?>"><?= e(strtoupper($status['label'])) ?></span>
        <?php
          $current = null;
          foreach ($employmentRecords as $rec) { if ($rec['is_current'] && $rec['status'] === 'Active') { $current = $rec; break; } }
        ?>
        <?php if ($current): ?>
          <dl class="mt-4 space-y-2 text-sm">
            <div><dt class="text-slate-500">Agency/Company</dt><dd class="font-medium text-slate-800"><?= e($current['agency_display_name']) ?></dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-800"><?= e($current['agency_company_address']) ?></dd></div>
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= format_date($current['date_hired']) ?></dd></div>
            <?php if (!empty($current['remarks'])): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

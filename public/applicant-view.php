<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$code = clean($_GET['code'] ?? '');

// Two ways to land on this page: the numeric id (every existing link
// in the app) or the applicant code (the QR scan / manual-entry lookup
// — see docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md
// §6). id wins if both are somehow present. $id is normalized from the
// fetched row right below, so every POST handler and query further
// down this same file behaves identically regardless of which lookup
// path was used to land here.
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE id = :id AND is_deleted = 0");
    $stmt->execute([':id' => $id]);
    $applicant = $stmt->fetch();
} elseif ($code !== '') {
    $stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0");
    $stmt->execute([':code' => $code]);
    $applicant = $stmt->fetch();
} else {
    $applicant = false;
}

if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

$id = (int)$applicant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    csrf_require();

    if ($action === 'delete') {
        require_role(['Administrator']);
        $pdo->prepare("UPDATE care_jf_applicants SET is_deleted = 1 WHERE id = :id")->execute([':id' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'care_jf_applicants', $id, "Deleted applicant {$applicant['applicant_code']}");
        flash_set('success', 'Applicant record deleted.');
        redirect('applicants.php');
    } elseif (in_array($action, ['enable_employment', 'disable_employment'], true)) {
        require_role(['Administrator', 'Employee']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $newStatus = $action === 'enable_employment' ? 'Active' : 'Disabled';
        $pdo->prepare("UPDATE care_jf_employment_records SET status = :s WHERE id = :id AND applicant_id = :aid")
            ->execute([':s' => $newStatus, ':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], strtoupper(str_replace('_employment', '', $action)), 'care_jf_employment_records', $recordId, "Employment record {$newStatus}");
        flash_set('success', "Employment record {$newStatus}.");
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'delete_employment') {
        require_role(['Administrator']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $pdo->prepare("DELETE FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid")
            ->execute([':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'care_jf_employment_records', $recordId, 'Employment record deleted');
        flash_set('success', 'Employment record deleted.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'mark_hired') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        // Agency identity is always server-derived for a Partner Agency —
        // never trusted from the request. Administrator/Employee explicitly
        // choose which agency to credit with the hire.
        if (is_partner_agency()) {
            $hireAgencyId = current_agency_id($pdo);
        } else {
            $hireAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$hireAgencyId) {
            flash_set('error', 'Select a Partner Agency to hire this applicant into.');
            redirect('applicant-view.php?id=' . $id);
        }

        $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id");
        $agStmt->execute([':id' => $hireAgencyId]);
        $hireAgencyRow = $agStmt->fetch();
        if (!$hireAgencyRow) {
            flash_set('error', 'Selected Partner Agency was not found.');
            redirect('applicant-view.php?id=' . $id);
        }

        // Guard against a double-hire: only proceed if the applicant genuinely
        // still has no current active employment record right now.
        $hireCheckStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
        );
        $hireCheckStmt->execute([':id' => $id]);
        if ((int)$hireCheckStmt->fetchColumn() > 0) {
            flash_set('error', 'This applicant has already been hired.');
            redirect('applicant-view.php?id=' . $id);
        }

        $hireStmt = $pdo->prepare(
            "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
             VALUES (:aid, :agid, :agency, :address, CURDATE(), 'Hired', 1, 'Active')"
        );
        $hireStmt->execute([
            ':aid' => $id, ':agid' => $hireAgencyId,
            ':agency' => $hireAgencyRow['agency_name'], ':address' => $hireAgencyRow['address'],
        ]);
        $newHireId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'MARK_HIRED', 'care_jf_employment_records', $newHireId,
            "Applicant {$applicant['applicant_code']} marked Hired by {$hireAgencyRow['agency_name']}");
        flash_set('success', 'Applicant marked as Hired.');
        redirect('applicant-view.php?id=' . $id);
    }
}

$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name,
            pa.contact_person AS agency_contact_person, pa.contact_no AS agency_contact_no, pa.email AS agency_email
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE er.applicant_id = :id ORDER BY er.date_hired DESC, er.id DESC"
);
$empStmt->execute([':id' => $id]);
$employmentRecords = $empStmt->fetchAll();

$status = current_employment_status($pdo, $id);

$hireAgencies = can_manage_employment() ? active_agencies($pdo) : [];
$myAgencyName = '';
if (is_partner_agency()) {
    $myAgencyStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $myAgencyStmt->execute([':id' => current_agency_id($pdo)]);
    $myAgencyName = (string)$myAgencyStmt->fetchColumn();
}

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

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 mb-6 flex items-center gap-4">
    <canvas id="applicantQrCanvas" x-data x-init="renderApplicantQr($el, <?= e(json_encode($applicant['applicant_code'])) ?>)"
            class="rounded-lg border border-slate-200 shrink-0"></canvas>
    <div>
      <p class="text-xs text-slate-500 uppercase tracking-wide">Applicant QR Code</p>
      <p class="text-xs text-slate-400 mb-2">Scan this code for a quick lookup, or present it when following up with the office.</p>
      <button type="button" onclick="downloadQrPng(document.getElementById('applicantQrCanvas'), <?= e(json_encode($applicant['applicant_code'] . '-qr.png')) ?>)"
              class="print:hidden px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-medium hover:bg-slate-50">
        <i class="fa-solid fa-download mr-1"></i> Download QR
      </button>
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
                  <span class="inline-block px-2 py-0.5 mr-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase"><?= e($s) ?></span>
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
                <td class="py-2.5 pr-3 uppercase"><?= e($rec['employment_status']) ?></td>
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
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ showHireConfirm: false, hireAgencyId: '' }">
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
            <?php if (!empty($current['agency_id'])): ?>
              <?php if (!empty($current['agency_contact_person'])): ?>
              <div><dt class="text-slate-500">Agency Contact Person</dt><dd class="font-medium text-slate-800"><?= e($current['agency_contact_person']) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($current['agency_contact_no'])): ?>
              <div><dt class="text-slate-500">Agency Contact No</dt><dd class="font-medium text-slate-800"><?= e($current['agency_contact_no']) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($current['agency_email'])): ?>
              <div><dt class="text-slate-500">Agency Email</dt><dd class="font-medium text-slate-800"><?= e($current['agency_email']) ?></dd></div>
              <?php endif; ?>
            <?php endif; ?>
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= format_date($current['date_hired']) ?></dd></div>
            <?php if (!empty($current['remarks'])): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php elseif (can_manage_employment() || is_partner_agency()): ?>
          <div class="mt-4 print:hidden">
            <button type="button" @click="showHireConfirm = true" class="w-full px-4 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-sm">
              <i class="fa-solid fa-handshake mr-1"></i> Mark as Hired
            </button>
          </div>

          <!-- Confirmation modal -->
          <div x-show="showHireConfirm" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="showHireConfirm = false">
              <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-circle-question text-emerald-600 mr-1"></i> Confirm Hire</h3>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_hired">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php if (can_manage_employment()): ?>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
                  <select name="agency_id" x-model="hireAgencyId" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                    <option value="">Select a Partner Agency</option>
                    <?php foreach ($hireAgencies as $ag): ?>
                      <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <p class="text-sm text-slate-600 mb-5">Mark <?= e(full_name($applicant)) ?> as hired by <strong><?= e($myAgencyName) ?></strong>?</p>
                <?php endif; ?>
                <div class="flex justify-end gap-2">
                  <button type="button" @click="showHireConfirm = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                  <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium">Confirm &amp; Mark as Hired</button>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

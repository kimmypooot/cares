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

        // If this is a confirmed hire tied to a Job Vacancy, restore that
        // vacancy's slot before deleting the record — otherwise the
        // vacancy stays permanently short one opening it no longer
        // actually has. Only Delete restores the slot (not Disable/
        // Enable) — deleting is the rare, irreversible action; toggling
        // a record's status was never guarded against double-booking
        // either, before or after this phase.
        $vacStmt = $pdo->prepare(
            "SELECT vacancy_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status = 'Hired'"
        );
        $vacStmt->execute([':id' => $recordId, ':aid' => $id]);
        $vacancyToRestore = $vacStmt->fetchColumn();

        $pdo->beginTransaction();
        try {
            if ($vacancyToRestore) {
                $pdo->prepare(
                    "UPDATE care_jf_job_vacancies SET vacant_count = vacant_count + 1, status = IF(status = 'Filled', 'Active', status) WHERE id = :id"
                )->execute([':id' => $vacancyToRestore]);
                audit_log($pdo, (int)current_user()['id'], 'VACANCY_RESTORE', 'care_jf_job_vacancies', (int)$vacancyToRestore,
                    'Vacancy slot restored (hire record deleted)');
            }
            $pdo->prepare("DELETE FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid")
                ->execute([':id' => $recordId, ':aid' => $id]);
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
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'tag_for_review') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        if (is_applicant_hired($pdo, $id)) {
            flash_set('error', 'This applicant has already been hired.');
            redirect('applicant-view.php?id=' . $id);
        }

        // Agency identity is always server-derived for a Partner Agency —
        // never trusted from the request. Administrator/Employee explicitly
        // choose which agency to tag on behalf of.
        if (is_partner_agency()) {
            $reviewAgencyId = current_agency_id($pdo);
        } else {
            $reviewAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$reviewAgencyId) {
            flash_set('error', 'Select a Partner Agency to tag for review.');
            redirect('applicant-view.php?id=' . $id);
        }

        $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id AND status = 'Active'");
        $agStmt->execute([':id' => $reviewAgencyId]);
        $reviewAgencyRow = $agStmt->fetch();
        if (!$reviewAgencyRow) {
            flash_set('error', 'Selected Partner Agency was not found.');
            redirect('applicant-view.php?id=' . $id);
        }

        // No duplicate concurrent tags from the same agency — re-tagging is
        // fine once a prior tag has moved to Withdrawn/Superseded/Hired,
        // since none of those match this check.
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND agency_id = :agid AND employment_status = 'For Review'"
        );
        $dupStmt->execute([':id' => $id, ':agid' => $reviewAgencyId]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            flash_set('error', 'This agency has already tagged this applicant for review.');
            redirect('applicant-view.php?id=' . $id);
        }

        $tagStmt = $pdo->prepare(
            "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
             VALUES (:aid, :agid, :agency, :address, NULL, 'For Review', 0, 'Active')"
        );
        $tagStmt->execute([
            ':aid' => $id, ':agid' => $reviewAgencyId,
            ':agency' => $reviewAgencyRow['agency_name'], ':address' => $reviewAgencyRow['address'],
        ]);
        $newReviewId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_TAGGED_FOR_REVIEW', 'care_jf_employment_records', $newReviewId,
            "Applicant {$applicant['applicant_code']} tagged For Review by {$reviewAgencyRow['agency_name']}");
        flash_set('success', 'Applicant tagged for review.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'confirm_hired') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $vacancyId = (int)($_POST['vacancy_id'] ?? 0);

        // Resolve and verify ownership of the review row being confirmed —
        // DB-resolved, never trusted from the request.
        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status = 'For Review'"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to confirm this hire.</h2>');
        }

        if (!$vacancyId) {
            flash_set('error', 'Select a Job Vacancy to confirm this hire.');
            redirect('applicant-view.php?id=' . $id);
        }

        $pdo->beginTransaction();
        try {
            // Guard against a double-hire: only proceed if the applicant
            // genuinely still has no other current active employment record.
            $hireCheckStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
            );
            $hireCheckStmt->execute([':id' => $id]);
            if ((int)$hireCheckStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                flash_set('error', 'This applicant has already been hired.');
                redirect('applicant-view.php?id=' . $id);
            }

            // Atomic, race-safe decrement: the UPDATE's own WHERE clause
            // enforces "still belongs to this agency, still Active, still
            // has an opening" in one statement, so a concurrent confirm_hired
            // against the same vacancy can't read-then-write a stale count
            // (the lost-update race a plain SELECT-then-UPDATE would have) —
            // matching generate_employer_id()'s SQL-side-arithmetic pattern
            // in includes/functions.php rather than computing the new value
            // in PHP.
            // NOTE: status is assigned BEFORE vacant_count in this SET
            // clause deliberately — MySQL evaluates a single UPDATE's SET
            // assignments left to right, and a later assignment sees the
            // already-updated value of an earlier-assigned column in the
            // same statement (documented MySQL behavior, differs from
            // standard SQL). Assigning vacant_count first and then
            // referencing "vacant_count - 1" in status's IF() would read
            // the ALREADY-decremented value, double-counting the decrement
            // (e.g. 2 -> 1 would wrongly flip straight to 'Filled'). Doing
            // status first means its "vacant_count - 1" still reads the
            // original pre-statement value.
            $decStmt = $pdo->prepare(
                "UPDATE care_jf_job_vacancies
                    SET status = IF(vacant_count - 1 <= 0, 'Filled', status),
                        vacant_count = vacant_count - 1
                  WHERE id = :vid AND agency_id = :agid AND status = 'Active' AND vacant_count > 0"
            );
            $decStmt->execute([':vid' => $vacancyId, ':agid' => $rowAgencyId]);
            if ($decStmt->rowCount() === 0) {
                $pdo->rollBack();
                flash_set('error', 'Selected Job Vacancy is not available.');
                redirect('applicant-view.php?id=' . $id);
            }

            // Standing is_current bookkeeping rule: clear before setting.
            $pdo->prepare("UPDATE care_jf_employment_records SET is_current = 0 WHERE applicant_id = :aid")
                ->execute([':aid' => $id]);

            $pdo->prepare(
                "UPDATE care_jf_employment_records SET employment_status = 'Hired', is_current = 1, date_hired = CURDATE(), vacancy_id = :vid WHERE id = :id"
            )->execute([':vid' => $vacancyId, ':id' => $recordId]);

            // Auto-close every other agency's still-open tag on this applicant.
            supersede_other_reviews($pdo, $id, $recordId, (int)current_user()['id']);

            audit_log($pdo, (int)current_user()['id'], 'APPLICANT_HIRED', 'care_jf_employment_records', $recordId,
                "Applicant {$applicant['applicant_code']} hired");
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_DECREMENT', 'care_jf_job_vacancies', $vacancyId,
                "Vacancy decremented (hire: {$applicant['applicant_code']})");

            $pdo->commit();
            flash_set('success', 'Applicant confirmed as Hired.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Confirm Hired failed: ' . $e->getMessage());
            flash_set('error', 'Confirming this hire failed due to a system error. Please try again.');
        }
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'withdraw_review') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status = 'For Review'"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to withdraw this tag.</h2>');
        }

        $pdo->prepare("UPDATE care_jf_employment_records SET employment_status = 'Withdrawn' WHERE id = :id")
            ->execute([':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_REVIEW_WITHDRAWN', 'care_jf_employment_records', $recordId,
            "Applicant {$applicant['applicant_code']} review tag withdrawn");
        flash_set('success', 'Review tag withdrawn.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'update_remarks') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $remarks = clean($_POST['remarks'] ?? '');

        if (mb_strlen($remarks) > 255) {
            flash_set('error', 'Remarks must be 255 characters or fewer.');
            redirect('applicant-view.php?id=' . $id);
        }

        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status IN ('For Review', 'Hired')"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to update remarks on this record.</h2>');
        }

        $pdo->prepare("UPDATE care_jf_employment_records SET remarks = :r WHERE id = :id")
            ->execute([':r' => $remarks !== '' ? $remarks : null, ':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_REVIEW_REMARKS_UPDATED', 'care_jf_employment_records', $recordId,
            "Remarks updated for applicant {$applicant['applicant_code']}");
        flash_set('success', 'Remarks saved.');
        redirect('applicant-view.php?id=' . $id);
    }
}

$empWhere = "er.applicant_id = :id";
$empParams = [':id' => $id];
if (is_partner_agency()) {
    // Partner Agency sees every confirmed/historical record exactly as
    // before, plus only their OWN in-flight tags — never another
    // agency's For Review/Withdrawn/Superseded row.
    $empWhere .= " AND (er.employment_status NOT IN ('For Review', 'Withdrawn', 'Superseded') OR er.agency_id = :myagid)";
    $empParams[':myagid'] = current_agency_id($pdo);
}
$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name,
            pa.contact_person AS agency_contact_person, pa.contact_no AS agency_contact_no, pa.email AS agency_email
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE $empWhere ORDER BY er.created_at DESC, er.id DESC"
);
$empStmt->execute($empParams);
$employmentRecords = $empStmt->fetchAll();

$status = current_employment_status($pdo, $id);
$applicantIsHired = is_applicant_hired($pdo, $id);

$myAgencyName = '';
$myAgencyIdForCheck = null;
if (is_partner_agency()) {
    $myAgencyIdForCheck = current_agency_id($pdo);
    $myAgencyStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $myAgencyStmt->execute([':id' => $myAgencyIdForCheck]);
    $myAgencyName = (string)$myAgencyStmt->fetchColumn();
}

$myOpenReview = null;
if (is_partner_agency()) {
    foreach ($employmentRecords as $rec) {
        if ($rec['employment_status'] === 'For Review' && (int)$rec['agency_id'] === $myAgencyIdForCheck) {
            $myOpenReview = $rec;
            break;
        }
    }
}

// Agencies with an open (For Review) tag on this applicant right now —
// used both to scope which agencies' vacancies to fetch and to exclude
// them from the "tag a new agency" dropdown (no duplicate concurrent tags).
$reviewingAgencyIds = array_values(array_unique(array_map('intval', array_column(
    array_filter($employmentRecords, fn($r) => $r['employment_status'] === 'For Review'), 'agency_id'
))));

$vacancyOptionsByAgency = [];
if ($reviewingAgencyIds) {
    $inClause = implode(',', array_fill(0, count($reviewingAgencyIds), '?'));
    $vacStmt = $pdo->prepare(
        "SELECT id, agency_id, position, job_level, vacant_count FROM care_jf_job_vacancies
         WHERE agency_id IN ($inClause) AND status = 'Active' AND vacant_count > 0 ORDER BY position"
    );
    $vacStmt->execute($reviewingAgencyIds);
    foreach ($vacStmt->fetchAll() as $vRow) {
        $vacancyOptionsByAgency[(int)$vRow['agency_id']][] = $vRow;
    }
}

$tagAgencyOptions = can_manage_employment()
    ? array_values(array_filter(active_agencies($pdo), fn($ag) => !in_array((int)$ag['id'], $reviewingAgencyIds, true)))
    : [];

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

      <?php
        $canTagAsPartnerAgency = is_partner_agency() && !$myOpenReview && !$applicantIsHired;
        $canTagAsStaff = can_manage_employment() && $tagAgencyOptions && !$applicantIsHired;
      ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ confirmHireRecordId: null, showTagForReview: false }">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Employment History</h2>
          <div class="flex gap-3 print:hidden">
            <?php if ($canTagAsPartnerAgency || $canTagAsStaff): ?>
            <button type="button" @click="showTagForReview = true" class="text-xs font-medium text-emerald-600 hover:text-emerald-800"><i class="fa-solid fa-flag mr-1"></i> Tag for Review</button>
            <?php endif; ?>
            <?php if (can_edit()): ?>
            <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="text-xs font-medium text-brand-600 hover:text-brand-800"><i class="fa-solid fa-plus mr-1"></i> Add Record</a>
            <?php endif; ?>
          </div>
        </div>

        <div x-show="showTagForReview" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
          <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="showTagForReview = false">
            <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-flag text-emerald-600 mr-1"></i> Tag for Review</h3>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="tag_for_review">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <?php if (can_manage_employment()): ?>
                <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
                <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                  <option value="">Select a Partner Agency</option>
                  <?php foreach ($tagAgencyOptions as $ag): ?>
                    <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <p class="text-sm text-slate-600 mb-5">Tag <?= e(full_name($applicant)) ?> for review by <strong><?= e($myAgencyName) ?></strong>?</p>
              <?php endif; ?>
              <div class="flex justify-end gap-2">
                <button type="button" @click="showTagForReview = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium">Confirm Tag</button>
              </div>
            </form>
          </div>
        </div>

        <?php if (!$employmentRecords): ?>
          <div class="text-center py-8 text-slate-400">
            <i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No employment records yet.
          </div>
        <?php else: ?>
        <?php $classColors = [
            'For Review' => 'bg-amber-100 text-amber-800',
            'Hired'      => 'bg-emerald-100 text-emerald-800',
            'Withdrawn'  => 'bg-gray-100 text-gray-500',
            'Superseded' => 'bg-gray-100 text-gray-500',
        ]; ?>
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
              <?php
                $isOwnAgencyRow = !empty($rec['agency_id']) && is_partner_agency() && (int)$rec['agency_id'] === $myAgencyIdForCheck;
                $canActOnReview = can_manage_employment() || $isOwnAgencyRow;
                $canEditRemarks = in_array($rec['employment_status'], ['For Review', 'Hired'], true) && $canActOnReview;
                $hideRemarksFromOtherAgency = $rec['employment_status'] === 'Hired' && is_partner_agency() && !$isOwnAgencyRow;
              ?>
              <tr>
                <td class="py-2.5 pr-3">
                  <?= e($rec['agency_display_name']) ?>
                  <?php if ($rec['is_current']): ?>
                    <span class="ml-1 text-[10px] font-semibold text-green-700 bg-green-100 px-1.5 py-0.5 rounded-full align-middle">CURRENT</span>
                  <?php endif; ?>
                  <p class="text-xs text-slate-400"><?= e($rec['agency_company_address']) ?></p>
                  <?php if ($canEditRemarks): ?>
                    <form method="POST" class="mt-1 flex items-start gap-1 print:hidden">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <input type="hidden" name="action" value="update_remarks">
                      <input type="text" name="remarks" maxlength="255" value="<?= e($rec['remarks'] ?? '') ?>" placeholder="Add a private remark..." class="flex-1 text-xs rounded border border-slate-300 px-2 py-1">
                      <button type="submit" class="text-xs text-brand-600 hover:text-brand-800 px-1.5 py-1" title="Save Remarks"><i class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                  <?php elseif (!empty($rec['remarks']) && !$hideRemarksFromOtherAgency): ?>
                    <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-note-sticky text-slate-400 mr-1"></i><?= e($rec['remarks']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 pr-3"><?= $rec['date_hired'] ? format_date($rec['date_hired']) : '—' ?></td>
                <td class="py-2.5 pr-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium uppercase <?= $classColors[$rec['employment_status']] ?? 'bg-slate-100 text-slate-700' ?>"><?= e($rec['employment_status']) ?></span></td>
                <td class="py-2.5 pr-3">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $rec['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($rec['status']) ?></span>
                </td>
                <td class="py-2.5 text-right print:hidden">
                  <?php if ($rec['employment_status'] === 'For Review' && $canActOnReview): ?>
                    <button type="button" @click="confirmHireRecordId = <?= (int)$rec['id'] ?>" class="text-slate-500 hover:text-emerald-600 px-1" title="Confirm Hired"><i class="fa-solid fa-handshake"></i></button>
                    <form method="POST" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <input type="hidden" name="action" value="withdraw_review">
                      <button type="button" data-confirm-delete="<?= e($rec['agency_display_name']) ?>'s review tag" data-confirm-verb="Withdraw" class="text-slate-500 hover:text-red-600 px-1" title="Withdraw"><i class="fa-solid fa-rotate-left"></i></button>
                    </form>

                    <div x-show="confirmHireRecordId === <?= (int)$rec['id'] ?>" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
                      <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 text-left" @click.outside="confirmHireRecordId = null">
                        <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-circle-question text-emerald-600 mr-1"></i> Confirm Hire</h3>
                        <p class="text-sm text-slate-600 mb-3">Confirm <?= e(full_name($applicant)) ?> as hired by <strong><?= e($rec['agency_display_name']) ?></strong>?</p>
                        <form method="POST">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="confirm_hired">
                          <input type="hidden" name="id" value="<?= (int)$id ?>">
                          <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                          <?php $vacOptions = $vacancyOptionsByAgency[(int)$rec['agency_id']] ?? []; ?>
                          <label class="block text-sm font-medium text-slate-700 mb-1">Job Vacancy <span class="text-red-500">*</span></label>
                          <?php if ($vacOptions): ?>
                          <select name="vacancy_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                            <option value="">Select a Job Vacancy</option>
                            <?php foreach ($vacOptions as $v): ?>
                              <option value="<?= (int)$v['id'] ?>"><?= e($v['position']) ?> (<?= e($v['job_level']) ?>, <?= (int)$v['vacant_count'] ?> open)</option>
                            <?php endforeach; ?>
                          </select>
                          <?php else: ?>
                          <p class="text-xs text-red-500 mb-4">This agency has no open Job Vacancies with available slots. Add one under Job Vacancies first.</p>
                          <?php endif; ?>
                          <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmHireRecordId = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                            <button type="submit" <?= $vacOptions ? '' : 'disabled' ?> class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium disabled:opacity-50 disabled:cursor-not-allowed">Confirm &amp; Hire</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  <?php elseif (in_array($rec['employment_status'], ['Withdrawn', 'Superseded'], true)): ?>
                    <span class="text-xs text-slate-300 px-1">—</span>
                  <?php else: ?>
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
        <?php $hideCurrentRemarksFromOtherAgency = $current && $current['employment_status'] === 'Hired' && is_partner_agency() && (int)($current['agency_id'] ?? 0) !== $myAgencyIdForCheck; ?>
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
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= $current['date_hired'] ? format_date($current['date_hired']) : '—' ?></dd></div>
            <?php if (!empty($current['remarks']) && !$hideCurrentRemarksFromOtherAgency): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="mt-4 text-sm text-slate-500">Use the Employment History section to tag a Partner Agency for review, then confirm the hire once ready.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

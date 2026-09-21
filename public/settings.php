<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_admin.php';
require_login();

$pdo = Database::getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['backup_database', 'reset_database'], true)) {
    csrf_require();
    $dbAction = $_POST['action'];
    $me = current_user();

    // Re-checked here even though the card is hidden for non-Administrators
    // client-side — a forged POST from another role must still be rejected
    // server-side, same dual-check pattern every other sensitive action in
    // this app follows.
    if (!can_manage_database()) {
        flash_set('error', 'You are not authorized to perform this action.');
        redirect('settings.php');
    }

    $stmt = $pdo->prepare("SELECT password FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => $me['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify((string)($_POST['password'] ?? ''), $hash)) {
        audit_log($pdo, (int)$me['id'], 'DB_ACTION_DENIED', 'care_jf_users', (int)$me['id'],
            "Incorrect password on $dbAction attempt");
        flash_set('error', 'Incorrect password. Action cancelled.');
        redirect('settings.php');
    }

    if ($dbAction === 'backup_database') {
        audit_log($pdo, (int)$me['id'], 'DATABASE_BACKUP', 'care_jf_users', (int)$me['id'], 'Full database backup downloaded');
        stream_sql_backup($pdo); // streams the file and exits
    } else {
        $summary = reset_application_records($pdo);
        // Written after reset_application_records() truncates
        // care_jf_audit_logs — otherwise this entry would be wiped along
        // with everything else, and there'd be no record at all of who
        // reset the system and when.
        audit_log($pdo, (int)$me['id'], 'DATABASE_RESET', 'care_jf_users', (int)$me['id'], 'Full data reset performed: ' . $summary);
        flash_set('success', 'Records reset successfully. ' . $summary);
        redirect('settings.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === '') {
    csrf_require();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM care_jf_users WHERE id = :id");
    $stmt->execute([':id' => current_user()['id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password'])) {
        $errors['current_password'] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors) {
        $pdo->prepare("UPDATE care_jf_users SET password = :p WHERE id = :id")
            ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        audit_log($pdo, (int)$user['id'], 'UPDATE', 'care_jf_users', (int)$user['id'], 'Changed own password');
        flash_set('success', 'Password updated successfully.');
        redirect('settings.php');
    }
}

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-lg mx-auto space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Settings</h1>
    <p class="text-sm text-slate-500">Manage your account preferences.</p>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Account</h2>
    <dl class="text-sm space-y-2 mb-6">
      <div class="flex justify-between"><dt class="text-slate-500">Username</dt><dd class="font-medium"><?= e(current_user()['username']) ?></dd></div>
      <div class="flex justify-between"><dt class="text-slate-500">Full Name</dt><dd class="font-medium"><?= e(current_user()['full_name']) ?></dd></div>
      <div class="flex justify-between"><dt class="text-slate-500">Role</dt><dd class="font-medium"><?= e(current_user()['role']) ?></dd></div>
    </dl>

    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Change Password</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Current Password</label>
          <input type="password" name="current_password" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['current_password'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
          <input type="password" name="new_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['new_password'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1"><?= e($errors['confirm_password'] ?? '') ?></p>
        </div>
      </div>
      <button type="submit" class="mt-4 px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Update Password</button>
    </form>
  </div>

  <?php if (can_manage_database()): ?>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="dbActionModal()">
    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-1">Database Management</h2>
    <p class="text-xs text-slate-500 mb-4">Administrator only. Back up all current records before making major changes, or reset the system's data for a new cycle.</p>
    <div class="flex flex-col sm:flex-row gap-3">
      <button type="button" @click="open('backup')" class="inline-flex items-center justify-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-semibold px-4 py-2.5 rounded-lg">
        <i class="fa-solid fa-database"></i> BACKUP CURRENT RECORD
      </button>
      <button type="button" @click="open('reset')" class="inline-flex items-center justify-center gap-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-4 py-2.5 rounded-lg">
        <i class="fa-solid fa-triangle-exclamation"></i> RESET RECORDS
      </button>
    </div>

    <!-- Real forms submitted only after the 3rd confirmation step -->
    <form x-ref="backupForm" method="POST" class="hidden">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="backup_database">
      <input type="hidden" name="password" :value="password">
    </form>
    <form x-ref="resetForm" method="POST" class="hidden">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_database">
      <input type="hidden" name="password" :value="password">
    </form>

    <!-- Step 1: are you sure -->
    <div x-show="step === 1" x-cloak x-transition.opacity class="fixed inset-0 bg-black/40 z-[95] flex items-center justify-center p-4" @keydown.escape.window="close()">
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-7">
        <h3 class="font-semibold text-slate-800 mb-2" x-text="activeAction === 'backup' ? 'Back Up Current Records?' : 'Reset All Records?'"></h3>
        <p class="text-sm text-slate-500 mb-5" x-text="activeAction === 'backup'
          ? 'This generates a full .sql backup of the current database for download.'
          : 'This permanently erases all applicants, employment records, job vacancies, and service-availment and audit-log data. User accounts and Partner Agency accounts are kept.'"></p>
        <div class="flex gap-2">
          <button type="button" @click="close()" class="flex-1 px-4 py-2.5 text-sm rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium transition">Cancel</button>
          <button type="button" @click="goToPasswordStep()" class="flex-1 px-4 py-2.5 text-sm rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold transition">Continue</button>
        </div>
      </div>
    </div>

    <!-- Step 2: re-enter password -->
    <div x-show="step === 2" x-cloak x-transition.opacity class="fixed inset-0 bg-black/40 z-[96] flex items-center justify-center p-4" @keydown.escape.window="close()">
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-7">
        <h3 class="font-semibold text-slate-800 mb-2">Confirm Your Password</h3>
        <p class="text-sm text-slate-500 mb-4">For security, re-enter your password to continue.</p>
        <input type="password" x-model="password" x-ref="passwordInput" placeholder="Your current password" autocomplete="current-password"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3 focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none"
               @keydown.enter="password && (step = 3)">
        <div class="flex gap-2">
          <button type="button" @click="close()" class="flex-1 px-4 py-2.5 text-sm rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium transition">Cancel</button>
          <button type="button" @click="password && (step = 3)" :disabled="!password" class="flex-1 px-4 py-2.5 text-sm rounded-xl bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white font-semibold transition">Confirm</button>
        </div>
      </div>
    </div>

    <!-- Step 3: final warning before executing -->
    <div x-show="step === 3" x-cloak x-transition.opacity class="fixed inset-0 bg-black/40 z-[97] flex items-center justify-center p-4" @keydown.escape.window="close()">
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-7 text-center">
        <div class="mx-auto w-12 h-12 rounded-full bg-red-50 flex items-center justify-center mb-4">
          <i class="fa-solid fa-triangle-exclamation text-xl text-red-500"></i>
        </div>
        <h3 class="font-semibold text-slate-800 mb-1">Last Chance</h3>
        <p class="text-sm text-slate-500 mb-6" x-text="activeAction === 'backup'
          ? 'Clicking Execute will generate and download the backup now.'
          : 'Clicking Execute will permanently erase the records now. This cannot be undone.'"></p>
        <div class="flex gap-2">
          <button type="button" @click="close()" class="flex-1 px-4 py-2.5 text-sm rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium transition">Cancel</button>
          <button type="button" @click="execute()" class="flex-1 px-4 py-2.5 text-sm rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition">Execute</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function dbActionModal() {
  return {
    step: 0, activeAction: null, password: '',
    open(action) {
      this.activeAction = action;
      this.password = '';
      this.step = 1;
    },
    goToPasswordStep() {
      this.step = 2;
      this.$nextTick(() => this.$refs.passwordInput && this.$refs.passwordInput.focus());
    },
    close() {
      this.step = 0;
      this.activeAction = null;
      this.password = '';
    },
    execute() {
      const form = this.activeAction === 'backup' ? this.$refs.backupForm : this.$refs.resetForm;
      form.submit();
      this.close();
    }
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

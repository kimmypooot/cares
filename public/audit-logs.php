<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator']);

$pdo = Database::getConnection();
[$limit, $offset, $page] = paginate_params();

$total = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT al.*, u.full_name, u.username FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
$pages = (int)ceil($total / $limit);

$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Audit Logs</h1>
    <p class="text-sm text-slate-500">System activity trail — logins, registrations, edits, and deletions.</p>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
          <tr>
            <th class="px-4 py-2.5 text-left">Date/Time</th>
            <th class="px-4 py-2.5 text-left">User</th>
            <th class="px-4 py-2.5 text-left">Action</th>
            <th class="px-4 py-2.5 text-left">Table</th>
            <th class="px-4 py-2.5 text-left">Description</th>
            <th class="px-4 py-2.5 text-left">IP Address</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$logs): ?>
            <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No audit entries yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td class="px-4 py-2.5 whitespace-nowrap text-slate-500"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
            <td class="px-4 py-2.5"><?= e($log['full_name'] ?? 'System') ?></td>
            <td class="px-4 py-2.5">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700"><?= e($log['action']) ?></span>
            </td>
            <td class="px-4 py-2.5 text-slate-500"><?= e($log['table_name']) ?></td>
            <td class="px-4 py-2.5"><?= e($log['description']) ?></td>
            <td class="px-4 py-2.5 text-slate-400"><?= e($log['ip_address'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
      <p class="text-xs text-slate-500">Showing page <?= $page ?> of <?= max($pages,1) ?> (<?= number_format($total) ?> entries)</p>
      <div class="flex gap-1">
        <a href="?page=<?= max(1,$page-1) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page<=1?'pointer-events-none opacity-40':'' ?>">Previous</a>
        <a href="?page=<?= min($pages,$page+1) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page>=$pages?'pointer-events-none opacity-40':'' ?>">Next</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

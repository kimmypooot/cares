<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Administrator']);

$pdo = Database::getConnection();
purge_old_audit_logs($pdo);
[$limit, $offset, $page] = paginate_params();

$total = (int)$pdo->query("SELECT COUNT(*) FROM care_jf_audit_logs")->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT al.*, u.full_name, u.username FROM care_jf_audit_logs al
     LEFT JOIN care_jf_users u ON u.id = al.user_id
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
    <div class="w-full min-w-0 overflow-x-auto overflow-y-auto max-h-[65vh]">
      <table class="min-w-max w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide sticky top-0 z-10">
          <tr>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Date/Time</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">User</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Action</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Table</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">Description</th>
            <th class="px-4 py-2.5 text-left whitespace-nowrap">IP Address</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
          <?php if (!$logs): ?>
            <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-clipboard-list text-2xl mb-2 block"></i> No audit entries yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($logs as $log): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-2.5 whitespace-nowrap text-slate-500"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
            <td class="px-4 py-2.5 whitespace-nowrap"><?= e($log['full_name'] ?? 'System') ?></td>
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700"><?= e($log['action']) ?></span>
            </td>
            <td class="px-4 py-2.5 whitespace-nowrap text-slate-500"><?= e($log['table_name']) ?></td>
            <td class="px-4 py-2.5"><?= e($log['description']) ?></td>
            <td class="px-4 py-2.5 whitespace-nowrap text-slate-400"><?= e($log['ip_address'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
      <p class="text-xs text-slate-500">Showing page <?= $page ?> of <?= max($pages,1) ?> (<?= number_format($total) ?> entries)</p>
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex gap-1">
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page<=1?'pointer-events-none opacity-40':'' ?>">Previous</a>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($pages,$page+1)])) ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 <?= $page>=$pages?'pointer-events-none opacity-40':'' ?>">Next</a>
        </div>
        <form method="GET" class="flex items-center gap-1">
          <?php foreach ($_GET as $paramName => $paramValue): if ($paramName === 'page' || !is_scalar($paramValue)) continue; ?>
          <input type="hidden" name="<?= e($paramName) ?>" value="<?= e((string)$paramValue) ?>">
          <?php endforeach; ?>
          <label class="sr-only" for="audit-logs-go-to-page">Go to page</label>
          <input id="audit-logs-go-to-page" type="number" name="page" min="1" max="<?= max($pages,1) ?>" value="<?= $page ?>"
                 class="w-20 rounded-lg border border-slate-300 text-sm px-2 py-1.5">
          <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Go</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

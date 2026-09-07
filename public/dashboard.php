<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();

if (is_partner_agency()) {
    $agencyId = current_agency_id($pdo);

    $agencyStmt = $pdo->prepare("SELECT * FROM partner_agencies WHERE id = :id");
    $agencyStmt->execute([':id' => $agencyId]);
    $agency = $agencyStmt->fetch();

    // Shared applicant pool stats (every Partner Agency sees the same
    // system-wide numbers here — the pool itself is shared, per the
    // "Partner Agency can view registered applicants" rule).
    $totalApplicantsPool = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0")->fetchColumn();
    $notHiredPool = (int)$pdo->query(
        "SELECT COUNT(*) FROM applicants a
         LEFT JOIN employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
         WHERE a.is_deleted = 0 AND er.id IS NULL"
    )->fetchColumn();

    // Own-agency stat: applicants this agency has actually hired.
    $hiredByAgencyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM employment_records WHERE agency_id = :aid AND is_current = 1 AND status = 'Active'"
    );
    $hiredByAgencyStmt->execute([':aid' => $agencyId]);
    $hiredByAgencyCount = (int)$hiredByAgencyStmt->fetchColumn();

    $pageTitle = 'Dashboard';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-800">My Agency</h1>
      <p class="text-sm text-slate-500"><?= e($agency['agency_name']) ?></p>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <?php
      $paCards = [
          ['label' => 'Total Registered Applicants',  'value' => $totalApplicantsPool, 'icon' => 'fa-users',          'color' => 'text-brand-600 bg-brand-50'],
          ['label' => 'Available for Recruitment',     'value' => $notHiredPool,        'icon' => 'fa-hourglass-half', 'color' => 'text-gray-600 bg-gray-100'],
          ['label' => 'Applicants Not Hired',           'value' => $notHiredPool,        'icon' => 'fa-user-clock',     'color' => 'text-gray-600 bg-gray-100'],
          ['label' => 'Hired by This Agency',           'value' => $hiredByAgencyCount,  'icon' => 'fa-briefcase',      'color' => 'text-green-600 bg-green-50'],
      ];
      foreach ($paCards as $c): ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-lg flex items-center justify-center <?= $c['color'] ?>">
          <i class="fa-solid <?= $c['icon'] ?>"></i>
        </div>
        <div>
          <p class="text-xs text-slate-500 font-medium"><?= e($c['label']) ?></p>
          <p class="text-xl font-bold text-slate-800"><?= number_format($c['value']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 mb-8">
      <p class="text-sm text-slate-600">
        Browse the full applicant pool under <a href="applicants.php" class="text-brand-600 font-medium hover:underline">Applicants</a>,
        or view your agency's profile under <a href="my-agency.php" class="text-brand-600 font-medium hover:underline">My Partner Agency</a>.
      </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pendingAgencyCount = can_manage_users()
    ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Partner Agency' AND status = 'Pending'")->fetchColumn()
    : 0;

// ---- Summary counts ----
$totalApplicants = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0")->fetchColumn();
$maleCount   = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND sex='MALE'")->fetchColumn();
$femaleCount = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND sex='FEMALE'")->fetchColumn();

$statusCounts = [
    'For Further Review' => 0,
    'Job Order'     => 0,
    'Temporary'     => 0,
    'COS'           => 0,
    'Permanent'     => 0,
    'Casual'        => 0,
    'Other'         => 0,
    'Hired'         => 0,
];

// A row only counts toward "hired" classification if it's the current AND
// active (non-disabled) employment record — matches current_employment_status().
$stmt = $pdo->query(
    "SELECT a.id, er.employment_status
     FROM applicants a
     LEFT JOIN employment_records er
       ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
     WHERE a.is_deleted = 0"
);
foreach ($stmt->fetchAll() as $row) {
    $status = $row['employment_status'] ?: 'For Further Review';
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;
}
$hiredCount = $totalApplicants - $statusCounts['For Further Review'];
$notHiredCount = $statusCounts['For Further Review'];

// ---- Registrations per month (last 6 months) ----
$regByMonth = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
     FROM applicants WHERE is_deleted = 0
     GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();
$regByMonth = array_reverse($regByMonth);

// ---- Hires per month (last 6 months) — active records only ----
$hireByMonth = $pdo->query(
    "SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total
     FROM employment_records WHERE status = 'Active'
     GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();
$hireByMonth = array_reverse($hireByMonth);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Dashboard</h1>
    <p class="text-sm text-slate-500">Overview of applicant registration and employment activity.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <?php if (can_edit()): ?>
    <a href="applicant-create.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> Register Applicant
    </a>
    <?php endif; ?>
    <a href="applicants.php" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
      <i class="fa-solid fa-users"></i> View Applicants
    </a>
    <a href="reports.php" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
      <i class="fa-solid fa-chart-column"></i> Generate Report
    </a>
  </div>
</div>

<?php if ($pendingAgencyCount > 0): ?>
<a href="users.php?tab=partner-agencies" class="block mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 hover:bg-amber-100 transition">
  <i class="fa-solid fa-building-circle-exclamation mr-2"></i>
  Pending Partner Agency Registrations: <?= $pendingAgencyCount ?> — click to review.
</a>
<?php endif; ?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <?php
  $cards = [
      ['label' => 'Total Applicants', 'value' => $totalApplicants, 'icon' => 'fa-users', 'color' => 'text-brand-600 bg-brand-50'],
      ['label' => 'Not Hired',        'value' => $notHiredCount, 'icon' => 'fa-hourglass-half', 'color' => 'text-gray-600 bg-gray-100'],
      ['label' => 'Hired',            'value' => $hiredCount, 'icon' => 'fa-briefcase', 'color' => 'text-green-600 bg-green-50'],
      ['label' => 'Permanent',        'value' => $statusCounts['Permanent'], 'icon' => 'fa-shield-halved', 'color' => 'text-emerald-700 bg-emerald-50'],
      ['label' => 'Male Applicants',  'value' => $maleCount, 'icon' => 'fa-mars', 'color' => 'text-blue-600 bg-blue-50'],
      ['label' => 'Female Applicants','value' => $femaleCount, 'icon' => 'fa-venus', 'color' => 'text-pink-600 bg-pink-50'],
      ['label' => 'Job Order',        'value' => $statusCounts['Job Order'], 'icon' => 'fa-file-signature', 'color' => 'text-yellow-700 bg-yellow-50'],
      ['label' => 'COS',              'value' => $statusCounts['COS'], 'icon' => 'fa-file-contract', 'color' => 'text-indigo-700 bg-indigo-50'],
  ];
  foreach ($cards as $c): ?>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
    <div class="w-11 h-11 rounded-lg flex items-center justify-center <?= $c['color'] ?>">
      <i class="fa-solid <?= $c['icon'] ?>"></i>
    </div>
    <div>
      <p class="text-xs text-slate-500 font-medium"><?= e($c['label']) ?></p>
      <p class="text-xl font-bold text-slate-800"><?= number_format($c['value']) ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="grid lg:grid-cols-2 gap-6 mb-8">
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Applicant Status</h3>
    <canvas id="chartApplicantStatus" height="200"></canvas>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Applicants by Employment Classification</h3>
    <canvas id="chartStatus" height="200"></canvas>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Applicants Registered Per Month</h3>
    <canvas id="chartRegistrations" height="200"></canvas>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Applicants Hired Per Month</h3>
    <canvas id="chartHires" height="200"></canvas>
  </div>
</div>

<script>
const palette = ['#3b63f5','#22c55e','#eab308','#a855f7','#ef4444','#0ea5e9'];

// Applicant Status: Hired vs Not Hired — pulled live from the database, not hard-coded.
new Chart(document.getElementById('chartApplicantStatus'), {
  type: 'doughnut',
  data: {
    labels: ['HIRED', 'NOT HIRED'],
    datasets: [{ data: [<?= $hiredCount ?>, <?= $notHiredCount ?>], backgroundColor: ['#22c55e', '#94a3b8'] }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('chartStatus'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map('strtoupper', array_keys($statusCounts))) ?>,
    datasets: [{ label: 'Applicants', data: <?= json_encode(array_values($statusCounts)) ?>, backgroundColor: palette }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartRegistrations'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($regByMonth, 'ym')) ?>,
    datasets: [{ label: 'Registered', data: <?= json_encode(array_map('intval', array_column($regByMonth, 'total'))) ?>,
      borderColor: '#3b63f5', backgroundColor: 'rgba(59,99,245,0.15)', fill: true, tension: 0.3 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartHires'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($hireByMonth, 'ym')) ?>,
    datasets: [{ label: 'Hired', data: <?= json_encode(array_map('intval', array_column($hireByMonth, 'total'))) ?>,
      borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.15)', fill: true, tension: 0.3 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

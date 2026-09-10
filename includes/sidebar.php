<?php
$currentPage = basename($_SERVER['PHP_SELF']);
// Employment section highlights for both the list and the add/edit form
$employmentPages = ['employment-list.php', 'employment-form.php'];
$agencyPages = ['partner-agency.php', 'partner-agency-form.php'];

if (is_partner_agency()) {
    // Partner Agency: no Employment module, no cross-agency Partner Agency
    // management page — their own profile lives at my-agency.php instead.
    $navItems = [
        ['href' => 'dashboard.php',      'icon' => 'fa-gauge-high',        'label' => 'Dashboard',         'match' => ['dashboard.php']],
        ['href' => 'applicants.php',     'icon' => 'fa-users',             'label' => 'Applicants',        'match' => ['applicants.php', 'applicant-view.php']],
        ['href' => 'my-agency.php',      'icon' => 'fa-building',          'label' => 'My Partner Agency', 'match' => ['my-agency.php']],
        ['href' => 'agency-users.php',   'icon' => 'fa-users-gear',        'label' => 'Agency Users',       'match' => ['agency-users.php']],
        ['href' => 'vacancies.php',      'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies',      'match' => ['vacancies.php']],
        ['href' => 'reports.php',        'icon' => 'fa-chart-column',      'label' => 'Reports',            'match' => ['reports.php']],
    ];
} else {
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',       'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicants',      'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'clients.php',         'icon' => 'fa-handshake',    'label' => 'Clients',         'match' => ['clients.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment',      'match' => $employmentPages],
        ['href' => 'partner-agency.php',  'icon' => 'fa-building',     'label' => 'Partner Agency',  'match' => $agencyPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Reports',         'match' => ['reports.php']],
    ];
    if (can_manage_employment()) {
        array_splice($navItems, 4, 0, [[
            'href' => 'vacancies.php', 'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies', 'match' => ['vacancies.php'],
        ]]);
    }
}
if (can_manage_users()) {
    $navItems[] = ['href' => 'users.php', 'icon' => 'fa-user-shield', 'label' => 'Users', 'match' => ['users.php']];
}
if (can_view_audit_logs()) {
    $navItems[] = ['href' => 'audit-logs.php', 'icon' => 'fa-clipboard-list', 'label' => 'Audit Logs', 'match' => ['audit-logs.php']];
}
$navItems[] = ['href' => 'settings.php', 'icon' => 'fa-gear', 'label' => 'Settings', 'match' => ['settings.php']];
?>
<!-- Mobile top bar -->
<div class="lg:hidden fixed top-0 inset-x-0 h-14 bg-brand-800 text-white flex items-center justify-between px-4 z-40 print:hidden">
  <button @click="sidebarOpen = true" class="p-2 -ml-2">
    <i class="fa-solid fa-bars text-lg"></i>
  </button>
  <div class="flex items-center gap-2">
    <img src="assets/images/csc-logo.png" alt="CSC Logo" width="24" height="24" class="h-6 w-6 object-contain shrink-0">
    <span class="font-extrabold tracking-wide text-sm">CARE</span>
  </div>
  <div class="w-6"></div>
</div>

<!-- Mobile overlay -->
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/40 z-40 lg:hidden" x-transition.opacity></div>

<!-- Sidebar -->
<aside
  :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
  class="fixed lg:sticky inset-y-0 lg:top-0 left-0 lg:h-screen w-64 bg-brand-900 text-brand-100 flex flex-col z-50 transform transition-transform duration-200 ease-in-out print:hidden">
  <div class="h-16 flex items-center gap-3 px-6 border-b border-white/10">
    <img src="assets/images/csc-logo.png" alt="CSC Logo" width="32" height="32" class="h-8 w-8 object-contain shrink-0">
    <div class="leading-tight">
      <p class="text-sm font-extrabold text-white tracking-wide">CARE</p>
      <p class="text-[10px] text-brand-300 -mt-0.5">Candidate App. &amp; Reg. for Employment</p>
    </div>
    <button @click="sidebarOpen = false" class="ml-auto lg:hidden p-1"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <nav class="flex-1 overflow-y-auto py-5 px-3 space-y-1">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= e($item['href']) ?>"
         class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
        <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center"></i>
        <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="border-t border-white/10 p-4">
    <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg">
      <div class="w-9 h-9 rounded-full bg-brand-600 flex items-center justify-center font-semibold text-white">
        <?= e(strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1))) ?>
      </div>
      <div class="leading-tight overflow-hidden">
        <p class="text-sm font-semibold text-white truncate"><?= e($user['full_name']) ?></p>
        <p class="text-[11px] text-brand-300"><?= e($user['role']) ?></p>
      </div>
    </div>
    <a href="logout.php"
       class="mt-2 flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-200 hover:bg-red-500/10">
      <i class="fa-solid fa-right-from-bracket w-4 text-center"></i> Logout
    </a>
  </div>
</aside>

<main class="flex-1 min-w-0 pt-14 lg:pt-0 print:pt-0">
  <div class="max-w-[1400px] mx-auto px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-8">

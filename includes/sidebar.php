<?php
$currentPage = basename($_SERVER['PHP_SELF']);
// Employment section highlights for both the list and the add/edit form
$employmentPages = ['employment-list.php', 'employment-form.php'];

if (is_partner_agency()) {
    $navItems = [
        ['href' => 'dashboard.php',  'icon' => 'fa-gauge-high',        'label' => 'Dashboard',      'match' => ['dashboard.php']],
        ['href' => 'applicants.php', 'icon' => 'fa-users',             'label' => 'Applicant',      'match' => ['applicants.php', 'applicant-view.php']],
        ['href' => 'clients.php',    'icon' => 'fa-handshake',         'label' => 'Clients',         'match' => ['clients.php']],
        ['href' => 'vacancies.php',  'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies',   'match' => ['vacancies.php']],
        ['href' => 'services.php',   'icon' => 'fa-list-check',        'label' => 'Services',        'match' => ['services.php']],
        ['href' => 'reports.php',    'icon' => 'fa-chart-column',      'label' => 'Report',          'match' => ['reports.php']],
    ];
    $settingsItems = [
        ['href' => 'my-agency.php',    'icon' => 'fa-building',     'label' => 'My Partner Agency', 'match' => ['my-agency.php']],
        ['href' => 'agency-users.php', 'icon' => 'fa-users-gear',   'label' => 'Agency Users',       'match' => ['agency-users.php']],
        ['href' => 'settings.php',     'icon' => 'fa-user-gear',    'label' => 'Account Settings',   'match' => ['settings.php']],
    ];
} else {
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',  'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicant',  'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'clients.php',         'icon' => 'fa-handshake',    'label' => 'Clients',    'match' => ['clients.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment', 'match' => $employmentPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Report',     'match' => ['reports.php']],
    ];
    if (can_manage_employment()) {
        array_splice($navItems, 4, 0, [[
            'href' => 'vacancies.php', 'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies', 'match' => ['vacancies.php'],
        ]]);
    }
    array_splice($navItems, count($navItems) - 1, 0, [[
        'href' => 'services.php', 'icon' => 'fa-list-check', 'label' => 'Services', 'match' => ['services.php'],
    ]]);

    $settingsItems = [
        ['href' => 'partner-agency.php', 'icon' => 'fa-building', 'label' => 'Partner Agency', 'match' => ['partner-agency.php', 'partner-agency-form.php']],
    ];
    if (can_manage_users()) {
        $settingsItems[] = ['href' => 'users.php', 'icon' => 'fa-user-shield', 'label' => 'Users', 'match' => ['users.php']];
    }
    if (can_view_audit_logs()) {
        $settingsItems[] = ['href' => 'audit-logs.php', 'icon' => 'fa-clipboard-list', 'label' => 'Audit Logs', 'match' => ['audit-logs.php']];
    }
    $settingsItems[] = ['href' => 'settings.php', 'icon' => 'fa-user-gear', 'label' => 'Account Settings', 'match' => ['settings.php']];
}

// The Settings dropdown starts expanded when the current page is one of
// its own sub-pages, so the active section stays visibly open on load.
$settingsPages = array_merge(...array_column($settingsItems, 'match'));
$settingsOpenDefault = in_array($currentPage, $settingsPages, true);
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

  <nav class="flex-1 overflow-y-auto py-5 px-3 space-y-1" x-data="{ settingsOpen: <?= $settingsOpenDefault ? 'true' : 'false' ?> }">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= e($item['href']) ?>"
         class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
        <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center"></i>
        <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>

    <button type="button" @click="settingsOpen = !settingsOpen"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                   <?= $settingsOpenDefault ? 'bg-white/5' : '' ?> hover:bg-white/5 text-brand-100">
      <i class="fa-solid fa-gear w-4 text-center"></i>
      <span class="flex-1 text-left">Settings</span>
      <i class="fa-solid fa-chevron-down text-xs transition-transform" :class="settingsOpen ? 'rotate-180' : ''"></i>
    </button>
    <div x-show="settingsOpen" x-cloak class="pl-4 space-y-1">
      <?php foreach ($settingsItems as $item): ?>
        <a href="<?= e($item['href']) ?>"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
          <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center text-xs"></i>
          <?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
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

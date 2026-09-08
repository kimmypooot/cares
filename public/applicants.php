<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pageTitle = 'Registered Applicants';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="applicantTable()" x-init="load()" class="space-y-5">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Registered Applicants</h1>
      <p class="text-sm text-slate-500">Search, filter, and manage all registered applicants.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <?php require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
      <?php if (can_edit()): ?>
      <a href="applicant-create.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
        <i class="fa-solid fa-user-plus"></i> Register New Applicant
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
    <div class="grid md:grid-cols-5 gap-3">
      <div class="md:col-span-2 relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" x-model="filters.search" @input.debounce.400ms="load(1)"
               placeholder="Search by name, ID or contact number..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
      </div>
      <select x-model="filters.sex" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Sex</option>
        <option value="MALE">MALE</option>
        <option value="FEMALE">FEMALE</option>
      </select>
      <select x-model="filters.civil_status" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Civil Status</option>
        <option>SINGLE</option><option>MARRIED</option><option>WIDOWED</option>
        <option>SEPARATED</option><option>DIVORCED</option><option>OTHER</option>
      </select>
      <select x-model="filters.employment_status" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Employment Status</option>
        <option>For Further Review</option><option>Job Order</option>
        <option>Temporary</option><option>COS</option><option>Permanent</option><option>Casual</option><option>Hired</option>
      </select>
    </div>
    <div class="flex justify-end mt-3">
      <button @click="resetFilters()" class="text-sm text-slate-500 hover:text-slate-700 font-medium">
        <i class="fa-solid fa-rotate-left mr-1"></i> Reset Filters
      </button>
    </div>
  </div>

  <!-- Table -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide">
          <tr>
            <th class="px-4 py-3 text-left">Applicant ID</th>
            <th class="px-4 py-3 text-left">Full Name</th>
            <th class="px-4 py-3 text-left">Sex</th>
            <th class="px-4 py-3 text-left">Date of Birth</th>
            <th class="px-4 py-3 text-left">Contact</th>
            <th class="px-4 py-3 text-left">Civil Status</th>
            <th class="px-4 py-3 text-left">Services Availed</th>
            <th class="px-4 py-3 text-left">Employment Status</th>
            <th class="px-4 py-3 text-left">Date Registered</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template x-if="loading">
            <tr><td colspan="10" class="px-4 py-6"><div class="h-4 skeleton rounded"></div></td></tr>
          </template>
          <template x-if="!loading && rows.length === 0">
            <tr><td colspan="10" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i> No applicants found.
            </td></tr>
          </template>
          <template x-for="row in rows" :key="row.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-brand-700" data-label="ID" x-text="row.applicant_code"></td>
              <td class="px-4 py-3" data-label="Name" x-text="row.full_name"></td>
              <td class="px-4 py-3" data-label="Sex" x-text="row.sex"></td>
              <td class="px-4 py-3" data-label="DOB" x-text="row.date_of_birth"></td>
              <td class="px-4 py-3" data-label="Contact" x-text="row.contact_number"></td>
              <td class="px-4 py-3" data-label="Civil Status" x-text="row.civil_status"></td>
              <td class="px-4 py-3" data-label="Services">
                <template x-for="svc in row.services_availed" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase" x-text="svc"></span>
                </template>
                <span x-show="!row.services_availed || row.services_availed.length === 0" class="text-slate-300 text-xs">—</span>
              </td>
              <td class="px-4 py-3" data-label="Employment">
                <span class="px-2 py-1 rounded-full text-xs font-medium uppercase"
                      :class="statusColor(row.employment_status)" x-text="row.employment_status"></span>
              </td>
              <td class="px-4 py-3" data-label="Registered" x-text="row.date_registered"></td>
              <td class="px-4 py-3 text-right" data-label="Actions">
                <a :href="'applicant-view.php?id=' + row.id" class="text-slate-500 hover:text-brand-600 px-1.5" title="View"><i class="fa-solid fa-eye"></i></a>
                <?php if (can_edit()): ?>
                <a :href="'applicant-edit.php?id=' + row.id" class="text-slate-500 hover:text-amber-600 px-1.5" title="Edit"><i class="fa-solid fa-pen"></i></a>
                <?php endif; ?>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 flex-wrap gap-3">
      <p class="text-xs text-slate-500">
        Showing <span x-text="rows.length ? (offset()+1) : 0"></span>–<span x-text="offset()+rows.length"></span> of <span x-text="total"></span> applicants
      </p>
      <div class="flex items-center gap-3">
        <select x-model.number="perPage" @change="load(1)" class="text-sm border border-slate-300 rounded-lg px-2 py-1">
          <option :value="10">10 / page</option>
          <option :value="25">25 / page</option>
          <option :value="50">50 / page</option>
          <option :value="100">100 / page</option>
        </select>
        <div class="flex gap-1">
          <button @click="load(page-1)" :disabled="page<=1" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Previous</button>
          <span class="px-3 py-1.5 text-sm">Page <span x-text="page"></span> of <span x-text="Math.max(pages,1)"></span></span>
          <button @click="load(page+1)" :disabled="page>=pages" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function applicantTable() {
  return {
    rows: [], total: 0, page: 1, pages: 1, perPage: 25, loading: true,
    filters: { search: '', sex: 'All', civil_status: 'All', employment_status: 'All' },
    offset() { return (this.page - 1) * this.perPage; },
    statusColor(status) {
      const map = {
        'For Further Review': 'bg-gray-100 text-gray-700',
        'Job Order': 'bg-yellow-100 text-yellow-800',
        'COS': 'bg-blue-100 text-blue-800',
        'Temporary': 'bg-purple-100 text-purple-800',
        'Permanent': 'bg-green-100 text-green-800',
        'Casual': 'bg-orange-100 text-orange-800',
        'Hired': 'bg-emerald-100 text-emerald-800',
      };
      return map[status] || 'bg-gray-100 text-gray-700';
    },
    resetFilters() {
      this.filters = { search: '', sex: 'All', civil_status: 'All', employment_status: 'All' };
      this.load(1);
    },
    async load(page = this.page) {
      this.loading = true;
      this.page = Math.max(1, page);
      const params = new URLSearchParams({ page: this.page, per_page: this.perPage, ...this.filters });
      try {
        const res = await fetch('api/applicants.php?' + params.toString());
        const json = await res.json();
        this.rows = json.data || [];
        this.total = json.total || 0;
        this.pages = json.pages || 1;
      } catch (err) {
        showToast('Failed to load applicants.', 'error');
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pageTitle = 'Clients';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (is_partner_agency()):
?>
<div x-data="agencyClientTable()" x-init="load()" class="space-y-5">

  <div>
    <h1 class="text-2xl font-bold text-slate-800">Clients</h1>
    <p class="text-sm text-slate-500">Registrants who have availed your agency's services.</p>
  </div>

  <!-- Client Actions, Search & Filters -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Client Actions</h2>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <div class="flex flex-col sm:flex-row gap-2">
        <?php $qrButtonLabel = 'Scan QR Code'; require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
      </div>
      <button type="button" @click="exportToExcel()" class="inline-flex items-center justify-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-file-excel"></i> Export to Excel
      </button>
    </div>

    <hr class="my-4 border-slate-100">

    <h2 class="text-sm font-semibold text-slate-700 mb-3">Search Client</h2>
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" x-model="filters.search" @input.debounce.400ms="load(1)"
               placeholder="Search by name or Application ID..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
      </div>
      <select x-model="filters.service_availed" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3 sm:w-56">
        <option value="All">All Services</option>
        <option value="agency_services">AVAIL AGENCY SERVICES</option>
        <option value="both">AVAILED BOTH</option>
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
    <div class="w-full min-w-0 overflow-x-auto overflow-y-auto max-h-[65vh]">
      <table class="min-w-max w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide sticky top-0 z-10">
          <tr>
            <th class="px-4 py-3 text-left whitespace-nowrap">Seq. No.</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Application ID</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Full Name</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Services Registered</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Service Availed</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Date Availed</th>
            <th class="px-4 py-3 text-right whitespace-nowrap min-w-[100px]">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
          <template x-if="loading">
            <tr><td colspan="8" class="px-4 py-6"><div class="h-4 skeleton rounded"></div></td></tr>
          </template>
          <template x-if="!loading && rows.length === 0">
            <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i> No clients found.
            </td></tr>
          </template>
          <template x-for="(row, idx) in rows" :key="row.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 text-slate-500" data-label="Seq" x-text="offset() + idx + 1"></td>
              <td class="px-4 py-3 font-medium text-brand-700" data-label="ID" x-text="row.applicant_code"></td>
              <td class="px-4 py-3 font-medium text-slate-800" data-label="Name" x-text="row.full_name"></td>
              <td class="px-4 py-3" data-label="Services Registered">
                <template x-for="svc in row.services_registered" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase" x-text="svc"></span>
                </template>
              </td>
              <td class="px-4 py-3" data-label="Service Availed" x-text="row.service_availed"></td>
              <td class="px-4 py-3" data-label="Status">
                <span class="px-2 py-1 rounded-full text-xs font-medium"
                      :class="row.status === 'AVAILING SERVICES' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'" x-text="row.status"></span>
              </td>
              <td class="px-4 py-3 text-slate-500" data-label="Date Availed" x-text="row.date_availed"></td>
              <td class="px-4 py-3 text-right whitespace-nowrap min-w-[100px]" data-label="Action">
                <a :href="'applicant-view.php?id=' + row.id" class="text-slate-500 hover:text-brand-600 px-1.5" title="View"><i class="fa-solid fa-eye"></i></a>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 flex-wrap gap-3">
      <p class="text-xs text-slate-500">
        Showing <span x-text="rows.length ? (offset()+1) : 0"></span>–<span x-text="offset()+rows.length"></span> of <span x-text="total"></span> clients
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
        <form @submit.prevent="load(goToPage)" class="flex items-center gap-1">
          <label class="sr-only" for="agency-clients-go-to-page">Go to page</label>
          <input id="agency-clients-go-to-page" type="number" min="1" :max="Math.max(pages,1)" x-model.number="goToPage"
                 placeholder="Page #" class="w-20 rounded-lg border border-slate-300 text-sm px-2 py-1.5">
          <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Go</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function agencyClientTable() {
  return {
    rows: [], total: 0, page: 1, pages: 1, perPage: 25, goToPage: 1, loading: true,
    filters: { search: '', service_availed: 'All' },
    offset() { return (this.page - 1) * this.perPage; },
    resetFilters() {
      this.filters = { search: '', service_availed: 'All' };
      this.load(1);
    },
    exportToExcel() {
      const params = new URLSearchParams(this.filters);
      window.location.href = 'api/agency-clients-export.php?' + params.toString();
    },
    async load(page = this.page) {
      this.loading = true;
      this.page = Math.max(1, Math.min(Math.trunc(page) || 1, this.pages || 1));
      const params = new URLSearchParams({ page: this.page, per_page: this.perPage, ...this.filters });
      try {
        const res = await fetch('api/agency-clients.php?' + params.toString());
        const json = await res.json();
        this.rows = json.data || [];
        this.total = json.total || 0;
        this.pages = json.pages || 1;
      } catch (err) {
        showToast('Failed to load clients.', 'error');
      } finally {
        this.loading = false;
        this.goToPage = this.page;
      }
    }
  }
}
</script>
<?php else: ?>
<div x-data="applicantTable()" x-init="filters.service = 'agency_services'; load()" class="space-y-5">

  <div>
    <h1 class="text-2xl font-bold text-slate-800">Clients</h1>
    <p class="text-sm text-slate-500">Search and manage registrants who avail Partner Agency services.</p>
  </div>

  <!-- Client Actions, Search & Filters -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Client Actions</h2>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <div class="flex flex-col sm:flex-row gap-2">
        <?php $qrButtonLabel = 'Scan QR Code'; require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
        <?php if (can_edit()): ?>
        <a href="applicant-create.php" class="inline-flex items-center justify-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
          <i class="fa-solid fa-user-plus"></i> Register New Applicant
        </a>
        <?php endif; ?>
      </div>
      <button type="button" @click="exportToExcel()" class="inline-flex items-center justify-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
        <i class="fa-solid fa-file-excel"></i> Export to Excel
      </button>
    </div>

    <hr class="my-4 border-slate-100">

    <h2 class="text-sm font-semibold text-slate-700 mb-3">Search Client</h2>
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" x-model="filters.search" @input.debounce.400ms="load(1)"
               placeholder="Search by name, ID or contact number..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
      </div>
      <select x-model="filters.service_availed" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3 sm:w-56">
        <option value="All">All Services</option>
        <option value="agency_services">AVAIL AGENCY SERVICES</option>
        <option value="both">AVAILED BOTH</option>
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
    <div class="w-full min-w-0 overflow-x-auto overflow-y-auto max-h-[65vh]">
      <table class="min-w-max w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide sticky top-0 z-10">
          <tr>
            <th class="px-4 py-3 text-left whitespace-nowrap">Seq. No.</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Applicant ID</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Full Name</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Date of Birth</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Services Availed</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Employment Status</th>
            <th class="px-4 py-3 text-left whitespace-nowrap">Date Registered</th>
            <th class="px-4 py-3 text-right whitespace-nowrap min-w-[100px]">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
          <template x-if="loading">
            <tr><td colspan="8" class="px-4 py-6"><div class="h-4 skeleton rounded"></div></td></tr>
          </template>
          <template x-if="!loading && rows.length === 0">
            <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i> No clients found.
            </td></tr>
          </template>
          <template x-for="(row, idx) in rows" :key="row.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 text-slate-500" data-label="Seq" x-text="offset() + idx + 1"></td>
              <td class="px-4 py-3 font-medium text-brand-700" data-label="ID" x-text="row.applicant_code"></td>
              <td class="px-4 py-3 font-medium text-slate-800" data-label="Name" x-text="row.full_name"></td>
              <td class="px-4 py-3 text-slate-500" data-label="DOB" x-text="row.date_of_birth"></td>
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
              <td class="px-4 py-3 text-slate-500" data-label="Registered" x-text="row.date_registered"></td>
              <td class="px-4 py-3 text-right whitespace-nowrap min-w-[100px]" data-label="Actions">
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
        Showing <span x-text="rows.length ? (offset()+1) : 0"></span>–<span x-text="offset()+rows.length"></span> of <span x-text="total"></span> clients
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
        <form @submit.prevent="load(goToPage)" class="flex items-center gap-1">
          <label class="sr-only" for="clients-go-to-page">Go to page</label>
          <input id="clients-go-to-page" type="number" min="1" :max="Math.max(pages,1)" x-model.number="goToPage"
                 placeholder="Page #" class="w-20 rounded-lg border border-slate-300 text-sm px-2 py-1.5">
          <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Go</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function applicantTable() {
  return {
    rows: [], total: 0, page: 1, pages: 1, perPage: 25, goToPage: 1, loading: true,
    filters: { search: '', service_availed: 'All', service: 'agency_services' },
    offset() { return (this.page - 1) * this.perPage; },
    statusColor(status) {
      const map = {
        'For Further Review': 'bg-gray-100 text-gray-700',
        'Job Order': 'bg-yellow-100 text-yellow-800',
        'COS': 'bg-blue-100 text-blue-800',
        'Temporary': 'bg-purple-100 text-purple-800',
        'Permanent': 'bg-green-100 text-green-800',
        'Casual': 'bg-orange-100 text-orange-800',
        'Hired': 'bg-green-100 text-green-800',
        'GIP': 'bg-teal-100 text-teal-800',
      };
      return map[status] || 'bg-gray-100 text-gray-700';
    },
    resetFilters() {
      this.filters = { search: '', service_availed: 'All', service: 'agency_services' };
      this.load(1);
    },
    exportToExcel() {
      const params = new URLSearchParams(this.filters);
      window.location.href = 'api/applicants-export.php?' + params.toString();
    },
    async load(page = this.page) {
      this.loading = true;
      this.page = Math.max(1, Math.min(Math.trunc(page) || 1, this.pages || 1));
      const params = new URLSearchParams({ page: this.page, per_page: this.perPage, ...this.filters });
      try {
        const res = await fetch('api/applicants.php?' + params.toString());
        const json = await res.json();
        this.rows = json.data || [];
        this.total = json.total || 0;
        this.pages = json.pages || 1;
      } catch (err) {
        showToast('Failed to load clients.', 'error');
      } finally {
        this.loading = false;
        this.goToPage = this.page;
      }
    }
  }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

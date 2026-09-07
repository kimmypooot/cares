/**
 * public/assets/js/app.js
 * Shared front-end behavior: confirm dialogs, toast helper, debounce util.
 */

function debounce(fn, delay = 350) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

function showToast(message, type = 'success') {
  const el = document.createElement('div');
  el.className =
    'fixed top-4 right-4 z-[100] max-w-sm rounded-lg shadow-lg px-4 py-3 text-sm font-medium flex items-start gap-2 ' +
    (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
  el.innerHTML =
    `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'} mt-0.5"></i><span>${message}</span>`;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-confirm-delete]');
  if (!btn) return;
  e.preventDefault();
  const name = btn.getAttribute('data-confirm-delete');
  const verb = btn.getAttribute('data-confirm-verb') || 'Delete';
  const form = btn.closest('form');
  if (!form) return;

  if (window.confirm(`${verb} "${name}"?\n\nThis action cannot be undone.`)) {
    form.submit();
  }
});

function validateForm(form) {
  let valid = true;
  form.querySelectorAll('[required]').forEach((field) => {
    if (field.closest('[data-conditional-hidden]')) return; // skip fields hidden by conditional logic
    const errorEl = form.querySelector(`[data-error-for="${field.name}"]`);
    if (!field.value || (field.type === 'checkbox' && !field.checked)) {
      valid = false;
      field.classList.add('border-red-500', 'ring-1', 'ring-red-500');
      if (errorEl) errorEl.classList.remove('hidden');
    } else {
      field.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
      if (errorEl) errorEl.classList.add('hidden');
    }
  });
  return valid;
}

/**
 * Force-uppercase any input/textarea marked with the "uppercase-field"
 * class as the person types, preserving cursor position. The Tailwind
 * "uppercase" class on the same elements handles the instant visual
 * transform; this ensures the actual submitted value is uppercase too
 * (not just its on-screen display).
 */
document.addEventListener('input', (e) => {
  const field = e.target;
  if (!field.classList || !field.classList.contains('uppercase-field')) return;
  const start = field.selectionStart;
  const end = field.selectionEnd;
  field.value = field.value.toUpperCase();
  if (start !== null && end !== null) {
    field.setSelectionRange(start, end);
  }
});

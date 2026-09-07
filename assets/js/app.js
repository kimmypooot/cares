/**
 * assets/js/app.js
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

/**
 * Generic delete-confirmation using a native <dialog>-style Alpine modal.
 * Usage: data-confirm-delete="Applicant Name" on a <button> whose closest
 * <form> will be submitted on confirmation.
 */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-confirm-delete]');
  if (!btn) return;
  e.preventDefault();
  const name = btn.getAttribute('data-confirm-delete');
  const form = btn.closest('form');
  if (!form) return;

  if (window.confirm(`Delete "${name}"?\n\nThis action cannot be undone.`)) {
    form.submit();
  }
});

/** Client-side validation helper: mark invalid fields with red border + message. */
function validateForm(form) {
  let valid = true;
  form.querySelectorAll('[required]').forEach((field) => {
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

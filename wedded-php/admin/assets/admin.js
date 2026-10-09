// Mobile sidebar toggle
const sidebar = document.getElementById('adminSidebar');
const overlay = document.getElementById('adminOverlay');
const toggle = document.getElementById('adminMenuToggle');
function closeSidebar() {
  sidebar && sidebar.classList.remove('open');
  overlay && overlay.classList.remove('open');
  toggle && toggle.setAttribute('aria-expanded', 'false');
}
if (toggle) {
  toggle.addEventListener('click', () => {
    const open = !sidebar.classList.contains('open');
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
  });
}
if (overlay) overlay.addEventListener('click', closeSidebar);

// Confirm before delete
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault();
  });
});

// Drag-to-reorder for .sortable-list (used for hero slides, categories, albums, album images)
document.querySelectorAll('.sortable-list').forEach(list => {
  let dragEl = null;
  list.querySelectorAll('.sortable-item').forEach(item => {
    item.setAttribute('draggable', 'true');
    item.addEventListener('dragstart', () => { dragEl = item; item.style.opacity = '0.4'; });
    item.addEventListener('dragend', () => {
      item.style.opacity = '1';
      persistOrder(list);
    });
    item.addEventListener('dragover', e => {
      e.preventDefault();
      const after = getDragAfterElement(list, e.clientY);
      if (!dragEl) return;
      if (after == null) list.appendChild(dragEl);
      else list.insertBefore(dragEl, after);
    });
  });
});
function getDragAfterElement(container, y) {
  const items = [...container.querySelectorAll('.sortable-item:not([style*="opacity: 0.4"])')];
  return items.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) return { offset, element: child };
    return closest;
  }, { offset: -Infinity }).element;
}
function persistOrder(list) {
  const url = list.getAttribute('data-reorder-url');
  if (!url) return;
  const ids = [...list.querySelectorAll('.sortable-item')].map(el => el.getAttribute('data-id'));
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids, csrf_token: token })
  }).catch(() => {});
}

// Image preview on file input
document.querySelectorAll('input[type=file][data-preview]').forEach(input => {
  const target = document.querySelector(input.getAttribute('data-preview'));
  if (!target) return;
  input.addEventListener('change', () => {
    if (input.files && input.files[0]) {
      const reader = new FileReader();
      reader.onload = e => { target.src = e.target.result; target.style.display = 'block'; };
      reader.readAsDataURL(input.files[0]);
    }
  });
});

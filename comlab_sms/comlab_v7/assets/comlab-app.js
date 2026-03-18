/**
 * COMLAB — Shared JS helpers
 * Provides: openModal, closeModal, showNotification (toast), clock, mobile sidebar
 */

// ── Clock ──────────────────────────────────────────────────────
(function clock() {
  const el = document.getElementById('topClock');
  if (!el) return;
  function tick() {
    el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }
  tick();
  setInterval(tick, 1000);
})();

// ── Mobile sidebar toggle ──────────────────────────────────────
(function sidebar() {
  const toggle  = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const main    = document.getElementById('main');
  if (!toggle || !sidebar) return;

  // Create overlay
  const overlay = document.createElement('div');
  overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(10,20,50,.4);z-index:199';
  document.body.appendChild(overlay);

  function open() {
    sidebar.classList.add('mobile-open');
    overlay.style.display = 'block';
  }
  function close() {
    sidebar.classList.remove('mobile-open');
    overlay.style.display = 'none';
  }

  toggle.addEventListener('click', () => {
    sidebar.classList.contains('mobile-open') ? close() : open();
  });
  overlay.addEventListener('click', close);
})();

// ── Modal helpers ──────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('open')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// Close on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => {
      m.classList.remove('open');
    });
    document.body.style.overflow = '';
  }
});

// ── Toast notifications ────────────────────────────────────────
function showNotification(message, type = 'info', duration = 3500) {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);
  }

  const icons = { success: 'circle-check', danger: 'circle-xmark', warning: 'triangle-exclamation', info: 'circle-info' };
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<i class="fas fa-${icons[type] || 'circle-info'}"></i> ${message}`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(18px)';
    toast.style.transition = 'opacity .3s, transform .3s';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// ── Escape helper ──────────────────────────────────────────────
function esc(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

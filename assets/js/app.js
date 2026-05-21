/* BillingPro — app.js */
document.addEventListener('DOMContentLoaded', function () {

  // ── Sidebar mobile toggle ──────────────────────────────────────────
  const sidebar  = document.getElementById('sidebar');
  const toggleBtn = document.getElementById('sidebar-toggle');

  if (toggleBtn && sidebar) {
    // Create overlay
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    toggleBtn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('show');
    });

    overlay.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
    });
  }

  // ── Auto-dismiss alerts after 4s ──────────────────────────────────
  document.querySelectorAll('.alert.fade.show').forEach(alert => {
    setTimeout(() => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      if (bsAlert) bsAlert.close();
    }, 4000);
  });

  // ── Confirm delete for inline forms ───────────────────────────────
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', function (e) {
      if (!confirm(this.dataset.confirm)) e.preventDefault();
    });
  });

  // ── Number format helper ───────────────────────────────────────────
  window.fmtNum = (n, decimals = 2) =>
    Number(n).toLocaleString('en-MY', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

});

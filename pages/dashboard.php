<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'dashboard';
$page_title   = 'Dashboard';

// Stats
$total_invoices = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$pending_amount = $pdo->query("SELECT COALESCE(SUM(total_amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id=invoices.id),0)),0) FROM invoices WHERE status IN ('sent','overdue')")->fetchColumn();
$paid_this_month = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())")->fetchColumn();
$overdue_count  = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='overdue' OR (status='sent' AND due_date < CURDATE())")->fetchColumn();

// Recent invoices
$recent = $pdo->query("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id ORDER BY i.created_at DESC LIMIT 10")->fetchAll();

// Monthly revenue (last 6 months)
$monthly = $pdo->query("
  SELECT DATE_FORMAT(payment_date,'%b %Y') AS month_label,
         DATE_FORMAT(payment_date,'%Y-%m') AS month_key,
         SUM(amount) AS revenue
  FROM payments
  WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
  GROUP BY month_key, month_label
  ORDER BY month_key
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<!-- Stat cards -->
<div class="row g-4 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary rounded-3 p-3"><i class="bi bi-receipt fs-4"></i></div>
        <div>
          <div class="text-muted small">Total Invoices</div>
          <div class="fs-3 fw-bold"><?= number_format((int)$total_invoices) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning rounded-3 p-3"><i class="bi bi-hourglass-split fs-4"></i></div>
        <div>
          <div class="text-muted small">Pending Payment</div>
          <div class="fs-4 fw-bold"><?= format_currency((float)$pending_amount) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success rounded-3 p-3"><i class="bi bi-check-circle fs-4"></i></div>
        <div>
          <div class="text-muted small">Paid This Month</div>
          <div class="fs-4 fw-bold"><?= format_currency((float)$paid_this_month) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-danger-subtle text-danger rounded-3 p-3"><i class="bi bi-exclamation-triangle fs-4"></i></div>
        <div>
          <div class="text-muted small">Overdue</div>
          <div class="fs-3 fw-bold text-danger"><?= number_format((int)$overdue_count) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Recent invoices -->
  <div class="col-xl-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-semibold mb-0">Recent Invoices</h6>
        <a href="/invoices" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
              <th>Invoice #</th><th>Customer</th><th>Date</th><th>Amount</th><th>Status</th><th>e-Invoice</th>
            </tr></thead>
            <tbody>
            <?php if ($recent): foreach($recent as $inv): ?>
            <tr>
              <td><a href="/invoice/view?id=<?= $inv['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($inv['invoice_number']) ?></a></td>
              <td><?= sanitize($inv['customer_name']) ?></td>
              <td><?= format_date($inv['issue_date']) ?></td>
              <td class="fw-semibold"><?= format_currency((float)$inv['total_amount']) ?></td>
              <td><?= get_invoice_status_badge($inv['status']) ?></td>
              <td><?= get_einvoice_status_badge($inv['einvoice_status']) ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No invoices yet. <a href="/invoice/create">Create your first invoice</a>.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Revenue chart -->
  <div class="col-xl-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 pt-3 pb-0">
        <h6 class="fw-semibold mb-0">Monthly Revenue (6 months)</h6>
      </div>
      <div class="card-body">
        <canvas id="revenueChart"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode(array_column($monthly, 'month_label')) ?>;
const data   = <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $monthly)) ?>;
new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Revenue (MYR)',
      data,
      backgroundColor: 'rgba(13,110,253,0.7)',
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

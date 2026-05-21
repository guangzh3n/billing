<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'reports';
$page_title   = 'Reports';

// Monthly revenue (12 months)
$monthly_revenue = $pdo->query("
    SELECT DATE_FORMAT(payment_date,'%b %Y') AS label,
           DATE_FORMAT(payment_date,'%Y-%m') AS key_val,
           SUM(amount) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY key_val, label
    ORDER BY key_val
")->fetchAll();

// Outstanding invoices
$outstanding = $pdo->query("
    SELECT i.*, c.name AS customer_name,
           DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
           (i.total_amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id=i.id),0)) AS balance
    FROM invoices i JOIN customers c ON c.id=i.customer_id
    WHERE i.status IN ('sent','overdue')
    HAVING balance > 0
    ORDER BY i.due_date ASC
")->fetchAll();

// Payment methods breakdown
$pay_methods = $pdo->query("
    SELECT payment_method, COUNT(*) AS cnt, SUM(amount) AS total
    FROM payments
    WHERE YEAR(payment_date)=YEAR(NOW())
    GROUP BY payment_method
    ORDER BY total DESC
")->fetchAll();

// Customer with most revenue
$top_customers = $pdo->query("
    SELECT c.name, SUM(p.amount) AS total_paid, COUNT(DISTINCT i.id) AS invoice_count
    FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN customers c ON c.id=i.customer_id
    WHERE YEAR(p.payment_date)=YEAR(NOW())
    GROUP BY c.id, c.name
    ORDER BY total_paid DESC
    LIMIT 10
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<ul class="nav nav-tabs mb-4" id="reportTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-revenue">Revenue</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-outstanding">Outstanding</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-methods">Payment Methods</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-customers">Top Customers</a></li>
</ul>

<div class="tab-content">

  <!-- Revenue tab -->
  <div class="tab-pane fade show active" id="tab-revenue">
    <div class="row g-4">
      <div class="col-xl-8">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">Monthly Revenue (12 months)</h6></div>
          <div class="card-body"><canvas id="monthlyChart" height="100"></canvas></div>
        </div>
      </div>
      <div class="col-xl-4">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">Monthly Summary</h6></div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead class="table-light"><tr><th>Month</th><th class="text-end">Revenue</th></tr></thead>
              <tbody>
              <?php foreach(array_reverse($monthly_revenue) as $m): ?>
              <tr><td><?= sanitize($m['label']) ?></td><td class="text-end"><?= format_currency((float)$m['total']) ?></td></tr>
              <?php endforeach; ?>
              <?php if(empty($monthly_revenue)): ?><tr><td colspan="2" class="text-muted text-center">No data</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Outstanding tab -->
  <div class="tab-pane fade" id="tab-outstanding">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0 d-flex justify-content-between">
        <h6 class="fw-semibold">Outstanding Invoices</h6>
        <span class="badge bg-danger"><?= count($outstanding) ?> invoices</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
              <th>Invoice #</th><th>Customer</th><th>Issue Date</th><th>Due Date</th>
              <th>Days Overdue</th><th class="text-end">Balance</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach($outstanding as $inv): ?>
            <tr>
              <td><a href="/invoice/view?id=<?= $inv['id'] ?>"><?= sanitize($inv['invoice_number']) ?></a></td>
              <td><?= sanitize($inv['customer_name']) ?></td>
              <td><?= format_date($inv['issue_date']) ?></td>
              <td><?= format_date($inv['due_date']) ?></td>
              <td>
                <?php if($inv['days_overdue'] > 0): ?>
                <span class="badge bg-danger"><?= $inv['days_overdue'] ?> days</span>
                <?php elseif($inv['due_date']): ?>
                <span class="badge bg-warning text-dark">Due in <?= abs($inv['days_overdue']) ?> days</span>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td class="text-end fw-semibold text-danger"><?= format_currency((float)$inv['balance']) ?></td>
              <td><?= get_invoice_status_badge($inv['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($outstanding)): ?>
            <tr><td colspan="7" class="text-center text-success py-4"><i class="bi bi-check-circle"></i> No outstanding invoices!</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment methods tab -->
  <div class="tab-pane fade" id="tab-methods">
    <div class="row g-4">
      <div class="col-md-5">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">By Payment Method (This Year)</h6></div>
          <div class="card-body"><canvas id="methodsChart"></canvas></div>
        </div>
      </div>
      <div class="col-md-7">
        <div class="card border-0 shadow-sm">
          <div class="card-body p-0">
            <table class="table mb-0">
              <thead class="table-light"><tr><th>Method</th><th class="text-center">Count</th><th class="text-end">Total</th></tr></thead>
              <tbody>
              <?php foreach($pay_methods as $m): ?>
              <tr>
                <td><?= sanitize(ucfirst(str_replace('_',' ',$m['payment_method']))) ?></td>
                <td class="text-center"><?= $m['cnt'] ?></td>
                <td class="text-end"><?= format_currency((float)$m['total']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($pay_methods)): ?><tr><td colspan="3" class="text-muted text-center">No data</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Top customers tab -->
  <div class="tab-pane fade" id="tab-customers">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">Top Customers by Revenue (This Year)</h6></div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead class="table-light"><tr><th>#</th><th>Customer</th><th class="text-center">Invoices</th><th class="text-end">Total Paid</th></tr></thead>
          <tbody>
          <?php foreach($top_customers as $idx=>$c): ?>
          <tr>
            <td class="text-muted"><?= $idx+1 ?></td>
            <td class="fw-semibold"><?= sanitize($c['name']) ?></td>
            <td class="text-center"><?= $c['invoice_count'] ?></td>
            <td class="text-end fw-semibold"><?= format_currency((float)$c['total_paid']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($top_customers)): ?><tr><td colspan="4" class="text-muted text-center">No data</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.tab-content -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
// Monthly revenue chart
new Chart(document.getElementById('monthlyChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($monthly_revenue,'label')) ?>,
    datasets: [{
      label: 'Revenue',
      data: <?= json_encode(array_map(fn($r)=>(float)$r['total'], $monthly_revenue)) ?>,
      borderColor: '#0d6efd',
      backgroundColor: 'rgba(13,110,253,0.1)',
      fill: true,
      tension: 0.3,
      pointRadius: 4,
    }]
  },
  options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});

// Payment methods pie
const methodLabels = <?= json_encode(array_map(fn($m)=>ucfirst(str_replace('_',' ',$m['payment_method'])), $pay_methods)) ?>;
const methodData   = <?= json_encode(array_map(fn($m)=>(float)$m['total'], $pay_methods)) ?>;
if (methodData.length > 0) {
  new Chart(document.getElementById('methodsChart'), {
    type: 'doughnut',
    data: {
      labels: methodLabels,
      datasets: [{ data: methodData, backgroundColor: ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14'] }]
    },
    options: { responsive:true }
  });
}

// Persist tab via localStorage
document.querySelectorAll('#reportTabs .nav-link').forEach(tab => {
  tab.addEventListener('shown.bs.tab', e => localStorage.setItem('reports_tab', e.target.getAttribute('href')));
});
const savedTab = localStorage.getItem('reports_tab');
if (savedTab) {
  const t = document.querySelector(`#reportTabs .nav-link[href="${savedTab}"]`);
  if (t) new bootstrap.Tab(t).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

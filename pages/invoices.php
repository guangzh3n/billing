<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'invoices';
$page_title   = 'Invoices';

// Filters
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$page_num  = max(1,(int)($_GET['p'] ?? 1));

$where_parts = [];
$params = [];

if ($status) { $where_parts[] = 'i.status = ?'; $params[] = $status; }
if ($q) { $where_parts[] = '(i.invoice_number LIKE ? OR c.name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($date_from) { $where_parts[] = 'i.issue_date >= ?'; $params[] = $date_from; }
if ($date_to)   { $where_parts[] = 'i.issue_date <= ?'; $params[] = $date_to; }

$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN customers c ON c.id=i.customer_id $where");
$count_stmt->execute($params);
$pg = paginate((int)$count_stmt->fetchColumn(), $page_num);

$stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id $where ORDER BY i.issue_date DESC, i.id DESC LIMIT ? OFFSET ?");
$stmt->execute([...$params, $pg['per_page'], $pg['offset']]);
$invoices = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Filters -->
<form method="get" action="/invoices" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <input name="q" class="form-control form-control-sm" placeholder="Search invoice #, customer…" value="<?= sanitize($q) ?>">
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach(['draft','sent','paid','overdue','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($date_from) ?>" placeholder="From">
      </div>
      <div class="col-md-2">
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($date_to) ?>" placeholder="To">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm">Filter</button>
        <a href="/invoices" class="btn btn-outline-danger btn-sm">Clear</a>
        <a href="/invoice/create" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg"></i> New</a>
      </div>
    </div>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr>
          <th>Invoice #</th><th>Customer</th><th>Issue Date</th><th>Due Date</th>
          <th>Amount</th><th>Status</th><th>e-Invoice</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($invoices as $inv): ?>
        <tr>
          <td><a href="/invoice/view?id=<?= $inv['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($inv['invoice_number']) ?></a></td>
          <td><?= sanitize($inv['customer_name']) ?></td>
          <td><?= format_date($inv['issue_date']) ?></td>
          <td class="<?= $inv['due_date'] && $inv['due_date'] < date('Y-m-d') && $inv['status']==='sent' ? 'text-danger fw-semibold' : '' ?>">
            <?= format_date($inv['due_date']) ?>
          </td>
          <td class="fw-semibold"><?= format_currency((float)$inv['total_amount']) ?></td>
          <td><?= get_invoice_status_badge($inv['status']) ?></td>
          <td><?= get_einvoice_status_badge($inv['einvoice_status']) ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="/invoice/view?id=<?= $inv['id'] ?>" class="btn btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
              <?php if($inv['status']==='draft'): ?>
              <a href="/invoice/edit?id=<?= $inv['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
              <?php endif; ?>
              <a href="/invoice/print?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($invoices)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No invoices found. <a href="/invoice/create">Create your first invoice</a>.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($pg['total_pages'] > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for($p=1;$p<=$pg['total_pages'];$p++): ?>
  <li class="page-item <?= $p===$pg['page']?'active':'' ?>">
    <a class="page-link" href="/invoices?q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&p=<?= $p ?>"><?= $p ?></a>
  </li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

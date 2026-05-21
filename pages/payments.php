<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'payments';
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    verify_csrf();
    $invoice_id  = (int)$_POST['invoice_id'];
    $amount      = (float)$_POST['amount'];
    $date        = $_POST['payment_date'] ?? date('Y-m-d');
    $method      = $_POST['payment_method'] ?? 'bank_transfer';
    $ref         = trim($_POST['reference_number'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    // Validate
    $inv_stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
    $inv_stmt->execute([$invoice_id]);
    $inv = $inv_stmt->fetch();

    $errors = [];
    if (!$inv) $errors[] = 'Invoice not found.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

    if ($inv && !$errors) {
        $balance = get_invoice_balance($invoice_id, (float)$inv['total_amount']);
        if ($amount > $balance + 0.01) $errors[] = 'Amount exceeds balance due (' . format_currency($balance) . ').';
    }

    if (!$errors) {
        $pdo->prepare("INSERT INTO payments (invoice_id,amount,payment_date,payment_method,reference_number,notes) VALUES (?,?,?,?,?,?)")
            ->execute([$invoice_id,$amount,$date,$method,$ref,$notes]);

        // Update invoice status
        $balance_after = get_invoice_balance($invoice_id, (float)$inv['total_amount']);
        if ($balance_after <= 0.01) {
            $pdo->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$invoice_id]);
        }

        flash('success', 'Payment of ' . format_currency($amount) . ' recorded.');
        redirect('/invoice/view?id=' . $invoice_id);
    }
}

if ($action === 'add') {
    $invoice_id = (int)($_GET['invoice_id'] ?? ($_POST['invoice_id'] ?? 0));
    if (!$invoice_id) redirect('/payments');

    $inv_stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
    $inv_stmt->execute([$invoice_id]);
    $inv = $inv_stmt->fetch();
    if (!$inv) { flash('error','Invoice not found.','error'); redirect('/payments'); }

    $balance = get_invoice_balance($invoice_id, (float)$inv['total_amount']);
    $page_title = 'Record Payment';
} else {
    $page_title = 'Payments';
    $page_num   = max(1,(int)($_GET['p'] ?? 1));

    $total = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    $pg    = paginate((int)$total, $page_num);

    $payments = $pdo->prepare("SELECT p.*, i.invoice_number, c.name AS customer_name
                               FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN customers c ON c.id=i.customer_id
                               ORDER BY p.payment_date DESC, p.id DESC LIMIT ? OFFSET ?");
    $payments->execute([$pg['per_page'], $pg['offset']]);
    $payments = $payments->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($action === 'add'): ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.sanitize($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-md-6">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between mb-1">
        <span class="text-muted">Invoice</span>
        <span class="fw-semibold"><?= sanitize($inv['invoice_number']) ?></span>
      </div>
      <div class="d-flex justify-content-between mb-1">
        <span class="text-muted">Customer</span>
        <span><?= sanitize($inv['customer_name']) ?></span>
      </div>
      <div class="d-flex justify-content-between mb-1">
        <span class="text-muted">Invoice Total</span>
        <span><?= format_currency((float)$inv['total_amount']) ?></span>
      </div>
      <hr class="my-2">
      <div class="d-flex justify-content-between">
        <span class="fw-bold">Balance Due</span>
        <span class="fw-bold text-danger"><?= format_currency($balance) ?></span>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <h6 class="fw-semibold mb-3">Record Payment</h6>
      <form method="post" action="/payments?action=add">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold">Amount (<?= sanitize($inv['currency']) ?>) <span class="text-danger">*</span></label>
          <input name="amount" type="number" step="0.01" min="0.01" max="<?= $balance ?>"
                 class="form-control" required value="<?= number_format($balance,2,'.','') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Payment Date</label>
          <input name="payment_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-select">
            <option value="bank_transfer">Bank Transfer</option>
            <option value="fpx">FPX</option>
            <option value="duitnow">DuitNow</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="credit_card">Credit Card</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Reference Number</label>
          <input name="reference_number" class="form-control" placeholder="Transaction ID, cheque no., etc.">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-success flex-fill"><i class="bi bi-check-lg"></i> Record Payment</button>
          <a href="/invoice/view?id=<?= $invoice_id ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
</div>

<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr>
          <th>Invoice #</th><th>Customer</th><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th>
        </tr></thead>
        <tbody>
        <?php foreach($payments as $p): ?>
        <tr>
          <td><a href="/invoice/view?id=<?= $p['invoice_id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($p['invoice_number']) ?></a></td>
          <td><?= sanitize($p['customer_name']) ?></td>
          <td><?= format_date($p['payment_date']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= sanitize(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></span></td>
          <td class="text-muted small"><?= sanitize($p['reference_number'] ?: '—') ?></td>
          <td class="text-end fw-semibold"><?= format_currency((float)$p['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($payments)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php if(($pg['total_pages']??1)>1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for($p=1;$p<=$pg['total_pages'];$p++): ?>
  <li class="page-item <?= $p===$pg['page']?'active':'' ?>"><a class="page-link" href="/payments?p=<?= $p ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

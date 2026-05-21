<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Invoice not found.','error'); redirect('/invoices'); }

$stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name, c.tin AS customer_tin, c.id_type, c.id_number,
                        c.email AS customer_email, c.phone AS customer_phone,
                        c.address AS customer_address, c.city AS customer_city,
                        c.postcode AS customer_postcode, c.state AS customer_state, c.country AS customer_country
                       FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) { flash('error','Invoice not found.','error'); redirect('/invoices'); }

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

$payments_stmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date DESC");
$payments_stmt->execute([$id]);
$payments = $payments_stmt->fetchAll();

$balance = get_invoice_balance($id, (float)$inv['total_amount']);

$invoice_types = get_invoice_types();
$current_page  = 'invoices';
$page_title    = 'Invoice ' . $inv['invoice_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <a href="/invoices" class="text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Back to Invoices</a>
  </div>
  <div class="d-flex gap-2">
    <a href="/invoice/print?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer"></i> Print / PDF
    </a>
    <?php if ($inv['status'] === 'draft'): ?>
    <a href="/invoice/edit?id=<?= $id ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-pencil"></i> Edit
    </a>
    <?php endif; ?>
    <?php if (in_array($inv['status'],['sent','overdue']) && $balance > 0): ?>
    <a href="/payments?action=add&invoice_id=<?= $id ?>" class="btn btn-success btn-sm">
      <i class="bi bi-cash-coin"></i> Record Payment
    </a>
    <?php endif; ?>
    <?php if (in_array($inv['status'],['draft','sent']) && get_setting('einvoice_enabled','0') === '1'): ?>
    <form method="post" action="/einvoice/submit" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="invoice_id" value="<?= $id ?>">
      <button class="btn btn-warning btn-sm">
        <i class="bi bi-send-check"></i> Submit e-Invoice
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <!-- Invoice header -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-6">
            <div class="fs-5 fw-bold text-primary"><?= sanitize(get_setting('company_name')) ?></div>
            <div class="text-muted small"><?= nl2br(sanitize(get_setting('company_address'))) ?></div>
            <div class="text-muted small"><?= sanitize(get_setting('company_city')) ?>, <?= sanitize(get_setting('company_postcode')) ?></div>
            <div class="text-muted small"><?= sanitize(get_setting('company_phone')) ?></div>
            <div class="text-muted small">TIN: <?= sanitize(get_setting('company_tin','—')) ?></div>
          </div>
          <div class="col-6 text-end">
            <div class="fs-2 fw-bold"><?= sanitize($invoice_types[$inv['invoice_type']] ?? 'Invoice') ?></div>
            <div class="fs-5 text-primary"><?= sanitize($inv['invoice_number']) ?></div>
            <div class="text-muted small">Issue Date: <?= format_date($inv['issue_date']) ?></div>
            <?php if($inv['due_date']): ?><div class="text-muted small">Due Date: <?= format_date($inv['due_date']) ?></div><?php endif; ?>
            <div class="mt-2">
              <?= get_invoice_status_badge($inv['status']) ?>
              <?= get_einvoice_status_badge($inv['einvoice_status']) ?>
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-5">
            <div class="fw-semibold text-muted small text-uppercase mb-1">Bill To</div>
            <div class="fw-semibold"><?= sanitize($inv['customer_name']) ?></div>
            <?php if($inv['customer_address']): ?><div class="text-muted small"><?= nl2br(sanitize($inv['customer_address'])) ?></div><?php endif; ?>
            <div class="text-muted small"><?= sanitize($inv['customer_city']??'') ?><?= $inv['customer_postcode']?' '.$inv['customer_postcode']:'' ?></div>
            <?php if($inv['customer_email']): ?><div class="text-muted small"><?= sanitize($inv['customer_email']) ?></div><?php endif; ?>
            <?php if($inv['customer_tin']): ?><div class="text-muted small">TIN: <?= sanitize($inv['customer_tin']) ?></div><?php endif; ?>
          </div>
        </div>

        <!-- Items table -->
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light"><tr>
              <th>#</th><th>Description</th><th class="text-center">Qty</th><th class="text-center">Unit</th>
              <th class="text-end">Unit Price</th><th class="text-end">Disc%</th>
              <th class="text-end">Tax</th><th class="text-end">Amount</th>
            </tr></thead>
            <tbody>
            <?php foreach($items as $idx=>$item): ?>
            <tr>
              <td class="text-muted"><?= $idx+1 ?></td>
              <td>
                <?= sanitize($item['description']) ?>
                <div class="text-muted" style="font-size:11px">Class: <?= sanitize($item['classification']) ?></div>
              </td>
              <td class="text-center"><?= number_format((float)$item['quantity'],2) ?></td>
              <td class="text-center"><?= sanitize($item['unit']) ?></td>
              <td class="text-end"><?= format_currency((float)$item['unit_price']) ?></td>
              <td class="text-end"><?= $item['discount_rate'] > 0 ? $item['discount_rate'].'%' : '—' ?></td>
              <td class="text-end"><?= $item['tax_rate'] > 0 ? $item['tax_type'].' '.$item['tax_rate'].'%' : 'Exempt' ?></td>
              <td class="text-end fw-semibold"><?= format_currency((float)$item['total']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr><td colspan="7" class="text-end">Subtotal</td><td class="text-end"><?= format_currency((float)$inv['subtotal']) ?></td></tr>
              <?php if($inv['discount_amount'] > 0): ?>
              <tr><td colspan="7" class="text-end text-danger">Discount</td><td class="text-end text-danger">-<?= format_currency((float)$inv['discount_amount']) ?></td></tr>
              <?php endif; ?>
              <tr><td colspan="7" class="text-end">Tax (<?= sanitize(get_setting('tax_label','SST')) ?>)</td><td class="text-end"><?= format_currency((float)$inv['tax_amount']) ?></td></tr>
              <tr class="fw-bold"><td colspan="7" class="text-end fs-6">Total</td><td class="text-end fs-6"><?= format_currency((float)$inv['total_amount']) ?></td></tr>
            </tfoot>
          </table>
        </div>

        <?php if($inv['notes']): ?>
        <div class="mt-2 p-3 bg-light rounded">
          <div class="fw-semibold text-muted small mb-1">Notes</div>
          <?= nl2br(sanitize($inv['notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <!-- Payment summary -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-0 pt-3 pb-0">
        <h6 class="fw-semibold">Payment Summary</h6>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Invoice Total</span>
          <span class="fw-semibold"><?= format_currency((float)$inv['total_amount']) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Amount Paid</span>
          <span class="text-success fw-semibold"><?= format_currency((float)$inv['total_amount'] - $balance) ?></span>
        </div>
        <hr class="my-2">
        <div class="d-flex justify-content-between">
          <span class="fw-bold">Balance Due</span>
          <span class="fw-bold fs-5 <?= $balance > 0 ? 'text-danger' : 'text-success' ?>"><?= format_currency($balance) ?></span>
        </div>
      </div>
    </div>

    <!-- Payments list -->
    <?php if($payments): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-0 pt-3 pb-0">
        <h6 class="fw-semibold">Payment History</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach($payments as $p): ?>
          <tr>
            <td><?= format_date($p['payment_date']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= sanitize(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></span></td>
            <td class="text-end"><?= format_currency((float)$p['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- e-Invoice status -->
    <?php if($inv['einvoice_status'] !== 'none'): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-0 pt-3 pb-0 d-flex justify-content-between">
        <h6 class="fw-semibold">e-Invoice Status</h6>
        <a href="/einvoice/status?invoice_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
      </div>
      <div class="card-body">
        <div class="mb-2"><?= get_einvoice_status_badge($inv['einvoice_status']) ?></div>
        <?php if($inv['einvoice_uuid']): ?>
        <div class="small text-muted">UUID: <code class="user-select-all"><?= sanitize($inv['einvoice_uuid']) ?></code></div>
        <?php endif; ?>
        <?php if($inv['einvoice_long_id']): ?>
        <div class="small text-muted mt-1">Long ID: <code class="user-select-all"><?= sanitize($inv['einvoice_long_id']) ?></code></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Bank details -->
    <?php if(get_setting('company_bank')): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h6 class="fw-semibold mb-2">Payment Instructions</h6>
        <div class="small"><?= sanitize(get_setting('company_bank')) ?></div>
        <div class="small">Account: <strong><?= sanitize(get_setting('company_bank_account')) ?></strong></div>
        <div class="small"><?= sanitize(get_setting('company_bank_holder')) ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

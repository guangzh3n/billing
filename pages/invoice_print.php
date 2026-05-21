<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invoice not found.');

$stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name, c.tin AS customer_tin, c.id_type, c.id_number,
                        c.email AS customer_email, c.phone AS customer_phone,
                        c.address AS customer_address, c.city AS customer_city,
                        c.postcode AS customer_postcode, c.state AS customer_state, c.country AS customer_country
                       FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) die('Invoice not found.');

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

$invoice_types = get_invoice_types();
$company_name    = get_setting('company_name');
$company_tin     = get_setting('company_tin');
$company_address = get_setting('company_address');
$company_city    = get_setting('company_city');
$company_postcode= get_setting('company_postcode');
$company_phone   = get_setting('company_phone');
$company_email   = get_setting('company_email');
$company_bank    = get_setting('company_bank');
$company_acct    = get_setting('company_bank_account');
$company_holder  = get_setting('company_bank_holder');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sanitize($invoice_types[$inv['invoice_type']] ?? 'Invoice') ?> <?= sanitize($inv['invoice_number']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; background: #fff; }
  .invoice-box { max-width: 860px; margin: 30px auto; padding: 30px; border: 1px solid #ddd; }
  .company-name { font-size: 20px; font-weight: 700; color: #0d6efd; }
  .invoice-title { font-size: 28px; font-weight: 700; text-transform: uppercase; }
  .label { color: #6c757d; font-size: 11px; text-transform: uppercase; font-weight: 600; }
  table th { background: #f8f9fa; }
  .totals-table td { padding: 4px 8px; }
  .footer-bar { background: #f8f9fa; padding: 10px; font-size: 11px; text-align: center; color: #6c757d; }
  .einvoice-badge { background: #198754; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
  .no-print { }
  @media print {
    .no-print { display: none !important; }
    .invoice-box { border: none; margin: 0; padding: 15px; max-width: 100%; }
    body { background: #fff; }
    @page { margin: 1cm; }
  }
</style>
</head>
<body>

<div class="no-print text-center py-3 bg-light border-bottom">
  <button onclick="window.print()" class="btn btn-primary me-2"><i class="bi bi-printer"></i> Print / Save as PDF</button>
  <a href="/invoice/view?id=<?= $id ?>" class="btn btn-outline-secondary">Back to Invoice</a>
  <div class="text-muted small mt-1">Use "Save as PDF" in the print dialog to download a PDF.</div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="invoice-box">
  <!-- Header -->
  <div class="row mb-4">
    <div class="col-6">
      <div class="company-name"><?= sanitize($company_name) ?></div>
      <?php if($company_tin): ?><div class="text-muted small">TIN: <?= sanitize($company_tin) ?></div><?php endif; ?>
      <?php if($company_address): ?><div class="small"><?= nl2br(sanitize($company_address)) ?></div><?php endif; ?>
      <?php if($company_city): ?><div class="small"><?= sanitize($company_city) ?><?= $company_postcode?' '.$company_postcode:'' ?></div><?php endif; ?>
      <?php if($company_phone): ?><div class="small">Tel: <?= sanitize($company_phone) ?></div><?php endif; ?>
      <?php if($company_email): ?><div class="small"><?= sanitize($company_email) ?></div><?php endif; ?>
    </div>
    <div class="col-6 text-end">
      <div class="invoice-title"><?= sanitize($invoice_types[$inv['invoice_type']] ?? 'Invoice') ?></div>
      <div class="fs-5 text-primary fw-bold"><?= sanitize($inv['invoice_number']) ?></div>
      <div class="text-muted small mt-2">
        Issue Date: <?= format_date($inv['issue_date']) ?><br>
        <?php if($inv['due_date']): ?>Due Date: <?= format_date($inv['due_date']) ?><br><?php endif; ?>
        Currency: <?= sanitize($inv['currency']) ?>
      </div>
      <?php if($inv['einvoice_status'] === 'valid'): ?>
      <div class="mt-2"><span class="einvoice-badge">&#10003; MyInvois Verified</span></div>
      <?php endif; ?>
    </div>
  </div>

  <hr>

  <!-- Bill to -->
  <div class="row mb-4">
    <div class="col-6">
      <div class="label mb-1">Bill To</div>
      <div class="fw-bold"><?= sanitize($inv['customer_name']) ?></div>
      <?php if($inv['customer_tin']): ?><div class="small text-muted">TIN: <?= sanitize($inv['customer_tin']) ?></div><?php endif; ?>
      <?php if($inv['id_number']): ?><div class="small text-muted"><?= sanitize($inv['id_type']) ?>: <?= sanitize($inv['id_number']) ?></div><?php endif; ?>
      <?php if($inv['customer_address']): ?><div class="small"><?= nl2br(sanitize($inv['customer_address'])) ?></div><?php endif; ?>
      <?php if($inv['customer_city']): ?><div class="small"><?= sanitize($inv['customer_city']) ?><?= $inv['customer_postcode']?' '.$inv['customer_postcode']:'' ?></div><?php endif; ?>
      <?php if($inv['customer_state']): ?><div class="small"><?= sanitize($inv['customer_state']) ?>, <?= sanitize($inv['customer_country']) ?></div><?php endif; ?>
      <?php if($inv['customer_email']): ?><div class="small"><?= sanitize($inv['customer_email']) ?></div><?php endif; ?>
    </div>
    <?php if($company_bank): ?>
    <div class="col-6">
      <div class="label mb-1">Payment To</div>
      <div class="small"><?= sanitize($company_bank) ?></div>
      <div class="small">Account No: <strong><?= sanitize($company_acct) ?></strong></div>
      <div class="small"><?= sanitize($company_holder) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Items table -->
  <table class="table table-bordered table-sm mb-3">
    <thead>
      <tr>
        <th>#</th>
        <th>Description</th>
        <th class="text-center">Qty</th>
        <th class="text-center">Unit</th>
        <th class="text-end">Unit Price</th>
        <th class="text-end">Discount</th>
        <th class="text-end">Tax</th>
        <th class="text-end">Amount (<?= sanitize($inv['currency']) ?>)</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($items as $idx=>$item): ?>
    <tr>
      <td><?= $idx+1 ?></td>
      <td><?= sanitize($item['description']) ?></td>
      <td class="text-center"><?= number_format((float)$item['quantity'],2) ?></td>
      <td class="text-center"><?= sanitize($item['unit']) ?></td>
      <td class="text-end"><?= number_format((float)$item['unit_price'],2) ?></td>
      <td class="text-end"><?= $item['discount_rate'] > 0 ? $item['discount_rate'].'%' : '—' ?></td>
      <td class="text-end"><?= $item['tax_rate'] > 0 ? $item['tax_type'].' '.$item['tax_rate'].'%' : 'E' ?></td>
      <td class="text-end"><?= number_format((float)$item['total'],2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="row justify-content-end mb-4">
    <div class="col-md-5">
      <table class="totals-table w-100">
        <tr><td>Subtotal</td><td class="text-end"><?= sanitize($inv['currency']) ?> <?= number_format((float)$inv['subtotal'],2) ?></td></tr>
        <?php if($inv['discount_amount'] > 0): ?>
        <tr><td class="text-danger">Discount</td><td class="text-end text-danger">-<?= number_format((float)$inv['discount_amount'],2) ?></td></tr>
        <?php endif; ?>
        <tr><td>Tax (<?= sanitize(get_setting('tax_label','SST')) ?>)</td><td class="text-end"><?= number_format((float)$inv['tax_amount'],2) ?></td></tr>
        <tr style="border-top:2px solid #dee2e6">
          <td class="fw-bold pt-2">TOTAL</td>
          <td class="text-end fw-bold fs-5 pt-2"><?= sanitize($inv['currency']) ?> <?= number_format((float)$inv['total_amount'],2) ?></td>
        </tr>
      </table>
    </div>
  </div>

  <?php if($inv['notes']): ?>
  <div class="p-3 bg-light rounded mb-3">
    <div class="label mb-1">Notes</div>
    <?= nl2br(sanitize($inv['notes'])) ?>
  </div>
  <?php endif; ?>

  <!-- e-Invoice QR -->
  <?php if($inv['einvoice_long_id']): ?>
  <div class="row align-items-center mb-3 p-3 border rounded">
    <div class="col-auto">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode($inv['einvoice_long_id']) ?>" width="100" height="100" alt="e-Invoice QR">
    </div>
    <div class="col">
      <div class="fw-bold text-success">MyInvois e-Invoice Verified</div>
      <div class="small text-muted">UUID: <?= sanitize($inv['einvoice_uuid']) ?></div>
      <div class="small text-muted" style="word-break:break-all">Long ID: <?= sanitize($inv['einvoice_long_id']) ?></div>
      <div class="small text-muted">Scan QR to verify at MyInvois portal</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="footer-bar mt-4">
    This is a computer-generated document. <?= sanitize($company_name) ?> — <?= sanitize($company_email) ?>
  </div>
</div>

<script>
// Auto-print on load (can be disabled by removing this)
// window.addEventListener('load', () => window.print());
</script>
</body>
</html>

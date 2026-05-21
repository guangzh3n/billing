<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'invoices';
$page_title   = 'Create Invoice';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $invoice_type  = $_POST['invoice_type'] ?? '01';
    $invoice_num   = trim($_POST['invoice_number'] ?? '');
    $issue_date    = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date      = $_POST['due_date'] ?: null;
    $currency      = $_POST['currency'] ?? 'MYR';
    $notes         = trim($_POST['notes'] ?? '');
    $status_action = $_POST['status_action'] ?? 'draft'; // 'draft' or 'sent'

    $errors = [];
    if (!$customer_id) $errors[] = 'Please select a customer.';
    if (!$invoice_num) $errors[] = 'Invoice number is required.';

    // Build items from POST arrays
    $descs    = $_POST['item_desc']      ?? [];
    $qtys     = $_POST['item_qty']       ?? [];
    $units    = $_POST['item_unit']      ?? [];
    $prices   = $_POST['item_price']     ?? [];
    $discounts= $_POST['item_discount']  ?? [];
    $tax_types= $_POST['item_tax_type']  ?? [];
    $tax_rates= $_POST['item_tax_rate']  ?? [];
    $classifs = $_POST['item_class']     ?? [];
    $prod_ids = $_POST['item_product_id']?? [];

    $items = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $items[] = [
            'product_id'   => (int)($prod_ids[$i] ?? 0) ?: null,
            'description'  => trim($desc),
            'quantity'     => (float)($qtys[$i] ?? 1),
            'unit'         => $units[$i] ?? 'UNIT',
            'unit_price'   => (float)($prices[$i] ?? 0),
            'discount_rate'=> (float)($discounts[$i] ?? 0),
            'tax_type'     => $tax_types[$i] ?? 'E',
            'tax_rate'     => (float)($tax_rates[$i] ?? 0),
            'classification'=> $classifs[$i] ?? '022',
            'tax_amount'   => 0,
            'subtotal'     => 0,
            'total'        => 0,
        ];
    }

    if (empty($items)) $errors[] = 'At least one line item is required.';

    // Check duplicate invoice number
    $dup = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number=?");
    $dup->execute([$invoice_num]);
    if ($dup->fetch()) $errors[] = "Invoice number '$invoice_num' already exists.";

    if (!$errors) {
        $totals = calculate_invoice_totals($items);
        $final_status = $status_action === 'sent' ? 'sent' : 'draft';

        $pdo->prepare("INSERT INTO invoices (invoice_number,customer_id,invoice_type,issue_date,due_date,status,subtotal,tax_amount,discount_amount,total_amount,currency,notes,created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$invoice_num,$customer_id,$invoice_type,$issue_date,$due_date,$final_status,
                       $totals['subtotal'],$totals['tax_amount'],$totals['discount_amount'],$totals['total_amount'],
                       $currency,$notes,$_SESSION['user_id']]);
        $inv_id = (int)$pdo->lastInsertId();

        $item_stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id,product_id,description,quantity,unit,unit_price,discount_rate,tax_type,tax_rate,tax_amount,subtotal,total,classification) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $item_stmt->execute([$inv_id,$item['product_id'],$item['description'],$item['quantity'],$item['unit'],
                                 $item['unit_price'],$item['discount_rate'],$item['tax_type'],$item['tax_rate'],
                                 $item['tax_amount'],$item['subtotal'],$item['total'],$item['classification']]);
        }

        flash('success', 'Invoice ' . $invoice_num . ' created successfully.');
        redirect('/invoice/view?id=' . $inv_id);
    }
}

// Load data for form
$customers_list = $pdo->query("SELECT id,name,tin FROM customers ORDER BY name")->fetchAll();
$products_list  = $pdo->query("SELECT id,name,unit_price,tax_type,tax_rate,classification,unit FROM products ORDER BY name")->fetchAll();
$invoice_number = generate_invoice_number();
// Roll back the counter since we only pre-fill it
$pdo->exec("UPDATE settings SET setting_value = setting_value - 1 WHERE setting_key = 'invoice_next_number'");

$invoice_types   = get_invoice_types();
$tax_types_map   = get_tax_types();
$classifications = get_myinvois_classifications();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-10">
<form method="post" action="/invoice/create" id="invoiceForm">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.sanitize($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
        <select name="customer_id" class="form-select" required id="customerSelect">
          <option value="">-- Select Customer --</option>
          <?php foreach($customers_list as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (isset($_POST['customer_id']) && $_POST['customer_id']==$c['id'])?'selected':'' ?>><?= sanitize($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <a href="/customers?action=add" class="form-text text-primary" target="_blank">+ Add new customer</a>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Invoice Type</label>
        <select name="invoice_type" class="form-select">
          <?php foreach($invoice_types as $code=>$label): ?>
          <option value="<?= $code ?>" <?= ($_POST['invoice_type']??'01')===$code?'selected':'' ?>><?= $code ?> - <?= sanitize($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Invoice Number <span class="text-danger">*</span></label>
        <input name="invoice_number" class="form-control" required value="<?= sanitize($_POST['invoice_number'] ?? $invoice_number) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Currency</label>
        <select name="currency" class="form-select">
          <?php foreach(['MYR','USD','SGD','EUR','GBP'] as $cur): ?>
          <option value="<?= $cur ?>" <?= ($_POST['currency']??'MYR')===$cur?'selected':'' ?>><?= $cur ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Issue Date</label>
        <input name="issue_date" type="date" class="form-control" value="<?= sanitize($_POST['issue_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Due Date</label>
        <input name="due_date" type="date" class="form-control" value="<?= sanitize($_POST['due_date'] ?? '') ?>">
      </div>
    </div>
  </div>
</div>

<!-- Line items -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-semibold">Line Items</h6>
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLineItem()"><i class="bi bi-plus-lg"></i> Add Item</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle" id="lineItemsTable">
        <thead class="table-light"><tr>
          <th style="min-width:200px">Description</th>
          <th style="width:70px">Qty</th>
          <th style="width:80px">Unit</th>
          <th style="width:100px">Unit Price</th>
          <th style="width:70px">Disc%</th>
          <th style="width:120px">Tax Type</th>
          <th style="width:70px">Tax%</th>
          <th style="width:100px">Amount</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="lineItemsBody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Totals + notes -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <label class="form-label fw-semibold">Notes</label>
        <textarea name="notes" class="form-control" rows="4" placeholder="Payment instructions, terms, etc."><?= sanitize($_POST['notes'] ?? '') ?></textarea>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <table class="table table-sm mb-3">
          <tr><td>Subtotal</td><td class="text-end fw-semibold" id="totalSubtotal">MYR 0.00</td></tr>
          <tr><td>Discount</td><td class="text-end" id="totalDiscount">MYR 0.00</td></tr>
          <tr><td>Tax</td><td class="text-end" id="totalTax">MYR 0.00</td></tr>
          <tr class="table-active"><td class="fw-bold">Total</td><td class="text-end fw-bold fs-5" id="totalAmount">MYR 0.00</td></tr>
        </table>
        <div class="d-flex gap-2">
          <button type="submit" name="status_action" value="draft" class="btn btn-outline-secondary flex-fill">
            <i class="bi bi-floppy"></i> Save Draft
          </button>
          <button type="submit" name="status_action" value="sent" class="btn btn-primary flex-fill">
            <i class="bi bi-send"></i> Save &amp; Send
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

</form>
</div>
</div>

<script>
const PRODUCTS = <?= json_encode($products_list) ?>;
const TAX_TYPES = <?= json_encode($tax_types_map) ?>;
const CLASSIFICATIONS = <?= json_encode($classifications) ?>;
const CURRENCY = '<?= get_setting('currency','MYR') ?>';

let lineItemCount = 0;

function addLineItem(prod = null) {
  lineItemCount++;
  const i = lineItemCount;
  const tbody = document.getElementById('lineItemsBody');
  const tr = document.createElement('tr');
  tr.id = 'row_' + i;

  const taxOptions = Object.entries(TAX_TYPES).map(([k,v]) =>
    `<option value="${k}" ${prod && prod.tax_type===k ? 'selected':''}>${v}</option>`
  ).join('');

  const classOptions = Object.entries(CLASSIFICATIONS).map(([k,v]) =>
    `<option value="${k}" ${prod && prod.classification===k ? 'selected':''}>${k}</option>`
  ).join('');

  tr.innerHTML = `
    <td>
      <input type="hidden" name="item_product_id[]" value="${prod ? prod.id : ''}">
      <input name="item_desc[]" class="form-control form-control-sm" required placeholder="Description"
             value="${prod ? prod.name : ''}" list="product-list-${i}">
      <datalist id="product-list-${i}">
        ${PRODUCTS.map(p => `<option value="${p.name}" data-id="${p.id}">${p.name}</option>`).join('')}
      </datalist>
      <select name="item_class[]" class="form-select form-select-sm mt-1">${classOptions}</select>
    </td>
    <td><input name="item_qty[]" type="number" step="0.0001" min="0" class="form-control form-control-sm line-qty" value="${prod ? 1 : 1}" onchange="calcRow(${i})"></td>
    <td><input name="item_unit[]" class="form-control form-control-sm" value="${prod ? prod.unit : 'UNIT'}"></td>
    <td><input name="item_price[]" type="number" step="0.01" min="0" class="form-control form-control-sm line-price" value="${prod ? prod.unit_price : '0.00'}" onchange="calcRow(${i})"></td>
    <td><input name="item_discount[]" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm line-disc" value="0" onchange="calcRow(${i})"></td>
    <td>
      <select name="item_tax_type[]" class="form-select form-select-sm line-taxtype" onchange="taxTypeChanged(${i})">${taxOptions}</select>
    </td>
    <td><input name="item_tax_rate[]" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm line-taxrate" value="${prod ? prod.tax_rate : '0.00'}" onchange="calcRow(${i})"></td>
    <td class="fw-semibold text-end line-total-display" id="line_total_${i}">0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLineItem(${i})"><i class="bi bi-x"></i></button></td>
  `;
  tbody.appendChild(tr);
  calcRow(i);
}

function removeLineItem(i) {
  const row = document.getElementById('row_' + i);
  if (row) row.remove();
  calculateTotals();
}

function taxTypeChanged(i) {
  const row = document.getElementById('row_' + i);
  const tt = row.querySelector('.line-taxtype').value;
  const rateMap = {'01': 10, '02': 8, '03': 10, '04': 5, 'E': 0, 'OE': 0, 'AE': 0};
  row.querySelector('.line-taxrate').value = (rateMap[tt] ?? 0).toFixed(2);
  calcRow(i);
}

function calcRow(i) {
  const row = document.getElementById('row_' + i);
  if (!row) return;
  const qty   = parseFloat(row.querySelector('.line-qty').value) || 0;
  const price = parseFloat(row.querySelector('.line-price').value) || 0;
  const disc  = parseFloat(row.querySelector('.line-disc').value) || 0;
  const taxR  = parseFloat(row.querySelector('.line-taxrate').value) || 0;
  const gross     = qty * price;
  const discAmt   = gross * (disc / 100);
  const taxable   = gross - discAmt;
  const taxAmt    = taxable * (taxR / 100);
  const total     = taxable + taxAmt;
  row.querySelector('.line-total-display').textContent = total.toFixed(2);
  calculateTotals();
}

function calculateTotals() {
  let subtotal = 0, discount = 0, tax = 0;
  document.querySelectorAll('#lineItemsBody tr').forEach(row => {
    const qty   = parseFloat(row.querySelector('.line-qty')?.value) || 0;
    const price = parseFloat(row.querySelector('.line-price')?.value) || 0;
    const disc  = parseFloat(row.querySelector('.line-disc')?.value) || 0;
    const taxR  = parseFloat(row.querySelector('.line-taxrate')?.value) || 0;
    const gross   = qty * price;
    const discAmt = gross * (disc / 100);
    const taxable = gross - discAmt;
    const taxAmt  = taxable * (taxR / 100);
    subtotal += taxable;
    discount += discAmt;
    tax      += taxAmt;
  });
  const total = subtotal + tax;
  document.getElementById('totalSubtotal').textContent = CURRENCY + ' ' + subtotal.toFixed(2);
  document.getElementById('totalDiscount').textContent = CURRENCY + ' ' + discount.toFixed(2);
  document.getElementById('totalTax').textContent      = CURRENCY + ' ' + tax.toFixed(2);
  document.getElementById('totalAmount').textContent   = CURRENCY + ' ' + total.toFixed(2);
}

// Quick product select
document.addEventListener('input', function(e) {
  if (e.target.tagName === 'INPUT' && e.target.name === 'item_desc[]') {
    const val = e.target.value;
    const prod = PRODUCTS.find(p => p.name === val);
    if (prod) {
      const row = e.target.closest('tr');
      row.querySelector('[name="item_product_id[]"]').value = prod.id;
      row.querySelector('.line-price').value = parseFloat(prod.unit_price).toFixed(2);
      row.querySelector('.line-taxtype').value = prod.tax_type;
      row.querySelector('.line-taxrate').value = parseFloat(prod.tax_rate).toFixed(2);
      const i = row.id.replace('row_','');
      calcRow(i);
    }
  }
});

// Add one empty row on load
addLineItem();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

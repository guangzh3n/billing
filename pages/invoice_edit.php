<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error','Invoice not found.','error'); redirect('/invoices'); }

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) { flash('error','Invoice not found.','error'); redirect('/invoices'); }
if ($inv['status'] !== 'draft') { flash('error','Only draft invoices can be edited.','error'); redirect('/invoice/view?id='.$id); }

$items_stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items_stmt->execute([$id]);
$existing_items = $items_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customer_id  = (int)$_POST['customer_id'];
    $invoice_type = $_POST['invoice_type'] ?? '01';
    $invoice_num  = trim($_POST['invoice_number'] ?? '');
    $issue_date   = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date     = $_POST['due_date'] ?: null;
    $currency     = $_POST['currency'] ?? 'MYR';
    $notes        = trim($_POST['notes'] ?? '');
    $status_action= $_POST['status_action'] ?? 'draft';

    $errors = [];
    if (!$customer_id) $errors[] = 'Please select a customer.';
    if (!$invoice_num) $errors[] = 'Invoice number is required.';

    // Check duplicate number (excluding current)
    $dup = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number=? AND id!=?");
    $dup->execute([$invoice_num, $id]);
    if ($dup->fetch()) $errors[] = "Invoice number '$invoice_num' already exists.";

    $descs   = $_POST['item_desc']      ?? [];
    $qtys    = $_POST['item_qty']       ?? [];
    $units   = $_POST['item_unit']      ?? [];
    $prices  = $_POST['item_price']     ?? [];
    $discounts=$_POST['item_discount']  ?? [];
    $tax_types=$_POST['item_tax_type']  ?? [];
    $tax_rates=$_POST['item_tax_rate']  ?? [];
    $classifs= $_POST['item_class']     ?? [];
    $prod_ids= $_POST['item_product_id']?? [];

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

    if (!$errors) {
        $totals = calculate_invoice_totals($items);
        $final_status = $status_action === 'sent' ? 'sent' : 'draft';

        $pdo->prepare("UPDATE invoices SET invoice_number=?,customer_id=?,invoice_type=?,issue_date=?,due_date=?,status=?,subtotal=?,tax_amount=?,discount_amount=?,total_amount=?,currency=?,notes=? WHERE id=?")
            ->execute([$invoice_num,$customer_id,$invoice_type,$issue_date,$due_date,$final_status,
                       $totals['subtotal'],$totals['tax_amount'],$totals['discount_amount'],$totals['total_amount'],
                       $currency,$notes,$id]);

        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        $item_stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id,product_id,description,quantity,unit,unit_price,discount_rate,tax_type,tax_rate,tax_amount,subtotal,total,classification) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $item_stmt->execute([$id,$item['product_id'],$item['description'],$item['quantity'],$item['unit'],
                                 $item['unit_price'],$item['discount_rate'],$item['tax_type'],$item['tax_rate'],
                                 $item['tax_amount'],$item['subtotal'],$item['total'],$item['classification']]);
        }

        flash('success', 'Invoice updated.');
        redirect('/invoice/view?id=' . $id);
    }
}

$customers_list = $pdo->query("SELECT id,name,tin FROM customers ORDER BY name")->fetchAll();
$products_list  = $pdo->query("SELECT id,name,unit_price,tax_type,tax_rate,classification,unit FROM products ORDER BY name")->fetchAll();
$invoice_types  = get_invoice_types();
$tax_types_map  = get_tax_types();
$classifications= get_myinvois_classifications();

$current_page = 'invoices';
$page_title   = 'Edit Invoice ' . $inv['invoice_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-10">
<form method="post" action="/invoice/edit?id=<?= $id ?>" id="invoiceForm">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.sanitize($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
        <select name="customer_id" class="form-select" required>
          <option value="">-- Select Customer --</option>
          <?php foreach($customers_list as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $inv['customer_id']==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Invoice Type</label>
        <select name="invoice_type" class="form-select">
          <?php foreach($invoice_types as $code=>$label): ?>
          <option value="<?= $code ?>" <?= $inv['invoice_type']===$code?'selected':'' ?>><?= $code ?> - <?= sanitize($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Invoice Number <span class="text-danger">*</span></label>
        <input name="invoice_number" class="form-control" required value="<?= sanitize($inv['invoice_number']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Currency</label>
        <select name="currency" class="form-select">
          <?php foreach(['MYR','USD','SGD','EUR','GBP'] as $cur): ?>
          <option value="<?= $cur ?>" <?= $inv['currency']===$cur?'selected':'' ?>><?= $cur ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Issue Date</label>
        <input name="issue_date" type="date" class="form-control" value="<?= $inv['issue_date'] ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Due Date</label>
        <input name="due_date" type="date" class="form-control" value="<?= $inv['due_date'] ?? '' ?>">
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-semibold">Line Items</h6>
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLineItem()"><i class="bi bi-plus-lg"></i> Add Item</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle" id="lineItemsTable">
        <thead class="table-light"><tr>
          <th style="min-width:200px">Description</th><th style="width:70px">Qty</th>
          <th style="width:80px">Unit</th><th style="width:100px">Unit Price</th>
          <th style="width:70px">Disc%</th><th style="width:120px">Tax Type</th>
          <th style="width:70px">Tax%</th><th style="width:100px">Amount</th><th style="width:40px"></th>
        </tr></thead>
        <tbody id="lineItemsBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <label class="form-label fw-semibold">Notes</label>
        <textarea name="notes" class="form-control" rows="4"><?= sanitize($inv['notes'] ?? '') ?></textarea>
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
          <button type="submit" name="status_action" value="draft" class="btn btn-outline-secondary flex-fill">Save Draft</button>
          <button type="submit" name="status_action" value="sent" class="btn btn-primary flex-fill">Save &amp; Send</button>
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
const CURRENCY = '<?= sanitize($inv['currency']) ?>';
const EXISTING_ITEMS = <?= json_encode($existing_items) ?>;
let lineItemCount = 0;

function addLineItem(item = null) {
  lineItemCount++;
  const i = lineItemCount;
  const tbody = document.getElementById('lineItemsBody');
  const tr = document.createElement('tr');
  tr.id = 'row_' + i;
  const taxOptions = Object.entries(TAX_TYPES).map(([k,v]) =>
    `<option value="${k}" ${item && item.tax_type===k?'selected':''}>${v}</option>`).join('');
  const classOptions = Object.entries(CLASSIFICATIONS).map(([k,v]) =>
    `<option value="${k}" ${item && item.classification===k?'selected':''}>${k}</option>`).join('');
  const desc    = item ? item.description    : '';
  const qty     = item ? item.quantity       : '1';
  const unit    = item ? item.unit           : 'UNIT';
  const price   = item ? item.unit_price     : '0.00';
  const disc    = item ? item.discount_rate  : '0';
  const taxRate = item ? item.tax_rate       : '0.00';
  const prodId  = item ? (item.product_id||'') : '';
  tr.innerHTML = `
    <td>
      <input type="hidden" name="item_product_id[]" value="${prodId}">
      <input name="item_desc[]" class="form-control form-control-sm" required value="${desc}">
      <select name="item_class[]" class="form-select form-select-sm mt-1">${classOptions}</select>
    </td>
    <td><input name="item_qty[]" type="number" step="0.0001" class="form-control form-control-sm line-qty" value="${qty}" onchange="calcRow(${i})"></td>
    <td><input name="item_unit[]" class="form-control form-control-sm" value="${unit}"></td>
    <td><input name="item_price[]" type="number" step="0.01" class="form-control form-control-sm line-price" value="${price}" onchange="calcRow(${i})"></td>
    <td><input name="item_discount[]" type="number" step="0.01" class="form-control form-control-sm line-disc" value="${disc}" onchange="calcRow(${i})"></td>
    <td><select name="item_tax_type[]" class="form-select form-select-sm line-taxtype" onchange="taxTypeChanged(${i})">${taxOptions}</select></td>
    <td><input name="item_tax_rate[]" type="number" step="0.01" class="form-control form-control-sm line-taxrate" value="${taxRate}" onchange="calcRow(${i})"></td>
    <td class="fw-semibold text-end line-total-display" id="line_total_${i}">0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLineItem(${i})"><i class="bi bi-x"></i></button></td>`;
  tbody.appendChild(tr);
  calcRow(i);
}
function removeLineItem(i){ const r=document.getElementById('row_'+i); if(r) r.remove(); calculateTotals(); }
function taxTypeChanged(i){
  const r=document.getElementById('row_'+i);
  const tt=r.querySelector('.line-taxtype').value;
  const map={'01':10,'02':8,'03':10,'04':5,'E':0,'OE':0,'AE':0};
  r.querySelector('.line-taxrate').value=(map[tt]??0).toFixed(2);
  calcRow(i);
}
function calcRow(i){
  const r=document.getElementById('row_'+i); if(!r) return;
  const qty=parseFloat(r.querySelector('.line-qty').value)||0;
  const price=parseFloat(r.querySelector('.line-price').value)||0;
  const disc=parseFloat(r.querySelector('.line-disc').value)||0;
  const taxR=parseFloat(r.querySelector('.line-taxrate').value)||0;
  const gross=qty*price; const discAmt=gross*(disc/100); const taxable=gross-discAmt;
  const taxAmt=taxable*(taxR/100); const total=taxable+taxAmt;
  r.querySelector('.line-total-display').textContent=total.toFixed(2);
  calculateTotals();
}
function calculateTotals(){
  let sub=0,disc=0,tax=0;
  document.querySelectorAll('#lineItemsBody tr').forEach(r=>{
    const qty=parseFloat(r.querySelector('.line-qty')?.value)||0;
    const price=parseFloat(r.querySelector('.line-price')?.value)||0;
    const d=parseFloat(r.querySelector('.line-disc')?.value)||0;
    const taxR=parseFloat(r.querySelector('.line-taxrate')?.value)||0;
    const gross=qty*price; const dAmt=gross*(d/100); const taxable=gross-dAmt;
    sub+=taxable; disc+=dAmt; tax+=taxable*(taxR/100);
  });
  document.getElementById('totalSubtotal').textContent=CURRENCY+' '+sub.toFixed(2);
  document.getElementById('totalDiscount').textContent=CURRENCY+' '+disc.toFixed(2);
  document.getElementById('totalTax').textContent=CURRENCY+' '+tax.toFixed(2);
  document.getElementById('totalAmount').textContent=CURRENCY+' '+(sub+tax).toFixed(2);
}
// Load existing items
EXISTING_ITEMS.forEach(item => addLineItem(item));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

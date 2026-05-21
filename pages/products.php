<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'products';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'add';

    $fields = [
        'name'           => trim($_POST['name'] ?? ''),
        'description'    => trim($_POST['description'] ?? ''),
        'unit_price'     => (float)($_POST['unit_price'] ?? 0),
        'tax_type'       => $_POST['tax_type'] ?? 'E',
        'tax_rate'       => (float)($_POST['tax_rate'] ?? 0),
        'classification' => $_POST['classification'] ?? '022',
        'unit'           => trim($_POST['unit'] ?? 'UNIT'),
    ];

    if (!$fields['name']) {
        flash('error', 'Product name is required.', 'error');
        redirect('/products?action=' . ($action === 'edit' ? "edit&id=$id" : 'add'));
    }

    if ($action === 'add') {
        $pdo->prepare("INSERT INTO products (name,description,unit_price,tax_type,tax_rate,classification,unit) VALUES (?,?,?,?,?,?,?)")
            ->execute(array_values($fields));
        flash('success', 'Product added.');
        redirect('/products');
    } elseif ($action === 'edit') {
        $eid = (int)$_POST['id'];
        $pdo->prepare("UPDATE products SET name=?,description=?,unit_price=?,tax_type=?,tax_rate=?,classification=?,unit=? WHERE id=?")
            ->execute([...array_values($fields), $eid]);
        flash('success', 'Product updated.');
        redirect('/products');
    } elseif ($action === 'delete') {
        $did = (int)$_POST['id'];
        $pdo->prepare("UPDATE invoice_items SET product_id=NULL WHERE product_id=?")->execute([$did]);
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$did]);
        flash('success', 'Product deleted.');
        redirect('/products');
    }
}

$product = null;
if (in_array($action, ['edit']) && $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) { flash('error','Product not found.','error'); redirect('/products'); }
}

if ($action === 'list') {
    $search   = trim($_GET['q'] ?? '');
    $page_num = max(1,(int)($_GET['p'] ?? 1));
    $where    = $search ? "WHERE name LIKE ?" : '';
    $params   = $search ? ["%$search%"] : [];

    $total = $pdo->prepare("SELECT COUNT(*) FROM products $where");
    $total->execute($params);
    $pg = paginate((int)$total->fetchColumn(), $page_num);

    $stmt = $pdo->prepare("SELECT * FROM products $where ORDER BY name ASC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $pg['per_page'], $pg['offset']]);
    $products = $stmt->fetchAll();
}

$tax_types_map = get_tax_types();
$classifications_map = get_myinvois_classifications();

$page_title = match($action) { 'add'=>'Add Product','edit'=>'Edit Product', default=>'Products & Services' };
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <form class="d-flex gap-2" method="get" action="/products">
    <input type="hidden" name="page" value="products">
    <input name="q" class="form-control" placeholder="Search products…" value="<?= sanitize($search ?? '') ?>">
    <button class="btn btn-outline-secondary">Search</button>
    <?php if(!empty($search)): ?><a href="/products" class="btn btn-outline-danger">Clear</a><?php endif; ?>
  </form>
  <a href="/products?action=add" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Product</a>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr>
          <th>Name</th><th>Unit Price</th><th>Tax Type</th><th>Tax Rate</th><th>Classification</th><th>Unit</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($products as $p): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= sanitize($p['name']) ?></div>
            <?php if($p['description']): ?><div class="text-muted small"><?= sanitize(mb_strimwidth($p['description'],0,60,'…')) ?></div><?php endif; ?>
          </td>
          <td><?= format_currency((float)$p['unit_price']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= sanitize($tax_types_map[$p['tax_type']] ?? $p['tax_type']) ?></span></td>
          <td><?= $p['tax_rate'] > 0 ? $p['tax_rate'].'%' : '—' ?></td>
          <td class="text-muted small"><?= sanitize($p['classification']) ?></td>
          <td><?= sanitize($p['unit']) ?></td>
          <td>
            <a href="/products?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <form method="post" action="/products" class="d-inline" onsubmit="return confirm('Delete this product?')">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($products)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No products yet. <a href="/products?action=add">Add one</a>.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif (in_array($action, ['add','edit'])): ?>
<div class="card border-0 shadow-sm" style="max-width:620px">
  <div class="card-body p-4">
    <form method="post" action="/products">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="action" value="<?= $action ?>">
      <?php if($action==='edit'): ?><input type="hidden" name="id" value="<?= $product['id'] ?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">Product / Service Name <span class="text-danger">*</span></label>
          <input name="name" class="form-control" required value="<?= sanitize($product['name'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"><?= sanitize($product['description'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Unit Price (MYR)</label>
          <input name="unit_price" type="number" step="0.01" min="0" class="form-control" value="<?= $product['unit_price'] ?? '0.00' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Unit</label>
          <input name="unit" class="form-control" value="<?= sanitize($product['unit'] ?? 'UNIT') ?>" list="unit-list">
          <datalist id="unit-list">
            <?php foreach(['UNIT','HRS','DAYS','MONTH','KG','SET','PCS','LTR','MTR'] as $u): ?>
            <option value="<?= $u ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tax Type</label>
          <select name="tax_type" class="form-select" id="taxTypeSelect" onchange="updateTaxRate(this.value)">
            <?php foreach($tax_types_map as $code=>$label): ?>
            <option value="<?= $code ?>" data-rate="<?= in_array($code,['01'])? 10 : ($code==='02'?8:0) ?>"
                    <?= ($product['tax_type']??'E')===$code?'selected':'' ?>><?= sanitize($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tax Rate (%)</label>
          <input name="tax_rate" type="number" step="0.01" min="0" max="100" class="form-control" id="taxRateInput" value="<?= $product['tax_rate'] ?? '0.00' ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">MyInvois Classification</label>
          <select name="classification" class="form-select">
            <?php foreach($classifications_map as $code=>$label): ?>
            <option value="<?= $code ?>" <?= ($product['classification']??'022')===$code?'selected':'' ?>><?= sanitize($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary"><?= $action==='add'?'Add Product':'Save Changes' ?></button>
        <a href="/products" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
function updateTaxRate(taxType) {
  const rates = { '01': 10, '02': 8, '03': 10, '04': 5, 'E': 0, 'OE': 0, 'AE': 0 };
  document.getElementById('taxRateInput').value = (rates[taxType] ?? 0).toFixed(2);
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

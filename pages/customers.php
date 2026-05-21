<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'customers';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'add';

    $fields = [
        'name'      => trim($_POST['name'] ?? ''),
        'tin'       => trim($_POST['tin'] ?? ''),
        'id_type'   => $_POST['id_type'] ?? 'BRN',
        'id_number' => trim($_POST['id_number'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'phone'     => trim($_POST['phone'] ?? ''),
        'address'   => trim($_POST['address'] ?? ''),
        'city'      => trim($_POST['city'] ?? ''),
        'postcode'  => trim($_POST['postcode'] ?? ''),
        'state'     => trim($_POST['state'] ?? ''),
        'country'   => trim($_POST['country'] ?? 'Malaysia'),
    ];

    $errors = [];
    if (!$fields['name']) $errors[] = 'Customer name is required.';

    if (!$errors) {
        if ($action === 'add') {
            $pdo->prepare("INSERT INTO customers (name,tin,id_type,id_number,email,phone,address,city,postcode,state,country) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_values($fields));
            flash('success', 'Customer added successfully.');
            redirect('/customers');
        } elseif ($action === 'edit') {
            $eid = (int)$_POST['id'];
            $pdo->prepare("UPDATE customers SET name=?,tin=?,id_type=?,id_number=?,email=?,phone=?,address=?,city=?,postcode=?,state=?,country=? WHERE id=?")
                ->execute([...array_values($fields), $eid]);
            flash('success', 'Customer updated.');
            redirect('/customers');
        } elseif ($action === 'delete') {
            $did = (int)$_POST['id'];
            $count = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id=?");
            $count->execute([$did]);
            if ($count->fetchColumn() > 0) {
                flash('error', 'Cannot delete: customer has existing invoices.', 'error');
            } else {
                $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$did]);
                flash('success', 'Customer deleted.');
            }
            redirect('/customers');
        }
    }
}

// ── Load data ────────────────────────────────────────────────────────────────
$customer = null;
if (in_array($action, ['edit','view']) && $id) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) { flash('error','Customer not found.','error'); redirect('/customers'); }
}

if ($action === 'list') {
    $search = trim($_GET['q'] ?? '');
    $page_num = max(1, (int)($_GET['p'] ?? 1));
    $where = $search ? "WHERE name LIKE ? OR email LIKE ? OR tin LIKE ?" : '';
    $params = $search ? ["%$search%","%$search%","%$search%"] : [];

    $total = $pdo->prepare("SELECT COUNT(*) FROM customers $where");
    $total->execute($params);
    $pg = paginate((int)$total->fetchColumn(), $page_num);

    $stmt = $pdo->prepare("SELECT * FROM customers $where ORDER BY name ASC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $pg['per_page'], $pg['offset']]);
    $customers = $stmt->fetchAll();
}

$page_title = match($action) {
    'add'  => 'Add Customer',
    'edit' => 'Edit Customer',
    'view' => 'Customer Details',
    default => 'Customers',
};
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <form class="d-flex gap-2" method="get" action="/customers">
    <input type="hidden" name="page" value="customers">
    <input name="q" class="form-control" placeholder="Search name, email, TIN…" value="<?= sanitize($search ?? '') ?>">
    <button class="btn btn-outline-secondary">Search</button>
    <?php if (!empty($search)): ?><a href="/customers" class="btn btn-outline-danger">Clear</a><?php endif; ?>
  </form>
  <a href="/customers?action=add" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Customer</a>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr>
          <th>Name</th><th>TIN</th><th>Email</th><th>Phone</th><th>City</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($customers as $c): ?>
        <tr>
          <td><a href="/customers?action=view&id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($c['name']) ?></a></td>
          <td><span class="text-muted"><?= sanitize($c['tin'] ?: '—') ?></span></td>
          <td><?= sanitize($c['email'] ?: '—') ?></td>
          <td><?= sanitize($c['phone'] ?: '—') ?></td>
          <td><?= sanitize($c['city'] ?: '—') ?></td>
          <td>
            <a href="/customers?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <form method="post" action="/customers" class="d-inline" onsubmit="return confirm('Delete this customer?')">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($customers)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No customers found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (($pg['total_pages'] ?? 1) > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for($p=1;$p<=$pg['total_pages'];$p++): ?>
  <li class="page-item <?= $p===$pg['page']?'active':'' ?>">
    <a class="page-link" href="/customers?q=<?= urlencode($search??'') ?>&p=<?= $p ?>"><?= $p ?></a>
  </li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php elseif (in_array($action, ['add','edit'])): ?>
<div class="card border-0 shadow-sm" style="max-width:700px">
  <div class="card-body p-4">
    <form method="post" action="/customers">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="action" value="<?= $action ?>">
      <?php if ($action==='edit'): ?><input type="hidden" name="id" value="<?= $customer['id'] ?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">Company / Customer Name <span class="text-danger">*</span></label>
          <input name="name" class="form-control" required value="<?= sanitize($customer['name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">TIN (Tax Identification Number)</label>
          <input name="tin" class="form-control" placeholder="e.g. C2345678910" value="<?= sanitize($customer['tin'] ?? '') ?>">
          <div class="form-text">Required for MyInvois e-Invoice submission.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">ID Type</label>
          <select name="id_type" class="form-select">
            <?php foreach(['BRN','NRIC','PASSPORT','ARMY'] as $t): ?>
            <option value="<?= $t ?>" <?= ($customer['id_type']??'BRN')===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">ID Number</label>
          <input name="id_number" class="form-control" value="<?= sanitize($customer['id_number'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input name="email" type="email" class="form-control" value="<?= sanitize($customer['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input name="phone" class="form-control" value="<?= sanitize($customer['phone'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <textarea name="address" class="form-control" rows="2"><?= sanitize($customer['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">City</label>
          <input name="city" class="form-control" value="<?= sanitize($customer['city'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Postcode</label>
          <input name="postcode" class="form-control" value="<?= sanitize($customer['postcode'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">State</label>
          <select name="state" class="form-select">
            <option value="">-- Select --</option>
            <?php foreach(get_malaysia_states() as $s): ?>
            <option value="<?= $s ?>" <?= ($customer['state']??'')===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Country</label>
          <input name="country" class="form-control" value="<?= sanitize($customer['country'] ?? 'Malaysia') ?>">
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary"><?= $action==='add'?'Add Customer':'Save Changes' ?></button>
        <a href="/customers" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php elseif ($action === 'view' && $customer): ?>
<div class="row g-4">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0 d-flex justify-content-between">
        <h6 class="fw-semibold">Customer Details</h6>
        <a href="/customers?action=edit&id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
      </div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= sanitize($customer['name']) ?></dd>
          <dt class="col-sm-4">TIN</dt><dd class="col-sm-8"><?= sanitize($customer['tin'] ?: '—') ?></dd>
          <dt class="col-sm-4">ID Type</dt><dd class="col-sm-8"><?= sanitize($customer['id_type']) ?></dd>
          <dt class="col-sm-4">ID Number</dt><dd class="col-sm-8"><?= sanitize($customer['id_number'] ?: '—') ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= sanitize($customer['email'] ?: '—') ?></dd>
          <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= sanitize($customer['phone'] ?: '—') ?></dd>
          <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= nl2br(sanitize($customer['address'] ?: '—')) ?></dd>
          <dt class="col-sm-4">City</dt><dd class="col-sm-8"><?= sanitize($customer['city'] ?: '—') ?></dd>
          <dt class="col-sm-4">Postcode</dt><dd class="col-sm-8"><?= sanitize($customer['postcode'] ?: '—') ?></dd>
          <dt class="col-sm-4">State</dt><dd class="col-sm-8"><?= sanitize($customer['state'] ?: '—') ?></dd>
          <dt class="col-sm-4">Country</dt><dd class="col-sm-8"><?= sanitize($customer['country']) ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <?php
    $cust_invoices = $pdo->prepare("SELECT * FROM invoices WHERE customer_id=? ORDER BY issue_date DESC LIMIT 10");
    $cust_invoices->execute([$customer['id']]);
    $cinvs = $cust_invoices->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0">
        <h6 class="fw-semibold">Recent Invoices</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach($cinvs as $ci): ?>
          <tr>
            <td><a href="/invoice/view?id=<?= $ci['id'] ?>"><?= sanitize($ci['invoice_number']) ?></a></td>
            <td><?= format_date($ci['issue_date']) ?></td>
            <td><?= format_currency((float)$ci['total_amount']) ?></td>
            <td><?= get_invoice_status_badge($ci['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($cinvs)): ?><tr><td colspan="4" class="text-muted text-center py-2">No invoices</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

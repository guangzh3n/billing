<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$current_page = 'settings';
$page_title   = 'Settings';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tab = $_POST['tab'] ?? 'company';

    if ($tab === 'company') {
        $keys = ['company_name','company_tin','company_id_type','company_id_number','company_email','company_phone','company_address','company_city','company_postcode','company_state','company_country','company_bank','company_bank_account','company_bank_holder'];
        foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? ''));
        flash('success','Company settings saved.');
    } elseif ($tab === 'invoice') {
        $keys = ['invoice_prefix','currency','tax_label','service_tax_rate'];
        foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? ''));
        flash('success','Invoice settings saved.');
    } elseif ($tab === 'einvoice') {
        $keys = ['einvoice_env','einvoice_client_id','einvoice_client_secret','einvoice_enabled'];
        foreach ($keys as $k) set_setting($k, trim($_POST[$k] ?? '0'));
        flash('success','e-Invoice settings saved.');
    } elseif ($tab === 'password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $db_pass = $stmt->fetchColumn();

        if (!password_verify($current, $db_pass)) {
            flash('error','Current password is incorrect.','error');
        } elseif (strlen($new_pass) < 8) {
            flash('error','New password must be at least 8 characters.','error');
        } elseif ($new_pass !== $confirm) {
            flash('error','Passwords do not match.','error');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user['id']]);
            flash('success','Password changed successfully.');
        }
    } elseif ($tab === 'add_user' && $user['role'] === 'admin') {
        $name  = trim($_POST['new_name'] ?? '');
        $email = trim($_POST['new_email'] ?? '');
        $pass  = $_POST['new_password'] ?? '';
        $role  = $_POST['new_role'] ?? 'user';
        if (!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('error','Please fill all fields correctly.','error');
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            if ($check->fetch()) {
                flash('error','Email already exists.','error');
            } else {
                $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)")
                    ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role]);
                flash('success','User created.');
            }
        }
    } elseif ($tab === 'delete_user' && $user['role'] === 'admin') {
        $del_id = (int)$_POST['del_user_id'];
        if ($del_id === (int)$user['id']) {
            flash('error','Cannot delete your own account.','error');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
            flash('success','User deleted.');
        }
    }

    redirect('/settings');
}

// Load all settings
$s = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
    $s[$row['setting_key']] = $row['setting_value'];
}

$users_list = [];
if ($user['role'] === 'admin') {
    $users_list = $pdo->query("SELECT id,name,email,role,created_at FROM users ORDER BY created_at")->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';

function sv(array $s, string $k, string $def=''): string {
    return htmlspecialchars($s[$k] ?? $def, ENT_QUOTES, 'UTF-8');
}
?>

<ul class="nav nav-tabs mb-4" id="settingsTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-company">Company Info</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-invoice">Invoice Settings</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-einvoice">MyInvois e-Invoice</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-security">Security</a></li>
  <?php if($user['role']==='admin'): ?>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-users">Users</a></li>
  <?php endif; ?>
</ul>

<div class="tab-content" style="max-width:700px">

  <!-- Company -->
  <div class="tab-pane fade show active" id="tab-company">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="tab" value="company">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Company Name</label>
              <input name="company_name" class="form-control" value="<?= sv($s,'company_name') ?>"></div>
            <div class="col-md-6"><label class="form-label">TIN (Tax Identification Number)</label>
              <input name="company_tin" class="form-control" value="<?= sv($s,'company_tin') ?>"></div>
            <div class="col-md-3"><label class="form-label">ID Type</label>
              <select name="company_id_type" class="form-select">
                <?php foreach(['BRN','NRIC','PASSPORT','ARMY'] as $t): ?>
                <option value="<?= $t ?>" <?= sv($s,'company_id_type')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-md-3"><label class="form-label">ID Number</label>
              <input name="company_id_number" class="form-control" value="<?= sv($s,'company_id_number') ?>"></div>
            <div class="col-md-6"><label class="form-label">Email</label>
              <input name="company_email" type="email" class="form-control" value="<?= sv($s,'company_email') ?>"></div>
            <div class="col-md-6"><label class="form-label">Phone</label>
              <input name="company_phone" class="form-control" value="<?= sv($s,'company_phone') ?>"></div>
            <div class="col-12"><label class="form-label">Address</label>
              <textarea name="company_address" class="form-control" rows="2"><?= sv($s,'company_address') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">City</label>
              <input name="company_city" class="form-control" value="<?= sv($s,'company_city') ?>"></div>
            <div class="col-md-2"><label class="form-label">Postcode</label>
              <input name="company_postcode" class="form-control" value="<?= sv($s,'company_postcode') ?>"></div>
            <div class="col-md-3"><label class="form-label">State</label>
              <select name="company_state" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach(get_malaysia_states() as $st): ?>
                <option value="<?= $st ?>" <?= sv($s,'company_state')===$st?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-md-3"><label class="form-label">Country</label>
              <input name="company_country" class="form-control" value="<?= sv($s,'company_country','Malaysia') ?>"></div>
            <div class="col-md-5"><label class="form-label">Bank Name</label>
              <input name="company_bank" class="form-control" value="<?= sv($s,'company_bank') ?>"></div>
            <div class="col-md-4"><label class="form-label">Account Number</label>
              <input name="company_bank_account" class="form-control" value="<?= sv($s,'company_bank_account') ?>"></div>
            <div class="col-md-3"><label class="form-label">Account Holder</label>
              <input name="company_bank_holder" class="form-control" value="<?= sv($s,'company_bank_holder') ?>"></div>
          </div>
          <button class="btn btn-primary mt-3">Save Company Info</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Invoice settings -->
  <div class="tab-pane fade" id="tab-invoice">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="tab" value="invoice">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-semibold">Invoice Number Prefix</label>
              <input name="invoice_prefix" class="form-control" value="<?= sv($s,'invoice_prefix','INV') ?>">
              <div class="form-text">e.g. INV → INV00001</div></div>
            <div class="col-md-4"><label class="form-label">Default Currency</label>
              <select name="currency" class="form-select">
                <?php foreach(['MYR','USD','SGD','EUR','GBP'] as $cur): ?>
                <option value="<?= $cur ?>" <?= sv($s,'currency','MYR')===$cur?'selected':'' ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-md-4"><label class="form-label">Tax Label</label>
              <input name="tax_label" class="form-control" value="<?= sv($s,'tax_label','SST') ?>">
              <div class="form-text">Displayed on invoices (SST, GST, etc.)</div></div>
            <div class="col-md-4"><label class="form-label">Default Service Tax Rate (%)</label>
              <input name="service_tax_rate" type="number" step="0.01" class="form-control" value="<?= sv($s,'service_tax_rate','8.00') ?>"></div>
          </div>
          <button class="btn btn-primary mt-3">Save Invoice Settings</button>
        </form>
      </div>
    </div>
  </div>

  <!-- e-Invoice / MyInvois -->
  <div class="tab-pane fade" id="tab-einvoice">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="alert alert-info mb-3">
          <strong>Malaysia MyInvois (LHDN)</strong> — Register at
          <a href="https://myinvois.hasil.gov.my" target="_blank">myinvois.hasil.gov.my</a>
          to obtain your Client ID and Client Secret.
          Start with <strong>Sandbox</strong> for testing.
        </div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="tab" value="einvoice">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="einvoice_enabled" value="1" id="einvoiceEnabled"
                       <?= sv($s,'einvoice_enabled')==='1'?'checked':'' ?>>
                <label class="form-check-label fw-semibold" for="einvoiceEnabled">Enable MyInvois e-Invoice submission</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Environment</label>
              <select name="einvoice_env" class="form-select">
                <option value="sandbox" <?= sv($s,'einvoice_env','sandbox')==='sandbox'?'selected':'' ?>>Sandbox (Testing)</option>
                <option value="production" <?= sv($s,'einvoice_env')==='production'?'selected':'' ?>>Production (Live)</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Client ID</label>
              <input name="einvoice_client_id" class="form-control" value="<?= sv($s,'einvoice_client_id') ?>" placeholder="your_client_id">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Client Secret</label>
              <input name="einvoice_client_secret" type="password" class="form-control" value="<?= sv($s,'einvoice_client_secret') ?>" placeholder="your_client_secret">
            </div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary">Save e-Invoice Settings</button>
            <button type="button" class="btn btn-outline-secondary" id="testConnectionBtn">
              <i class="bi bi-wifi"></i> Test Connection
            </button>
          </div>
          <div id="testResult" class="mt-2"></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Security -->
  <div class="tab-pane fade" id="tab-security">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Change Password</h6>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="tab" value="password">
          <div class="mb-3"><label class="form-label">Current Password</label>
            <input name="current_password" type="password" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">New Password</label>
            <input name="new_password" type="password" class="form-control" required minlength="8"></div>
          <div class="mb-3"><label class="form-label">Confirm New Password</label>
            <input name="confirm_password" type="password" class="form-control" required></div>
          <button class="btn btn-warning">Change Password</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Users (admin only) -->
  <?php if($user['role']==='admin'): ?>
  <div class="tab-pane fade" id="tab-users">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">Users</h6></div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead class="table-light"><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php foreach($users_list as $u): ?>
          <tr>
            <td><?= sanitize($u['name']) ?></td>
            <td><?= sanitize($u['email']) ?></td>
            <td><span class="badge <?= $u['role']==='admin'?'bg-danger':'bg-secondary' ?>"><?= ucfirst($u['role']) ?></span></td>
            <td class="text-muted small"><?= format_date($u['created_at']) ?></td>
            <td>
              <?php if($u['id'] != $user['id']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="tab" value="delete_user">
                <input type="hidden" name="del_user_id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-semibold">Add User</h6></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="tab" value="add_user">
          <div class="row g-3">
            <div class="col-md-5"><label class="form-label">Name</label><input name="new_name" class="form-control" required></div>
            <div class="col-md-5"><label class="form-label">Email</label><input name="new_email" type="email" class="form-control" required></div>
            <div class="col-md-5"><label class="form-label">Password</label><input name="new_password" type="password" class="form-control" required minlength="8"></div>
            <div class="col-md-3"><label class="form-label">Role</label>
              <select name="new_role" class="form-select"><option value="user">User</option><option value="admin">Admin</option></select>
            </div>
          </div>
          <button class="btn btn-primary mt-3">Add User</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
// Tab persistence
document.querySelectorAll('#settingsTabs .nav-link').forEach(t => {
  t.addEventListener('shown.bs.tab', e => localStorage.setItem('settings_tab', e.target.getAttribute('href')));
});
const saved = localStorage.getItem('settings_tab');
if (saved) { const t = document.querySelector(`#settingsTabs .nav-link[href="${saved}"]`); if(t) new bootstrap.Tab(t).show(); }

// Test MyInvois connection
document.getElementById('testConnectionBtn')?.addEventListener('click', function() {
  const btn = this;
  const result = document.getElementById('testResult');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing…';
  result.innerHTML = '';

  fetch('/api/test-einvoice.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>'
  })
  .then(r => r.json())
  .then(data => {
    result.innerHTML = data.success
      ? '<div class="alert alert-success py-2">Connection successful! Token received.</div>'
      : `<div class="alert alert-danger py-2">${data.error}</div>`;
  })
  .catch(() => { result.innerHTML = '<div class="alert alert-danger py-2">Request failed.</div>'; })
  .finally(() => { btn.disabled=false; btn.innerHTML='<i class="bi bi-wifi"></i> Test Connection'; });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

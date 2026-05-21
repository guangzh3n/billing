<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/invoices'); }
verify_csrf();

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if (!$invoice_id) { flash('error','Invalid request.','error'); redirect('/invoices'); }

// Check e-invoice is enabled
if (get_setting('einvoice_enabled','0') !== '1') {
    flash('error','MyInvois e-Invoice is not enabled. Enable it in Settings → MyInvois.','error');
    redirect('/invoice/view?id=' . $invoice_id);
}

// Load invoice
$stmt = $pdo->prepare("SELECT i.*, c.* FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
if (!$invoice) { flash('error','Invoice not found.','error'); redirect('/invoices'); }

if ($invoice['einvoice_status'] === 'valid') {
    flash('error','This invoice has already been submitted and validated.','error');
    redirect('/invoice/view?id=' . $invoice_id);
}

// Load items
$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$invoice_id]);
$items = $items->fetchAll();

if (empty($items)) {
    flash('error','Invoice has no line items.','error');
    redirect('/invoice/view?id=' . $invoice_id);
}

// Validate company TIN
if (!get_setting('company_tin')) {
    flash('error','Company TIN is required for e-Invoice submission. Set it in Settings → Company Info.','error');
    redirect('/invoice/view?id=' . $invoice_id);
}

// Load company settings
$company = [];
foreach (['company_name','company_tin','company_id_type','company_id_number','company_email','company_phone',
          'company_address','company_city','company_postcode','company_state','company_country',
          'company_bank','company_bank_account','company_bank_holder'] as $k) {
    $company[$k] = get_setting($k);
}

$customer = [
    'id'         => $invoice['customer_id'],
    'name'       => $invoice['name'],
    'tin'        => $invoice['tin'],
    'id_type'    => $invoice['id_type'],
    'id_number'  => $invoice['id_number'],
    'email'      => $invoice['email'],
    'phone'      => $invoice['phone'],
    'address'    => $invoice['address'],
    'city'       => $invoice['city'],
    'postcode'   => $invoice['postcode'],
    'state'      => $invoice['state'],
    'country'    => $invoice['country'],
];

require_once __DIR__ . '/../classes/EInvoiceDocument.php';
require_once __DIR__ . '/../classes/MyInvoisAPI.php';

try {
    $docGen = new EInvoiceDocument();
    $docArray = $docGen->generate($invoice, $items, $customer, $company);
    $encoded  = $docGen->encodeDocument($docArray);
    $hash     = $docGen->generateHash($docArray);

    $env    = get_setting('einvoice_env','sandbox');
    $cid    = get_setting('einvoice_client_id');
    $secret = get_setting('einvoice_client_secret');

    $api = new MyInvoisAPI($env, $cid, $secret);
    if (!$api->authenticate()) {
        flash('error','MyInvois authentication failed: ' . $api->getLastError(), 'error');
        redirect('/invoice/view?id=' . $invoice_id);
    }

    $result = $api->submitDocuments([
        [
            'format'       => 'JSON',
            'document'     => $encoded,
            'documentHash' => $hash,
            'codeNumber'   => $invoice['invoice_number'],
        ]
    ]);

    if (isset($result['error'])) {
        flash('error','Submission failed: ' . $result['error'], 'error');
        redirect('/invoice/view?id=' . $invoice_id);
    }

    // Extract submissionUid and document details
    $submissionUid = $result['submissionUid'] ?? null;
    $accepted = $result['acceptedDocuments'] ?? [];
    $rejected = $result['rejectedDocuments'] ?? [];

    if (!empty($rejected)) {
        $reason = $rejected[0]['error']['message'] ?? json_encode($rejected[0]);
        flash('error','Invoice rejected by MyInvois: ' . $reason, 'error');
        $pdo->prepare("UPDATE invoices SET einvoice_status='invalid' WHERE id=?")->execute([$invoice_id]);
        redirect('/invoice/view?id=' . $invoice_id);
    }

    $uuid   = $accepted[0]['uuid']       ?? null;
    $longId = $accepted[0]['invoiceCodeNumber'] ?? null;

    $pdo->prepare("UPDATE invoices SET einvoice_status='pending', einvoice_submission_uid=?, einvoice_uuid=?, einvoice_long_id=? WHERE id=?")
        ->execute([$submissionUid, $uuid, $longId, $invoice_id]);

    log_einvoice("Invoice #{$invoice['invoice_number']} submitted. UID=$submissionUid UUID=$uuid");
    flash('success', 'Invoice submitted to MyInvois. Status: Pending validation. UUID: ' . ($uuid ?: 'pending'));
    redirect('/invoice/view?id=' . $invoice_id);

} catch (Exception $e) {
    log_einvoice("Exception submitting invoice #{$invoice['invoice_number']}: " . $e->getMessage());
    flash('error','Unexpected error: ' . $e->getMessage(), 'error');
    redirect('/invoice/view?id=' . $invoice_id);
}

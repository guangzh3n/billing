<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) { redirect('/invoices'); }

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
if (!$invoice) { flash('error','Invoice not found.','error'); redirect('/invoices'); }

if ($invoice['einvoice_status'] === 'none') {
    flash('error','This invoice has not been submitted to MyInvois yet.','error');
    redirect('/invoice/view?id=' . $invoice_id);
}

require_once __DIR__ . '/../classes/MyInvoisAPI.php';

$env    = get_setting('einvoice_env','sandbox');
$cid    = get_setting('einvoice_client_id');
$secret = get_setting('einvoice_client_secret');

$api = new MyInvoisAPI($env, $cid, $secret);
if (!$api->authenticate()) {
    flash('error','MyInvois auth failed: ' . $api->getLastError(),'error');
    redirect('/invoice/view?id=' . $invoice_id);
}

$updated_status = $invoice['einvoice_status'];
$updated_uuid   = $invoice['einvoice_uuid'];
$updated_longid = $invoice['einvoice_long_id'];
$message        = '';

// First check submission status if we have a submissionUid but no UUID yet
if ($invoice['einvoice_submission_uid'] && !$invoice['einvoice_uuid']) {
    $result = $api->getSubmissionStatus($invoice['einvoice_submission_uid']);
    if (!isset($result['error'])) {
        $accepted = $result['acceptedDocuments'] ?? [];
        $rejected = $result['rejectedDocuments'] ?? [];
        if (!empty($accepted)) {
            $updated_uuid   = $accepted[0]['uuid'] ?? $updated_uuid;
            $updated_longid = $accepted[0]['longId'] ?? $updated_longid;
            $updated_status = 'valid';
            $message = 'Invoice validated by MyInvois.';
        } elseif (!empty($rejected)) {
            $updated_status = 'invalid';
            $message = 'Invoice rejected: ' . ($rejected[0]['error']['message'] ?? 'Unknown reason');
        } else {
            $docStatus = $result['overallStatus'] ?? '';
            if ($docStatus === 'InProgress') $updated_status = 'pending';
            $message = 'Status: ' . ($docStatus ?: 'Pending');
        }
    }
} elseif ($invoice['einvoice_uuid']) {
    // Check individual document details
    $result = $api->getDocumentDetails($invoice['einvoice_uuid']);
    if (!isset($result['error'])) {
        $apiStatus = strtolower($result['status'] ?? '');
        $statusMap = ['valid'=>'valid','invalid'=>'invalid','cancelled'=>'cancelled','rejected'=>'rejected','submitted'=>'pending','inprogress'=>'pending'];
        $updated_status = $statusMap[$apiStatus] ?? $updated_status;
        $updated_longid = $result['longId'] ?? $updated_longid;
        $message = 'Document status: ' . ucfirst($apiStatus);
    }
}

// Update DB
$pdo->prepare("UPDATE invoices SET einvoice_status=?, einvoice_uuid=?, einvoice_long_id=? WHERE id=?")
    ->execute([$updated_status, $updated_uuid, $updated_longid, $invoice_id]);

$type = $updated_status === 'valid' ? 'success' : ($updated_status === 'invalid' ? 'error' : 'info');
flash($type ?: 'info', $message ?: 'e-Invoice status refreshed: ' . ucfirst($updated_status), $type);
redirect('/invoice/view?id=' . $invoice_id);

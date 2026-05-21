<?php
function get_setting(string $key, string $default = ''): string {
    global $pdo;
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['setting_value'] : $default;
    }
    return $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void {
    global $pdo;
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
        ->execute([$key, $value]);
}

function format_currency(float $amount, string $currency = ''): string {
    if (!$currency) $currency = get_setting('currency', 'MYR');
    return $currency . ' ' . number_format($amount, 2);
}

function format_date(?string $date): string {
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

function generate_invoice_number(): string {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE settings SET setting_value = setting_value + 1 WHERE setting_key = 'invoice_next_number'");
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'invoice_next_number'");
        $num  = (int)$stmt->fetchColumn();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    $prefix = get_setting('invoice_prefix', 'INV');
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function get_invoice_status_badge(string $status): string {
    $map = [
        'draft'     => 'secondary',
        'sent'      => 'primary',
        'paid'      => 'success',
        'overdue'   => 'danger',
        'cancelled' => 'dark',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst($status) . '</span>';
}

function get_einvoice_status_badge(string $status): string {
    $map = [
        'none'       => ['secondary', 'Not Submitted'],
        'pending'    => ['warning text-dark', 'Pending'],
        'valid'      => ['success', 'Valid'],
        'invalid'    => ['danger', 'Invalid'],
        'cancelled'  => ['dark', 'Cancelled'],
        'rejected'   => ['danger', 'Rejected'],
    ];
    [$color, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flash(string $key, string $msg, string $type = 'success'): void {
    $_SESSION['flash'][$key] = ['msg' => $msg, 'type' => $type];
}

function get_flash(string $key): ?array {
    if (isset($_SESSION['flash'][$key])) {
        $f = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $f;
    }
    return null;
}

function sanitize(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function paginate(int $total, int $page, int $per_page = 20): array {
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'page'        => $page,
        'total_pages' => $total_pages,
        'offset'      => ($page - 1) * $per_page,
    ];
}

function calculate_invoice_totals(array &$items): array {
    $subtotal = 0;
    $tax_total = 0;
    $discount_total = 0;
    foreach ($items as &$item) {
        $line_gross    = (float)$item['quantity'] * (float)$item['unit_price'];
        $discount_amt  = $line_gross * ((float)$item['discount_rate'] / 100);
        $taxable       = $line_gross - $discount_amt;
        $tax_amt       = $taxable * ((float)$item['tax_rate'] / 100);
        $item['subtotal']    = round($taxable, 2);
        $item['tax_amount']  = round($tax_amt, 2);
        $item['total']       = round($taxable + $tax_amt, 2);
        $subtotal       += $item['subtotal'];
        $tax_total      += $item['tax_amount'];
        $discount_total += round($discount_amt, 2);
    }
    return [
        'subtotal'        => round($subtotal, 2),
        'tax_amount'      => round($tax_total, 2),
        'discount_amount' => round($discount_total, 2),
        'total_amount'    => round($subtotal + $tax_total, 2),
    ];
}

function get_invoice_balance(int $invoice_id, float $total_amount): float {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
    $stmt->execute([$invoice_id]);
    $paid = (float)$stmt->fetchColumn();
    return max(0, $total_amount - $paid);
}

function log_einvoice(string $message): void {
    $log = __DIR__ . '/../logs/einvoice.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

function get_malaysia_states(): array {
    return ['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Penang','Perak','Perlis','Sabah','Sarawak','Selangor','Terengganu','Kuala Lumpur','Labuan','Putrajaya'];
}

function get_myinvois_classifications(): array {
    return [
        '001' => '001 - Breastfeeding equipment',
        '002' => '002 - Child care centres and kindergartens fees',
        '003' => '003 - Computer, smartphone or tablet',
        '004' => '004 - Consolidated e-invoices',
        '005' => '005 - Construction materials',
        '006' => '006 - Disbursement',
        '007' => '007 - Donation',
        '008' => '008 - E-commerce (buyer)',
        '009' => '009 - E-commerce (seller)',
        '010' => '010 - Education fees',
        '011' => '011 - Electrical',
        '012' => '012 - Employment Agency',
        '013' => '013 - Fertiliser',
        '014' => '014 - Food & Beverages',
        '015' => '015 - Freight and Transportation',
        '016' => '016 - Furniture',
        '017' => '017 - Healthcare',
        '018' => '018 - Insurance',
        '019' => '019 - Intellectual property',
        '020' => '020 - IT services',
        '021' => '021 - Laundry',
        '022' => '022 - General (Others)',
        '023' => '023 - Motor vehicle',
        '024' => '024 - Pet care',
        '025' => '025 - Professional services',
        '026' => '026 - Rental',
        '027' => '027 - Repair & maintenance',
        '028' => '028 - Restaurants',
        '029' => '029 - Salary & wages',
        '030' => '030 - Sales',
        '031' => '031 - Subscription',
        '032' => '032 - Telecommunication',
        '033' => '033 - Utilities',
        '034' => '034 - Travel & accommodation',
    ];
}

function get_tax_types(): array {
    return [
        'E'  => 'Exempt',
        'OE' => 'Out of Scope',
        '01' => 'Sales Tax (10%)',
        '02' => 'Service Tax (8%)',
        '03' => 'Tourism Tax',
        '04' => 'High Value Goods Tax',
        'AE' => 'VAT Reverse Charge',
    ];
}

function get_invoice_types(): array {
    return [
        '01' => 'Invoice',
        '02' => 'Credit Note',
        '03' => 'Debit Note',
        '04' => 'Refund Note',
        '11' => 'Self-Billed Invoice',
        '12' => 'Self-Billed Credit Note',
        '13' => 'Self-Billed Debit Note',
        '14' => 'Self-Billed Refund Note',
    ];
}

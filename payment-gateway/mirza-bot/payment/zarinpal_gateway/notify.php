<?php
/*
 * بعد از پرداخت موفق، درگاه واسط (سرور ایتریمر) نتیجه را به این فایل POST می‌کند.
 * امضای درخواست بررسی می‌شود و سپس سفارش در ربات «پرداخت‌شده» ثبت و موجودی کاربر شارژ می‌شود.
 * این فایل چند بار صدا زدن را تحمل می‌کند و هر سفارش فقط یک بار شارژ می‌شود.
 */
ini_set('error_log', 'error_log');
header('Content-Type: application/json');

function respond($code, $data)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$gateway = require __DIR__ . '/config.php';

$body = file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(hash_hmac('sha256', $body, $gateway['secret']), $signature)) {
    respond(403, ['ok' => false, 'error' => 'bad signature']);
}
$data = json_decode($body, true);
if (!is_array($data) || ($data['bot'] ?? '') !== $gateway['bot_id'] || ($data['status'] ?? '') !== 'paid') {
    respond(400, ['ok' => false, 'error' => 'bad payload']);
}

$Pathfiles = dirname(dirname(__DIR__));
require_once $Pathfiles . '/config.php';
require_once $Pathfiles . '/jdf.php';
require_once $Pathfiles . '/botapi.php';
require_once $Pathfiles . '/functions.php';
require_once $Pathfiles . '/panels.php';
require_once $Pathfiles . '/text.php';
$ManagePanel = new ManagePanel();

$order_id = (string)$data['order_id'];
$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) {
    respond(404, ['ok' => false, 'error' => 'order not found']);
}
if ((int)$Payment_report['price'] !== (int)$data['amount']) {
    respond(409, ['ok' => false, 'error' => 'amount mismatch']);
}
if ($Payment_report['payment_Status'] === 'paid') {
    respond(200, ['ok' => true, 'already' => true]);
}

// اول وضعیت را «پرداخت‌شده» می‌کنیم تا اگر همزمان دو درخواست رسید، فقط یکی شارژ کند
$stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status != 'paid'");
$stmt->execute([$order_id]);
if ($stmt->rowCount() === 0) {
    respond(200, ['ok' => true, 'already' => true]);
}

DirectPayment($order_id);
update("user", "Processing_value", "0", "id", $Payment_report['id_user']);
update("user", "Processing_value_one", "0", "id", $Payment_report['id_user']);
update("user", "Processing_value_tow", "0", "id", $Payment_report['id_user']);

$setting = select("setting", "*");
if (strlen($setting['Channel_Report']) > 0) {
    $report = "💵 پرداخت زرین‌پال (درگاه واسط)\n\n"
        . "آیدی کاربر: {$Payment_report['id_user']}\n"
        . "مبلغ: " . number_format((int)$Payment_report['price']) . " تومان\n"
        . "شماره سفارش: $order_id\n"
        . "کد پیگیری: " . htmlspecialchars((string)($data['ref_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    sendmessage($setting['Channel_Report'], $report, null, 'HTML');
}

respond(200, ['ok' => true]);

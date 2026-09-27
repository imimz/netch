<?php
/*
 * لینکی که ربات به کاربر می‌دهد به این فایل اشاره می‌کند:
 *   https://DOMAIN/PATH/payment/zarinpal_gateway/start.php?price=50000&order_id=abc123
 * این فایل سفارش را در دیتابیس ربات چک می‌کند و کاربر را با یک لینک امضاشده
 * به صفحه‌ی هشدار VPN روی درگاه واسط می‌فرستد.
 */
ini_set('error_log', 'error_log');
$Pathfiles = dirname(dirname(__DIR__));
require_once $Pathfiles . '/config.php';
require_once $Pathfiles . '/functions.php';
$gateway = require __DIR__ . '/config.php';

$amount = (string)($_GET['price'] ?? '');
$order_id = (string)($_GET['order_id'] ?? '');
if (!ctype_digit($amount) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $order_id)) {
    http_response_code(400);
    exit('لینک پرداخت نامعتبر است.');
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report || (string)$Payment_report['price'] !== $amount) {
    http_response_code(400);
    exit('مبلغ یا شماره سفارش نامعتبر است.');
}
if ($Payment_report['payment_Status'] === 'paid') {
    exit('این سفارش قبلاً پرداخت شده است.');
}

$exp = time() + (int)$gateway['link_ttl'];
$sig = hash_hmac('sha256', "{$gateway['bot_id']}|$order_id|$amount|$exp", $gateway['secret']);
$url = rtrim($gateway['gateway_url'], '/') . '/?' . http_build_query([
    'bot' => $gateway['bot_id'],
    'order' => $order_id,
    'amount' => $amount,
    'exp' => $exp,
    'sig' => $sig,
]);
header('Location: ' . $url, true, 302);

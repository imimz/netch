<?php
/*
 * بعد از تأیید «VPN را خاموش کردم»، درخواست پرداخت در زرین‌پال ساخته می‌شود
 * و کاربر به صفحه‌ی پرداخت زرین‌پال منتقل می‌شود.
 */
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . cfg('base_url') . '/?t=' . rawurlencode((string)($_GET['t'] ?? '')), true, 303);
    exit;
}

$tx = find_tx_by_token($_POST['t'] ?? '');
if (!$tx) {
    render_message('لینک نامعتبر', 'این لینک پرداخت پیدا نشد. لطفاً از داخل ربات دوباره لینک پرداخت بسازید.');
}
$retry = '<a class="btn primary" href="' . e(cfg('base_url')) . '/?t=' . e($tx['token']) . '">بازگشت</a>';

if (empty($_POST['vpn_off'])) {
    render_message('تأیید لازم است', 'لطفاً ابتدا VPN را خاموش کنید و گزینه‌ی «VPN و پروکسی را خاموش کردم» را تیک بزنید.', 'error', $retry);
}
if ($tx['status'] === 'paid') {
    header('Location: ' . cfg('base_url') . '/?t=' . $tx['token'], true, 303);
    exit;
}

[$authority, $error] = zarinpal_request($tx);
if (!$authority) {
    render_message('خطا در ساخت پرداخت', $error, 'error', $retry);
}

$pdo = db();
$pdo->prepare('INSERT OR REPLACE INTO attempts (authority, tx_id, created_at) VALUES (?, ?, ?)')
    ->execute([$authority, $tx['id'], time()]);
$pdo->prepare('UPDATE transactions SET authority = ? WHERE id = ? AND status != \'paid\'')
    ->execute([$authority, $tx['id']]);

header('Location: ' . zarinpal_startpay_url($authority), true, 303);
exit;

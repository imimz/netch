<?php
/*
 * بازگشت از زرین‌پال: پرداخت تأیید (verify) می‌شود و نتیجه به ربات اطلاع داده می‌شود.
 * زرین‌پال این صفحه را با ?Authority=...&Status=OK|NOK صدا می‌زند.
 */
require __DIR__ . '/lib.php';

$authority = (string)($_GET['Authority'] ?? '');
$status = (string)($_GET['Status'] ?? '');

$tx = preg_match('/^[A-Za-z0-9]{1,64}$/', $authority) ? find_tx_by_authority($authority) : null;
if (!$tx) {
    render_message('تراکنش پیدا نشد', 'اطلاعات بازگشتی از درگاه معتبر نیست. اگر مبلغی از حساب شما کسر شده، با پشتیبانی تماس بگیرید.');
}

$bot = bot_config($tx['bot']);
$backButton = $bot && !empty($bot['return_url'])
    ? '<a class="btn primary" href="' . e($bot['return_url']) . '">بازگشت به ربات</a>'
    : '';
$retryButton = '<a class="btn secondary" href="' . e(cfg('base_url')) . '/?t=' . e($tx['token']) . '">تلاش دوباره</a>';

function success_page($tx, $backButton)
{
    $details = '<dl class="summary">'
        . '<div><dt>مبلغ</dt><dd>' . e(toman($tx['amount'])) . '</dd></div>'
        . '<div><dt>کد پیگیری</dt><dd dir="ltr">' . e($tx['ref_id']) . '</dd></div>'
        . '<div><dt>شماره سفارش</dt><dd dir="ltr">' . e($tx['order_id']) . '</dd></div>'
        . '</dl>';
    $note = $tx['notified']
        ? '<p class="note">حساب شما در ربات شارژ شد. حالا می‌توانید VPN را دوباره روشن کنید و به ربات برگردید.</p>'
        : '<p class="note">پرداخت ثبت شد و در حال اعمال در ربات است. اگر تا چند دقیقه‌ی دیگر شارژ نشد، کد پیگیری را برای پشتیبانی بفرستید.</p>';
    render_message('پرداخت با موفقیت انجام شد', 'از پرداخت شما متشکریم.', 'success', $details . $note . $backButton);
}

// قبلاً تأیید شده (مثلاً رفرش صفحه)
if ($tx['status'] === 'paid') {
    if (!$tx['notified']) {
        notify_bot($tx);
        $tx = find_tx_by_token($tx['token']);
    }
    success_page($tx, $backButton);
}

if ($status !== 'OK') {
    render_message('پرداخت انجام نشد', 'پرداخت لغو شد یا ناموفق بود. اگر مبلغی از حساب شما کسر شده، طی ۷۲ ساعت به حسابتان برمی‌گردد.', 'error', $retryButton . $backButton);
}

$result = zarinpal_verify($tx, $authority);
if (!$result) {
    render_message('پرداخت تأیید نشد', 'بانک پرداخت را تأیید نکرد. اگر مبلغی از حساب شما کسر شده، طی ۷۲ ساعت به حسابتان برمی‌گردد.', 'error', $retryButton . $backButton);
}

db()->prepare('UPDATE transactions SET status = \'paid\', authority = ?, ref_id = ?, card_pan = ?, paid_at = ? WHERE id = ? AND status != \'paid\'')
    ->execute([$authority, $result['ref_id'], $result['card_pan'], time(), $tx['id']]);
$tx = find_tx_by_token($tx['token']);

if (!$tx['notified']) {
    notify_bot($tx);
    $tx = find_tx_by_token($tx['token']);
}
success_page($tx, $backButton);

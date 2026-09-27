<?php
/*
 * صفحه‌ی واسط: کاربر از ربات به اینجا می‌آید، هشدار خاموش کردن VPN را می‌بیند
 * و بعد با زدن دکمه‌ی پرداخت به زرین‌پال منتقل می‌شود.
 *
 * ورودی از ربات:  ?bot=mirza&order=ORDER_ID&amount=TOMAN&exp=UNIX_TIME&sig=HMAC
 * بعد از بررسی امضا، کاربر به ?t=TOKEN منتقل می‌شود تا لینک کوتاه و قابل رفرش باشد.
 */
require __DIR__ . '/lib.php';

// ---- مرحله‌ی ۱: لینک امضاشده از ربات ----
if (isset($_GET['sig'])) {
    $botId = (string)($_GET['bot'] ?? '');
    $orderId = (string)($_GET['order'] ?? '');
    $amount = (string)($_GET['amount'] ?? '');
    $exp = (string)($_GET['exp'] ?? '');
    $sig = (string)$_GET['sig'];

    $bot = bot_config($botId);
    if (!$bot || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId) || !ctype_digit($amount) || !ctype_digit($exp)) {
        render_message('لینک نامعتبر', 'لینک پرداخت معتبر نیست. لطفاً از داخل ربات دوباره لینک پرداخت بسازید.');
    }
    if (!hash_equals(link_signature($bot['secret'], $botId, $orderId, $amount, $exp), $sig)) {
        render_message('لینک نامعتبر', 'امضای لینک پرداخت صحیح نیست. لطفاً از داخل ربات دوباره لینک پرداخت بسازید.');
    }
    if ((int)$exp < time()) {
        render_message('لینک منقضی شده', 'مهلت این لینک پرداخت تمام شده است. لطفاً از داخل ربات یک لینک جدید بسازید.');
    }
    if ((int)$amount < (int)($bot['min_amount'] ?? 1000) || (int)$amount > (int)($bot['max_amount'] ?? 100000000)) {
        render_message('مبلغ نامعتبر', 'مبلغ پرداخت خارج از محدوده‌ی مجاز است.');
    }
    $tx = get_or_create_tx($botId, $orderId, (int)$amount);
    if (!$tx) {
        render_message('سفارش نامعتبر', 'این شماره سفارش قبلاً با مبلغ دیگری ثبت شده است. لطفاً از داخل ربات دوباره لینک بسازید.');
    }
    header('Location: ' . cfg('base_url') . '/?t=' . $tx['token'], true, 302);
    exit;
}

// ---- مرحله‌ی ۲: صفحه‌ی هشدار ----
$tx = find_tx_by_token($_GET['t'] ?? '');
if (!$tx) {
    render_message('لینک نامعتبر', 'این لینک پرداخت پیدا نشد. لطفاً از داخل ربات دوباره لینک پرداخت بسازید.');
}
$bot = bot_config($tx['bot']);
$backButton = $bot && !empty($bot['return_url'])
    ? '<a class="btn secondary" href="' . e($bot['return_url']) . '">بازگشت به ربات</a>'
    : '';

if ($tx['status'] === 'paid') {
    render_message('این سفارش پرداخت شده است', 'پرداخت این سفارش قبلاً با موفقیت انجام شده. کد پیگیری: ' . $tx['ref_id'], 'success', $backButton);
}

$country = visitor_country();
$vpnDetected = $country !== null && $country !== 'IR';

ob_start();
?>
<div class="warning<?= $vpnDetected ? ' danger' : '' ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22L12 2zm0 5.5 7.5 13h-15L12 7.5zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>
    <div>
        <h1>قبل از پرداخت، VPN خود را خاموش کنید</h1>
        <p>درگاه‌های بانکی ایران اتصال با آی‌پی خارج از کشور را قبول نمی‌کنند. اگر VPN یا پروکسی روشن باشد، پرداخت ناموفق می‌شود یا ممکن است مبلغ از حساب کسر شود و تا ۷۲ ساعت بعد برگردد.</p>
    </div>
</div>

<?php if ($vpnDetected): ?>
<div class="alert">
    به نظر می‌رسد VPN شما هنوز روشن است (آی‌پی شما از کشور <b><?= e($country) ?></b> شناسایی شد).
    لطفاً آن را خاموش کنید و <a href="<?= e(cfg('base_url')) ?>/?t=<?= e($tx['token']) ?>">دوباره بررسی کنید</a>.
</div>
<?php endif; ?>

<ol class="steps">
    <li>برنامه‌ی VPN یا فیلترشکن (مثل v2rayNG، Hiddify، V2Box، OpenVPN و...) را به‌طور کامل <b>قطع</b> کنید.</li>
    <li>اگر پروکسی مرورگر یا سیستم روشن است، آن را هم خاموش کنید.</li>
    <li>بعد از خاموش کردن، روی دکمه‌ی «پرداخت» بزنید تا به زرین‌پال منتقل شوید.</li>
</ol>

<dl class="summary">
    <div><dt>مبلغ قابل پرداخت</dt><dd class="amount"><?= e(toman($tx['amount'])) ?></dd></div>
    <div><dt>شماره سفارش</dt><dd dir="ltr"><?= e($tx['order_id']) ?></dd></div>
    <?php if (!empty($bot['title'])): ?>
    <div><dt>بابت</dt><dd><?= e($bot['title']) ?></dd></div>
    <?php endif; ?>
</dl>

<form method="post" action="<?= e(cfg('base_url')) ?>/pay.php" id="payForm">
    <input type="hidden" name="t" value="<?= e($tx['token']) ?>">
    <label class="confirm">
        <input type="checkbox" name="vpn_off" value="1" required id="vpnOff">
        <span>VPN و پروکسی را خاموش کردم</span>
    </label>
    <button type="submit" class="btn primary" id="payBtn">پرداخت از طریق زرین‌پال</button>
</form>
<?= $backButton ?>
<?php if (cfg('support_url')): ?>
<p class="support">مشکلی دارید؟ <a href="<?= e(cfg('support_url')) ?>">پیام به پشتیبانی</a></p>
<?php endif; ?>

<script>
(function () {
    var box = document.getElementById('vpnOff'), btn = document.getElementById('payBtn'), form = document.getElementById('payForm');
    function sync() { btn.disabled = !box.checked; }
    box.addEventListener('change', sync); sync();
    form.addEventListener('submit', function () { btn.disabled = true; btn.textContent = 'در حال انتقال به زرین‌پال…'; });
})();
</script>
<?php
render('پرداخت', ob_get_clean());

<?php
// ارسال دوباره‌ی نتیجه‌ی پرداخت‌هایی که ربات هنوز دریافتشان را تأیید نکرده.
// فقط از خط فرمان اجرا می‌شود؛ آن را در کرون هر ۵ دقیقه بگذارید:
//   */5 * * * * php /path/to/gateway/retry_notify.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';

$rows = db()->query("SELECT * FROM transactions WHERE status = 'paid' AND notified = 0 AND notify_tries < 100 ORDER BY id")->fetchAll();
foreach ($rows as $tx) {
    $ok = notify_bot($tx);
    echo ($ok ? 'OK   ' : 'FAIL ') . $tx['bot'] . '/' . $tx['order_id'] . PHP_EOL;
}

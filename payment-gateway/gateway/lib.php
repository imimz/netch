<?php
/*
 * توابع مشترک درگاه واسط
 * مبالغ داخل این درگاه همه به «تومان» ذخیره می‌شوند و فقط هنگام ارسال به زرین‌پال به ریال تبدیل می‌شوند.
 */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('config.php not found. Copy config.sample.php to config.php and fill it in.');
}

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/data/error.log');

function cfg($key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . cfg('db_path'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        token       TEXT NOT NULL UNIQUE,
        bot         TEXT NOT NULL,
        order_id    TEXT NOT NULL,
        amount      INTEGER NOT NULL,
        status      TEXT NOT NULL DEFAULT \'pending\',
        authority   TEXT,
        ref_id      TEXT,
        card_pan    TEXT,
        notified    INTEGER NOT NULL DEFAULT 0,
        notify_tries INTEGER NOT NULL DEFAULT 0,
        created_at  INTEGER NOT NULL,
        paid_at     INTEGER,
        UNIQUE (bot, order_id)
    )');
    // هر بار که کاربر روی «پرداخت» می‌زند یک Authority جدید ساخته می‌شود
    $pdo->exec('CREATE TABLE IF NOT EXISTS attempts (
        authority   TEXT PRIMARY KEY,
        tx_id       INTEGER NOT NULL,
        created_at  INTEGER NOT NULL
    )');
    return $pdo;
}

function bot_config($bot)
{
    $bots = cfg('bots');
    return (is_string($bot) && isset($bots[$bot])) ? $bots[$bot] : null;
}

/* ---------- امضا ---------- */

function link_signature($secret, $bot, $orderId, $amount, $exp)
{
    return hash_hmac('sha256', "$bot|$orderId|$amount|$exp", $secret);
}

function body_signature($secret, $body)
{
    return hash_hmac('sha256', $body, $secret);
}

/* ---------- تراکنش ---------- */

function find_tx_by_token($token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM transactions WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function find_tx_by_authority($authority)
{
    $st = db()->prepare('SELECT t.* FROM attempts a JOIN transactions t ON t.id = a.tx_id WHERE a.authority = ?');
    $st->execute([$authority]);
    return $st->fetch() ?: null;
}

/** تراکنش را پیدا می‌کند یا می‌سازد. اگر سفارش قبلاً با مبلغ دیگری ثبت شده باشد null برمی‌گرداند. */
function get_or_create_tx($bot, $orderId, $amount)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM transactions WHERE bot = ? AND order_id = ?');
    $st->execute([$bot, $orderId]);
    $tx = $st->fetch();
    if ($tx) {
        return ((int)$tx['amount'] === (int)$amount) ? $tx : null;
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT OR IGNORE INTO transactions (token, bot, order_id, amount, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$token, $bot, $orderId, (int)$amount, time()]);
    $st->execute([$bot, $orderId]);
    $tx = $st->fetch();
    return ($tx && (int)$tx['amount'] === (int)$amount) ? $tx : null;
}

/* ---------- زرین‌پال ---------- */

function zarinpal_api_base()
{
    $z = cfg('zarinpal');
    if (!empty($z['api_base'])) {
        return rtrim($z['api_base'], '/');
    }
    return !empty($z['sandbox']) ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
}

function zarinpal_startpay_url($authority)
{
    return zarinpal_api_base() . '/pg/StartPay/' . rawurlencode($authority);
}

function post_json($url, array $payload, array $headers = [], $timeout = 20)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        error_log("POST $url failed: $error");
        return [0, null];
    }
    return [$code, json_decode($response, true)];
}

/** @return array [authority|null, errorMessage|null] */
function zarinpal_request($tx)
{
    $bot = bot_config($tx['bot']);
    [, $res] = post_json(zarinpal_api_base() . '/pg/v4/payment/request.json', [
        'merchant_id' => cfg('zarinpal')['merchant_id'],
        'amount' => (int)$tx['amount'] * 10, // تومان به ریال
        'callback_url' => cfg('base_url') . '/callback.php',
        'description' => 'پرداخت سفارش ' . $tx['order_id'] . ' - ' . ($bot['title'] ?? $tx['bot']),
        'metadata' => ['order_id' => (string)$tx['order_id']],
    ]);
    if (isset($res['data']['code']) && (int)$res['data']['code'] === 100 && !empty($res['data']['authority'])) {
        return [$res['data']['authority'], null];
    }
    error_log('zarinpal request error: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
    return [null, zarinpal_error_message($res)];
}

/** @return array|null ['ref_id' => ..., 'card_pan' => ...] در صورت موفقیت */
function zarinpal_verify($tx, $authority)
{
    [, $res] = post_json(zarinpal_api_base() . '/pg/v4/payment/verify.json', [
        'merchant_id' => cfg('zarinpal')['merchant_id'],
        'amount' => (int)$tx['amount'] * 10,
        'authority' => $authority,
    ]);
    $code = isset($res['data']['code']) ? (int)$res['data']['code'] : null;
    // 100 = موفق، 101 = قبلاً تأیید شده
    if ($code === 100 || $code === 101) {
        return [
            'ref_id' => (string)($res['data']['ref_id'] ?? ''),
            'card_pan' => (string)($res['data']['card_pan'] ?? ''),
        ];
    }
    error_log('zarinpal verify error: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
    return null;
}

function zarinpal_error_message($res)
{
    $code = $res['errors']['code'] ?? ($res['data']['code'] ?? null);
    $messages = [
        -9 => 'اطلاعات ارسالی به درگاه نامعتبر است.',
        -10 => 'آی‌پی یا مرچنت کد پذیرنده صحیح نیست.',
        -11 => 'مرچنت کد فعال نیست.',
        -12 => 'تلاش بیش از حد در یک بازه‌ی زمانی کوتاه؛ کمی بعد دوباره امتحان کنید.',
        -15 => 'درگاه پرداخت به حالت تعلیق درآمده است.',
        -16 => 'سطح تأیید پذیرنده پایین‌تر از سطح نقره‌ای است.',
    ];
    return $messages[$code] ?? 'خطا در اتصال به درگاه زرین‌پال. لطفاً چند لحظه بعد دوباره تلاش کنید.';
}

/* ---------- اطلاع به ربات ---------- */

/** نتیجه‌ی پرداخت موفق را به ربات ارسال می‌کند. true یعنی ربات دریافت را تأیید کرد. */
function notify_bot($tx)
{
    $bot = bot_config($tx['bot']);
    if (!$bot || empty($bot['notify_url'])) {
        return false;
    }
    $payload = [
        'bot' => $tx['bot'],
        'order_id' => (string)$tx['order_id'],
        'amount' => (int)$tx['amount'],
        'status' => 'paid',
        'ref_id' => (string)$tx['ref_id'],
        'card_pan' => (string)$tx['card_pan'],
        'authority' => (string)$tx['authority'],
        'paid_at' => (int)$tx['paid_at'],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($bot['notify_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Gateway-Signature: ' . body_signature($bot['secret'], $body),
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    $ok = $httpCode === 200 && !empty($decoded['ok']);

    db()->prepare('UPDATE transactions SET notified = ?, notify_tries = notify_tries + 1 WHERE id = ?')
        ->execute([$ok ? 1 : 0, $tx['id']]);
    if (!$ok) {
        error_log("notify {$tx['bot']}/{$tx['order_id']} failed: HTTP $httpCode " . substr((string)$response, 0, 300));
    }
    return $ok;
}

/* ---------- تشخیص VPN ---------- */

/** کد کشور IP کاربر یا null اگر قابل تشخیص نباشد */
function visitor_country()
{
    switch (cfg('geo_check')) {
        case 'cloudflare':
            $c = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
            return preg_match('/^[A-Z]{2}$/', $c) ? $c : null;
        case 'geoip':
            if (function_exists('geoip_country_code_by_name')) {
                $c = @geoip_country_code_by_name($_SERVER['REMOTE_ADDR'] ?? '');
                return $c ?: null;
            }
            return null;
        default:
            return null;
    }
}

/* ---------- نمایش ---------- */

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function toman($n)
{
    return number_format((int)$n) . ' تومان';
}

function render($title, $content)
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    $site = e(cfg('site_name'));
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($title) . ' | ' . $site . '</title>'
        . '<link rel="stylesheet" href="' . e(cfg('base_url')) . '/assets/style.css">'
        . '</head><body><main class="card">'
        . '<div class="brand">' . $site . '</div>'
        . $content
        . '</main></body></html>';
    exit;
}

function render_message($title, $message, $kind = 'error', $extraHtml = '')
{
    render($title, '<div class="status ' . e($kind) . '"><h1>' . e($title) . '</h1><p>' . e($message) . '</p></div>' . $extraHtml);
}

<?php
/*
 * تنظیمات درگاه واسط
 * این فایل را به اسم config.php کپی کنید و مقادیر را پر کنید.
 *   cp config.sample.php config.php
 */
return [
    // آدرس کامل همین پوشه روی سرور ایتریمر، بدون / در انتها
    // مثال: https://pay.etrimer.ir  یا  https://etrimer.ir/pay
    'base_url' => 'https://pay.example.com',

    // اسمی که بالای صفحه نمایش داده می‌شود
    'site_name' => 'ایتریمر',

    // لینک پشتیبانی (اختیاری) — مثلاً آیدی تلگرام پشتیبانی
    'support_url' => 'https://t.me/YourSupport',

    'zarinpal' => [
        // مرچنت کد ۳۶ کاراکتری زرین‌پال (همان درگاهی که برای دامنه‌ی ایتریمر تأیید شده)
        'merchant_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        // برای تست روی سندباکس زرین‌پال true کنید
        'sandbox' => false,
        // فقط برای تست محلی؛ خالی بگذارید
        'api_base' => '',
    ],

    // مسیر دیتابیس SQLite (پوشه باید قابل نوشتن برای PHP باشد و از بیرون در دسترس نباشد)
    'db_path' => __DIR__ . '/data/gateway.sqlite',

    // تشخیص روشن بودن VPN از روی کشور IP کاربر:
    //   'off'        : فقط هشدار ثابت نمایش داده می‌شود
    //   'cloudflare' : اگر دامنه پشت Cloudflare است (هدر CF-IPCountry)
    //   'geoip'      : اگر اکستنشن geoip روی PHP نصب است
    'geo_check' => 'off',

    // ربات‌هایی که اجازه دارند از این درگاه استفاده کنند
    // کلید آرایه (مثلاً mirza) همان bot_id است که در تنظیمات ربات می‌گذارید
    'bots' => [
        'mirza' => [
            'title' => 'ربات میرزا پرو',
            // یک رشته‌ی تصادفی طولانی؛ دقیقاً همین مقدار را در تنظیمات ربات هم بگذارید
            // ساخت: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
            'secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
            // فایلی که بعد از پرداخت موفق، نتیجه به آن ارسال می‌شود (روی سرور ربات)
            'notify_url' => 'https://bot.example.com/mirzabot/payment/zarinpal_gateway/notify.php',
            // دکمه‌ی «بازگشت به ربات»
            'return_url' => 'https://t.me/YourBot',
            // محدوده‌ی مبلغ به تومان
            'min_amount' => 5000,
            'max_amount' => 10000000,
        ],
    ],
];

<?php
// تنظیمات اتصال ربات میرزا به درگاه واسط (روی سرور ایتریمر)
return [
    // آدرس درگاه واسط، همان base_url در config.php درگاه
    'gateway_url' => 'https://pay.example.com',
    // همان کلید آرایه‌ی bots در config.php درگاه
    'bot_id' => 'mirza',
    // دقیقاً همان secret که در config.php درگاه برای این ربات گذاشته‌اید
    'secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    // مدت اعتبار لینک پرداخت (ثانیه)
    'link_ttl' => 86400,
];

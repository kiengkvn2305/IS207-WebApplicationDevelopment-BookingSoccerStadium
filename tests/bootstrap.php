<?php

// Only the isolated test database is accepted; never read the application's .env.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3308');
define('DB_NAME', 'sannha_test');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SEPAY_WEBHOOK_SECRET', '');
define('APP_DEBUG', false);
define('BASE', '');
const OPEN_HOUR = 6;
const CLOSE_HOUR = 22;
const PEAK_FROM = 17;
const CANCEL_BEFORE_HOURS = 2;
const BANKS = ['970436' => 'Vietcombank'];
const STATUS_VI = ['confirmed' => 'Đã xác nhận','pending' => 'Chờ thanh toán','paid' => 'Đã thanh toán','cancelled' => 'Đã hủy','expired' => 'Hết hạn','cancel_fee_pending' => 'Chờ phí hủy'];
date_default_timezone_set('Asia/Ho_Chi_Minh');
spl_autoload_register(function ($class) {
    foreach (['core', 'models', 'controllers'] as $folder) {
        $file = dirname(__DIR__) . "/app/$folder/$class.php";
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

<?php

// Thông tin nhạy cảm đọc từ file .env (KHÔNG commit lên git). Xem .env.example
define('DB_HOST', Env::get('DB_HOST', '127.0.0.1'));
define('DB_PORT', Env::get('DB_PORT', '3306'));
define('DB_NAME', Env::get('DB_NAME', 'san_bong'));
define('DB_USER', Env::get('DB_USER', 'root'));
define('DB_PASS', Env::get('DB_PASS', ''));
define('SEPAY_WEBHOOK_SECRET', Env::get('SEPAY_WEBHOOK_SECRET'));
define('APP_DEBUG', filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

// Quy tắc nghiệp vụ (không nhạy cảm, có thể commit)
const HOLD_MINUTES = 1;       // giữ tương thích với các đơn chờ thanh toán cũ
const CANCEL_BEFORE_HOURS = 2;  // hủy trong vòng này phải thanh toán 70% phí sân
const OPEN_HOUR = 6;
const CLOSE_HOUR = 22;
const PEAK_FROM = 17;

const BANKS = [
  '970436' => 'Vietcombank','970415' => 'VietinBank','970418' => 'BIDV','970405' => 'Agribank',
  '970422' => 'MB Bank','970407' => 'Techcombank','970416' => 'ACB','970432' => 'VPBank',
  '970423' => 'TPBank','970403' => 'Sacombank',
];
const STATUS_VI = [
  'pending' => 'Chờ thanh toán (đơn cũ)',
  'confirmed' => 'Đã xác nhận',
  'cancel_fee_pending' => 'Chờ thanh toán phí hủy',
  'paid' => 'Đã thanh toán',
  'cancelled' => 'Đã hủy',
  'expired' => 'Hết hạn giữ chỗ'
];
date_default_timezone_set('Asia/Ho_Chi_Minh');

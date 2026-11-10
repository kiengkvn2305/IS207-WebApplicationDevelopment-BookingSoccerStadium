<?php
require __DIR__ . '/core/Env.php';
Env::load(dirname(__DIR__) . '/.env');      // .env nằm ở thư mục gốc dự án, NGOÀI public/
require __DIR__ . '/config.php';
spl_autoload_register(function ($c) {
    foreach (['core', 'models', 'controllers'] as $d) {
        $f = __DIR__ . "/$d/$c.php";
        if (is_file($f)) { require $f; return; }
    }
});
define('BASE', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));
Auth::boot();

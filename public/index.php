<?php

// Front controller: mọi request đi qua file này.
if (php_sapi_name() === 'cli-server') {   // php -S: để server phục vụ file tĩnh trong assets/
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f)) {
        return false;
    }
}
require __DIR__ . '/../app/bootstrap.php';
(require __DIR__ . '/../app/routes.php')->dispatch();

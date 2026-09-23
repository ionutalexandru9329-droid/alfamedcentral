<?php
// Optional router for `php -S 127.0.0.1:8000 router.php`.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__;
$file = realpath($root.$path);
if ($path !== '/' && $file && str_starts_with($file, realpath($root)) && is_file($file)) {
    return false;
}
require $root.'/index.php';

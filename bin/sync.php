<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$result=(new App\Services\SyncService())->syncAll();echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;

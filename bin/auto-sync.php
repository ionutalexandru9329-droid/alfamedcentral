<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Database;
use App\Core\UpgradeManager;
use App\Services\AutoSyncService;

try {
    UpgradeManager::runIfNeeded(Database::connection());
    $result=(new AutoSyncService())->force();
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(($result['ok']??false)?0:1);
} catch(Throwable $e){
    fwrite(STDERR,'Auto-sync error: '.$e->getMessage().PHP_EOL);
    exit(1);
}

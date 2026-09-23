<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Database;
use App\Core\UpgradeManager;

try {
    UpgradeManager::run(Database::connection());
    echo 'Upgrade finalizat la v'.UpgradeManager::currentVersion()."\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Upgrade esuat: '.$e->getMessage()."\n");
    exit(1);
}

<?php
namespace App\Core;
use PDO;

final class Database {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;
        $dsn = (string) Env::get('DB_DSN','sqlite:storage/alfamed.sqlite');
        if (str_starts_with($dsn,'sqlite:')) {
            $path = substr($dsn,7);
            if (!str_starts_with($path,'/')) $dsn = 'sqlite:' . dirname(__DIR__,2) . '/' . $path;
        }
        self::$pdo = new PDO($dsn, (string)Env::get('DB_USER',''), (string)Env::get('DB_PASS',''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with($dsn,'sqlite:')) self::$pdo->exec('PRAGMA foreign_keys=ON');
        return self::$pdo;
    }
}

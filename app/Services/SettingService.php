<?php
namespace App\Services;

use App\Core\Database;
use PDO;

final class SettingService {
    private PDO $db;
    private array $cache=[];
    public function __construct(?PDO $db = null){ $this->db=$db ?: Database::connection(); }

    public function get(string $key, mixed $default=null): mixed {
        if(array_key_exists($key,$this->cache)) return $this->cache[$key];
        $st=$this->db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $v=$st->fetchColumn();
        if($v===false) return $default;
        return $this->cache[$key]=$v;
    }

    public function bool(string $key,bool $default=false): bool {
        return filter_var((string)$this->get($key,$default?'true':'false'),FILTER_VALIDATE_BOOL);
    }

    public function set(string $key,mixed $value,string $scope='app'): void {
        $value=is_bool($value)?($value?'true':'false'):(string)$value;
        $driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $st=$this->db->prepare('INSERT INTO settings(setting_key,value,scope,updated_at) VALUES(?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE value=VALUES(value),scope=VALUES(scope),updated_at=CURRENT_TIMESTAMP');
        } else {
            $st=$this->db->prepare('INSERT INTO settings(setting_key,value,scope,updated_at) VALUES(?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET value=excluded.value,scope=excluded.scope,updated_at=CURRENT_TIMESTAMP');
        }
        $st->execute([$key,$value,$scope]);
        $this->cache[$key]=$value;
    }

    public function setMany(array $values,string $scope='app'): void {
        foreach($values as $key=>$value) $this->set((string)$key,$value,$scope);
    }

    public function allByScope(string $scope): array {
        $st=$this->db->prepare('SELECT setting_key,value FROM settings WHERE scope=? ORDER BY setting_key');
        $st->execute([$scope]);
        $out=[]; foreach($st->fetchAll() as $r)$out[$r['setting_key']]=$r['value']; return $out;
    }
}

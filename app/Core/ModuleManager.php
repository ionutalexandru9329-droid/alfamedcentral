<?php
namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ModuleManager {
    private PDO $db;
    private string $root;
    private static ?array $booted = null;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::connection();
        $this->root = dirname(__DIR__, 2);
    }

    public function discover(): array {
        $modules = [];
        foreach (glob($this->root.'/modules/*/manifest.json') ?: [] as $manifestFile) {
            $dir = dirname($manifestFile);
            $data = json_decode((string)file_get_contents($manifestFile), true);
            if (!is_array($data)) continue;
            $slug = basename($dir);
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) continue;
            if (($data['slug'] ?? $slug) !== $slug) continue;
            $data['slug'] = $slug;
            $data['name'] = (string)($data['name'] ?? $slug);
            $data['version'] = (string)($data['version'] ?? '0.0.0');
            $data['description'] = (string)($data['description'] ?? '');
            $data['author'] = (string)($data['author'] ?? '');
            $data['default_enabled'] = (bool)($data['default_enabled'] ?? false);
            $data['_dir'] = $dir;
            $modules[$slug] = $data;
        }
        ksort($modules);
        return $modules;
    }

    public function listing(): array {
        $discovered = $this->discover();
        $rows = [];
        try {
            foreach ($this->db->query('SELECT * FROM modules ORDER BY name')->fetchAll() as $row) $rows[$row['slug']] = $row;
        } catch (Throwable) {}
        $out = [];
        foreach ($discovered as $slug => $manifest) {
            $row = $rows[$slug] ?? null;
            $out[] = array_merge($manifest, [
                'installed' => (bool)$row,
                'enabled' => $row ? (bool)$row['enabled'] : false,
                'installed_version' => $row['version'] ?? null,
            ]);
        }
        return $out;
    }

    public function installDefaults(): void {
        foreach ($this->discover() as $slug => $manifest) {
            if ($manifest['default_enabled']) $this->install($slug, true);
        }
    }

    public function install(string $slug, bool $enable = false): void {
        $all = $this->discover();
        if (!isset($all[$slug])) throw new RuntimeException('Modulul nu a fost gasit.');
        $m = $all[$slug];
        $this->assertCompatible($m);
        $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $migration = $m['_dir'].'/database/'.($driver === 'mysql' ? 'mysql.sql' : 'sqlite.sql');
        if (is_file($migration)) $this->db->exec((string)file_get_contents($migration));

        $q = $this->db->prepare('SELECT id,enabled FROM modules WHERE slug=?');
        $q->execute([$slug]);
        $existing = $q->fetch();
        if ($existing) {
            $st = $this->db->prepare('UPDATE modules SET name=?,version=?,description=?,enabled=?,updated_at=CURRENT_TIMESTAMP WHERE slug=?');
            $st->execute([$m['name'],$m['version'],$m['description'],$enable ? 1 : (int)$existing['enabled'],$slug]);
        } else {
            $st = $this->db->prepare('INSERT INTO modules(slug,name,version,description,enabled) VALUES(?,?,?,?,?)');
            $st->execute([$slug,$m['name'],$m['version'],$m['description'],$enable ? 1 : 0]);
        }
        self::$booted = null;
    }

    public function setEnabled(string $slug, bool $enabled): void {
        $q = $this->db->prepare('SELECT id FROM modules WHERE slug=?');
        $q->execute([$slug]);
        if (!$q->fetchColumn()) $this->install($slug, $enabled);
        else {
            $st = $this->db->prepare('UPDATE modules SET enabled=?,updated_at=CURRENT_TIMESTAMP WHERE slug=?');
            $st->execute([$enabled ? 1 : 0, $slug]);
        }
        self::$booted = null;
    }

    public function dispatch(string $event, array $payload = []): void {
        foreach ($this->boot()['listeners'][$event] ?? [] as $listener) {
            try { $listener($payload); }
            catch (Throwable $e) { $this->moduleLog('error', 'module_event', $event.': '.$e->getMessage()); }
        }
    }

    public function handleRoute(string $path, string $method, bool $publicOnly = false): bool {
        foreach ($this->boot()['routes'] as $route) {
            $isPublic = !empty($route['public']);
            if ($publicOnly !== $isPublic) continue;
            if (strtoupper((string)($route['method'] ?? 'GET')) !== strtoupper($method)) continue;
            $pattern = (string)($route['path'] ?? '');
            $matches = [];
            $matched = str_starts_with($pattern, '#') ? preg_match($pattern, $path, $matches) === 1 : $pattern === $path;
            if (!$matched || !is_callable($route['handler'] ?? null)) continue;
            ($route['handler'])($matches);
            return true;
        }
        return false;
    }

    public function navItems(): array { return $this->boot()['nav']; }
    public function settingsSections(): array { return $this->boot()['settings']; }
    public function actions(string $group): array {
        $actions=$this->boot()['actions'][$group] ?? [];
        foreach($actions as $key=>$action){
            $available=$action['available']??true;
            try{
                if(is_callable($available)) $available=(bool)$available();
                if(!$available) unset($actions[$key]);
            }catch(Throwable){unset($actions[$key]);}
        }
        return $actions;
    }
    public function runAction(string $group,string $key,array $context=[]): mixed {
        $action=$this->boot()['actions'][$group][$key] ?? null;
        if(!$action || !is_callable($action['handler'] ?? null)) throw new RuntimeException('Actiunea modulului nu este disponibila.');
        return ($action['handler'])($context);
    }

    public function renderHook(string $hook, array $context = []): void {
        foreach ($this->boot()['renderers'][$hook] ?? [] as $renderer) {
            try { $renderer($context); }
            catch (Throwable $e) { $this->moduleLog('error','module_render',$hook.': '.$e->getMessage()); }
        }
    }

    public function uploadZip(array $file): string {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Extensia PHP ZipArchive nu este activa pe server.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Fisierul modulului nu a putut fi incarcat.');
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Modulul depaseste limita de 10 MB.');
        $tmpFile = (string)($file['tmp_name'] ?? '');
        if ($tmpFile === '' || !is_file($tmpFile)) throw new RuntimeException('Fisier temporar invalid.');

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) throw new RuntimeException('Arhiva ZIP este invalida.');
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = str_replace('\\','/', (string)$zip->getNameIndex($i));
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name,'/') || preg_match('#(^|/)\.\.(/|$)#',$name)) {
                $zip->close(); throw new RuntimeException('Arhiva contine o cale nesigura.');
            }
            if (preg_match('#(^|/)\.htaccess$#i',$name)) { $zip->close(); throw new RuntimeException('Modulele nu pot include .htaccess.'); }
        }
        $tempDir = $this->root.'/storage/module_uploads/'.bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) { $zip->close(); throw new RuntimeException('Nu pot crea folderul temporar.'); }
        $zip->extractTo($tempDir); $zip->close();

        $manifests = glob($tempDir.'/*/manifest.json') ?: [];
        if (is_file($tempDir.'/manifest.json')) $manifests[] = $tempDir.'/manifest.json';
        if (count($manifests) !== 1) { $this->removeTree($tempDir); throw new RuntimeException('ZIP-ul trebuie sa contina exact un manifest.json.'); }
        $sourceDir = dirname($manifests[0]);
        $manifest = json_decode((string)file_get_contents($manifests[0]), true);
        $slug = (string)($manifest['slug'] ?? basename($sourceDir));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) { $this->removeTree($tempDir); throw new RuntimeException('Slug de modul invalid.'); }
        if (($manifest['slug'] ?? $slug) !== $slug) { $this->removeTree($tempDir); throw new RuntimeException('Slug-ul manifestului nu corespunde folderului modulului.'); }

        $dest = $this->root.'/modules/'.$slug;
        $backup = null;
        if (is_dir($dest)) { $backup = $this->root.'/storage/module_uploads/backup-'.$slug.'-'.date('YmdHis'); rename($dest,$backup); }
        try {
            $this->copyTree($sourceDir, $dest);
            $this->removeTree($tempDir);
            $this->install($slug, false);
            $this->setEnabled($slug, false);
            if ($backup) $this->removeTree($backup);
        } catch (Throwable $e) {
            $this->removeTree($dest);
            if ($backup && is_dir($backup)) rename($backup,$dest);
            $this->removeTree($tempDir);
            throw $e;
        }
        return $slug;
    }

    private function boot(): array {
        if (self::$booted !== null) return self::$booted;
        $state = ['listeners'=>[],'routes'=>[],'nav'=>[],'renderers'=>[],'settings'=>[],'actions'=>[]];
        $all = $this->discover();
        try { $enabled = $this->db->query('SELECT slug FROM modules WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN); }
        catch (Throwable) { $enabled = []; }
        foreach ($enabled as $slug) {
            if (!isset($all[$slug])) continue;
            $file = $all[$slug]['_dir'].'/bootstrap.php';
            if (!is_file($file)) continue;
            try {
                $cfg = require $file;
                if (!is_array($cfg)) continue;
                foreach (($cfg['listeners'] ?? []) as $event => $listeners) {
                    if (is_callable($listeners)) $listeners = [$listeners];
                    foreach ((array)$listeners as $listener) if (is_callable($listener)) $state['listeners'][$event][] = $listener;
                }
                foreach ((array)($cfg['routes'] ?? []) as $route) if (is_array($route)) $state['routes'][] = $route;
                foreach ((array)($cfg['nav'] ?? []) as $nav) {
                    if (!is_array($nav)) continue;
                    $nav['_module_slug']=$slug;
                    $nav['_module_name']=$all[$slug]['name'] ?? $slug;
                    $state['nav'][]=$nav;
                }
                foreach ((array)($cfg['renderers'] ?? []) as $hook=>$renderers) {
                    if (is_callable($renderers)) $renderers=[$renderers];
                    foreach ((array)$renderers as $renderer) if (is_callable($renderer)) $state['renderers'][$hook][]=$renderer;
                }
                foreach ((array)($cfg['settings'] ?? []) as $section) {
                    if (!is_array($section)) continue;
                    $section['_module_slug']=$slug;
                    $section['_module_name']=$all[$slug]['name'] ?? $slug;
                    $state['settings'][]=$section;
                }
                foreach ((array)($cfg['actions'] ?? []) as $group=>$actions) {
                    foreach ((array)$actions as $key=>$action) {
                        if (!is_array($action) || !is_callable($action['handler'] ?? null)) continue;
                        $action['_module_slug']=$slug;
                        $action['_module_name']=$all[$slug]['name'] ?? $slug;
                        $state['actions'][(string)$group][(string)$key]=$action;
                    }
                }
            } catch (Throwable $e) { $this->moduleLog('error','module_boot',$slug.': '.$e->getMessage()); }
        }
        return self::$booted = $state;
    }

    private function assertCompatible(array $manifest): void {
        $min = (string)($manifest['min_app_version'] ?? '0.0.0');
        $current = trim((string)@file_get_contents($this->root.'/VERSION')) ?: '0.0.0';
        if (version_compare($current, $min, '<')) throw new RuntimeException("Modulul necesita ALFAMED CENTRAL {$min} sau mai nou.");
    }

    private function moduleLog(string $level,string $action,string $message): void {
        try { $st=$this->db->prepare('INSERT INTO sync_logs(channel_id,level,action,message,context_json) VALUES(NULL,?,?,?,NULL)');$st->execute([$level,$action,$message]); } catch (Throwable) {}
    }

    private function copyTree(string $src,string $dst): void {
        if (!is_dir($dst) && !mkdir($dst,0775,true) && !is_dir($dst)) throw new RuntimeException('Nu pot crea folderul modulului.');
        foreach (scandir($src) ?: [] as $item) {
            if ($item==='.' || $item==='..') continue;
            $from=$src.'/'.$item; $to=$dst.'/'.$item;
            if (is_link($from)) throw new RuntimeException('Link-urile simbolice nu sunt permise in module.');
            if (is_dir($from)) $this->copyTree($from,$to); else if (!copy($from,$to)) throw new RuntimeException('Nu pot copia fisierul modulului.');
        }
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) { if (is_file($dir)) @unlink($dir); return; }
        foreach (scandir($dir) ?: [] as $item) { if ($item==='.'||$item==='..') continue; $p=$dir.'/'.$item; is_dir($p) && !is_link($p) ? $this->removeTree($p) : @unlink($p); }
        @rmdir($dir);
    }
}

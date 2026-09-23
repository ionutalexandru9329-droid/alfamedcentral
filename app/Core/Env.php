<?php
namespace App\Core;

final class Env {
    private static array $vars = [];
    public static function load(string $file): void {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k,$v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if (str_starts_with($v,'"') && str_ends_with($v,'"')) { $v = substr($v,1,-1); $v = strtr($v, ['\\\\'=>'\\','\\"'=>'"']); }
            elseif (str_starts_with($v,"'") && str_ends_with($v,"'")) $v = substr($v,1,-1);
            self::$vars[$k] = $v;
            $_ENV[$k] = $v;
        }
    }
    public static function get(string $key, mixed $default=null): mixed { if(array_key_exists($key,self::$vars))return self::$vars[$key]; if(array_key_exists($key,$_ENV))return $_ENV[$key]; $g=getenv($key); return $g!==false?$g:$default; }
    public static function bool(string $key, bool $default=false): bool { return filter_var(self::get($key,$default?'true':'false'), FILTER_VALIDATE_BOOL); }
}

<?php
namespace App\Core;

use RuntimeException;

final class EnvEditor {
    public static function update(array $updates): void {
        $file = dirname(__DIR__,2).'/.env';
        if (!is_file($file)) throw new RuntimeException('Fisierul .env nu exista.');
        if (!is_writable($file)) throw new RuntimeException('Fisierul .env nu este scriabil. Verifica permisiunile.');
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new RuntimeException('Nu pot citi fisierul .env.');
        $pending = $updates;
        foreach ($lines as &$line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim,'#') || !str_contains($line,'=')) continue;
            [$key] = explode('=',$line,2); $key=trim($key);
            if (!array_key_exists($key,$pending)) continue;
            $line = $key.'='.self::encode((string)$pending[$key]);
            unset($pending[$key]);
        }
        unset($line);
        if ($pending) {
            $lines[] = '';
            foreach ($pending as $key=>$value) {
                if (!preg_match('/^[A-Z0-9_]+$/',(string)$key)) continue;
                $lines[] = $key.'='.self::encode((string)$value);
            }
        }
        $tmp=$file.'.tmp-'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,implode("\n",$lines)."\n",LOCK_EX)===false) throw new RuntimeException('Nu pot scrie configurarea temporara.');
        @chmod($tmp,0640);
        if(!rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Nu pot salva configurarea .env.');}
    }
    private static function encode(string $value): string {
        if(str_contains($value,"\n")||str_contains($value,"\r")) throw new RuntimeException('Valorile de configurare nu pot contine linii noi.');
        return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
    }
}

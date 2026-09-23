<?php
namespace App\Core;
final class Csrf {
    public static function token(): string { if(empty($_SESSION['_csrf'])) $_SESSION['_csrf']=bin2hex(random_bytes(24)); return $_SESSION['_csrf']; }
    public static function field(): string { return '<input type="hidden" name="_csrf" value="'.htmlspecialchars(self::token(),ENT_QUOTES).'">'; }
    public static function verify(): void {
        $t=$_POST['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if(!$t || !hash_equals($_SESSION['_csrf']??'', (string)$t)){ http_response_code(419); exit('CSRF token invalid. Reincarca pagina.'); }
    }
}

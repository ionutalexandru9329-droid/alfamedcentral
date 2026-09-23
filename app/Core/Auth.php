<?php
namespace App\Core;

use RuntimeException;

final class Auth {
    private const REMEMBER_COOKIE='alfamed_remember';
    private const REMEMBER_DAYS=30;

    public static function user(): ?array { return $_SESSION['user'] ?? null; }

    public static function check(): bool {
        if(isset($_SESSION['user'])) return true;
        return self::restoreRemembered();
    }

    public static function attempt(string $email,string $password,bool $remember=false): bool {
        $st=Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $u=$st->fetch();
        if (!$u || !password_verify($password,(string)$u['password_hash']) || (array_key_exists('is_active',$u) && !(bool)$u['is_active']) || !empty($u['deleted_at'])) return false;
        self::storeSession($u);
        self::touchActivity(true);
        session_regenerate_id(true);
        if($remember) self::issueRememberToken((int)$u['id']);
        else self::clearRememberToken();
        return true;
    }

    public static function logout(): void {
        self::clearRememberToken();
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
        }
        if(session_status()===PHP_SESSION_ACTIVE) session_destroy();
    }

    public static function requireLogin(): void { if(!self::check()) redirect('/login'); }

    /** Refresh role/permissions so an administrator's changes apply without a new login. */
    public static function syncSession(): void {
        $id=(int)($_SESSION['user']['id']??0);
        if($id<=0) return;
        $st=Database::connection()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $u=$st->fetch();
        if(!$u || (array_key_exists('is_active',$u) && !(bool)$u['is_active']) || !empty($u['deleted_at'])){
            self::logout();
            redirect('/login');
        }
        self::storeSession($u);
    }

    public static function isAdmin(): bool { return (string)(self::user()['role']??'')==='admin'; }

    public static function can(string $permission): bool {
        $u=self::user();
        if(!$u) return false;
        if((string)($u['role']??'')==='admin') return true;
        $perms=json_decode((string)($u['permissions_json']??'[]'),true);
        if(!is_array($perms)) $perms=[];
        return in_array('*',$perms,true) || in_array($permission,$perms,true);
    }

    public static function requirePermission(string $permission): void {
        if(!self::can($permission)) throw new RuntimeException('Nu ai permisiunea necesara pentru aceasta functie.');
    }

    public static function touchActivity(bool $force=false): void {
        $id=(int)(self::user()['id']??0);
        if($id<=0) return;
        $last=(int)($_SESSION['_activity_touch']??0);
        if(!$force && time()-$last<20) return;
        try {
            Database::connection()->prepare('UPDATE users SET last_active_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
            $_SESSION['_activity_touch']=time();
            if(isset($_SESSION['user'])) $_SESSION['user']['last_active_at']=date('Y-m-d H:i:s');
        } catch(\Throwable) {}
    }

    /** Restore a logged-out PHP session from a long-lived, hashed browser token. */
    private static function restoreRemembered(): bool {
        $raw=trim((string)($_COOKIE[self::REMEMBER_COOKIE]??''));
        if($raw===''||!str_contains($raw,'.')) return false;
        [$selector,$validator]=array_pad(explode('.',$raw,2),2,'');
        if(!preg_match('/^[a-f0-9]{24}$/',$selector)||!preg_match('/^[a-f0-9]{64}$/',$validator)){self::expireRememberCookie();return false;}
        try{
            $db=Database::connection();
            $st=$db->prepare('SELECT t.id token_id,t.token_hash,t.expires_at,u.* FROM auth_remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector=? LIMIT 1');
            $st->execute([$selector]);$u=$st->fetch();
            if(!$u){self::expireRememberCookie();return false;}
            $expires=strtotime((string)($u['expires_at']??''));
            $valid=$expires!==false&&$expires>time()&&hash_equals((string)$u['token_hash'],hash('sha256',$validator))&&(!array_key_exists('is_active',$u)||(bool)$u['is_active'])&&empty($u['deleted_at']);
            if(!$valid){$db->prepare('DELETE FROM auth_remember_tokens WHERE selector=?')->execute([$selector]);self::expireRememberCookie();return false;}
            self::storeSession($u);session_regenerate_id(true);self::touchActivity(true);
            try{$db->prepare('UPDATE auth_remember_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$u['token_id']]);}catch(\Throwable){}
            return true;
        }catch(\Throwable){return false;}
    }

    private static function issueRememberToken(int $userId): void {
        self::clearRememberToken();
        if($userId<=0)return;
        try{
            $selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));$hash=hash('sha256',$validator);$expires=time()+(self::REMEMBER_DAYS*86400);
            Database::connection()->prepare('INSERT INTO auth_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,?)')->execute([$userId,$selector,$hash,date('Y-m-d H:i:s',$expires)]);
            setcookie(self::REMEMBER_COOKIE,$selector.'.'.$validator,self::cookieOptions($expires));
            $_COOKIE[self::REMEMBER_COOKIE]=$selector.'.'.$validator;
        }catch(\Throwable){/* Login itself must remain usable if persistent-token storage is unavailable. */}
    }

    private static function clearRememberToken(): void {
        $raw=trim((string)($_COOKIE[self::REMEMBER_COOKIE]??''));
        $selector=str_contains($raw,'.')?explode('.',$raw,2)[0]:'';
        if(preg_match('/^[a-f0-9]{24}$/',$selector)){
            try{Database::connection()->prepare('DELETE FROM auth_remember_tokens WHERE selector=?')->execute([$selector]);}catch(\Throwable){}
        }
        self::expireRememberCookie();
    }

    private static function expireRememberCookie(): void {
        setcookie(self::REMEMBER_COOKIE,'',self::cookieOptions(time()-3600));
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function cookieOptions(int $expires): array {
        $path=function_exists('app_base_path')?\app_base_path():'';$path=$path===''?'/':rtrim($path,'/').'/';
        $forwardedProto=strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')));
        $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||$forwardedProto==='https';
        return ['expires'=>$expires,'path'=>$path,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'];
    }

    private static function storeSession(array $u): void {
        $_SESSION['user']=[
            'id'=>(int)$u['id'],
            'name'=>(string)$u['name'],
            'email'=>(string)$u['email'],
            'role'=>(string)($u['role']??'operator'),
            'permissions_json'=>(string)($u['permissions_json']??'[]'),
            'is_active'=>(int)($u['is_active']??1),
            'job_title'=>(string)($u['job_title']??''),
            'avatar_path'=>(string)($u['avatar_path']??''),
            'last_active_at'=>(string)($u['last_active_at']??''),
        ];
    }
}

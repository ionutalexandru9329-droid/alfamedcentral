<?php
declare(strict_types=1);
require __DIR__.'/app/Core/Env.php';
\App\Core\Env::load(__DIR__.'/.env');
date_default_timezone_set((string)\App\Core\Env::get('APP_TIMEZONE','Europe/Bucharest'));
spl_autoload_register(function(string $class){
    if(!str_starts_with($class,'App\\')) return;
    $path=__DIR__.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path)) require $path;
});

/**
 * URL path where the project root is mounted.
 * Works when installed in the document root or in any subfolder and does not
 * depend on Apache rewrite rules.
 */
function app_base_path(): string {
    static $base = null;
    if ($base !== null) return $base;

    $normalize = static function(?string $value): string {
        $value = trim(str_replace('\\','/',(string)$value));
        if ($value === '' || $value === '/' || $value === '.') return '';
        return '/'.trim($value, '/');
    };

    // Prefer the actual front-controller location. This remains reliable even
    // when APP_URL was saved incorrectly by an older installer.
    $script = str_replace('\\','/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/public/index.php','/public/install.php'] as $suffix) {
        if ($script !== '' && str_ends_with($script, $suffix)) {
            $candidate = substr($script, 0, -strlen($suffix));
            return $base = $normalize($candidate);
        }
    }
    foreach (['/index.php','/install.php'] as $suffix) {
        if ($script !== '' && str_ends_with($script, $suffix)) {
            return $base = $normalize(substr($script, 0, -strlen($suffix)));
        }
    }

    $configured = parse_url((string)\App\Core\Env::get('APP_URL',''), PHP_URL_PATH);
    return $base = $normalize(is_string($configured) ? $configured : '');
}

/** True when the web server DocumentRoot points directly to /public. */
function app_public_is_docroot(): bool {
    $scriptName = str_replace('\\','/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\','/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $publicDir = str_replace('\\','/', (string)(realpath(__DIR__.'/public') ?: (__DIR__.'/public')));
    return $scriptFile !== ''
        && ($scriptFile === $publicDir.'/index.php' || $scriptFile === $publicDir.'/install.php')
        && !str_contains($scriptName, '/public/');
}

/** Absolute-path front controller inside the current host. */
function app_front_controller(): string {
    return (app_base_path() ?: '').'/index.php';
}

/**
 * Build a URL for an internal route. Internal navigation is query-string based
 * so it works on Apache/cPanel/XAMPP even when mod_rewrite is disabled.
 */
function app_path(string $path = '/'): string {
    if ($path === '') $path = '/';
    if ($path[0] === '#' || str_starts_with($path, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) return $path;

    // Keep extra query parameters outside the encoded `route` value. This lets
    // callers safely use app_path('/settings?tab=integrations') as well as the
    // older app_path('/settings').'&tab=integrations' form.
    $fragment='';
    if (($hashPos=strpos($path,'#'))!==false) { $fragment=substr($path,$hashPos); $path=substr($path,0,$hashPos); }
    $query='';
    if (($queryPos=strpos($path,'?'))!==false) { $query=substr($path,$queryPos+1); $path=substr($path,0,$queryPos); }

    $normalized = '/'.ltrim($path, '/');
    $base = app_base_path() ?: '';

    // Static assets are real files; no router is involved.
    if (str_starts_with($normalized, '/assets/') || str_starts_with($normalized, '/uploads/')) $url=$base.(app_public_is_docroot() ? '' : '/public').$normalized;
    elseif ($normalized === '/install.php') $url=$base.'/install.php';
    elseif ($normalized === '/index.php') $url=$base.'/index.php';
    elseif ($normalized === '/') $url=$base.'/index.php';
    else $url=$base.'/index.php?route='.rawurlencode($normalized);

    if ($query !== '') $url.=(str_contains($url,'?')?'&':'?').$query;
    return $url.$fragment;
}


/** Absolute application URL for links that leave the admin interface (for example customer documents). */
function app_absolute_path(string $path = '/'): string {
    $configured=rtrim((string)\App\Core\Env::get('APP_URL',''),'/');
    $base=app_base_path();
    $routePath=app_path($path);
    if($configured!==''){
        $parts=parse_url($configured);
        if(is_array($parts)&&!empty($parts['scheme'])&&!empty($parts['host'])){
            $origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
            return $origin.$routePath;
        }
    }
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    $scheme=$https?'https':'http';
    $host=(string)($_SERVER['HTTP_HOST']??'localhost');
    return $scheme.'://'.$host.$routePath;
}

/** Route requested by the front controller. */
function app_route_path(?string $requestUri = null): string {
    if (isset($_GET['route'])) {
        $route = trim((string)$_GET['route']);
        if ($route === '') return '/';
        if (str_contains($route, "\0") || str_contains($route, "\r") || str_contains($route, "\n") || str_contains($route, '..')) return '/';
        return '/'.ltrim($route, '/');
    }

    // Backward-compatible fallback for old pretty URLs/bookmarks.
    $path = (string)(parse_url($requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $base = app_base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) $path = substr($path, strlen($base));
    if ($path === '' || $path === '/' || $path === '/index.php') return '/';
    if (str_starts_with($path, '/index.php/')) $path = substr($path, strlen('/index.php'));
    return $path === '' ? '/' : $path;
}

if(PHP_SAPI!=='cli' && session_status()!==PHP_SESSION_ACTIVE){
    $cookiePath=app_base_path();
    $cookiePath=$cookiePath===''?'/':rtrim($cookiePath,'/').'/';
    session_set_cookie_params([
        'httponly'=>true,
        'samesite'=>'Lax',
        'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),
        'path'=>$cookiePath
    ]);
    session_start();
}
function flash(string $key, ?string $value=null): ?string {
    if($value!==null){$_SESSION['_flash'][$key]=$value; return null;}
    $v=$_SESSION['_flash'][$key]??null; unset($_SESSION['_flash'][$key]); return $v;
}
function redirect(string $url): never {
    $location = ($url !== '' && ($url[0] === '#' || str_starts_with($url,'//') || preg_match('#^[a-z][a-z0-9+.-]*:#i',$url))) ? $url : app_path($url);
    header('Location: '.$location, true, 302);
    exit;
}

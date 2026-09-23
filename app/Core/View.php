<?php
namespace App\Core;
final class View {
    public static function render(string $view,array $data=[]): void { extract($data,EXTR_SKIP); $viewFile=dirname(__DIR__,2).'/views/'.$view.'.php'; require dirname(__DIR__,2).'/views/layout.php'; }
    public static function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
}

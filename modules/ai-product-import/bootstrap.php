<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\SettingService;
use AlfamedModules\AiProductImport\AiProductImportService;

require_once __DIR__.'/src/AiProductImportService.php';

return [
    'renderers'=>[
        'products.actions'=>static function():void{
            if(!Auth::can('products.manage'))return;
            echo '<a class="button-secondary product-top-action ai-import-action" href="'.View::e(app_path('/products/ai-import')).'">✨ Auto Import AI</a>';
        },
    ],
    'routes'=>[
        ['method'=>'GET','path'=>'/products/ai-import','handler'=>static function():void{
            Auth::requirePermission('products.manage');$settings=new SettingService();View::render('product_ai_import',['title'=>'Auto Import AI','preview'=>null,'token'=>'','settings'=>$settings]);
        }],
        ['method'=>'POST','path'=>'/products/ai-import/preview','handler'=>static function():void{
            Auth::requirePermission('products.manage');Csrf::verify();$result=(new AiProductImportService())->preview((string)($_POST['urls']??''));$token=bin2hex(random_bytes(16));$_SESSION['ai_product_import'][$token]=$result['products'];$settings=new SettingService();View::render('product_ai_import',['title'=>'Auto Import AI','preview'=>$result,'token'=>$token,'settings'=>$settings]);
        }],
        ['method'=>'POST','path'=>'/products/ai-import/create','handler'=>static function():void{
            Auth::requirePermission('products.manage');Csrf::verify();$token=(string)($_POST['token']??'');$products=$_SESSION['ai_product_import'][$token]??null;if(!is_array($products))throw new RuntimeException('Previzualizarea a expirat. Reia analiza URL-urilor.');$result=(new AiProductImportService())->createDrafts($products,(array)($_POST['selected']??[]),(array)($_POST['targets']??[]));unset($_SESSION['ai_product_import'][$token]);$errors=(array)($result['errors']??[]);flash($errors?'error':'success','Produse create ca ciorna: '.(int)($result['ok']??0).($errors?' · Erori: '.implode(' | ',array_slice($errors,0,3)):''));redirect('/products');
        }],
    ],
    'settings'=>[[
        'title'=>'Auto Import AI produse','description'=>'Extrage automat informatii publice din JSON-LD/OpenGraph. Produsele sunt create exclusiv ca ciorna pentru verificare umana inainte de publicare.',
        'fields'=>[
            ['key'=>'max_urls','label'=>'Maximum URL-uri / import','type'=>'number','min'=>1,'max'=>25,'default'=>'10'],
            ['key'=>'fetch_timeout','label'=>'Timeout descarcare pagina (secunde)','type'=>'number','min'=>3,'max'=>30,'default'=>'10'],
            ['key'=>'default_univera','label'=>'Bifeaza implicit Univera.ro','type'=>'checkbox','default'=>'true'],
            ['key'=>'default_alfamed','label'=>'Bifeaza implicit Alfamedclinic.ro','type'=>'checkbox','default'=>'true'],
        ],
    ]],
];

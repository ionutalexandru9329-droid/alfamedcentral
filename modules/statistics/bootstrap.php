<?php
use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\Csrf;
use App\Services\SettingService;
use App\Services\SyncService;
use AlfamedModules\Statistics\StatisticsService;

require_once __DIR__.'/src/StatisticsService.php';

return [
    'nav'=>[['label'=>'Statistici','url'=>'/statistics','permission'=>'dashboard.view']],
    'routes'=>[[
        'method'=>'GET','path'=>'/statistics','handler'=>static function():void{
            Auth::requirePermission('dashboard.view');$db=Database::connection();$settings=new SettingService($db);$allowed=[7,8,14,30,90,365];$default=(int)$settings->get('module.statistics.default_days','30');$days=(int)($_GET['days']??$default);if(!in_array($days,$allowed,true))$days=in_array($default,$allowed,true)?$default:30;$channel=trim((string)($_GET['channel']??''));
            $channels=$db->query('SELECT code,name FROM channels ORDER BY name')->fetchAll();if($channel!==''&&!in_array($channel,array_column($channels,'code'),true))$channel='';$report=(new StatisticsService($db))->report($days,$channel);
            View::render('statistics',compact('report','channels','days','channel')+['title'=>'Statistici','defaultDays'=>$default]);
        },
    ],[
        'method'=>'POST','path'=>'/statistics/sync','handler'=>static function():void{
            Auth::requirePermission('dashboard.view');Csrf::verify();if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
            $result=(new SyncService())->syncOrdersOnly();$errors=array_filter($result,static fn($r)=>!($r['ok']??false));$new=array_sum(array_map(static fn($r)=>(int)($r['new']??0),$result));
            if(session_status()!==PHP_SESSION_ACTIVE) @session_start();
            flash($errors?'error':'success',$errors?'Sincronizarea comenzilor s-a terminat cu erori. Verifica Setari > Jurnal.':'Statistici actualizate. Comenzi noi: '.$new.'.');
            $days=(int)($_POST['days']??30);$channel=trim((string)($_POST['channel']??''));$target='/statistics?days='.$days;if($channel!=='')$target.='&channel='.rawurlencode($channel);redirect($target);
        },
    ]],
    'renderers'=>[
        'dashboard.analytics'=>static function():void{
            if(!Auth::can('dashboard.view'))return;$settings=new SettingService(Database::connection());$days=max(7,min(90,(int)$settings->get('module.statistics.dashboard_days','14')));$report=(new StatisticsService(Database::connection()))->dashboardReport($days);require dirname(__DIR__,2).'/views/partials/dashboard_statistics.php';
        },
    ],
    'settings'=>[ [
        'title'=>'Statistici comenzi','description'=>'Controleaza perioada implicita si modul de calcul al indicatorilor de vanzari.',
        'fields'=>[
            ['key'=>'default_days','label'=>'Perioada implicita pagina Statistici','type'=>'select','default'=>'30','options'=>['7'=>'7 zile','8'=>'8 zile','14'=>'14 zile','30'=>'30 zile','90'=>'90 zile','365'=>'365 zile']],
            ['key'=>'dashboard_days','label'=>'Perioada grafic Dashboard (zile)','type'=>'number','min'=>7,'max'=>90,'default'=>'14'],
            ['key'=>'exclude_cancelled','label'=>'Exclude comenzile anulate/returnate/stornate din venituri','type'=>'checkbox','default'=>'true'],
        ],
    ] ],
];

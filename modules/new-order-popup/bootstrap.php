<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Services\NotificationService;
use App\Services\SettingService;
use App\Services\WebPushService;

$json=static function(array $payload,int $status=200):void{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
};

return [
    'listeners'=>[
        'order.created'=>static function(array $payload):void{
            $settings=new SettingService();
            if(!$settings->bool('module.new-order-popup.new_orders_enabled',true))return;
            $orderId=(int)($payload['order_id']??0);
            if($orderId>0)(new NotificationService())->newOrder($orderId);
        },
        'product.stock_changed'=>static function(array $payload):void{(new NotificationService())->stockChanged($payload);},
    ],
    'routes'=>[
        ['method'=>'GET','path'=>'/push-sw.js','public'=>true,'handler'=>static function():void{
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Service-Worker-Allowed: '.((app_base_path()?:'').'/'));
            readfile(__DIR__.'/push-sw.js');
        }],
        ['method'=>'GET','path'=>'/manifest.webmanifest','public'=>true,'handler'=>static function():void{
            header('Content-Type: application/manifest+json; charset=utf-8');
            $start=app_path('/');
            echo json_encode(['name'=>'ALFAMED CENTRAL','short_name'=>'ALFAMED','start_url'=>$start,'scope'=>(app_base_path()?:'').'/', 'display'=>'standalone','background_color'=>'#f4f6fa','theme_color'=>'#121a2d'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }],
        ['method'=>'GET','path'=>'/api/push/public-key','handler'=>static function() use ($json):void{
            Auth::requireLogin();
            $service=new WebPushService();
            $userId=(int)(Auth::user()['id']??0);
            try{
                $json([
                    'ok'=>true,
                    'supported'=>$service->supported(),
                    'public_key'=>$service->supported()?$service->publicKey():'',
                    'subscriptions'=>$service->userSubscriptionCount($userId),
                    'permission_hint'=>'Activeaza permisiunea de notificari in browser si pastreaza abonamentul activ pe dispozitiv.',
                ]);
            }catch(Throwable $e){$json(['ok'=>false,'supported'=>false,'error'=>$e->getMessage()],422);}
        }],
        ['method'=>'POST','path'=>'/api/push/subscribe','handler'=>static function() use ($json):void{
            Auth::requireLogin();Csrf::verify();
            try{
                $subscription=json_decode((string)($_POST['subscription']??''),true);
                if(!is_array($subscription))throw new RuntimeException('Abonamentul push este invalid.');
                $label=trim((string)($_POST['label']??''));
                (new WebPushService())->saveSubscription((int)Auth::user()['id'],$subscription,$label,(string)($_SERVER['HTTP_USER_AGENT']??''));
                $json(['ok'=>true]);
            }catch(Throwable $e){$json(['ok'=>false,'error'=>$e->getMessage()],422);}
        }],
        ['method'=>'POST','path'=>'/api/push/unsubscribe','handler'=>static function() use ($json):void{
            Auth::requireLogin();Csrf::verify();
            try{(new WebPushService())->removeSubscription((int)Auth::user()['id'],trim((string)($_POST['endpoint']??'')));$json(['ok'=>true]);}
            catch(Throwable $e){$json(['ok'=>false,'error'=>$e->getMessage()],422);}
        }],
        ['method'=>'POST','path'=>'/api/push/test','handler'=>static function() use ($json):void{
            Auth::requireLogin();Csrf::verify();
            try{$r=(new WebPushService())->testUser((int)Auth::user()['id']);if((int)($r['sent']??0)<1)throw new RuntimeException('Nu exista niciun dispozitiv push activ sau livrarea a esuat'.(!empty($r['last_error'])?': '.$r['last_error']:'.'));$json(['ok'=>true]+$r);}
            catch(Throwable $e){$json(['ok'=>false,'error'=>$e->getMessage()],422);}
        }],
    ],
    'settings'=>[[
        'title'=>'Notificari comenzi si stoc',
        'description'=>'Alege alertele, textele si notificarile push care pot ajunge pe PC, tableta sau telefon. La click se deschide direct comanda sau produsul.',
        'fields'=>[
            ['key'=>'section_orders','label'=>'Comenzi','type'=>'heading'],
            ['key'=>'new_orders_enabled','label'=>'Notifica pentru comenzile noi din cele 4 canale','type'=>'checkbox','default'=>'true'],
            ['key'=>'popup_new_orders','label'=>'Afiseaza pop-up pentru comenzi noi','type'=>'checkbox','default'=>'true'],
            ['key'=>'new_order_title','label'=>'Titlu notificare comanda noua','type'=>'text','default'=>'Comanda noua · {channel}','help'=>'Variabile: {order}, {channel}, {customer}, {total}, {currency}.'],
            ['key'=>'new_order_message','label'=>'Text notificare comanda noua','type'=>'textarea','default'=>'{order} · {customer} · {total} {currency}','help'=>'Textul este folosit in centrul de notificari si in Web Push.'],

            ['key'=>'section_stock','label'=>'Stoc WooCommerce','type'=>'heading'],
            ['key'=>'low_stock_enabled','label'=>'Notifica atunci cand stocul devine aproape epuizat','type'=>'checkbox','default'=>'true'],
            ['key'=>'out_of_stock_enabled','label'=>'Notifica atunci cand produsul ajunge fara stoc','type'=>'checkbox','default'=>'true'],
            ['key'=>'low_stock_threshold','label'=>'Prag stoc aproape epuizat (bucati)','type'=>'number','min'=>1,'max'=>1000,'default'=>'5'],
            ['key'=>'univera_stock','label'=>'Monitorizeaza stocul Univera.ro','type'=>'checkbox','default'=>'true'],
            ['key'=>'alfamed_stock','label'=>'Monitorizeaza stocul Alfamedclinic.ro','type'=>'checkbox','default'=>'true'],
            ['key'=>'alert_initial_stock','label'=>'Alerteaza si produsele deja cu stoc mic la prima sincronizare','type'=>'checkbox','default'=>'false'],
            ['key'=>'popup_stock','label'=>'Afiseaza pop-up si pentru alertele de stoc','type'=>'checkbox','default'=>'true'],
            ['key'=>'low_stock_title','label'=>'Titlu notificare stoc aproape epuizat','type'=>'text','default'=>'Stoc aproape epuizat · {channel}','help'=>'Variabile: {product}, {stock}, {threshold}, {channel}.'],
            ['key'=>'low_stock_message','label'=>'Text notificare stoc aproape epuizat','type'=>'textarea','default'=>'{product} mai are {stock} buc. in stoc.'],
            ['key'=>'out_of_stock_title','label'=>'Titlu notificare stoc epuizat','type'=>'text','default'=>'Produs fara stoc · {channel}','help'=>'Variabile: {product}, {stock}, {threshold}, {channel}.'],
            ['key'=>'out_of_stock_message','label'=>'Text notificare stoc epuizat','type'=>'textarea','default'=>'{product} nu mai are stoc.'],

            ['key'=>'section_push','label'=>'Notificari push pe dispozitive','type'=>'heading'],
            ['key'=>'push_enabled','label'=>'Activeaza Web Push pentru dispozitivele abonate','type'=>'checkbox','default'=>'true','help'=>'Functioneaza prin Service Worker pe HTTPS. Pe iPhone/iPad, site-ul trebuie adaugat pe ecranul principal pentru notificari Web Push.'],
            ['key'=>'push_new_orders','label'=>'Trimite push pentru comenzi noi','type'=>'checkbox','default'=>'true'],
            ['key'=>'push_low_stock','label'=>'Trimite push pentru stoc aproape epuizat','type'=>'checkbox','default'=>'true'],
            ['key'=>'push_out_of_stock','label'=>'Trimite push pentru stoc epuizat','type'=>'checkbox','default'=>'true'],
            ['key'=>'push_contact','label'=>'Contact tehnic Web Push','type'=>'text','default'=>'mailto:contact@alfamedclinic.ro','help'=>'Adresa VAPID. Pastreaza formatul mailto:email@domeniu.ro sau un URL HTTPS.'],

            ['key'=>'section_delivery','label'=>'Afisare in platforma','type'=>'heading'],
            ['key'=>'poll_seconds','label'=>'Actualizare clopotel la fiecare (secunde)','type'=>'number','min'=>3,'max'=>120,'default'=>'5'],
            ['key'=>'browser_notifications','label'=>'Permite fallback notificari de sistem cand pagina este deschisa','type'=>'checkbox','default'=>'true'],
            ['key'=>'mark_seen_on_open','label'=>'Marcheaza notificarile vizibile ca citite cand deschid lista','type'=>'checkbox','default'=>'true'],
        ],
    ]],
];

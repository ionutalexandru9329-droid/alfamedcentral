<?php
namespace AlfamedModules\Couriers;

use App\Core\Database;
use App\Core\Http;
use App\Integrations\WooCommerceClient;
use App\Services\ChannelFactory;
use App\Services\OrderOperations;
use App\Services\OrderPresentation;
use App\Services\SettingService;
use PDO;
use RuntimeException;
use Throwable;

final class CourierService {
    private PDO $db;
    private SettingService $settings;
    private ?string $fanToken=null;
    private ?string $samedayToken=null;

    public function __construct(?PDO $db=null) {
        $this->db=$db?:Database::connection();
        $this->settings=new SettingService($this->db);
    }

    /** @return array<string,string> Curierii afisabili in interfata: modul activ + API complet configurat. */
    public function configuredCouriers(): array {
        $out=[];
        $samedayReady=trim((string)$this->settings->get('module.couriers.sameday_username',''))!==''
            && (string)$this->settings->get('module.couriers.sameday_password','')!==''
            && (int)$this->settings->get('module.couriers.sameday_pickup_point','0')>0
            && (int)$this->settings->get('module.couriers.sameday_service_id','0')>0;
        if($samedayReady)$out['sameday']='Sameday';

        $fanReady=trim((string)$this->settings->get('module.couriers.fan_username',''))!==''
            && (string)$this->settings->get('module.couriers.fan_password','')!==''
            && trim((string)$this->settings->get('module.couriers.fan_client_id',''))!=='';
        if($fanReady)$out['fan']='FAN Courier';

        $dragonReady=trim((string)$this->settings->get('module.couriers.dragonstar_base_url',''))!==''
            && trim((string)$this->settings->get('module.couriers.dragonstar_awb_path',''))!==''
            && trim((string)$this->settings->get('module.couriers.dragonstar_payload_template',''))!=='';
        if($dragonReady)$out['dragonstar']='Dragon Star';
        return $out;
    }

    public function create(int $orderId,string $courier,array $options=[]): array {
        $order=(new OrderOperations())->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='woocommerce') throw new RuntimeException('Pentru comenzile eMAG AWB-ul se genereaza prin fluxul eMAG Marketplace, nu prin integrarea directa a curierului.');
        $courier=strtolower(trim($courier));
        $configured=$this->configuredCouriers();
        if(!isset($configured[$courier])) {
            throw new RuntimeException('Curierul selectat nu este activ/configurat complet in Setari > Module > Curieri.');
        }
        $st=$this->db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('cancelled','deleted') ORDER BY id DESC LIMIT 1");
        $st->execute([$orderId]);
        if($st->fetch()) throw new RuntimeException('Comanda are deja un AWB activ. Sterge/anuleaza AWB-ul existent inainte de a genera altul.');

        $ctx=$this->context($order,$options);
        $result=match($courier){
            'fan'=>$this->fan($ctx),
            'sameday'=>$this->sameday($ctx),
            'dragonstar'=>$this->dragonStar($ctx),
        };
        $awb=trim((string)($result['awb']??''));
        if($awb==='') throw new RuntimeException('Curierul nu a returnat numarul AWB.');
        $tracking=trim((string)($result['tracking_url']??''));
        if($tracking==='')$tracking=$this->trackingUrl($courier,$awb);

        $stored=[
            'provider'=>$result['raw']??$result,
            'shipment'=>['weight'=>$ctx['weight'],'parcels'=>$ctx['parcels'],'cod'=>$ctx['cod']],
        ];
        $ins=$this->db->prepare('INSERT INTO shipments(order_id,courier,awb,status,tracking_url,raw_json) VALUES(?,?,?,?,?,?)');
        $ins->execute([$orderId,$courier,$awb,'created',$tracking?:null,json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $shipmentId=(int)$this->db->lastInsertId();

        // AWB-ul exista deja la curier; sincronizarea catre WooCommerce este best-effort,
        // ca sa nu provocam generarea unui al doilea AWB daca WooCommerce raspunde cu eroare.
        $warnings=[];$woo=null;
        try{
            $woo=$this->syncWooOrderTracking($order,$courier,$awb,$tracking,$ctx);
        }catch(Throwable $e){
            $warnings[]='AWB creat, dar nu a putut fi atasat comenzii WooCommerce: '.$e->getMessage();
            $woo=['synced'=>false,'error'=>$e->getMessage()];
        }
        $stored['woocommerce']=$woo;
        $this->db->prepare('UPDATE shipments SET raw_json=? WHERE id=?')->execute([json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$shipmentId]);

        return ['order_id'=>$orderId,'awb'=>$awb,'courier'=>$courier,'tracking_url'=>$tracking,'cod'=>$ctx['cod'],'weight'=>$ctx['weight'],'parcels'=>$ctx['parcels'],'warnings'=>$warnings,'raw'=>$result['raw']??null];
    }

    public function createAutomatic(int $orderId,array $options=[]): array {
        $order=(new OrderOperations())->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='woocommerce')throw new RuntimeException('Fluxul automat direct este disponibil pentru comenzile WooCommerce.');
        $configured=$this->configuredCouriers();if(!$configured)throw new RuntimeException('Nu exista niciun curier WooCommerce configurat complet.');
        $courier=$this->automaticCourier($order,$configured);
        if($courier==='')throw new RuntimeException('Metoda de livrare a comenzii nu indica un curier configurat. Deschide comanda si selecteaza curierul manual.');
        return $this->create($orderId,$courier,$options);
    }

    public function bulkCreateAutomatic(array $orderIds,array $options=[]): array {
        $ok=0;$errors=[];$warnings=[];$items=[];$successIds=[];
        foreach($this->cleanIds($orderIds) as $id){
            try{$r=$this->createAutomatic($id,$options);$ok++;$successIds[]=$id;$items[]=$r;foreach((array)($r['warnings']??[]) as $warning)$warnings[]='#'.$id.': '.$warning;}
            catch(Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}
        }
        return ['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings,'items'=>$items,'success_ids'=>$successIds];
    }

    private function automaticCourier(array $order,array $configured): string {
        $kind=OrderPresentation::deliveryKind($order);$method=strtolower(OrderPresentation::shippingMethodRaw($order));
        if($kind==='easybox'&&isset($configured['sameday']))return 'sameday';
        if($kind==='fanbox'&&isset($configured['fan']))return 'fan';
        $tests=['sameday'=>['sameday','same day','easybox'],'fan'=>['fan courier','fancourier','fanbox'],'dragonstar'=>['dragon star','dragonstar']];
        foreach($tests as $code=>$tokens){if(!isset($configured[$code]))continue;foreach($tokens as $token)if(str_contains($method,$token))return $code;}
        if(count($configured)===1)return (string)array_key_first($configured);
        $preferred=strtolower(trim((string)$this->settings->get('module.couriers.default_woo_courier','')));
        if($preferred!==''&&isset($configured[$preferred]))return $preferred;
        return '';
    }

    public function bulkCreate(array $orderIds,string $courier,array $options=[]): array {
        $ok=0;$errors=[];$warnings=[];$items=[];$successIds=[];
        foreach($this->cleanIds($orderIds) as $id){
            try{
                $r=$this->create($id,$courier,$options);$ok++;$successIds[]=$id;$items[]=$r;
                foreach((array)($r['warnings']??[]) as $warning)$warnings[]='#'.$id.': '.$warning;
            }
            catch(Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}
        }
        return ['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings,'items'=>$items,'success_ids'=>$successIds];
    }

    public function awbPdf(int $orderId,string $format='A4'): array {
        $format=strtoupper(trim($format));
        if(!in_array($format,['A4','A6'],true))throw new RuntimeException('Format AWB invalid. Sunt acceptate A4 si A6.');
        $st=$this->db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('cancelled','deleted') ORDER BY id DESC LIMIT 1");
        $st->execute([$orderId]);$shipment=$st->fetch();
        if(!$shipment)throw new RuntimeException('Comanda nu are un AWB activ.');
        $awb=trim((string)($shipment['awb']??''));$courier=strtolower(trim((string)($shipment['courier']??'')));
        if($awb==='')throw new RuntimeException('Expedierea nu are numar AWB.');
        $body=match($courier){
            'sameday'=>$this->samedayAwbPdf($awb,$format),
            'fan'=>$this->fanAwbPdf($awb,$format),
            default=>throw new RuntimeException('Descarcarea etichetei A4/A6 nu este configurata pentru curierul '.($courier?:'necunoscut').'.'),
        };
        return ['body'=>$body,'filename'=>'AWB-'.$awb.'-'.$format.'.pdf','awb'=>$awb,'courier'=>$courier,'format'=>$format];
    }


    /** Importa AWB/tracking deja creat in WooCommerce de un plugin de curier. */
    public function syncExistingRemoteAwb(int $orderId): ?array {
        $st=$this->db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('cancelled','deleted') ORDER BY id DESC LIMIT 1");
        $st->execute([$orderId]);$local=$st->fetch();if($local)return $local;
        $order=(new OrderOperations())->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='woocommerce')return null;
        $client=ChannelFactory::client((string)$order['channel_code']);if(!$client instanceof WooCommerceClient)return null;
        $external=trim((string)($order['external_id']??''));if($external==='')return null;
        try{$remote=$client->readOrder($external);}catch(Throwable){return null;}
        $tokens=$this->wooTrackingTokens($remote);if(!$tokens)return null;
        foreach($tokens as $item){
            $awb=trim((string)($item['token']??''));if($awb==='')continue;
            $courier=$this->normalizeCourierCode((string)($item['courier']??'WooCommerce'));
            $q=$this->db->prepare("SELECT id,order_id FROM shipments WHERE awb=? OR awb_barcode=? ORDER BY id DESC LIMIT 1");$q->execute([$awb,$awb]);$existing=$q->fetch();
            if($existing){if((int)$existing['order_id']===$orderId){$st->execute([$orderId]);return $st->fetch()?:null;}continue;}
            $meta=['source'=>'woocommerce_remote','tracking'=>$item,'remote'=>$remote];
            try{
                $ins=$this->db->prepare('INSERT INTO shipments(order_id,courier,awb,awb_barcode,status,tracking_url,raw_json) VALUES(?,?,?,?,?,?,?)');
                $ins->execute([$orderId,$courier,$awb,$awb,'created',null,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }catch(Throwable){continue;}
            $st->execute([$orderId]);return $st->fetch()?:null;
        }
        return null;
    }

    private function wooTrackingTokens(array $order): array {
        $found=[];
        $normalize=static fn(string $v):string=>strtoupper(preg_replace('/[^A-Za-z0-9]/','',$v)??'');
        $add=static function(string $value,string $provider='WooCommerce') use (&$found,$normalize):void{
            $value=trim($value);if($value===''||strlen($value)>220)return;$norm=$normalize($value);if(strlen($norm)<6)return;
            if(preg_match('/^20\d{6,12}$/',$norm))return;$found[$norm]=['token'=>$value,'courier'=>trim($provider)!==''?trim($provider):'WooCommerce'];
        };
        $walk=null;
        $walk=static function(mixed $value,string $key='',bool $trackingContext=false,string $provider='WooCommerce') use (&$walk,$add):void{
            $lk=strtolower($key);
            $tokenKey=(bool)preg_match('/(?:^|_)(?:awb(?:_number|_code)?|tracking(?:_number|_code)?|waybill(?:_number)?|consignment(?:_number)?)(?:$|_)/i',$key);
            $container=$trackingContext||(bool)preg_match('/(?:shipment.?tracking|tracking.?items|tracking.?info|awb)/i',$key);
            if(is_array($value)){
                $localProvider=$provider;foreach(['tracking_provider','custom_tracking_provider','courier_name','courier','provider'] as $pk){if(isset($value[$pk])&&is_scalar($value[$pk])&&trim((string)$value[$pk])!==''){$localProvider=trim((string)$value[$pk]);break;}}
                foreach($value as $k=>$v)$walk($v,is_string($k)?$k:'',$container,$localProvider);return;
            }
            if(!is_scalar($value))return;$text=trim((string)$value);if($text==='')return;
            if($tokenKey){$add($text,$provider);return;}
            if($container&&preg_match('/(?:url|link)$/i',$lk)){
                $parts=@parse_url($text);if(is_array($parts)){if(!empty($parts['query'])){parse_str((string)$parts['query'],$q);foreach($q as $v)if(is_scalar($v))$add((string)$v,$provider);}if(!empty($parts['path'])){$seg=array_values(array_filter(explode('/',trim((string)$parts['path'],'/'))));if($seg)$add((string)end($seg),$provider);}}
            }
        };
        $walk($order);return array_values($found);
    }

    private function normalizeCourierCode(string $provider): string {
        $p=strtolower(trim($provider));
        if(str_contains($p,'sameday')||str_contains($p,'same day'))return 'sameday';
        if(str_contains($p,'fan'))return 'fan';
        if(str_contains($p,'dragon'))return 'dragonstar';
        return $p!==''?$p:'woocommerce';
    }

    private function fanAuthToken(): string {
        if($this->fanToken!==null&&$this->fanToken!=='')return $this->fanToken;
        $username=trim((string)$this->settings->get('module.couriers.fan_username',''));
        $password=(string)$this->settings->get('module.couriers.fan_password','');
        if($username===''||$password==='')throw new RuntimeException('Completeaza credentialele FAN Courier in Setari > Module > Curieri.');
        $base=rtrim((string)$this->settings->get('module.couriers.fan_base_url','https://api.fancourier.ro'),'/');
        $auth=Http::request('POST',$base.'/login?'.http_build_query(['username'=>$username,'password'=>$password]),['Accept: application/json'],null,30);
        if($auth['status']<200||$auth['status']>=300)throw new RuntimeException('FAN autentificare HTTP '.$auth['status'].': '.substr((string)$auth['body'],0,500));
        $token=(string)($auth['json']['data']['token']??'');if($token==='')throw new RuntimeException('FAN Courier nu a returnat token de autentificare.');
        return $this->fanToken=$token;
    }

    private function samedayAuthToken(): string {
        if($this->samedayToken!==null&&$this->samedayToken!=='')return $this->samedayToken;
        $username=trim((string)$this->settings->get('module.couriers.sameday_username',''));
        $password=(string)$this->settings->get('module.couriers.sameday_password','');
        if($username===''||$password==='')throw new RuntimeException('Completeaza credentialele Sameday in Setari > Module > Curieri.');
        $base=rtrim((string)$this->settings->get('module.couriers.sameday_base_url','https://api.sameday.ro'),'/');
        $auth=Http::request('POST',$base.'/api/authenticate',['Accept: application/json','X-AUTH-USERNAME: '.$username,'X-AUTH-PASSWORD: '.$password,'Content-Type: application/x-www-form-urlencoded'],['remember_me'=>'true'],30);
        if($auth['status']<200||$auth['status']>=300)throw new RuntimeException('Sameday autentificare HTTP '.$auth['status'].': '.substr((string)$auth['body'],0,600));
        $token=$this->findToken($auth['json']??[]);if($token==='')throw new RuntimeException('Sameday nu a returnat token de autentificare.');
        return $this->samedayToken=$token;
    }

    private function samedayAwbPdf(string $awb,string $format): string {
        $base=rtrim((string)$this->settings->get('module.couriers.sameday_base_url','https://api.sameday.ro'),'/');
        $token=$this->samedayAuthToken();
        $r=Http::request('GET',$base.'/api/awb/download/'.rawurlencode($awb).'/'.$format,['Accept: application/pdf','X-AUTH-TOKEN: '.$token],null,45);
        if($r['status']<200||$r['status']>=300)throw new RuntimeException('Sameday PDF AWB HTTP '.$r['status'].': '.substr((string)$r['body'],0,500));
        $body=(string)$r['body'];if(!str_starts_with($body,'%PDF'))throw new RuntimeException('Sameday nu a returnat un fisier PDF valid pentru AWB.');
        return $body;
    }

    private function fanAwbPdf(string $awb,string $format): string {
        $base=rtrim((string)$this->settings->get('module.couriers.fan_base_url','https://api.fancourier.ro'),'/');
        $clientId=trim((string)$this->settings->get('module.couriers.fan_client_id',''));if($clientId==='')throw new RuntimeException('Lipseste Client ID FAN Courier.');
        $token=$this->fanAuthToken();
        $query=http_build_query(['clientId'=>$clientId,'awbs'=>[$awb],'pdf'=>1,'format'=>$format,'language'=>'ro']);
        $r=Http::request('GET',$base.'/awb/label?'.$query,['Accept: application/pdf','Authorization: Bearer '.$token],null,45);
        if($r['status']<200||$r['status']>=300)throw new RuntimeException('FAN PDF AWB HTTP '.$r['status'].': '.substr((string)$r['body'],0,500));
        $body=(string)$r['body'];if(!str_starts_with($body,'%PDF'))throw new RuntimeException('FAN Courier nu a returnat un fisier PDF valid pentru AWB. Formatul solicitat poate sa nu fie disponibil pentru serviciul AWB curent.');
        return $body;
    }

    private function context(array $order,array $options): array {
        $shipping=json_decode((string)($order['shipping_json']??'{}'),true)?:[];
        $billing=json_decode((string)($order['billing_json']??'{}'),true)?:[];
        $raw=json_decode((string)($order['raw_json']??'{}'),true)?:[];
        $addr=$this->address(array_filter($shipping,fn($v)=>$v!==''&&$v!==null)?$shipping:$billing,$billing);
        if($addr['city']===''||$addr['address']==='') throw new RuntimeException('Comanda nu are adresa de livrare completa.');
        if(trim((string)$order['customer_phone'])==='') throw new RuntimeException('Comanda nu are numar de telefon.');
        $weightInput=$options['weight']??null;
        $weight=($weightInput===null||trim((string)$weightInput)==='')
            ? (float)$this->settings->get('module.couriers.default_weight','1')
            : (float)$weightInput;
        $weight=max(0.1,$weight);
        // Rambursul nu se introduce manual: numerar/ramburs => total comanda; card/online/OP => 0.
        $cod=$this->isCod($raw)?(float)$order['total']:0.0;
        return ['order'=>$order,'raw'=>$raw,'address'=>$addr,'weight'=>$weight,'cod'=>$cod,'parcels'=>max(1,(int)($options['parcels']??1))];
    }

    private function fan(array $c): array {
        $username=trim((string)$this->settings->get('module.couriers.fan_username',''));
        $password=(string)$this->settings->get('module.couriers.fan_password','');
        $clientId=trim((string)$this->settings->get('module.couriers.fan_client_id',''));
        if($username===''||$password===''||$clientId==='') throw new RuntimeException('Completeaza credentialele FAN Courier in Setari > Module > Curieri.');
        $base=rtrim((string)$this->settings->get('module.couriers.fan_base_url','https://api.fancourier.ro'),'/');
        $token=$this->fanAuthToken();

        $o=$c['order'];$a=$c['address'];
        $serviceSetting=trim((string)$this->settings->get('module.couriers.fan_service','Auto'));
        $service=(strcasecmp($serviceSetting,'Auto')===0||$serviceSetting==='')?($c['cod']>0?'Cont Colector':'Standard'):$serviceSetting;
        $info=[
            'service'=>$service,
            'bank'=>(string)$this->settings->get('module.couriers.fan_bank',''),
            'bankAccount'=>(string)$this->settings->get('module.couriers.fan_iban',''),
            'packages'=>['parcel'=>$c['parcels'],'envelope'=>0],
            'weight'=>$c['weight'],
            'cod'=>$c['cod'],
            'declaredValue'=>(float)($c['cod']>0?$o['total']:0),
            'payment'=>(string)$this->settings->get('module.couriers.fan_payment','sender'),
            'refund'=>null,
            'returnPayment'=>null,
            'observation'=>'ALFAMED CENTRAL '.$o['code'],
            'content'=>'Comanda '.$o['code'],
            'dimensions'=>[
                'length'=>max(1,(float)$this->settings->get('module.couriers.default_length','20')),
                'height'=>max(1,(float)$this->settings->get('module.couriers.default_height','10')),
                'width'=>max(1,(float)$this->settings->get('module.couriers.default_width','15')),
            ],
            'costCenter'=>(string)$this->settings->get('module.couriers.fan_cost_center',''),
            'options'=>array_values(array_filter(array_map('trim',explode(',',(string)$this->settings->get('module.couriers.fan_options',''))))),
        ];
        $recipient=[
            'name'=>(string)$o['customer_name'],
            'contactPerson'=>(string)$o['customer_name'],
            'phone'=>(string)$o['customer_phone'],
            'email'=>(string)$o['customer_email'],
            'address'=>[
                'county'=>$a['county'],'locality'=>$a['city'],'street'=>$a['address'],'streetNo'=>'',
                'zipCode'=>$a['postcode'],'building'=>'','entrance'=>'','floor'=>'','apartment'=>'',
            ],
        ];
        $payload=['clientId'=>(int)$clientId,'shipments'=>[['info'=>$info,'recipient'=>$recipient]]];
        $r=Http::request('POST',$base.'/intern-awb',['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),45);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException('FAN AWB HTTP '.$r['status'].': '.substr((string)$r['body'],0,700));
        $row=$r['json']['response'][0]??null;
        if(!is_array($row)) throw new RuntimeException('Raspuns FAN Courier neasteptat: '.substr((string)$r['body'],0,600));
        if(!empty($row['errors'])) throw new RuntimeException('FAN Courier: '.(is_array($row['errors'])?implode('; ',$row['errors']):(string)$row['errors']));
        return ['awb'=>(string)($row['awbNumber']??''),'tracking_url'=>'','raw'=>$r['json']];
    }

    private function sameday(array $c): array {
        $username=trim((string)$this->settings->get('module.couriers.sameday_username',''));
        $password=(string)$this->settings->get('module.couriers.sameday_password','');
        if($username===''||$password==='') throw new RuntimeException('Completeaza credentialele Sameday in Setari > Module > Curieri.');
        $base=rtrim((string)$this->settings->get('module.couriers.sameday_base_url','https://api.sameday.ro'),'/');
        $token=$this->samedayAuthToken();

        $pickup=(int)$this->settings->get('module.couriers.sameday_pickup_point','0');
        $service=(int)$this->settings->get('module.couriers.sameday_service_id','0');
        $contact=(int)$this->settings->get('module.couriers.sameday_contact_person','0');
        if($pickup<=0||$service<=0) throw new RuntimeException('Completeaza Pickup Point ID si Service ID pentru Sameday.');
        $o=$c['order'];$a=$c['address'];
        $body=[
            'pickupPoint'=>$pickup,
            'contactPerson'=>$contact>0?$contact:null,
            'packageType'=>0,
            'packageNumber'=>$c['parcels'],
            'packageWeight'=>$c['weight'],
            'service'=>$service,
            'awbPayment'=>1,
            'cashOnDelivery'=>$c['cod'],
            'insuredValue'=>0,
            'thirdPartyPickup'=>0,
            'awbRecipient'=>[
                'countyString'=>$a['county'],'cityString'=>$a['city'],'address'=>$a['address'],
                'name'=>(string)$o['customer_name'],'phoneNumber'=>(string)$o['customer_phone'],
                'email'=>(string)$o['customer_email'],'postalCode'=>$a['postcode'],'personType'=>0,
            ],
            'parcels'=>array_fill(0,$c['parcels'],[
                'weight'=>max(0.1,$c['weight']/$c['parcels']),
                'width'=>max(0,(float)$this->settings->get('module.couriers.default_width','15')),
                'length'=>max(0,(float)$this->settings->get('module.couriers.default_length','20')),
                'height'=>max(0,(float)$this->settings->get('module.couriers.default_height','10')),
            ]),
            'clientInternalReference'=>(string)$o['code'],
            'observation'=>'ALFAMED CENTRAL '.$o['code'],
        ];
        $r=Http::request('POST',$base.'/api/awb',['Accept: application/json','X-AUTH-TOKEN: '.$token,'Content-Type: application/x-www-form-urlencoded'],$body,45);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException('Sameday AWB HTTP '.$r['status'].': '.substr((string)$r['body'],0,800));
        $awb=$this->findAwb($r['json']??[]);
        if($awb==='') throw new RuntimeException('Sameday nu a returnat numarul AWB: '.substr((string)$r['body'],0,600));
        return ['awb'=>$awb,'tracking_url'=>'','raw'=>$r['json']];
    }

    private function dragonStar(array $c): array {
        $base=rtrim(trim((string)$this->settings->get('module.couriers.dragonstar_base_url','')),'/');
        $path=trim((string)$this->settings->get('module.couriers.dragonstar_awb_path',''));
        $template=trim((string)$this->settings->get('module.couriers.dragonstar_payload_template',''));
        if($base===''||$path===''||$template==='') throw new RuntimeException('Dragon Star necesita endpoint-ul si sablonul JSON furnizate impreuna cu contul API. Completeaza Setari > Module > Curieri > Dragon Star.');
        $o=$c['order'];$a=$c['address'];
        $vars=[
            '{{order_id}}'=>(string)$o['external_id'],'{{order_code}}'=>(string)$o['code'],'{{name}}'=>(string)$o['customer_name'],
            '{{phone}}'=>(string)$o['customer_phone'],'{{email}}'=>(string)$o['customer_email'],'{{county}}'=>$a['county'],
            '{{city}}'=>$a['city'],'{{address}}'=>$a['address'],'{{postcode}}'=>$a['postcode'],'{{weight}}'=>(string)$c['weight'],
            '{{cod}}'=>(string)$c['cod'],'{{total}}'=>(string)$o['total'],'{{currency}}'=>(string)$o['currency'],
        ];
        $encoded=[];foreach($vars as $key=>$value)$encoded[$key]=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $json=strtr($template,$encoded);
        $payload=json_decode($json,true);
        if(!is_array($payload)) throw new RuntimeException('Sablonul JSON Dragon Star este invalid dupa inlocuirea variabilelor. Foloseste variabile fara ghilimele, ex. "name": {{name}}.');
        $headers=['Accept: application/json','Content-Type: application/json'];
        $headerName=trim((string)$this->settings->get('module.couriers.dragonstar_auth_header','Authorization'));
        $token=(string)$this->settings->get('module.couriers.dragonstar_api_token','');
        $prefix=(string)$this->settings->get('module.couriers.dragonstar_auth_prefix','Bearer ');
        if($token!==''&&$headerName!=='')$headers[]=$headerName.': '.$prefix.$token;
        $user=(string)$this->settings->get('module.couriers.dragonstar_username','');
        $pass=(string)$this->settings->get('module.couriers.dragonstar_password','');
        if($token===''&&$user!=='')$headers[]='Authorization: Basic '.base64_encode($user.':'.$pass);
        $r=Http::request('POST',$base.'/'.ltrim($path,'/'),$headers,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),45);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException('Dragon Star AWB HTTP '.$r['status'].': '.substr((string)$r['body'],0,800));
        $responsePath=trim((string)$this->settings->get('module.couriers.dragonstar_awb_response_path','awb'));
        $awb=(string)$this->pathValue($r['json']??[],$responsePath);
        if($awb==='') throw new RuntimeException('Nu am putut extrage AWB-ul Dragon Star. Verifica campul „Cale AWB in raspuns”.');
        return ['awb'=>$awb,'tracking_url'=>'','raw'=>$r['json']];
    }

    private function address(array $a,array $billing=[]): array {
        $get=fn($k)=>(string)($a[$k]??$billing[$k]??'');
        $state=$get('state');
        return ['address'=>trim($get('address_1').' '.$get('address_2')),'city'=>$get('city'),'county'=>$this->countyName($state),'postcode'=>$get('postcode')];
    }
    private function countyName(string $v): string {
        $v=trim($v);$m=['AB'=>'Alba','AR'=>'Arad','AG'=>'Arges','BC'=>'Bacau','BH'=>'Bihor','BN'=>'Bistrita-Nasaud','BT'=>'Botosani','BV'=>'Brasov','BR'=>'Braila','B'=>'Bucuresti','BZ'=>'Buzau','CS'=>'Caras-Severin','CL'=>'Calarasi','CJ'=>'Cluj','CT'=>'Constanta','CV'=>'Covasna','DB'=>'Dambovita','DJ'=>'Dolj','GL'=>'Galati','GR'=>'Giurgiu','GJ'=>'Gorj','HR'=>'Harghita','HD'=>'Hunedoara','IL'=>'Ialomita','IS'=>'Iasi','IF'=>'Ilfov','MM'=>'Maramures','MH'=>'Mehedinti','MS'=>'Mures','NT'=>'Neamt','OT'=>'Olt','PH'=>'Prahova','SM'=>'Satu Mare','SJ'=>'Salaj','SB'=>'Sibiu','SV'=>'Suceava','TR'=>'Teleorman','TM'=>'Timis','TL'=>'Tulcea','VS'=>'Vaslui','VL'=>'Valcea','VN'=>'Vrancea'];
        return $m[strtoupper($v)]??$v;
    }
    private function syncWooOrderTracking(array $order,string $courier,string $awb,string $tracking,array $ctx): ?array {
        if((string)($order['channel_type']??'')!=='woocommerce')return null;
        $client=ChannelFactory::client((string)$order['channel_code']);
        if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea WooCommerce a canalului este dezactivata.');
        $data=$this->wooTrackingPayload($courier,$awb,$tracking,$ctx);
        $client->updateOrder($order['external_id'],['meta_data'=>$data['meta_data']]);
        $noteResult=$client->createOrderNote($order['external_id'],$data['note'],true);
        return ['synced'=>true,'note_id'=>$noteResult['id']??null,'tracking_url'=>$tracking,'meta_keys'=>array_column($data['meta_data'],'key')];
    }

    /** @return array{meta_data:array<int,array{key:string,value:string}>,note:string} */
    private function wooTrackingPayload(string $courier,string $awb,string $tracking,array $ctx): array {
        $label=$this->courierLabel($courier);
        $meta=[
            ['key'=>'alfamed_awb','value'=>$awb],
            ['key'=>'alfamed_courier','value'=>$label],
            ['key'=>'alfamed_tracking_url','value'=>$tracking],
            ['key'=>'alfamed_parcels','value'=>(string)($ctx['parcels']??1)],
            ['key'=>'alfamed_weight_kg','value'=>(string)($ctx['weight']??'')],
        ];
        $note='Expedierea a fost pregatita. Curier: '.$label.'. AWB: '.$awb.'.';
        if($tracking!=='')$note.=' Urmarire colet: '.$tracking;
        return ['meta_data'=>$meta,'note'=>$note];
    }

    private function trackingUrl(string $courier,string $awb): string {
        $defaults=[
            'sameday'=>'https://sameday.ro/status-colet/',
            'fan'=>'https://www.fancourier.ro/awb-tracking/',
            'dragonstar'=>'https://dragonstarcurier.ro/tracking-awb',
        ];
        $template=trim((string)$this->settings->get('module.couriers.'.$courier.'_tracking_url',$defaults[$courier]??''));
        if($template==='')return '';
        return str_replace(['{{awb}}','{awb}'],rawurlencode($awb),$template);
    }

    private function courierLabel(string $courier): string {
        return match($courier){'sameday'=>'Sameday','fan'=>'FAN Courier','dragonstar'=>'Dragon Star',default=>$courier};
    }

    private function isCod(array $raw): bool {
        $method=strtolower(trim((string)($raw['payment_method']??'')));
        if(in_array($method,['cod','cash_on_delivery','cash-on-delivery'],true))return true;
        $vals=[];foreach(['payment_method','payment_method_title','payment_type','payment_mode','payment','detailed_payment_method'] as $k){$v=$raw[$k]??null;if(is_scalar($v)&&!is_numeric($v))$vals[]=(string)$v;}
        $text=strtolower(implode(' ',$vals));
        // Respecta mai intai descrierea explicita a platii din canal; un ordin deja platit nu primeste ramburs.
        foreach(['card online','plata online','online card','credit card','debit card','transfer bancar','bank transfer','ordin de plata','op '] as $needle)if($text!==''&&str_contains($text,$needle))return false;
        foreach(['cash on delivery','cash-on-delivery','ramburs','numerar','plata la livrare','plata ramburs'] as $needle)if($text!==''&&str_contains($text,$needle))return true;
        // Fallback numeric pentru payload-urile eMAG mai vechi.
        if(array_key_exists('payment_mode_id',$raw) && is_scalar($raw['payment_mode_id']))return (int)$raw['payment_mode_id']===1;
        if(array_key_exists('payment_mode',$raw) && is_numeric($raw['payment_mode']))return (int)$raw['payment_mode']===1;
        return false;
    }
    private function findToken(array $r): string {foreach(['token','access_token','accessToken'] as $k)if(!empty($r[$k]))return (string)$r[$k];foreach(['data','result'] as $k)if(isset($r[$k])&&is_array($r[$k])){$x=$this->findToken($r[$k]);if($x!=='')return $x;}return '';}
    private function findAwb(array $r): string {foreach(['awbNumber','awb_number','awb','number'] as $k)if(isset($r[$k])&&is_scalar($r[$k])&&(string)$r[$k]!=='')return (string)$r[$k];foreach(['data','result','response'] as $k)if(isset($r[$k])){if(is_array($r[$k])){$node=$r[$k];if(array_is_list($node)&&isset($node[0])&&is_array($node[0]))$node=$node[0];$x=$this->findAwb($node);if($x!=='')return $x;}}return '';}
    private function pathValue(array $data,string $path): mixed {$v=$data;foreach(array_filter(explode('.',$path),'strlen') as $p){if(!is_array($v)||!array_key_exists($p,$v))return null;$v=$v[$p];}return $v;}
    private function cleanIds(array $ids): array {return array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));}
}

<?php
namespace AlfamedModules\Couriers;

use App\Core\Database;
use App\Integrations\EmagClient;
use App\Services\ChannelFactory;
use App\Services\OrderOperations;
use App\Services\SettingService;
use PDO;
use RuntimeException;
use Throwable;

final class EmagFulfillmentService {
    private PDO $db;
    private SettingService $settings;
    public function __construct(?PDO $db=null){$this->db=$db?:Database::connection();$this->settings=new SettingService($this->db);}

    public function courierAccounts(array $order): array {
        $client=$this->client($order);
        $res=$client->readCourierAccounts();
        $out=[];
        foreach((array)($res['results']??[]) as $row){
            if(!is_array($row)||(int)($row['status']??0)!==1)continue;
            $type=(int)($row['courier_account_type']??0);if(!in_array($type,[2,3],true))continue;
            $id=(int)($row['account_id']??$row['id']??0);if($id<=0)continue;
            $label=trim((string)($row['account_display_name']??$row['courier_name']??('Cont '.$id)));
            $props=array_values(array_unique(array_map('intval',(array)($row['courier_account_properties']??[]))));
            $out[$id]=['id'=>$id,'label'=>$label!==''?$label:('Cont '.$id),'courier'=>(string)($row['courier_name']??''),'properties'=>$props];
        }

        // eMAG Marketplace API 4.5.1: courier_account_properties
        // 0 = Regular / Home Delivery, 2 = Lockers, 6 = Offices, 8 = Crossborder, 9 = BPO.
        // Buildurile vechi ALFAMED CENTRAL au presupus 1/4; le acceptam doar ca fallback
        // de compatibilitate, dar prioritizam valorile oficiale 0/2.
        $mode=$this->deliveryMode($order);
        $isLocker=static fn(array $a):bool=>in_array(2,(array)$a['properties'],true)||in_array(4,(array)$a['properties'],true);
        $isRegular=static fn(array $a):bool=>in_array(0,(array)$a['properties'],true)||in_array(1,(array)$a['properties'],true);
        if($mode==='pickup'){
            $locker=array_filter($out,$isLocker);
            if($locker)$out=$locker;
        } elseif($mode==='courier'){
            $regularOnly=array_filter($out,static fn(array $a):bool=>$isRegular($a)&&!$isLocker($a));
            if($regularOnly)$out=$regularOnly;
            else {
                $regular=array_filter($out,$isRegular);
                if($regular)$out=$regular;
                else {
                    $nonLocker=array_filter($out,static fn(array $a):bool=>!$isLocker($a));
                    if($nonLocker)$out=$nonLocker;
                }
            }
        }
        return $this->prioritizeCourierAccountsForOrder($order,$out);
    }

    /**
     * Pune primul contul care corespunde explicit optiunii din comanda eMAG.
     * Pentru home delivery fara curier explicit nu inventam un castigator: UI-ul
     * foloseste optiunea "Automat eMAG Marketplace", iar AWB/save lasa eMAG sa
     * aplice contul implicit/preferat al sellerului.
     *
     * @param array<int,array{id:int,label:string,courier:string,properties:array<int,int>}> $accounts
     * @return array<int,array{id:int,label:string,courier:string,properties:array<int,int>}>
     */
    public function prioritizeCourierAccountsForOrder(array $order,array $accounts): array {
        if(!$accounts)return $accounts;
        $preferred=$this->preferredCourierAccountId($order,$accounts);
        if($preferred===null||!isset($accounts[$preferred]))return $accounts;
        $sorted=[$preferred=>$accounts[$preferred]];
        foreach($accounts as $id=>$account)if((int)$id!==$preferred)$sorted[(int)$id]=$account;
        return $sorted;
    }

    /** Returneaza contul exact/compatibil numai cand comanda contine un indiciu suficient. */
    public function preferredCourierAccountId(array $order,array $accounts): ?int {
        if(!$accounts)return null;
        $pref=$this->deliveryPreference($order);
        $explicit=(int)($pref['account_id']??0);
        if($explicit>0&&isset($accounts[$explicit]))return $explicit;

        $network=(string)($pref['network']??'');
        $hint=(string)($pref['courier_hint']??'');
        if($network===''&&$hint==='')return null;

        $bestId=null;$bestScore=0;
        foreach($accounts as $id=>$account){
            $text=$this->searchText((string)($account['label']??'').' '.(string)($account['courier']??''));
            $score=0;
            if($network!=='')$score+=$this->courierAccountScore($network,$text);
            if($hint!=='')$score+=$this->courierHintScore($hint,$text);
            if($score>$bestScore){$bestScore=$score;$bestId=(int)$id;}
        }
        return $bestScore>0?$bestId:null;
    }

    /** Metoda de livrare normalizata din comanda eMAG: pickup / courier / necunoscut. */
    public function deliveryMode(array $order): string {
        $raw=(array)($order['raw']??[]);$details=is_array($raw['details']??null)?$raw['details']:[];
        $mode=$this->searchText((string)($raw['delivery_mode']??$details['delivery_mode']??''));
        if($mode==='pickup'||str_contains($mode,'locker'))return 'pickup';
        if($mode==='courier'||str_contains($mode,'home')||str_contains($mode,'domicili'))return 'courier';
        return $mode;
    }

    /** Eticheta optiunii de livrare aleasa de client, folosita in formularul AWB. */
    public function deliveryChoiceLabel(array $order): string {
        $pref=$this->deliveryPreference($order);
        if(($pref['network']??'')==='easybox')return 'easybox';
        if(($pref['network']??'')==='fanbox')return 'FANbox';
        if(($pref['mode']??'')==='pickup')return 'locker';
        if(($pref['mode']??'')==='courier')return 'livrare la domiciliu';
        return 'livrare eMAG';
    }

    /** Returneaza easybox/fanbox cand reteaua poate fi dedusa sigur din comanda. */
    public function lockerNetworkPreference(array $order): string {
        return (string)($this->deliveryPreference($order)['network']??'');
    }

    /**
     * Extrage toate indiciile logistice disponibile in order/read. Versiunile API
     * standard expun delivery_mode + locker_id/name; unele payload-uri pot include
     * suplimentar curierul sau account id, pe care le folosim daca sunt prezente.
     *
     * @return array{mode:string,network:string,account_id:int,courier_hint:string}
     */
    public function deliveryPreference(array $order): array {
        $shipping=(array)($order['shipping']??[]);$raw=(array)($order['raw']??[]);
        $details=is_array($raw['details']??null)?$raw['details']:[];
        $mode=$this->deliveryMode($order);
        $networkParts=[
            $shipping['locker_name']??'', $shipping['address_1']??'', $shipping['address_2']??'',
            $raw['locker_name']??'', $details['locker_name']??'',
        ];
        $networkText=$this->searchText(implode(' ',array_map(static fn($v)=>(string)$v,$networkParts)));
        $network='';
        if(str_contains($networkText,'fanbox')||str_contains($networkText,'fan box'))$network='fanbox';
        elseif(str_contains($networkText,'easybox')||str_contains($networkText,'easy box'))$network='easybox';

        $accountId=0;
        foreach(['courier_account_id','shipping_courier_account_id','awb_courier_account_id'] as $key){
            $candidate=(int)($raw[$key]??$details[$key]??0);if($candidate>0){$accountId=$candidate;break;}
        }

        $hintParts=[];
        foreach(['courier','courier_name','shipping_method','shipping_method_name','delivery_method','delivery_method_name','delivery_service','delivery_service_name','selected_courier'] as $key){
            if(isset($raw[$key]))$hintParts[]=(string)$raw[$key];
            if(isset($details[$key]))$hintParts[]=(string)$details[$key];
        }
        $hint=$this->searchText(implode(' ',$hintParts));
        // Termenii generici nu reprezinta un curier concret si nu trebuie sa faca
        // platforma sa aleaga arbitrar primul cont.
        if(in_array($hint,['courier','pickup','locker','home delivery','livrare la domiciliu'],true))$hint='';
        return ['mode'=>$mode,'network'=>$network,'account_id'=>$accountId,'courier_hint'=>$hint];
    }

    private function courierAccountScore(string $preference,string $text): int {
        if($preference==='easybox'){
            if(str_contains($text,'fanbox')||str_contains($text,'fan box'))return -300;
            if(str_contains($text,'easybox')||str_contains($text,'easy box'))return 300;
            if(str_contains($text,'sameday'))return 220;
            if(str_contains($text,'fan courier')||preg_match('/(^|[^a-z])fan([^a-z]|$)/',$text))return -180;
        }
        if($preference==='fanbox'){
            if(str_contains($text,'easybox')||str_contains($text,'easy box')||str_contains($text,'sameday'))return -300;
            if(str_contains($text,'fanbox')||str_contains($text,'fan box'))return 300;
            if(str_contains($text,'fan courier')||preg_match('/(^|[^a-z])fan([^a-z]|$)/',$text))return 220;
        }
        return 0;
    }

    private function courierHintScore(string $hint,string $text): int {
        $brands=[
            'sameday'=>['sameday','same day'],
            'fan'=>['fan courier','fancourier','fan courier express'],
            'cargus'=>['cargus','urgent cargus','urgentcargus'],
            'dpd'=>['dpd'],
            'gls'=>['gls'],
        ];
        foreach($brands as $tokens){
            $hintHas=false;$accountHas=false;
            foreach($tokens as $token){if(str_contains($hint,$token))$hintHas=true;if(str_contains($text,$token))$accountHas=true;}
            if($hintHas)return $accountHas?500:-120;
        }
        // Fallback pentru denumiri neprevazute: potrivire pe cuvinte semnificative.
        $tokens=preg_split('/[^a-z0-9]+/',$hint,-1,PREG_SPLIT_NO_EMPTY)?:[];$score=0;
        foreach($tokens as $token)if(strlen($token)>=4&&str_contains($text,$token))$score+=40;
        return $score;
    }

    private function searchText(string $value): string {
        $value=strtolower(trim($value));
        if(function_exists('iconv')){$ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);if(is_string($ascii)&&$ascii!=='')$value=strtolower($ascii);}
        $value=preg_replace('/\s+/',' ',$value)??$value;
        return $value;
    }

    public function create(int $orderId,array $input): array {
        $order=(new OrderOperations($this->db))->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='emag')throw new RuntimeException('Fluxul AWB eMAG poate fi folosit doar pentru comenzi eMAG.');
        $raw=(array)($order['raw']??[]);
        if((int)($raw['type']??3)===2)throw new RuntimeException('Comanda este Fulfilled by eMAG. AWB-ul este administrat de eMAG, nu de seller.');
        if((string)$order['status']==='new')throw new RuntimeException('Preia mai intai comanda eMAG. AWB-ul se poate genera dupa acknowledge.');
        if(in_array((string)$order['status'],['cancelled','returned','refunded'],true))throw new RuntimeException('Nu se poate genera AWB pentru o comanda anulata/returnata/stornata.');

        $deliveryMode=$this->deliveryMode($order);
        $existing=$this->activeLocals($orderId);
        $additional=!empty($input['additional_awb']);
        if($existing&&!$additional)throw new RuntimeException('Comanda are deja AWB eMAG salvat local. Pentru easybox/FANbox poti crea explicit un AWB suplimentar doar daca expedierea nu incape intr-un singur locker.');
        if($additional&&!$existing)throw new RuntimeException('AWB suplimentar poate fi creat doar dupa ce exista deja primul AWB al comenzii.');
        if($additional&&$deliveryMode!=='pickup')throw new RuntimeException('Pentru livrarea la domiciliu nu se creeaza AWB suplimentar: foloseste campul Numar colete in acelasi AWB.');
        if((string)($order['status']??'')==='completed'&&!($additional&&$deliveryMode==='pickup'&&$existing))throw new RuntimeException('Comanda eMAG este deja finalizata si nu are un flux sigur pentru un AWB nou.');

        $client=$this->client($order);
        $shipping=(array)($order['shipping']??[]);$billing=(array)($order['billing']??[]);
        $address=array_filter($shipping,static fn($v)=>$v!==''&&$v!==null)?$shipping:$billing;
        $receiverName=trim((string)($shipping['name']??$order['customer_name']??''));if($receiverName==='')$receiverName=(string)$order['customer_name'];
        $phone=$this->phone((string)($shipping['phone']??$order['customer_phone']??''));
        $localityId=(int)($address['locality_id']??0);$street=trim((string)($address['address_1']??''));
        if($receiverName===''||$phone===''||$localityId<=0||$street==='')throw new RuntimeException('Date eMAG insuficiente pentru AWB: sunt necesare destinatar, telefon, locality_id si strada. Sincronizeaza din nou comanda.');
        $sender=$this->sender((string)$order['channel_code']);

        $account=(int)($input['courier_account_id']??0);$accounts=[];
        if($deliveryMode==='pickup'){
            $accounts=$this->courierAccounts($order);
            if($account<=0){$preferred=$this->preferredCourierAccountId($order,$accounts);if($preferred!==null)$account=$preferred;}
            if($account<=0||!isset($accounts[$account]))throw new RuntimeException('Nu am putut identifica automat contul de curier pentru lockerul ales. Verifica metoda de livrare a comenzii si configurarea conturilor eMAG.');
        } elseif($account>0){
            $accounts=$this->courierAccounts($order);
            if(!isset($accounts[$account]))throw new RuntimeException('Contul de curier eMAG selectat nu este compatibil cu metoda de livrare a comenzii.');
        }
        $useMarketplaceDefault=$deliveryMode==='courier'&&$account===0;

        // Locker/easybox/FANbox: un AWB reprezinta un colet. Daca expedierea este prea
        // voluminoasa, utilizatorul poate cere explicit un AWB suplimentar din comanda.
        // Home delivery: un singur AWB poate contine numarul necesar de colete.
        if($deliveryMode==='pickup'){$parcels=1;$envelopes=0;}
        else {
            $parcels=max(0,min(999,(int)($input['parcels']??1)));
            $envelopes=max(0,min(9999,(int)($input['envelopes']??0)));
            if($parcels===0&&$envelopes===0)throw new RuntimeException('Trebuie sa existe cel putin un colet sau un plic.');
        }
        $weight=max(0.01,(float)($input['weight']??$this->settings->get('module.couriers.default_weight','1')));
        $cod=$this->isCod($raw)?(float)$order['total']:0.0;
        $payload=[
            'order_id'=>(int)$order['external_id'],'sender'=>$sender,
            'receiver'=>[
                'name'=>$receiverName,'contact'=>$receiverName,'phone1'=>$phone,
                'legal_entity'=>!empty($billing['_fiscal']['person_type'])&&$billing['_fiscal']['person_type']==='Persoana juridica'?1:0,
                'locality_id'=>$localityId,'street'=>$street,'zipcode'=>(string)($address['postcode']??''),
            ],
            'is_oversize'=>isset($input['is_oversize'])?1:0,'insured_value'=>max(0,(float)($input['insured_value']??0)),
            'weight'=>$weight,'envelope_number'=>$envelopes,'parcel_number'=>$parcels,
            'observation'=>$this->clip(trim((string)($input['observation']??('ALFAMED CENTRAL '.$order['code']))),255),'cod'=>$cod,
        ];
        if(!$useMarketplaceDefault)$payload['courier_account_id']=$account;
        $lockerId=trim((string)($shipping['locker_id']??$raw['details']['locker_id']??''));
        if($deliveryMode==='pickup'){
            if($lockerId==='')throw new RuntimeException('Comanda este de tip locker, dar locker_id lipseste. Sincronizeaza din nou comanda inainte de generarea AWB.');
            $payload['locker_id']=$lockerId;
        }

        try{$res=$client->createAwb($payload);}
        catch(Throwable $e){throw new RuntimeException('eMAG nu a confirmat generarea AWB. Verifica direct comanda in Marketplace inainte de a reincerca, pentru a evita o dublura. Detaliu: '.$e->getMessage());}
        $fallbackCourier=$useMarketplaceDefault?'Curier implicit eMAG Marketplace':((string)($accounts[$account]['courier']??'')!==''?(string)$accounts[$account]['courier']:(string)($accounts[$account]['label']??'eMAG Marketplace'));
        $parsed=$this->parseAwbResponse($res,(string)$order['external_id'],$fallbackCourier);
        if($parsed['awb']==='')throw new RuntimeException('eMAG a acceptat cererea, dar numarul AWB nu a putut fi identificat in raspuns. Verifica Marketplace inainte sa reincerci.');
        $this->storeRemote($orderId,$parsed,$res);
        if(!$existing){$raw['status']=4;$this->db->prepare("UPDATE orders SET status='completed',raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$orderId]);}
        return ['recovered'=>false,'additional'=>$additional,'delivery_mode'=>$deliveryMode,'parcels'=>$parcels]+$parsed;
    }

    public function createAutomatic(int $orderId,array $options=[]): array {
        $order=(new OrderOperations($this->db))->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='emag')throw new RuntimeException('Comanda nu este eMAG.');
        if($this->activeLocals($orderId))throw new RuntimeException('Comanda are deja AWB activ. Generarea bulk nu creeaza automat expeditii suplimentare.');
        $mode=$this->deliveryMode($order);$account=0;
        if($mode==='pickup'){
            $accounts=$this->courierAccounts($order);$preferred=$this->preferredCourierAccountId($order,$accounts);
            if($preferred===null)throw new RuntimeException('Nu am putut identifica automat contul eMAG pentru lockerul ales de client. Deschide comanda si selecteaza manual curierul.');
            $account=$preferred;
        } elseif($mode!=='courier'){
            $accounts=$this->courierAccounts($order);$preferred=$this->preferredCourierAccountId($order,$accounts);$account=$preferred??0;
        }
        return $this->create($orderId,[
            'courier_account_id'=>$account,
            'parcels'=>$mode==='pickup'?1:max(1,(int)($options['parcels']??1)),
            'envelopes'=>$mode==='pickup'?0:max(0,(int)($options['envelopes']??0)),
            'weight'=>$options['weight']??$this->settings->get('module.couriers.default_weight','1'),
            'insured_value'=>$options['insured_value']??0,
            'observation'=>$options['observation']??('ALFAMED CENTRAL '.$order['code']),
        ]);
    }



    /** @return array{body:string,filename:string,format:string,awb:string} */
    public function awbPdf(int $orderId,string $format='A4'): array {
        $shipment=$this->activeLocal($orderId);if(!$shipment)throw new RuntimeException('Comanda nu are un AWB activ.');
        return $this->awbPdfFromShipment($orderId,$shipment,$format);
    }

    /** @return array{body:string,filename:string,format:string,awb:string} */
    public function awbPdfForShipment(int $orderId,int $shipmentId,string $format='A4'): array {
        $st=$this->db->prepare("SELECT * FROM shipments WHERE id=? AND order_id=? AND status NOT IN ('cancelled','deleted') LIMIT 1");$st->execute([$shipmentId,$orderId]);$shipment=$st->fetch();
        if(!$shipment)throw new RuntimeException('AWB-ul selectat nu exista sau nu apartine comenzii.');
        return $this->awbPdfFromShipment($orderId,$shipment,$format);
    }

    /** @return array{pdfs:array<int,array>,errors:array<int,string>} */
    public function awbPdfs(int $orderId,string $format='A4'): array {
        $pdfs=[];$errors=[];
        foreach($this->activeLocals($orderId) as $shipment){
            try{$pdfs[]=$this->awbPdfFromShipment($orderId,$shipment,$format);}
            catch(Throwable $e){$errors[]=(string)($shipment['awb']??'AWB').': '.$e->getMessage();}
        }
        return ['pdfs'=>$pdfs,'errors'=>$errors];
    }

    /** @return array{body:string,filename:string,format:string,awb:string} */
    private function awbPdfFromShipment(int $orderId,array $shipment,string $format): array {
        $format=strtoupper(trim($format));if(!in_array($format,['A4','A6'],true))throw new RuntimeException('Formatul AWB trebuie sa fie A4 sau A6.');
        $order=(new OrderOperations($this->db))->getOrder($orderId);
        if((string)($order['channel_type']??'')!=='emag')throw new RuntimeException('Descarcarea AWB A4/A6 din Marketplace este disponibila pentru comenzile eMAG.');
        $client=$this->client($order);$emagId=0;$providerId=trim((string)($shipment['provider_awb_id']??''));
        if($providerId!==''&&ctype_digit($providerId))$emagId=(int)$providerId;
        if($emagId<=0){
            $meta=json_decode((string)($shipment['raw_json']??'{}'),true)?:[];$provider=is_array($meta['provider']??null)?$meta['provider']:[];
            $remote=$this->findRemoteRow($provider,(string)$order['external_id'],(string)$shipment['awb']);
            if($remote)$emagId=$this->remoteEmagId($remote);
        }
        if($emagId<=0)throw new RuntimeException('AWB-ul nu are ID-ul intern eMAG necesar pentru descarcarea etichetei prin API. Eticheta ramane disponibila in Marketplace.');
        $pdf=$client->downloadAwbPdf($emagId,$format);$awb=trim((string)($shipment['awb']??''))?:'AWB';$safe=preg_replace('/[^A-Za-z0-9._-]+/','-',trim($awb))?:'AWB';
        return ['body'=>(string)$pdf['body'],'filename'=>$safe.'-'.$format.'.pdf','format'=>$format,'awb'=>$awb];
    }

    private function findRemoteRow(array $node,string $externalId,string $awb=''): ?array {
        if(!$node)return null;
        $nodeOrder=(string)($node['order_id']??'');
        $nodeAwb=(string)($node['awb_number']??$node['number']??'');
        $nodeBarcode=(string)($node['awb_barcode']??'');
        if(($nodeOrder===$externalId||($awb!==''&&($nodeAwb===$awb||$nodeBarcode===$awb)))&&$this->remoteEmagId($node)>0)return $node;
        foreach($node as $value)if(is_array($value)){
            if(array_is_list($value)){foreach($value as $row)if(is_array($row)){$found=$this->findRemoteRow($row,$externalId,$awb);if($found)return $found;}}
            else {$found=$this->findRemoteRow($value,$externalId,$awb);if($found)return $found;}
        }
        return null;
    }
    private function remoteEmagId(array $row): int {
        foreach(['emag_id','id','awb_id'] as $key){$v=(int)($row[$key]??0);if($v>0)return $v;}
        $awb=$row['awb']??null;if(is_array($awb)){if(array_is_list($awb))$awb=$awb[0]??[];if(is_array($awb))foreach(['emag_id','id','awb_id'] as $key){$v=(int)($awb[$key]??0);if($v>0)return $v;}}
        return 0;
    }

    public function senderMissing(string $channelCode): array {
        $p='module.couriers.'.$channelCode.'_sender_';$missing=[];
        foreach(['name'=>'Nume expeditor','contact'=>'Persoana contact','phone'=>'Telefon','locality_id'=>'Locality ID','street'=>'Adresa'] as $key=>$label)if(trim((string)$this->settings->get($p.$key,''))==='')$missing[]=$label;
        return $missing;
    }

    private function sender(string $channelCode): array {
        $missing=$this->senderMissing($channelCode);if($missing)throw new RuntimeException('Completeaza expeditorul eMAG in Setari > Module > Curieri: '.implode(', ',$missing).'.');
        $p='module.couriers.'.$channelCode.'_sender_';
        return ['name'=>(string)$this->settings->get($p.'name',''),'contact'=>(string)$this->settings->get($p.'contact',''),'phone1'=>$this->phone((string)$this->settings->get($p.'phone','')),'locality_id'=>(int)$this->settings->get($p.'locality_id','0'),'street'=>(string)$this->settings->get($p.'street',''),'zipcode'=>(string)$this->settings->get($p.'zipcode','')];
    }
    private function client(array $order): EmagClient {$client=ChannelFactory::client((string)$order['channel_code']);if(!$client instanceof EmagClient)throw new RuntimeException('Integrarea eMAG pentru aceasta tara este dezactivata.');return $client;}
    /** @return array<int,array> */
    private function activeLocals(int $orderId): array {$st=$this->db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('cancelled','deleted') ORDER BY id ASC");$st->execute([$orderId]);return $st->fetchAll()?:[];}
    private function activeLocal(int $orderId): ?array {$rows=$this->activeLocals($orderId);return $rows?end($rows):null;}

    private function resultRows(array $response): array {
        // eMAG has changed envelope/wrapper shapes between API revisions and gateways.
        // Do not assume `results[]` is only one or two levels deep: collect shipment rows
        // recursively, but only accept nodes that can be tied to an order and contain AWB data.
        $roots=[];
        foreach(['results','data','items','awbs','shipments'] as $key){
            $node=$response[$key]??null;if(is_array($node))$roots[]=$node;
        }
        if(!$roots)$roots=[$response];
        $out=[];$seen=[];
        $walk=function(array $node,int $depth=0) use (&$walk,&$out,&$seen): void {
            if($depth>8)return;
            $hasDirectOrder=false;
            foreach(['order_id','orderId'] as $key)if(isset($node[$key])&&is_scalar($node[$key])&&trim((string)$node[$key])!==''){$hasDirectOrder=true;break;}
            if(!$hasDirectOrder&&is_array($node['order']??null)){
                foreach(['id','order_id','orderId'] as $key)if(isset($node['order'][$key])&&is_scalar($node['order'][$key])&&trim((string)$node['order'][$key])!==''){$hasDirectOrder=true;break;}
            }
            $hasAwb=$this->containsAwbData($node,0);
            if($hasAwb&&$hasDirectOrder){
                $signature=sha1(json_encode($node,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:serialize($node));
                if(!isset($seen[$signature])){$seen[$signature]=true;$out[]=$node;}
                // A direct order/shipment row is the useful unit. Its nested AWB entries are
                // parsed later by parsedAwbEntries(), so do not also return every child row.
                return;
            }
            foreach($node as $child){
                if(!is_array($child))continue;
                if(array_is_list($child)){foreach($child as $entry)if(is_array($entry))$walk($entry,$depth+1);}
                else $walk($child,$depth+1);
            }
        };
        foreach($roots as $root){
            if(!is_array($root))continue;
            if(array_is_list($root)){foreach($root as $entry)if(is_array($entry))$walk($entry,0);}
            else $walk($root,0);
        }
        if(!$out&&$this->containsAwbData($response,0)&&$this->remoteOrderId($response)!=='')$out[]=$response;
        return $out;
    }

    private function containsAwbData(array $node,int $depth=0): bool {
        if($depth>7)return false;
        foreach(['awb_number','awb_barcode','waybill_number','waybill_barcode'] as $key){
            if(isset($node[$key])&&is_scalar($node[$key])&&trim((string)$node[$key])!=='')return true;
        }
        if(array_key_exists('awb',$node)){
            $v=$node['awb'];
            if(is_scalar($v)&&trim((string)$v)!=='')return true;
            if(is_array($v)&&$v!==[])return true;
        }
        foreach($node as $key=>$child){
            if(!is_array($child))continue;
            $name=is_string($key)?strtolower($key):'';
            // Restrict recursion to logistics/wrapper nodes to avoid classifying an order
            // merely because an unrelated product happens to have a field named `number`.
            if($name!==''&&!preg_match('/^(?:results|data|items|details|shipment|shipments|awb|awbs|parcel|parcels|package|packages|label|labels|waybill|waybills)$/',$name))continue;
            if(array_is_list($child)){foreach($child as $entry)if(is_array($entry)&&$this->containsAwbData($entry,$depth+1))return true;}
            elseif($this->containsAwbData($child,$depth+1))return true;
        }
        return false;
    }
    private function remoteOrderId(array $row): string {
        return $this->remoteOrderIdRecursive($row,0);
    }

    private function remoteOrderIdRecursive(array $node,int $depth): string {
        if($depth>8)return '';
        foreach(['order_id','orderId','orderID'] as $key){
            $v=$node[$key]??null;if(is_scalar($v)){$v=trim((string)$v);if($v!=='')return $v;}
        }
        // Some wrappers put the external id under order.id rather than order_id.
        if(isset($node['order'])&&is_array($node['order'])){
            foreach(['id','order_id','orderId'] as $key){$v=$node['order'][$key]??null;if(is_scalar($v)){$v=trim((string)$v);if($v!=='')return $v;}}
        }
        foreach($node as $child){
            if(!is_array($child))continue;
            if(array_is_list($child)){foreach($child as $entry)if(is_array($entry)){ $v=$this->remoteOrderIdRecursive($entry,$depth+1);if($v!=='')return $v; }}
            else { $v=$this->remoteOrderIdRecursive($child,$depth+1);if($v!=='')return $v; }
        }
        return '';
    }

    /**
     * Return every printed parcel/AWB entry contained in one eMAG AWB/read row.
     * AWB/read has a top-level shipment and an `awb` list. Older code kept only
     * the first item from that list, which could silently drop additional labels.
     *
     * @return array<int,array{awb:string,awb_barcode:string,provider_awb_id:string,courier:string,tracking_url:string,remote:array}>
     */
    private function parsedAwbEntries(array $row,string $fallbackCourier='eMAG'): array {
        $parentOrder=$this->remoteOrderId($row);$parentEmagId=$this->remoteEmagId($row);
        $parentCourier=$this->findAwbScalar($row,['courier_name','courier']);if($parentCourier==='')$parentCourier=$fallbackCourier;
        $nodes=[];$nodeSeen=[];
        $collect=function(array $node,int $depth=0,bool $awbContext=false) use (&$collect,&$nodes,&$nodeSeen): void {
            if($depth>8)return;
            $direct=false;
            $directKeys=['awb_number','awb_barcode','waybill_number','waybill_barcode','tracking_number','tracking_code'];
            if($awbContext)$directKeys=array_merge($directKeys,['number','barcode']);
            foreach($directKeys as $key){if(isset($node[$key])&&is_scalar($node[$key])&&trim((string)$node[$key])!==''){$direct=true;break;}}
            if($direct){$sig=sha1(json_encode($node,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:serialize($node));if(!isset($nodeSeen[$sig])){$nodeSeen[$sig]=true;$nodes[]=$node;}}
            foreach($node as $key=>$child){
                $name=is_string($key)?strtolower($key):'';$nextContext=$awbContext||preg_match('/^(?:awb|awbs|shipment|shipments|waybill|waybills|parcel|parcels|package|packages|label|labels)$/',$name);
                if(!is_array($child))continue;
                if(!$nextContext&&!in_array($name,['data','details','results','items'],true))continue;
                if(array_is_list($child)){foreach($child as $entry)if(is_array($entry))$collect($entry,$depth+1,(bool)$nextContext);}
                else $collect($child,$depth+1,(bool)$nextContext);
            }
        };
        $collect($row,0,false);
        if(!$nodes){
            // Old/simplified gateways may expose the values at shipment level and use an
            // unusual wrapper. Recursive scalar lookup still gives us one safe parcel.
            $fallbackAwb=$this->findAwbScalar($row,['awb_number','waybill_number','tracking_number']);
            $fallbackBarcode=$this->findAwbScalar($row,['awb_barcode','waybill_barcode','tracking_code']);
            if($fallbackAwb!==''||$fallbackBarcode!=='')$nodes=[$row];
        }
        $out=[];$seen=[];$multi=count($nodes)>1;
        foreach($nodes as $entry){
            $awb=$this->findAwbScalar($entry,['awb_number','waybill_number','tracking_number','number']);$barcode=$this->findAwbScalar($entry,['awb_barcode','waybill_barcode','tracking_code','barcode']);
            if($awb===''&&isset($entry['awb'])&&is_scalar($entry['awb'])){$candidate=trim((string)$entry['awb']);if(strlen($this->scanKey($candidate))>=6)$awb=$candidate;}
            if($awb===''&&$barcode==='')continue;if($awb==='')$awb=$barcode;
            $childId=0;foreach(['emag_id','awb_id','id'] as $key){$v=$entry[$key]??null;if(is_scalar($v)&&($n=(int)$v)>0){$childId=$n;break;}}
            $courier=$this->findAwbScalar($entry,['courier_name','courier']);if($courier==='')$courier=$parentCourier?:'eMAG';
            $uniq=$this->scanKey($barcode!==''?$barcode:$awb);if($uniq===''||isset($seen[$uniq]))continue;$seen[$uniq]=true;
            $providerId=$childId>0?(string)$childId:($parentEmagId>0?(string)$parentEmagId:'');
            if($childId<=0&&$parentEmagId>0&&$multi)$providerId=(string)$parentEmagId.'-'.substr(sha1($uniq),0,12);
            $remote=['order_id'=>$parentOrder,'parent_emag_id'=>$parentEmagId,'awb_entry'=>$entry,'source_row'=>$row];
            $out[]=['awb'=>$awb,'awb_barcode'=>$barcode,'provider_awb_id'=>$providerId,'courier'=>$courier,'tracking_url'=>'','remote'=>$remote];
        }
        return $out;
    }

    private function findAwbScalar(array $node,array $keys): string {
        return $this->findAwbScalarRecursive($node,$keys,0);
    }

    private function findAwbScalarRecursive(array $node,array $keys,int $depth): string {
        if($depth>8)return '';
        foreach($keys as $key){
            $v=$node[$key]??null;if(is_scalar($v)){$v=trim((string)$v);if($v!=='')return $v;}
        }
        foreach($node as $child){
            if(!is_array($child))continue;
            if(array_is_list($child)){foreach($child as $entry)if(is_array($entry)){ $v=$this->findAwbScalarRecursive($entry,$keys,$depth+1);if($v!=='')return $v; }}
            else { $v=$this->findAwbScalarRecursive($child,$keys,$depth+1);if($v!=='')return $v; }
        }
        return '';
    }
    /** Retry throttling only in background/reconciliation work. Interactive lookups fail fast. */
    /**
     * Fast-path for the only safe direct AWB/read lookup used by the scanner:
     * eMAG's internal AWB id (emag_id). Printed awb_number/awb_barcode and order_id
     * are resolved from the paginated AWB mirror because they are response fields.
     *
     * @return array<int,array>
     */    /** @return array{pages:int,total:int,per_page:int} */    /**
     * Refresh the pages most likely to contain newly-created Marketplace AWBs without
     * trying to rediscover the history tail on every autosync. The sequential background
     * mirror learns the real last page once; until then page 1 + the current history cursor
     * give deterministic forward progress with very few API calls.
     */
    /**
     * Importa o pagina de AWB-uri eMAG si le leaga de comenzile locale folosind order_id-ul eMAG.
     * Toate coletele din lista `awb` sunt salvate, nu doar primul.
     */    /**
     * Read-only recovery used by the scanner when an AWB is not yet cached locally.
     * Search both history edges first, then walk inward. This avoids the old assumption
     * that page 1 always contains the newest AWBs.
     */   /**
     * Search the AWB/read feed without blocking page rendering. It first checks both
     * history edges; when eMAG omits total_pages it discovers the tail exponentially,
     * then advances a persistent per-order cursor. Every inspected row is cached for
     * any already-local order, so this also repairs the global local mirror.
     *
     * @return array{entries:array,diagnostic:array,continue_search:bool}
     */
    /**
     * Hydrate the local shipment for one already-imported eMAG order without blocking page render.
     * Fast paths first: payload AWB data, type-10 AWB attachment hints, documented emag_id /
     * reservation_id filters, then a bounded paginated fallback validated by order_id.
     */
    /** Count matching attachment objects recursively in an eMAG response. */
    /** Repair a few newest eMAG orders missing local AWB on each background tick. */
    /**
     * Walk every locally imported eMAG order that is still missing an AWB. Unlike the
     * recent-order repair above, this worker owns a persistent descending order cursor,
     * therefore a handful of new orders without AWB can never starve the historical
     * backlog. When the oldest order is reached the cursor is reset and a new pass starts
     * later, so AWBs created after the original order date are eventually discovered too.
     */
    /** Store explicit AWB data embedded in order/read without mistaking generic invoice/product numbers for AWBs. */
    /** @return array<int,string> AWB-like printed tokens found only in explicit AWB/type-10 attachment context. */
    /** @return array<int,array<string,int>> */    /** Ensure an AWB's eMAG order exists locally before linking the shipment. */
    /** @return array<int,array> */    /** Return the enabled eMAG channel row for an already-local external order id. */
    /** Cache AWBs for orders that are already present locally; no extra order/read calls. */
    /** Detect final page without assuming that a short non-empty page is final. */
    private function parseAwbResponse(array $res,string $externalId,string $courier='eMAG'): array {
        foreach($this->resultRows($res) as $row){foreach($this->parsedAwbEntries($row,$courier) as $p)if($p['awb']!==''||$p['awb_barcode']!=='')return $p;}
        return ['awb'=>'','awb_barcode'=>'','provider_awb_id'=>'','courier'=>$courier,'tracking_url'=>'','remote'=>['order_id'=>$externalId]];
    }
    private function storeRemote(int $orderId,array $parsed,array $provider=[]): int {
        $awb=trim((string)($parsed['awb']??''));$barcode=trim((string)($parsed['awb_barcode']??''));$providerId=trim((string)($parsed['provider_awb_id']??''));
        if($awb===''&&$barcode==='')return 0;if($awb==='')$awb=$barcode;
        $raw=['provider'=>$provider?:($parsed['remote']??[]),'source'=>'emag_marketplace'];$json=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $st=$this->db->prepare("SELECT id FROM shipments WHERE awb=? OR (?<>'' AND awb_barcode=?) OR (?<>'' AND provider_awb_id=?) ORDER BY id DESC LIMIT 1");
        $st->execute([$awb,$barcode,$barcode,$providerId,$providerId]);$existing=(int)($st->fetchColumn()?:0);
        if($existing>0){$up=$this->db->prepare("UPDATE shipments SET order_id=?,courier=?,awb=?,awb_barcode=?,provider_awb_id=?,status=CASE WHEN status IN ('deleted','cancelled') THEN 'created' ELSE status END,raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");$up->execute([$orderId,(string)($parsed['courier']??'eMAG'),$awb,$barcode!==''?$barcode:null,$providerId!==''?$providerId:null,$json,$existing]);return $existing;}
        $ins=$this->db->prepare('INSERT INTO shipments(order_id,courier,awb,awb_barcode,provider_awb_id,status,tracking_url,raw_json) VALUES(?,?,?,?,?,?,?,?)');$ins->execute([$orderId,(string)($parsed['courier']??'eMAG'),$awb,$barcode!==''?$barcode:null,$providerId!==''?$providerId:null,'created',null,$json]);return (int)$this->db->lastInsertId();
    }
    private function scanKey(string $value): string {return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');}
    private function isCod(array $raw): bool {
        $values=[];foreach(['payment_mode','payment_method','payment_method_title','detailed_payment_method','payment_type'] as $key){$v=$raw[$key]??null;if(is_scalar($v)&&!is_numeric($v))$values[]=strtolower(trim((string)$v));}
        $text=implode(' ',$values);
        // Textul explicit primit in comanda are prioritate fata de ID-uri: card/online/transfer nu trebuie sa genereze ramburs.
        foreach(['card online','plata online','online card','credit card','debit card','transfer bancar','bank transfer','ordin de plata','op '] as $needle)if($text!==''&&str_contains($text,$needle))return false;
        foreach(['ramburs','cash on delivery','cash-on-delivery','numerar','plata la livrare','cod'] as $needle)if($text!==''&&str_contains($text,$needle))return true;
        if(array_key_exists('payment_mode_id',$raw)&&is_scalar($raw['payment_mode_id']))return (int)$raw['payment_mode_id']===1;
        if(array_key_exists('payment_mode',$raw)&&is_numeric($raw['payment_mode']))return (int)$raw['payment_mode']===1;
        return false;
    }
    private function phone(string $value): string {$v=preg_replace('/(?!^\+)[^0-9]/','',$value)??'';if(str_starts_with($v,'00'))$v='+'.substr($v,2);return $v;}
    private function clip(string $value,int $limit): string {return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);}
}

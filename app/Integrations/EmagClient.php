<?php
namespace App\Integrations;
use App\Core\Http;
use RuntimeException;
use Throwable;

final class EmagClient {
    public function __construct(private string $baseUrl, private string $username, private string $password, private string $currency='RON') {}
    private function headers(array $extra=[]): array { return array_merge(['Authorization: Basic '.base64_encode($this->username.':'.$this->password),'Accept: application/json'], $extra); }
    private function requestRaw(string $resource,string $action,array $data=[],int $timeout=30): array {
        $url=rtrim($this->baseUrl,'/').'/'.trim($resource,'/').'/'.$action;
        return Http::request('POST',$url,$this->headers(['Content-Type: application/x-www-form-urlencoded']),['data'=>$data],$timeout);
    }
    private function post(string $resource,string $action,array $data=[],int $timeout=30): array {
        $r=$this->requestRaw($resource,$action,$data,$timeout);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException("eMAG HTTP {$r['status']}: ".substr($r['body'],0,500));
        $json=$r['json']??[]; if(($json['isError']??false)===true) throw new RuntimeException('eMAG API: '.json_encode($json['messages']??$json,JSON_UNESCAPED_UNICODE));
        return $json;
    }
    private function postJson(string $resource,string $action,array $payload,int $timeout=30): array {
        $url=rtrim($this->baseUrl,'/').'/'.trim($resource,'/').'/'.$action;
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($body===false) throw new RuntimeException('Nu pot serializa cererea JSON eMAG.');
        $r=Http::request('POST',$url,$this->headers(['Content-Type: application/json']),$body,$timeout);
        if((int)($r['status']??0)<200||(int)($r['status']??0)>=300) throw new RuntimeException('eMAG HTTP '.(int)($r['status']??0).': '.substr((string)($r['body']??''),0,500));
        $json=$r['json']??[];if(($json['isError']??false)===true)throw new RuntimeException('eMAG API: '.json_encode($json['messages']??$json,JSON_UNESCAPED_UNICODE));
        return is_array($json)?$json:[];
    }
    public function readOrders(array $filters=[],int $timeout=30): array { return $this->post('order','read',$filters+['currentPage'=>1,'itemsPerPage'=>100],$timeout); }
    public function readOrderById(string|int $id,int $timeout=30): ?array {
        $id=trim((string)$id);if($id==='')return null;
        // order/read defaults to seller-fulfilled orders (type=3). Keep that fast path,
        // then retry type=2 so an older FBE order is not silently excluded.
        foreach([3,2] as $type){
            $res=$this->readOrders(['id'=>$id,'type'=>$type,'currentPage'=>1,'itemsPerPage'=>1],$timeout);
            $row=$this->firstOrderResult($res,$id);if($row)return $row;
        }
        return null;
    }
    private function firstOrderResult(array $response,string $requestedId=''): ?array {
        $results=$response['results']??[];
        if(is_array($results)&&isset($results['id'])){
            $rowId=trim((string)($results['id']??$results['order_id']??''));
            return $requestedId===''||$rowId===''||$rowId===$requestedId?$results:null;
        }
        foreach((array)$results as $row){
            if(!is_array($row))continue;
            $rowId=trim((string)($row['id']??$row['order_id']??''));
            if($requestedId===''||$rowId===''||$rowId===$requestedId)return $row;
        }
        return null;
    }
    public function readOrderAttachments(string|int $orderId,int $orderType=3,int $timeout=12): array {
        $orderId=(int)$orderId;$orderType=(int)$orderType;
        if($orderId<=0) throw new RuntimeException('ID-ul comenzii eMAG lipseste pentru citirea atasamentelor.');
        if(!in_array($orderType,[2,3],true)) throw new RuntimeException('Tipul comenzii eMAG pentru atasamente trebuie sa fie 2 sau 3.');

        // API 4.5.1 documents order/attachments/read as a JSON request whose
        // order_id and order_type are top-level fields. Do not use the legacy
        // data[...] wrapper used by older Marketplace resources.
        $url=rtrim($this->baseUrl,'/').'/order/attachments/read';
        $payload=json_encode(['order_id'=>$orderId,'order_type'=>$orderType],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($payload===false) throw new RuntimeException('Nu pot serializa cererea pentru atasamentele eMAG.');
        $r=Http::request('POST',$url,$this->headers(['Content-Type: application/json']),$payload,$timeout);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException("eMAG HTTP {$r['status']}: ".substr((string)$r['body'],0,500));
        $json=$r['json']??[];
        if(($json['isError']??false)===true) throw new RuntimeException('eMAG API: '.json_encode($json['messages']??$json,JSON_UNESCAPED_UNICODE));
        return is_array($json)?$json:[];
    }

    public function updateOrderStatus(string|int $orderId,int $status,array $extra=[]): array {
        $data=['id'=>$orderId,'status'=>$status]+$extra; if($this->currency==='EUR') $data['currency']='EUR'; return $this->post('order','save',$data);
    }
    public function acknowledge(string|int $orderId): array {
        $url=rtrim($this->baseUrl,'/').'/order/acknowledge/'.rawurlencode((string)$orderId);
        $r=Http::request('POST',$url,$this->headers(['Content-Type: application/x-www-form-urlencoded']),[]);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException("eMAG HTTP {$r['status']}: ".substr((string)$r['body'],0,500));
        $json=$r['json']??[];if(($json['isError']??false)===true) throw new RuntimeException('eMAG API: '.json_encode($json['messages']??$json,JSON_UNESCAPED_UNICODE));
        return is_array($json)?$json:[];
    }
    public function readOffers(array $filters=[]): array { return $this->post('product_offer','read',$filters+['currentPage'=>1,'itemsPerPage'=>100]); }
    public function updateOffer(array $offer): array { return $this->post('product_offer','save',$offer); }
    public function createAwb(array $data): array {
        if($this->currency==='EUR')$data['currency']='EUR';
        // API 4.5.1 documents awb/save as JSON {"data":[{...}]}. The previous
        // urlencoded data[order_id] shape could be accepted by some gateways but return
        // only a reservation/identifier, leaving ALFAMED CENTRAL without the printed AWB.
        $saved=$this->postJson('awb','save',['data'=>[$data]],60);
        if($this->responseContainsAwb($saved))return $saved;

        // Some Marketplace gateways acknowledge the reservation first and expose the
        // printable number only through awb/read. Never issue a second awb/save here:
        // recover the just-created shipment using the identifiers returned by save.
        $ids=$this->firstAwbIdentifiers($saved);
        if($ids['emag_id']>0||$ids['reservation_id']>0){
            try{
                $filters=[];if($ids['emag_id']>0)$filters['emag_id']=$ids['emag_id'];if($ids['reservation_id']>0)$filters['reservation_id']=$ids['reservation_id'];
                $read=$this->readAwbs($filters,20);
                if($this->responseContainsAwb($read)){$read['_save_response']=$saved;return $read;}
                $saved['_read_response']=$read;
            }catch(Throwable $e){$saved['_read_error']=$e->getMessage();}
        }
        return $saved;
    }
    public function readAwbs(array $filters=[],int $timeout=30): array {
        $payload=[];
        foreach(['emag_id','reservation_id'] as $key){$v=(int)($filters[$key]??0);if($v>0)$payload[$key]=$v;}
        if(!$payload)throw new RuntimeException('eMAG AWB/read necesita emag_id sau reservation_id.');
        // API 4.5.1: emag_id / reservation_id are top-level JSON fields.
        return $this->postJson('awb','read',$payload,$timeout);
    }
    private function responseContainsAwb(array $response): bool {
        $walk=function(array $node,int $depth=0) use (&$walk):bool{
            if($depth>8)return false;
            foreach(['awb_number','awb_barcode','waybill_number','waybill_barcode'] as $key){$v=$node[$key]??null;if(is_scalar($v)&&trim((string)$v)!=='')return true;}
            if(isset($node['awb'])&&is_scalar($node['awb'])&&trim((string)$node['awb'])!=='')return true;
            foreach($node as $child){if(!is_array($child))continue;if(array_is_list($child)){foreach($child as $entry)if(is_array($entry)&&$walk($entry,$depth+1))return true;}elseif($walk($child,$depth+1))return true;}
            return false;
        };
        return $walk($response,0);
    }
    /** @return array{emag_id:int,reservation_id:int} */
    private function firstAwbIdentifiers(array $response): array {
        $found=['emag_id'=>0,'reservation_id'=>0];
        $walk=function(array $node,int $depth=0) use (&$walk,&$found):bool{
            if($depth>8)return false;
            $localEmag=(int)($node['emag_id']??0);$localReservation=(int)($node['reservation_id']??0);
            if($localEmag>0||$localReservation>0){$found=['emag_id'=>$localEmag,'reservation_id'=>$localReservation];return true;}
            foreach($node as $child){if(!is_array($child))continue;if(array_is_list($child)){foreach($child as $entry)if(is_array($entry)&&$walk($entry,$depth+1))return true;}elseif($walk($child,$depth+1))return true;}
            return false;
        };
        $walk($response,0);return $found;
    }
    public function downloadAwbPdf(int $emagId,string $format='A4'): array {
        $format=strtoupper(trim($format));
        if(!in_array($format,['A4','A5','A6'],true))throw new RuntimeException('Format AWB eMAG invalid.');
        if($emagId<=0)throw new RuntimeException('ID-ul AWB eMAG lipseste.');
        $url=rtrim($this->baseUrl,'/').'/awb/read_pdf?'.http_build_query(['emag_id'=>$emagId,'awb_format'=>$format]);
        $r=Http::request('GET',$url,$this->headers(['Accept: application/pdf']),null,60);
        $body=(string)($r['body']??'');
        if((int)($r['status']??0)<200||(int)($r['status']??0)>=300)throw new RuntimeException('eMAG AWB PDF HTTP '.(int)($r['status']??0).': '.substr(strip_tags($body),0,400));
        if(!str_starts_with(ltrim($body),'%PDF'))throw new RuntimeException('eMAG nu a returnat un PDF valid pentru AWB.');
        return ['body'=>$body,'format'=>$format,'emag_id'=>$emagId];
    }
    public function readOfferById(string|int $id): ?array {
        $id=trim((string)$id);if($id==='')return null;
        $res=$this->readOffers(['id'=>$id,'currentPage'=>1,'itemsPerPage'=>5]);
        return $this->firstOfferResult($res,'id',$id);
    }
    public function readOfferByPartNumber(string $partNumber): ?array {
        $partNumber=trim($partNumber);if($partNumber==='')return null;
        $res=$this->readOffers(['part_number'=>$partNumber,'currentPage'=>1,'itemsPerPage'=>20]);
        return $this->firstOfferResult($res,'part_number',$partNumber);
    }
    public function readOfferByPartNumberKey(string $pnk): ?array {
        $pnk=strtoupper(trim($pnk));if($pnk==='')return null;
        $res=$this->readOffers(['part_number_key'=>$pnk,'currentPage'=>1,'itemsPerPage'=>20]);
        return $this->firstOfferResult($res,'part_number_key',$pnk);
    }
    public function readOfferByEan(string $ean): ?array {
        $ean=preg_replace('/[^0-9]/','',$ean)??'';if(strlen($ean)<8)return null;
        $res=$this->readOffers(['ean'=>$ean,'currentPage'=>1,'itemsPerPage'=>20]);
        return $this->firstOfferResult($res,'ean',$ean);
    }
    private function firstOfferResult(array $response,string $field='',string $expected=''): ?array {
        $results=$response['results']??$response['data']??[];
        if(is_array($results)&&isset($results['id']))$results=[$results];
        $rows=array_values(array_filter((array)$results,'is_array'));
        if($field===''||$expected==='')return $rows[0]??null;
        $expectedNorm=$field==='ean'?(preg_replace('/[^0-9]/','',$expected)??''):strtoupper(trim($expected));
        foreach($rows as $row){
            $values=[];
            $value=$row[$field]??null;
            if(is_array($value)){foreach($value as $v)if(is_scalar($v))$values[]=(string)$v;}
            elseif(is_scalar($value)){$values[]=(string)$value;}
            // API variants may nest the same identifiers under product/offer.
            foreach(['product','offer','details'] as $node)if(is_array($row[$node]??null)){
                $v=$row[$node][$field]??null;if(is_array($v)){foreach($v as $x)if(is_scalar($x))$values[]=(string)$x;}elseif(is_scalar($v)){$values[]=(string)$v;}
            }
            foreach($values as $candidate){
                $norm=$field==='ean'?(preg_replace('/[^0-9]/','',$candidate)??''):strtoupper(trim($candidate));
                if($norm!==''&&$norm===$expectedNorm)return $row;
            }
        }
        return null;
    }

    public function readCourierAccounts(array $filters=[]): array { return $this->post('courier_accounts','read',$filters+['currentPage'=>1,'itemsPerPage'=>100]); }
    public function readLocalities(array $filters=[]): array { return $this->post('locality','read',$filters+['currentPage'=>1,'itemsPerPage'=>50]); }
    public function saveOrderAttachment(string|int $orderId,string $name,string $url,int $type=1,bool $forceDownload=true,int $orderType=3): array {
        if(!filter_var($url,FILTER_VALIDATE_URL)) throw new RuntimeException('URL-ul documentului eMAG nu este valid.');
        $orderId=(int)$orderId;$orderType=(int)$orderType;
        if($orderId<=0)throw new RuntimeException('ID-ul comenzii eMAG lipseste pentru atasament.');
        if(!in_array($orderType,[2,3],true))$orderType=3;
        $label=function_exists('mb_substr')?mb_substr(trim($name),0,60,'UTF-8'):substr(trim($name),0,60);
        $attachment=[
            'order_id'=>$orderId,
            'order_type'=>$orderType,
            'name'=>$label!==''?$label:'Document',
            'url'=>$url,
            'type'=>$type,
            'force_download'=>$forceDownload?1:0,
        ];
        // API 4.5.1: order/attachments/save expects JSON {"data":[{...}]}.
        // No multipart field emulation is needed because eMAG receives a public PDF URL,
        // not the PDF bytes themselves.
        return $this->postJson('order/attachments','save',['data'=>[$attachment]],30);
    }

    /**
     * Read-only API diagnostic. It asks eMAG for a single order and never modifies data.
     * Returned data is safe to persist: credentials and Authorization headers are omitted.
     */
    public function diagnoseOrders(): array {
        $endpoint=rtrim($this->baseUrl,'/').'/order/read';
        if($this->username===''||$this->password===''){
            return self::diagnosticResult(false,'config_missing',0,'Lipsesc utilizatorul API sau parola API.','',$endpoint,[]);
        }
        if(!filter_var($endpoint,FILTER_VALIDATE_URL)){
            return self::diagnosticResult(false,'endpoint_invalid',0,'Endpoint-ul eMAG configurat nu este un URL valid.','',$endpoint,[]);
        }
        try {
            $r=$this->requestRaw('order','read',['currentPage'=>1,'itemsPerPage'=>1]);
        } catch(Throwable $e) {
            return self::diagnosticResult(false,'network_error',0,'Conexiunea HTTP catre eMAG a esuat.',$e->getMessage(),$endpoint,[]);
        }
        return self::classifyDiagnosticResponse((int)($r['status']??0),is_array($r['json']??null)?$r['json']:null,(string)($r['body']??''),$endpoint,(array)($r['info']??[]));
    }

    public static function classifyDiagnosticResponse(int $status,?array $json,string $body,string $endpoint='',array $network=[]): array {
        $isError=(bool)($json['isError']??false);
        $apiText=self::messageText($json,$body);
        $lower=strtolower($apiText);
        if($status>=200&&$status<300&&!$isError){
            return self::diagnosticResult(true,'ok',$status,'Conexiunea eMAG API functioneaza. Endpoint-ul order/read a raspuns corect.','',$endpoint,$network);
        }
        if(str_contains($lower,'invalid vendor ip') || ($status===403&&str_contains($lower,'vendor ip'))){
            return self::diagnosticResult(false,'ip_not_allowed',$status,'eMAG a respins IP-ul serverului. Adauga IP-ul public de iesire al hostingului in lista de IP-uri permise pentru API.',$apiText,$endpoint,$network);
        }
        if($status===401 || str_contains($lower,'invalid credential') || str_contains($lower,'authentication failed') || str_contains($lower,'unauthorized')){
            return self::diagnosticResult(false,'auth_failed',$status,'Autentificarea API a fost respinsa. Verifica utilizatorul API si parola salvata pentru aceasta tara.',$apiText,$endpoint,$network);
        }
        if($status===403 && (str_contains($lower,'not allowed to use this api') || str_contains($lower,'not allowed'))){
            return self::diagnosticResult(false,'api_rights_denied',$status,'eMAG a refuzat accesul la API. Acest raspuns poate indica fie credentiale neacceptate, fie drepturi API lipsa pentru utilizatorul din aceasta tara.',$apiText,$endpoint,$network);
        }
        if($status===403){
            return self::diagnosticResult(false,'access_denied',$status,'eMAG a raspuns HTTP 403. Verifica drepturile API ale utilizatorului si whitelist-ul IP pentru acest cont.',$apiText,$endpoint,$network);
        }
        if($status===404){
            return self::diagnosticResult(false,'endpoint_invalid',$status,'Endpoint-ul API nu a fost gasit. Verifica domeniul si calea API configurata.',$apiText,$endpoint,$network);
        }
        if($status>=500){
            return self::diagnosticResult(false,'emag_unavailable',$status,'eMAG API a raspuns cu eroare de server. Reincearca mai tarziu.',$apiText,$endpoint,$network);
        }
        if($isError){
            return self::diagnosticResult(false,'api_error',$status,'eMAG API a raspuns cu o eroare. Vezi mesajul tehnic de mai jos.',$apiText,$endpoint,$network);
        }
        return self::diagnosticResult(false,'http_error',$status,'Conexiunea eMAG API nu a putut fi validata.',$apiText,$endpoint,$network);
    }

    private static function messageText(?array $json,string $body): string {
        if(is_array($json)){
            $flat=[];
            foreach(['messages','errors'] as $key){
                $value=$json[$key]??null;
                if(is_array($value))array_walk_recursive($value,static function($v) use (&$flat){if(is_scalar($v)&&trim((string)$v)!=='')$flat[]=trim((string)$v);});
                elseif(is_scalar($value)&&trim((string)$value)!=='')$flat[]=trim((string)$value);
            }
            // Never echo the full JSON response here: order/read results contain customer PII.
            return $flat?implode(' | ',array_values(array_unique($flat))):'';
        }
        $body=trim(strip_tags($body));
        return self::clip($body,700);
    }

    private static function clip(string $value,int $limit): string {
        return function_exists('mb_substr') ? mb_substr($value,0,$limit,'UTF-8') : substr($value,0,$limit);
    }

    private static function diagnosticResult(bool $ok,string $code,int $httpStatus,string $message,string $apiMessage,string $endpoint,array $network): array {
        return [
            'ok'=>$ok,
            'code'=>$code,
            'http_status'=>$httpStatus,
            'message'=>$message,
            'api_message'=>self::clip($apiMessage,700),
            'endpoint'=>$endpoint,
            'network'=>[
                'remote_ip'=>(string)($network['primary_ip']??''),
                'local_ip'=>(string)($network['local_ip']??''),
                'duration_ms'=>(int)($network['total_time_ms']??0),
            ],
        ];
    }
}

<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Integrations\EmagClient;
use PDO;
use Throwable;

final class EmagDiagnosticService {
    private PDO $db;
    private SettingService $settings;

    public function __construct(?PDO $db=null,?SettingService $settings=null){
        $this->db=$db?:Database::connection();
        $this->settings=$settings?:new SettingService($this->db);
    }

    public function run(string $code): array {
        $cfg=$this->config($code);
        $publicIp=$this->detectPublicIp();
        $expectedHost=$code==='emag_bg'?'marketplace-api.emag.bg':'marketplace-api.emag.ro';
        $actualHost=strtolower((string)(parse_url($cfg['base_url'],PHP_URL_HOST)??''));
        if($actualHost!==''&&$actualHost!==$expectedHost){
            $result=['ok'=>false,'code'=>'endpoint_country_mismatch','http_status'=>0,'message'=>'Endpoint-ul configurat nu corespunde tarii selectate.','api_message'=>'','endpoint'=>rtrim($cfg['base_url'],'/').'/order/read','network'=>['remote_ip'=>'','local_ip'=>'','duration_ms'=>0]];
        }else{
            $client=new EmagClient($cfg['base_url'],$cfg['username'],$cfg['password'],$cfg['currency']);
            $result=$client->diagnoseOrders();
        }
        $result['channel']=$code;
        $result['tested_at']=date(DATE_ATOM);
        $result['public_ip']=$publicIp;
        $result['username_configured']=$cfg['username']!=='';
        $result['password_configured']=$cfg['password']!=='';
        $result['api_code_configured']=$cfg['api_code']!=='';
        $result['base_url']=$cfg['base_url'];
        $result['expected_host']=$expectedHost;
        $result['actual_host']=$actualHost;
        $result['endpoint_country_ok']=$actualHost===$expectedHost;

        $this->settings->set('diagnostic.'.$code.'.last_result',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'diagnostic');
        $channelId=$this->channelId($code);
        $level=($result['ok']??false)?'success':(($result['code']??'')==='network_error'?'warning':'error');
        $message='Test conexiune eMAG: '.(string)($result['message']??'Rezultat necunoscut');
        $context=[
            'code'=>$result['code']??'',
            'http_status'=>$result['http_status']??0,
            'public_ip'=>$publicIp,
            'endpoint'=>$result['endpoint']??$cfg['base_url'],
            'api_message'=>$result['api_message']??'',
            'expected_host'=>$result['expected_host']??'',
            'actual_host'=>$result['actual_host']??'',
            'api_code_configured'=>$result['api_code_configured']??false,
        ];
        try{
            $st=$this->db->prepare('INSERT INTO sync_logs(channel_id,level,action,message,context_json) VALUES(?,?,?,?,?)');
            $st->execute([$channelId,$level,'emag_api_test',$message,json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }catch(Throwable){}
        return $result;
    }

    public function last(string $code): ?array {
        if(!in_array($code,['emag_ro','emag_bg'],true))return null;
        $raw=(string)$this->settings->get('diagnostic.'.$code.'.last_result','');
        if($raw==='')return null;
        $decoded=json_decode($raw,true);
        if(!is_array($decoded))return null;
        if(!empty($decoded['ok']))$decoded['api_message']='';
        return $decoded;
    }

    private function config(string $code): array {
        if(!in_array($code,['emag_ro','emag_bg'],true))throw new \InvalidArgumentException('Canal eMAG invalid.');
        $prefix=$code==='emag_bg'?'EMAG_BG':'EMAG_RO';
        return [
            'base_url'=>rtrim((string)Env::get($prefix.'_BASE_URL',$code==='emag_bg'?'https://marketplace-api.emag.bg/api-3':'https://marketplace-api.emag.ro/api-3'),'/'),
            'username'=>trim((string)Env::get($prefix.'_USERNAME','')),
            'password'=>(string)Env::get($prefix.'_PASSWORD',''),
            'api_code'=>(string)Env::get($prefix.'_API_CODE',''),
            'currency'=>$code==='emag_bg'?'EUR':'RON',
        ];
    }

    private function channelId(string $code): ?int {
        $st=$this->db->prepare('SELECT id FROM channels WHERE code=? LIMIT 1');$st->execute([$code]);$id=$st->fetchColumn();return $id===false?null:(int)$id;
    }

    /** Best-effort only; a failure here must never make the eMAG API test fail. */
    private function detectPublicIp(): string {
        try{
            $r=Http::request('GET','https://api.ipify.org?format=json',['Accept: application/json'],null,5);
            if(($r['status']??0)>=200&&($r['status']??0)<300&&is_array($r['json']??null)){
                $ip=trim((string)($r['json']['ip']??''));
                if(filter_var($ip,FILTER_VALIDATE_IP))return $ip;
            }
        }catch(Throwable){}
        return '';
    }
}

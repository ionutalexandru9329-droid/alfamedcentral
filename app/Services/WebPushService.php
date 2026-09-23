<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Http;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Minimal RFC 8291 / RFC 8292 Web Push implementation.
 * Keeps ALFAMED CENTRAL self-contained on cPanel hosting without Composer.
 */
final class WebPushService {
    private PDO $db;
    private SettingService $settings;

    public function __construct(?PDO $db=null){
        $this->db=$db ?: Database::connection();
        $this->settings=new SettingService($this->db);
    }

    public function supported(): bool {
        return function_exists('openssl_pkey_new')
            && function_exists('openssl_pkey_get_details')
            && function_exists('openssl_pkey_derive')
            && function_exists('openssl_encrypt');
    }

    public function publicKey(): string {
        [$private,$public]=$this->ensureVapidKeys();
        return $public;
    }

    public function saveSubscription(int $userId,array $subscription,string $label='',string $userAgent=''): void {
        if(!$this->supported()) throw new RuntimeException('Serverul nu suporta criptografia necesara pentru Web Push.');
        $endpoint=trim((string)($subscription['endpoint']??''));
        $keys=is_array($subscription['keys']??null)?$subscription['keys']:[];
        $p256dh=trim((string)($keys['p256dh']??''));
        $auth=trim((string)($keys['auth']??''));
        if($endpoint===''||!filter_var($endpoint,FILTER_VALIDATE_URL)||$p256dh===''||$auth==='') throw new RuntimeException('Abonamentul push primit de la browser este invalid.');
        $hash=hash('sha256',$endpoint);
        $label=self::clip($label!==''?$label:'Dispozitiv browser',120);
        $userAgent=self::clip($userAgent,500);
        $driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $sql='INSERT INTO push_subscriptions(user_id,endpoint,endpoint_hash,p256dh,auth_key,device_label,user_agent,active,last_seen_at,updated_at) VALUES(?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth_key=VALUES(auth_key),device_label=VALUES(device_label),user_agent=VALUES(user_agent),active=1,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
        }else{
            $sql='INSERT INTO push_subscriptions(user_id,endpoint,endpoint_hash,p256dh,auth_key,device_label,user_agent,active,last_seen_at,updated_at) VALUES(?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(endpoint_hash) DO UPDATE SET user_id=excluded.user_id,endpoint=excluded.endpoint,p256dh=excluded.p256dh,auth_key=excluded.auth_key,device_label=excluded.device_label,user_agent=excluded.user_agent,active=1,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
        }
        $this->db->prepare($sql)->execute([$userId,$endpoint,$hash,$p256dh,$auth,$label,$userAgent]);
    }

    public function removeSubscription(int $userId,string $endpoint=''): void {
        if($endpoint!==''){
            $st=$this->db->prepare('UPDATE push_subscriptions SET active=0,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND endpoint_hash=?');
            $st->execute([$userId,hash('sha256',$endpoint)]);
        }else{
            $st=$this->db->prepare('UPDATE push_subscriptions SET active=0,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');
            $st->execute([$userId]);
        }
    }

    public function userSubscriptionCount(int $userId): int {
        try{$st=$this->db->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=? AND active=1');$st->execute([$userId]);return (int)$st->fetchColumn();}catch(Throwable){return 0;}
    }

    public function broadcast(array $payload,string $type=''): array {
        if(!$this->supported()) return ['sent'=>0,'failed'=>0,'unsupported'=>true];
        if($type!==''&&!$this->typeEnabled($type)) return ['sent'=>0,'failed'=>0,'disabled'=>true];
        $pref=$this->preferenceColumn($type);
        try{
            $sql='SELECT ps.* FROM push_subscriptions ps JOIN users u ON u.id=ps.user_id WHERE ps.active=1 AND u.is_active=1';
            if($pref!=='')$sql.=' AND COALESCE(u.'.$pref.',1)=1';
            $sql.=' ORDER BY ps.id';
            $rows=$this->db->query($sql)->fetchAll();
        }catch(Throwable $e){$this->logPush('error','push_select',$e->getMessage(),['type'=>$type]);return ['sent'=>0,'failed'=>0];}
        return $this->sendRows($rows,$payload,$type);
    }

    /** Send a push only to the selected application users (all active devices for each user). */
    public function sendToUsers(array $userIds,array $payload,string $type=''): array {
        if(!$this->supported()) return ['sent'=>0,'failed'=>0,'unsupported'=>true];
        if($type!==''&&!$this->typeEnabled($type)) return ['sent'=>0,'failed'=>0,'disabled'=>true];
        $ids=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn($v)=>$v>0)));
        if(!$ids)return ['sent'=>0,'failed'=>0];
        try{
            $ph=implode(',',array_fill(0,count($ids),'?'));$pref=$this->preferenceColumn($type);
            $sql='SELECT ps.* FROM push_subscriptions ps JOIN users u ON u.id=ps.user_id WHERE ps.active=1 AND u.is_active=1 AND ps.user_id IN ('.$ph.')';
            if($pref!=='')$sql.=' AND COALESCE(u.'.$pref.',1)=1';
            $sql.=' ORDER BY ps.id';
            $st=$this->db->prepare($sql);$st->execute($ids);$rows=$st->fetchAll();
        }catch(Throwable $e){$this->logPush('error','push_select_users',$e->getMessage(),['type'=>$type]);return ['sent'=>0,'failed'=>0];}
        return $this->sendRows($rows,$payload,$type);
    }

    public function testUser(int $userId): array {
        if(!$this->supported())return ['sent'=>0,'failed'=>0,'unsupported'=>true];
        $st=$this->db->prepare('SELECT ps.* FROM push_subscriptions ps JOIN users u ON u.id=ps.user_id WHERE ps.active=1 AND u.is_active=1 AND ps.user_id=? ORDER BY ps.id');
        $st->execute([$userId]);
        return $this->sendRows($st->fetchAll(),['id'=>'push-test-'.time(),'type'=>'test','title'=>'ALFAMED CENTRAL','message'=>'Notificarile push functioneaza pe acest dispozitiv.','url'=>app_absolute_path('/profile')],'test');
    }

    private function sendRows(array $rows,array $payload,string $type=''): array {
        if(!$rows)return ['sent'=>0,'failed'=>0];
        $sent=0;$failed=0;$lastError='';
        foreach($rows as $row){
            try{
                $response=$this->sendOne($row,$payload);$status=(int)($response['status']??0);
                if($status>=200&&$status<300){$sent++;$this->touch((int)$row['id']);}
                else{
                    $failed++;$lastError='HTTP '.$status;$this->logPush('error','push_delivery','Serviciul push a raspuns HTTP '.$status,['type'=>$type,'subscription_id'=>(int)$row['id'],'provider_body'=>self::clip(trim(strip_tags((string)($response['body']??''))),300)]);
                    if(in_array($status,[404,410],true))$this->deactivate((int)$row['id']);
                }
            }catch(Throwable $e){$failed++;$lastError=$e->getMessage();$this->logPush('error','push_exception',$e->getMessage(),['type'=>$type,'subscription_id'=>(int)($row['id']??0)]);}
        }
        return ['sent'=>$sent,'failed'=>$failed,'last_error'=>$lastError];
    }

    private function preferenceColumn(string $type): string {
        return match($type){
            'new_order'=>'push_orders_enabled',
            'low_stock','out_of_stock'=>'push_stock_enabled',
            'chat_message'=>'push_chat_enabled',
            default=>'',
        };
    }

    private function logPush(string $level,string $action,string $message,array $context=[]): void {
        try{$st=$this->db->prepare('INSERT INTO sync_logs(channel_id,level,action,message,context_json) VALUES(NULL,?,?,?,?)');$st->execute([$level,$action,self::clip($message,700),$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}
    }

    private function typeEnabled(string $type): bool {
        if($type==='chat_message'){
            return $this->settings->bool('module.team-chat.push_enabled',true)
                && $this->settings->bool('module.team-chat.push_messages',true);
        }
        if(!$this->settings->bool('module.new-order-popup.push_enabled',true)) return false;
        return match($type){
            'new_order'=>$this->settings->bool('module.new-order-popup.push_new_orders',true),
            'low_stock'=>$this->settings->bool('module.new-order-popup.push_low_stock',true),
            'out_of_stock'=>$this->settings->bool('module.new-order-popup.push_out_of_stock',true),
            default=>true,
        };
    }

    private function sendOne(array $row,array $payload): array {
        $endpoint=trim((string)($row['endpoint']??''));
        $clientPublic=self::b64urlDecode((string)($row['p256dh']??''));
        $authSecret=self::b64urlDecode((string)($row['auth_key']??''));
        if($endpoint===''||strlen($clientPublic)!==65||strlen($authSecret)<8) throw new RuntimeException('Abonament push invalid.');
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new RuntimeException('Payload push invalid.');
        $encrypted=$this->encrypt($json,$clientPublic,$authSecret);
        $jwt=$this->vapidJwt($endpoint);
        $public=$this->publicKey();
        $headers=[
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: vapid t='.$jwt.', k='.$public,
        ];
        return Http::request('POST',$endpoint,$headers,$encrypted,15);
    }

    private function encrypt(string $payload,string $clientPublic,string $authSecret): string {
        $clientKey=openssl_pkey_get_public($this->publicPointPem($clientPublic));
        if($clientKey===false)throw new RuntimeException('Cheia publica push a browserului nu poate fi citita.');
        $serverKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if($serverKey===false)throw new RuntimeException('Nu pot genera cheia temporara Web Push.');
        $details=openssl_pkey_get_details($serverKey);
        $ec=is_array($details['ec']??null)?$details['ec']:[];
        $x=(string)($ec['x']??'');$y=(string)($ec['y']??'');
        if(strlen($x)!==32||strlen($y)!==32)throw new RuntimeException('Cheie temporara Web Push invalida.');
        $serverPublic="\x04".$x.$y;
        $shared=openssl_pkey_derive($clientKey,$serverKey,32);
        if(!is_string($shared)||strlen($shared)!==32)throw new RuntimeException('Schimbul ECDH pentru Web Push a esuat.');

        $prkKey=self::hkdfExtract($authSecret,$shared);
        $keyInfo="WebPush: info\x00".$clientPublic.$serverPublic;
        $ikm=self::hkdfExpand($prkKey,$keyInfo,32);
        $salt=random_bytes(16);
        $prk=self::hkdfExtract($salt,$ikm);
        $cek=self::hkdfExpand($prk,"Content-Encoding: aes128gcm\x00",16);
        $nonce=self::hkdfExpand($prk,"Content-Encoding: nonce\x00",12);
        $plain=$payload."\x02";
        $tag='';
        $cipher=openssl_encrypt($plain,'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
        if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Criptarea Web Push a esuat.');
        return $salt.pack('N',4096).chr(strlen($serverPublic)).$serverPublic.$cipher.$tag;
    }

    private function vapidJwt(string $endpoint): string {
        [$private,$public]=$this->ensureVapidKeys();
        $parts=parse_url($endpoint);$scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');
        if($scheme===''||$host==='')throw new RuntimeException('Endpoint push invalid pentru VAPID.');
        $aud=$scheme.'://'.$host.(isset($parts['port'])?':'.$parts['port']:'');
        $subject=trim((string)$this->settings->get('module.new-order-popup.push_contact','mailto:contact@alfamedclinic.ro'));
        if(!preg_match('#^(mailto:|https://)#i',$subject))$subject='mailto:contact@alfamedclinic.ro';
        $header=self::b64urlEncode(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES));
        $claims=self::b64urlEncode(json_encode(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$subject],JSON_UNESCAPED_SLASHES));
        $data=$header.'.'.$claims;$der='';
        if(!openssl_sign($data,$der,$private,OPENSSL_ALGO_SHA256))throw new RuntimeException('Semnarea VAPID a esuat.');
        return $data.'.'.self::b64urlEncode(self::ecdsaDerToJose($der,32));
    }

    private function ensureVapidKeys(): array {
        $private=(string)$this->settings->get('internal.push.vapid_private','');
        if($private!==''){
            $key=@openssl_pkey_get_private($private);
            if($key!==false){$details=openssl_pkey_get_details($key);$pub=$this->detailsPublicPoint($details);if($pub!=='')return [$private,self::b64urlEncode($pub)];}
        }
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if($key===false)throw new RuntimeException('Nu pot genera cheile VAPID.');
        $pem='';if(!openssl_pkey_export($key,$pem)||$pem==='')throw new RuntimeException('Nu pot exporta cheia VAPID privata.');
        $details=openssl_pkey_get_details($key);$pub=$this->detailsPublicPoint($details);if($pub==='')throw new RuntimeException('Nu pot exporta cheia VAPID publica.');
        $this->settings->set('internal.push.vapid_private',$pem,'internal');
        return [$pem,self::b64urlEncode($pub)];
    }

    private function detailsPublicPoint(array|false $details): string {
        if(!is_array($details))return '';$ec=is_array($details['ec']??null)?$details['ec']:[];$x=(string)($ec['x']??'');$y=(string)($ec['y']??'');
        return strlen($x)===32&&strlen($y)===32?"\x04".$x.$y:'';
    }

    private function publicPointPem(string $point): string {
        if(strlen($point)!==65||ord($point[0])!==4)throw new RuntimeException('Punct public P-256 invalid.');
        $prefix=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der=$prefix.$point;
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
    }

    private static function hkdfExtract(string $salt,string $ikm): string {return hash_hmac('sha256',$ikm,$salt,true);}
    private static function hkdfExpand(string $prk,string $info,int $length): string {
        $out='';$t='';$i=1;while(strlen($out)<$length){$t=hash_hmac('sha256',$t.$info.chr($i),$prk,true);$out.=$t;$i++;if($i>255)break;}return substr($out,0,$length);
    }

    private static function ecdsaDerToJose(string $der,int $partLength): string {
        $offset=0;if(ord($der[$offset++]??"\x00")!==0x30)throw new RuntimeException('Semnatura ECDSA invalida.');
        self::readDerLength($der,$offset);
        if(ord($der[$offset++]??"\x00")!==0x02)throw new RuntimeException('Semnatura ECDSA invalida (R).');
        $rLen=self::readDerLength($der,$offset);$r=substr($der,$offset,$rLen);$offset+=$rLen;
        if(ord($der[$offset++]??"\x00")!==0x02)throw new RuntimeException('Semnatura ECDSA invalida (S).');
        $sLen=self::readDerLength($der,$offset);$s=substr($der,$offset,$sLen);
        $r=ltrim($r,"\x00");$s=ltrim($s,"\x00");
        return str_pad(substr($r,-$partLength),$partLength,"\x00",STR_PAD_LEFT).str_pad(substr($s,-$partLength),$partLength,"\x00",STR_PAD_LEFT);
    }
    private static function readDerLength(string $der,int &$offset): int {
        $len=ord($der[$offset++]??"\x00");if(($len&0x80)===0)return $len;$bytes=$len&0x7f;$len=0;for($i=0;$i<$bytes;$i++)$len=($len<<8)|ord($der[$offset++]??"\x00");return $len;
    }
    private static function b64urlEncode(string|false $value): string {if(!is_string($value))return '';return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private static function b64urlDecode(string $value): string {$value=strtr($value,'-_','+/');$value.=str_repeat('=',(4-strlen($value)%4)%4);$decoded=base64_decode($value,true);return is_string($decoded)?$decoded:'';}
    private static function clip(string $value,int $limit): string {return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);}
    private function deactivate(int $id): void {try{$this->db->prepare('UPDATE push_subscriptions SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);}catch(Throwable){}}
    private function touch(int $id): void {try{$this->db->prepare('UPDATE push_subscriptions SET last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);}catch(Throwable){}}
}

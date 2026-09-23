<?php
namespace App\Services;

use App\Core\Database;
use App\Integrations\WooCommerceClient;
use PDO;
use RuntimeException;

final class WooWebhookService {
    private PDO $db; private SettingService $settings;
    public function __construct(){ $this->db=Database::connection();$this->settings=new SettingService($this->db); }
    public function secretFor(string $channelCode): string {$key='webhook.'.$channelCode.'.secret';$secret=(string)$this->settings->get($key,'');if($secret===''){$secret=bin2hex(random_bytes(32));$this->settings->set($key,$secret,'webhook');}return $secret;}
    public function endpointFor(string $channelCode): string {return app_absolute_path('/webhooks/woocommerce/'.$channelCode);}
    public function verify(string $channelCode,string $rawBody,string $signature): bool {if(!in_array($channelCode,['univera','alfamed'],true))return false;$secret=$this->secretFor($channelCode);if($signature==='')return false;$expected=base64_encode(hash_hmac('sha256',$rawBody,$secret,true));return hash_equals($expected,trim($signature));}

    public function ingest(string $channelCode,array $payload,string $topic='order.updated'): array {
        $st=$this->db->prepare("SELECT * FROM channels WHERE code=? AND type='woocommerce' LIMIT 1");$st->execute([$channelCode]);$channel=$st->fetch();if(!$channel)throw new RuntimeException('Canal WooCommerce necunoscut.');$topic=strtolower(trim($topic));
        if(str_starts_with($topic,'order.')){
            if($topic==='order.deleted'||strtolower((string)($payload['status']??''))==='trash'){(new SyncService())->ingestWooOrderDeleted($channel,(string)($payload['id']??''));return ['type'=>'order','deleted'=>true,'id'=>(string)($payload['id']??'')];}
            $r=(new SyncService())->ingestWooOrder($channel,$payload,true);return ['type'=>'order']+$r;
        }
        if(str_starts_with($topic,'product.')){
            $service=new ProductService($this->db);$id=(string)($payload['id']??'');
            if($topic==='product.deleted'||strtolower((string)($payload['status']??''))==='trash'){$service->markRemoteDeleted($channelCode,$id);return ['type'=>'product','deleted'=>true,'id'=>$id];}
            $r=$service->upsertWooProduct($channel,$payload);return ['type'=>'product']+$r;
        }
        return ['type'=>'ignored','topic'=>$topic];
    }

    public function configureAll(): array {$channels=$this->db->query("SELECT * FROM channels WHERE enabled=1 AND type='woocommerce' ORDER BY id")->fetchAll();$out=[];foreach($channels as $channel)$out[$channel['code']]=$this->configureChannel($channel);return $out;}
    public function configureChannel(array $channel): array {
        $client=ChannelFactory::client((string)$channel['code']);if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea '.$channel['name'].' nu este configurata.');$delivery=$this->endpointFor((string)$channel['code']);$secret=$this->secretFor((string)$channel['code']);$existing=$client->readWebhooks(['per_page'=>100]);
        $wanted=['order.created','order.updated','order.deleted','product.created','product.updated','product.deleted'];$done=[];
        foreach($wanted as $topic){$found=null;foreach($existing as $hook){if(($hook['topic']??'')===$topic&&($hook['delivery_url']??'')===$delivery){$found=$hook;break;}}$payload=['name'=>'ALFAMED CENTRAL - '.$topic,'topic'=>$topic,'delivery_url'=>$delivery,'secret'=>$secret,'status'=>'active'];$done[$topic]=$found?$client->updateWebhook((int)$found['id'],$payload):$client->createWebhook($payload);}
        $this->settings->set('webhook.'.$channel['code'].'.configured_at',date('Y-m-d H:i:s'),'webhook');return ['ok'=>true,'delivery_url'=>$delivery,'topics'=>array_keys($done)];
    }
}

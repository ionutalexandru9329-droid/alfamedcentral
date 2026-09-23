<?php
namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class NotificationService {
    private PDO $db;
    private SettingService $settings;
    public function __construct(?PDO $db=null){ $this->db=$db?:Database::connection();$this->settings=new SettingService($this->db); }

    public function newOrder(int $orderId): void {
        $st=$this->db->prepare('SELECT o.id,o.code,o.total,o.currency,o.customer_name,c.id channel_id,c.code channel_code,c.name channel_name FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.id=?');
        $st->execute([$orderId]);$o=$st->fetch();if(!$o)return;
        $vars=[
            'order'=>(string)$o['code'],
            'channel'=>(string)$o['channel_name'],
            'customer'=>(string)($o['customer_name']?:'Client'),
            'total'=>number_format((float)$o['total'],2,',','.'),
            'currency'=>(string)$o['currency'],
        ];
        $title=$this->template('new_order_title','Comanda noua · {channel}',$vars);
        $message=$this->template('new_order_message','{order} · {customer} · {total} {currency}',$vars);
        try{
            $q=$this->db->prepare('INSERT INTO notifications(type,title,message,order_id,channel_id,product_id,severity,data_json) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute(['new_order',$title,$message,$orderId,(int)$o['channel_id'],null,'info',json_encode(['order_code'=>$o['code'],'total'=>$o['total'],'currency'=>$o['currency'],'channel'=>$o['channel_name'],'channel_code'=>$o['channel_code']],JSON_UNESCAPED_UNICODE)]);
            $id=(int)$this->db->lastInsertId();
            if($id>0)$this->push('new_order',$id,$title,$message,app_absolute_path('/orders/'.$orderId),$orderId,null);
        }catch(Throwable){}
    }

    public function stockChanged(array $payload): void {
        $channel=(string)($payload['channel_code']??'');if(!in_array($channel,['univera','alfamed'],true))return;
        if(!$this->settings->bool('module.new-order-popup.'.$channel.'_stock',true))return;
        $old=array_key_exists('old_stock',$payload)&&$payload['old_stock']!==null?(int)$payload['old_stock']:null;$new=(int)($payload['new_stock']??0);$threshold=max(1,min(1000,(int)$this->settings->get('module.new-order-popup.low_stock_threshold','5')));$initial=(bool)($payload['new_mapping']??false);
        if($initial&&!$this->settings->bool('module.new-order-popup.alert_initial_stock',false))return;
        $type='';$severity='warning';
        if($new<=0 && ($old===null||$old>0)){$type='out_of_stock';$severity='danger';if(!$this->settings->bool('module.new-order-popup.out_of_stock_enabled',true))return;}
        elseif($new>0 && $new<=$threshold && ($old===null||$old>$threshold)){$type='low_stock';if(!$this->settings->bool('module.new-order-popup.low_stock_enabled',true))return;}
        if($type==='')return;
        $productId=(int)($payload['product_id']??0);$channelId=(int)($payload['channel_id']??0);$name=(string)($payload['product_name']??'Produs');$channelName=(string)($payload['channel_name']??$channel);
        $vars=['product'=>$name,'stock'=>(string)$new,'threshold'=>(string)$threshold,'channel'=>$channelName];
        if($type==='out_of_stock'){
            $title=$this->template('out_of_stock_title','Produs fara stoc · {channel}',$vars);
            $message=$this->template('out_of_stock_message','{product} nu mai are stoc.',$vars);
        }else{
            $title=$this->template('low_stock_title','Stoc aproape epuizat · {channel}',$vars);
            $message=$this->template('low_stock_message','{product} mai are {stock} buc. in stoc.',$vars);
        }
        try{
            $q=$this->db->prepare('INSERT INTO notifications(type,title,message,order_id,channel_id,product_id,severity,data_json) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$type,$title,$message,null,$channelId,$productId,$severity,json_encode(['product_id'=>$productId,'stock'=>$new,'old_stock'=>$old,'threshold'=>$threshold,'channel'=>$channelName,'channel_code'=>$channel],JSON_UNESCAPED_UNICODE)]);
            $id=(int)$this->db->lastInsertId();
            if($id>0)$this->push($type,$id,$title,$message,app_absolute_path('/products/'.$productId.'?source='.rawurlencode($channel)),null,$productId);
        }catch(Throwable){}
    }

    public function latestForUser(int $userId,int $limit=30): array {
        $limit=max(1,min(100,$limit));$sql='SELECT n.*,CASE WHEN nr.notification_id IS NULL THEN 0 ELSE 1 END is_read,o.code order_code,p.name product_name,c.name channel_name,c.code channel_code FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? LEFT JOIN orders o ON o.id=n.order_id LEFT JOIN products p ON p.id=n.product_id LEFT JOIN channels c ON c.id=n.channel_id WHERE n.created_at>=? ORDER BY n.id DESC LIMIT '.$limit;
        $st=$this->db->prepare($sql);$st->execute([$userId,date('Y-m-d H:i:s',strtotime('-30 days'))]);$items=$st->fetchAll();foreach($items as &$item)$item=$this->decorate($item);unset($item);return $items;
    }

    public function popupForUser(int $userId,int $limit=10): array {
        $limit=max(1,min(30,$limit));$types=[];if($this->settings->bool('module.new-order-popup.popup_new_orders',true))$types[]='new_order';if($this->settings->bool('module.new-order-popup.popup_stock',true)){$types[]='low_stock';$types[]='out_of_stock';}if(!$types)return [];
        $ph=implode(',',array_fill(0,count($types),'?'));$sql='SELECT n.*,o.code order_code,p.name product_name,c.name channel_name,c.code channel_code FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? LEFT JOIN orders o ON o.id=n.order_id LEFT JOIN products p ON p.id=n.product_id LEFT JOIN channels c ON c.id=n.channel_id WHERE nr.notification_id IS NULL AND n.created_at>=? AND n.type IN ('.$ph.') ORDER BY n.id ASC LIMIT '.$limit;
        $st=$this->db->prepare($sql);$st->execute(array_merge([$userId,date('Y-m-d H:i:s',strtotime('-15 minutes'))],$types));$items=$st->fetchAll();foreach($items as &$item)$item=$this->decorate($item);unset($item);return $items;
    }

    public function unreadForUser(int $userId,int $limit=10): array { return $this->popupForUser($userId,$limit); }
    public function unreadCount(int $userId): int {$st=$this->db->prepare('SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? WHERE nr.notification_id IS NULL AND n.created_at>=?');$st->execute([$userId,date('Y-m-d H:i:s',strtotime('-30 days'))]);return (int)$st->fetchColumn();}

    public function markRead(int $userId,array $ids): void {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));if(!$ids)return;$driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='mysql'?'INSERT IGNORE INTO notification_reads(user_id,notification_id,read_at) VALUES(?,?,CURRENT_TIMESTAMP)':'INSERT OR IGNORE INTO notification_reads(user_id,notification_id,read_at) VALUES(?,?,CURRENT_TIMESTAMP)';$st=$this->db->prepare($sql);foreach($ids as $id)$st->execute([$userId,$id]);
    }
    public function markAllRead(int $userId): void {$st=$this->db->prepare('SELECT id FROM notifications WHERE created_at>=?');$st->execute([date('Y-m-d H:i:s',strtotime('-30 days'))]);$this->markRead($userId,$st->fetchAll(PDO::FETCH_COLUMN));}
    public function markOrderRead(int $userId,int $orderId): void {$st=$this->db->prepare('SELECT id FROM notifications WHERE order_id=?');$st->execute([$orderId]);$this->markRead($userId,$st->fetchAll(PDO::FETCH_COLUMN));}

    private function decorate(array $item): array {
        $item['is_read']=(bool)($item['is_read']??false);$item['icon']=match((string)$item['type']){'new_order'=>'🛒','low_stock'=>'⚠️','out_of_stock'=>'⛔',default=>'🔔'};$item['url']='';
        if(!empty($item['order_id']))$item['url']=app_path('/orders/'.(int)$item['order_id']);
        elseif(!empty($item['product_id']))$item['url']=app_path('/products/'.(int)$item['product_id'].(!empty($item['channel_code'])?'?source='.rawurlencode((string)$item['channel_code']):''));
        $item['time_label']=$this->timeLabel((string)($item['created_at']??''));return $item;
    }

    private function template(string $key,string $default,array $vars): string {
        $value=trim((string)$this->settings->get('module.new-order-popup.'.$key,$default));
        if($value==='')$value=$default;
        foreach($vars as $name=>$replacement)$value=str_replace('{'.$name.'}',(string)$replacement,$value);
        return $value;
    }

    private function push(string $type,int $id,string $title,string $message,string $url,?int $orderId=null,?int $productId=null): void {
        try{(new WebPushService($this->db))->broadcast(['id'=>$id,'type'=>$type,'title'=>$title,'message'=>$message,'url'=>$url,'order_id'=>$orderId,'product_id'=>$productId],$type);}catch(Throwable){}
    }

    private function timeLabel(string $date): string {$ts=strtotime($date);if(!$ts)return '';$d=max(0,time()-$ts);if($d<60)return 'acum';if($d<3600)return (int)floor($d/60).' min';if($d<86400)return (int)floor($d/3600).' h';return date('d.m H:i',$ts);}
}

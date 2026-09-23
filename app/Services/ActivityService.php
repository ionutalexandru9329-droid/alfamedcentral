<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Lightweight user activity journal.
 *
 * The journal stores snapshots of the user's display data so historical rows
 * remain readable even when an account is later deleted. It intentionally
 * never stores passwords, secrets, message bodies or uploaded file contents.
 */
final class ActivityService {
    private PDO $db;
    private static bool $auditRegistered=false;
    private static bool $auditFailed=false;
    private static bool $auditSkip=false;

    public function __construct(?PDO $db=null){$this->db=$db?:Database::connection();}

    public static function beginRequestAudit(string $path,string $method,?PDO $db=null): void {
        if(self::$auditRegistered||!Auth::check()) return;
        $method=strtoupper($method);
        $classification=self::classify($path,$method,$_GET,$_POST);
        if(!$classification) return;
        self::$auditRegistered=true;self::$auditFailed=false;self::$auditSkip=false;
        $user=Auth::user()?:[];
        $snapshot=[
            'id'=>(int)($user['id']??0),'name'=>(string)($user['name']??'Utilizator'),'email'=>(string)($user['email']??''),'role'=>(string)($user['role']??''),
        ];
        $query=self::safeContext($classification['context']??[]);
        register_shutdown_function(static function() use($db,$snapshot,$classification,$path,$method,$query):void{
            if(self::$auditSkip||($snapshot['id']??0)<=0)return;
            try{
                $service=new self($db?:Database::connection());
                $label=$service->resolveLabel((string)$classification['label'],$classification,$query);
                $service->insert([
                    'user_id'=>$snapshot['id'],'user_name'=>$snapshot['name'],'user_email'=>$snapshot['email'],'user_role'=>$snapshot['role'],
                    'action_key'=>(string)$classification['action'],'action_label'=>$label,'category'=>(string)$classification['category'],
                    'entity_type'=>(string)($classification['entity_type']??''),'entity_id'=>(string)($classification['entity_id']??''),
                    'route'=>$path,'method'=>$method,'status'=>self::$auditFailed?'failed':'success','context'=>$query,
                ]);
            }catch(Throwable){}
        });
    }

    public static function markCurrentFailed(): void {self::$auditFailed=true;}
    public static function skipCurrent(): void {self::$auditSkip=true;}

    public function record(string $action,string $label,string $category='system',array $context=[],string $entityType='',string $entityId='',string $status='success'): void {
        $u=Auth::user()?:[];$id=(int)($u['id']??0);if($id<=0)return;
        $this->insert([
            'user_id'=>$id,'user_name'=>(string)($u['name']??'Utilizator'),'user_email'=>(string)($u['email']??''),'user_role'=>(string)($u['role']??''),
            'action_key'=>$action,'action_label'=>$label,'category'=>$category,'entity_type'=>$entityType,'entity_id'=>$entityId,
            'route'=>'','method'=>'SYSTEM','status'=>$status,'context'=>self::safeContext($context),
        ]);
    }

    public function list(array $filters=[],int $limit=50,int $offset=0): array {
        $where=['1=1'];$params=[];
        $userId=(int)($filters['user_id']??0);if($userId>0){$where[]='user_id=?';$params[]=$userId;}
        $category=trim((string)($filters['category']??''));if($category!==''){$where[]='category=?';$params[]=$category;}
        $status=trim((string)($filters['status']??''));if(in_array($status,['success','failed'],true)){$where[]='status=?';$params[]=$status;}
        $days=max(0,min(3650,(int)($filters['days']??30)));if($days>0){$where[]='created_at>=?';$params[]=date('Y-m-d H:i:s',time()-$days*86400);}
        $q=trim((string)($filters['q']??''));if($q!==''){$where[]='(user_name LIKE ? OR user_email LIKE ? OR action_label LIKE ? OR entity_id LIKE ?)';for($i=0;$i<4;$i++)$params[]='%'.$q.'%';}
        $limit=max(1,min(200,$limit));$offset=max(0,$offset);
        $sql='SELECT * FROM user_activity_logs WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset;
        $st=$this->db->prepare($sql);$st->execute($params);return $st->fetchAll();
    }

    public function count(array $filters=[]): int {
        $where=['1=1'];$params=[];
        $userId=(int)($filters['user_id']??0);if($userId>0){$where[]='user_id=?';$params[]=$userId;}
        $category=trim((string)($filters['category']??''));if($category!==''){$where[]='category=?';$params[]=$category;}
        $status=trim((string)($filters['status']??''));if(in_array($status,['success','failed'],true)){$where[]='status=?';$params[]=$status;}
        $days=max(0,min(3650,(int)($filters['days']??30)));if($days>0){$where[]='created_at>=?';$params[]=date('Y-m-d H:i:s',time()-$days*86400);}
        $q=trim((string)($filters['q']??''));if($q!==''){$where[]='(user_name LIKE ? OR user_email LIKE ? OR action_label LIKE ? OR entity_id LIKE ?)';for($i=0;$i<4;$i++)$params[]='%'.$q.'%';}
        $st=$this->db->prepare('SELECT COUNT(*) FROM user_activity_logs WHERE '.implode(' AND ',$where));$st->execute($params);return (int)$st->fetchColumn();
    }

    public function recentOperational(int $limit=7): array {
        $limit=max(1,min(20,$limit));
        $st=$this->db->query("SELECT * FROM user_activity_logs WHERE status='success' AND method<>'GET' AND category IN ('orders','products','documents','awb') ORDER BY id DESC LIMIT {$limit}");
        return $st->fetchAll();
    }

    public function usersForFilter(): array {
        return $this->db->query("SELECT id,name,email,is_active,deleted_at FROM users ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END,name")->fetchAll();
    }

    private function insert(array $row): void {
        $context=(array)($row['context']??[]);
        $ip=trim((string)($_SERVER['REMOTE_ADDR']??''));$ua=trim((string)($_SERVER['HTTP_USER_AGENT']??''));
        $sql='INSERT INTO user_activity_logs(user_id,user_name,user_email,user_role,action_key,action_label,category,entity_type,entity_id,route,method,status,ip_address,user_agent,context_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->db->prepare($sql)->execute([
            (int)$row['user_id'],self::clip((string)$row['user_name'],150),self::clip((string)$row['user_email'],190),self::clip((string)$row['user_role'],30),
            self::clip((string)$row['action_key'],100),self::clip((string)$row['action_label'],500),self::clip((string)$row['category'],50),
            self::clip((string)($row['entity_type']??''),50)?:null,self::clip((string)($row['entity_id']??''),190)?:null,
            self::clip((string)($row['route']??''),500),self::clip((string)($row['method']??''),15),self::clip((string)($row['status']??'success'),20),
            self::clip($ip,64)?:null,self::clip($ua,500)?:null,$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
        ]);
    }

    private function resolveLabel(string $label,array $classification,array $context): string {
        $type=(string)($classification['entity_type']??'');$id=(string)($classification['entity_id']??'');
        if($type==='order'&&ctype_digit($id)){
            try{$st=$this->db->prepare('SELECT code FROM orders WHERE id=?');$st->execute([(int)$id]);$code=(string)($st->fetchColumn()?:'');if($code!=='')$label=str_replace('{order}',''.$code,$label);}catch(Throwable){}
        }
        if($type==='product'&&ctype_digit($id)){
            try{$st=$this->db->prepare('SELECT sku,name FROM products WHERE id=?');$st->execute([(int)$id]);$p=$st->fetch();if($p){$ref=trim((string)($p['sku']??''));if($ref==='')$ref=trim((string)($p['name']??''));$label=str_replace('{product}',$ref?:('#'.$id),$label);}}catch(Throwable){}
        }
        if($type==='user'&&ctype_digit($id)){
            try{$st=$this->db->prepare('SELECT name FROM users WHERE id=?');$st->execute([(int)$id]);$name=(string)($st->fetchColumn()?:'');if($name!=='')$label=str_replace('{user}',$name,$label);}catch(Throwable){}
        }
        foreach($context as $k=>$v){if(is_scalar($v))$label=str_replace('{'.$k.'}',(string)$v,$label);}
        return str_replace(['{order}','{product}','{user}'],['comanda #'.$id,'produsul #'.$id,'utilizatorul #'.$id],$label);
    }

    private static function classify(string $path,string $method,array $get,array $post): ?array {
        if(str_starts_with($path,'/assets/')||str_starts_with($path,'/uploads/'))return null;
        if($path==='/api/notifications'||$path==='/api/chat/summary'||$path==='/api/chat/heartbeat'||preg_match('#^/api/chat/messages/\d+$#',$path)&&$method==='GET')return null;
        $context=[];
        if($method==='GET'){
            if($path==='/')return self::c('dashboard.view','a deschis Dashboard-ul','navigation');
            if($path==='/orders')return self::c('orders.list','a deschis lista de comenzi','navigation');
            if(preg_match('#^/orders/(\d+)$#',$path,$m))return self::c('orders.view','a deschis {order}','orders','order',$m[1]);
            if($path==='/products')return self::c('products.list','a deschis catalogul de produse','navigation');
            if(preg_match('#^/products/(\d+)$#',$path,$m))return self::c('products.view','a deschis produsul {product}','products','product',$m[1]);
            if($path==='/statistics')return self::c('statistics.view','a deschis Statisticile','navigation');
            if($path==='/users')return self::c('users.list','a deschis Utilizatori','users');
            if($path==='/users/activity')return self::c('users.activity','a deschis Jurnalul de activitate','users');
            if(preg_match('#^/users/(\d+)$#',$path,$m))return self::c('users.view','a deschis profilul administrativ al lui {user}','users','user',$m[1]);
            if(str_starts_with($path,'/settings/modules/'))return self::c('settings.module.view','a deschis setarile unui modul','settings');
            if(str_starts_with($path,'/settings'))return self::c('settings.view','a deschis Setarile','settings');
            if($path==='/modules')return self::c('modules.view','a deschis Module','settings');
            if($path==='/awb')return self::c('awb.view','a deschis lista AWB','navigation');
            if($path==='/scan')return self::c('scan.view','a deschis Scanare AWB','navigation');
            if($path==='/profile')return self::c('profile.view','a deschis Profilul meu','navigation');
            return null;
        }
        if($method!=='POST')return null;

        if(preg_match('#^/orders/(\d+)/status$#',$path,$m)){
            $status=trim((string)($post['status']??''));$context=['status'=>$status];
            if(in_array(strtolower($status),['completed','delivered','finalized','finished'],true))return self::c('order.completed','a finalizat {order}','orders','order',$m[1],$context);
            return self::c('order.status','a schimbat statusul pentru {order} in {status}','orders','order',$m[1],$context);
        }
        if(preg_match('#^/orders/(\d+)/cancel$#',$path,$m))return self::c('order.cancelled','a anulat {order}','orders','order',$m[1]);
        if($path==='/orders/actions'){
            $action=trim((string)($post['action']??''));$single=(int)($post['single_order_id']??0);if($single>0)$action=trim((string)($post['row_action'][$single]??$action));
            $ids=array_values(array_filter(array_map('intval',(array)($post['order_ids']??[]))));if($single>0)$ids=[$single];$count=count(array_unique($ids));
            $context=['count'=>$count,'action'=>$action];
            $map=['invoice'=>'a generat facturi pentru {count} comenzi','create_invoice'=>'a generat facturi pentru {count} comenzi','proforma'=>'a generat proforme pentru {count} comenzi','notice'=>'a generat avize pentru {count} comenzi','storno'=>'a generat storno pentru {count} comenzi','generate_awb'=>'a generat AWB pentru {count} comenzi','generate_awb_auto'=>'a generat AWB pentru {count} comenzi','download_awb'=>'a descarcat AWB pentru {count} comenzi'];
            return self::c('orders.bulk.'.($action?:'action'),$map[$action]??'a executat actiunea {action} pentru {count} comenzi',in_array($action,['invoice','create_invoice','proforma','notice','storno'],true)?'documents':(str_contains($action,'awb')?'awb':'orders'),'','',$context);
        }
        if($path==='/products/new')return self::c('product.created','a creat un produs nou','products');
        if(preg_match('#^/products/(\d+)/save$#',$path,$m))return self::c('product.updated','a actualizat produsul {product}','products','product',$m[1]);
        if(preg_match('#^/products/(\d+)/quick-save$#',$path,$m))return self::c('product.quick_updated','a facut o modificare rapida la {product}','products','product',$m[1]);
        if(preg_match('#^/products/(\d+)/transfer$#',$path,$m))return self::c('product.transferred','a transferat produsul {product} intre magazine','products','product',$m[1]);
        if($path==='/products/actions'){ $ids=array_values(array_filter(array_map('intval',(array)($post['product_ids']??[]))));return self::c('products.bulk','a executat o actiune in masa pentru {count} produse','products','','',['count'=>count(array_unique($ids)),'action'=>(string)($post['action']??'')]); }
        if($path==='/products/sync'||$path==='/api/products/sync')return self::c('products.sync','a sincronizat produsele cu magazinele','products');
        if($path==='/sync')return self::c('orders.sync','a pornit sincronizarea manuala a comenzilor','orders');
        if($path==='/statistics/sync')return self::c('statistics.sync','a actualizat datele statistice','orders');
        if($path==='/users/new')return self::c('user.created','a creat utilizatorul {name}','users','','',['name'=>trim((string)($post['name']??''))]);
        if(preg_match('#^/users/(\d+)/toggle$#',$path,$m))return self::c('user.state','a '.(((int)($post['set_active']??0))===1?'reactivat':'suspendat').' utilizatorul {user}','users','user',$m[1]);
        if(preg_match('#^/users/(\d+)/delete$#',$path,$m))return self::c('user.deleted','a sters utilizatorul {user}','users','user',$m[1]);
        if(preg_match('#^/users/(\d+)$#',$path,$m))return self::c('user.updated','a actualizat utilizatorul {user}','users','user',$m[1]);
        if(str_starts_with($path,'/settings/modules/'))return self::c('settings.module.changed','a modificat setarile unui modul','settings');
        if(str_starts_with($path,'/settings/integrations/'))return self::c('settings.integration.changed','a modificat sau testat o integrare','settings');
        if(str_starts_with($path,'/settings'))return self::c('settings.changed','a modificat setarile ALFAMED CENTRAL','settings');
        if(str_starts_with($path,'/modules/'))return self::c('module.changed','a modificat starea unui modul','settings');
        if(preg_match('#^/api/chat/messages/(\d+)$#',$path,$m))return self::c('chat.message','a trimis un mesaj in chat','chat','chat_room',$m[1]);
        if(preg_match('#^/api/chat/message/(\d+)/(edit|delete)$#',$path,$m))return self::c('chat.message.'.$m[2],'a '.($m[2]==='edit'?'editat':'sters').' un mesaj din chat','chat','chat_message',$m[1]);
        if($path==='/profile')return self::c('profile.updated','si-a actualizat profilul','users');
        return self::c('request.post','a executat o operatiune in ALFAMED CENTRAL','system','','',['route'=>$path]);
    }

    private static function c(string $action,string $label,string $category,string $entityType='',string $entityId='',array $context=[]): array{return compact('action','label','category','entityType','entityId','context')+['entity_type'=>$entityType,'entity_id'=>$entityId];}
    private static function safeContext(array $context): array {
        $out=[];foreach($context as $k=>$v){$key=(string)$k;if(preg_match('/pass|secret|token|key|message|content|description/i',$key))continue;if(is_scalar($v)||$v===null)$out[$key]=self::clip((string)$v,190);elseif(is_array($v))$out[$key]=count($v).' elemente';}return $out;
    }
    private static function clip(string $value,int $max): string {return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
}

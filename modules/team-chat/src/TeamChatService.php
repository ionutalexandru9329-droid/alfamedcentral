<?php
namespace AlfamedModules\TeamChat;

use App\Core\Database;
use App\Services\SettingService;
use App\Services\WebPushService;
use PDO;
use RuntimeException;

final class TeamChatService {
    private PDO $db;
    private SettingService $settings;
    private string $root;
    private const MODIFY_WINDOW_SECONDS = 300;

    public function __construct(?PDO $db=null){
        $this->db=$db ?: Database::connection();
        $this->settings=new SettingService($this->db);
        $this->root=dirname(__DIR__,3);
    }

    public function users(int $currentUserId): array {
        $st=$this->db->prepare('SELECT id,name,email,role,job_title,avatar_path,last_active_at,is_active FROM users WHERE id<>? AND is_active=1 AND deleted_at IS NULL ORDER BY name');
        $st->execute([$currentUserId]);
        $window=max(30,min(600,(int)$this->settings->get('module.team-chat.online_window_seconds','90')));
        $now=time();$out=[];
        foreach($st->fetchAll() as $u){
            $last=(string)($u['last_active_at']??'');$ts=$last!==''?strtotime($last):false;
            $u['online']=$ts!==false && ($now-$ts)<=$window;
            $u['last_seen_label']=$u['online']?'Online':$this->lastSeenLabel($ts?:0);
            $u['avatar_url']=!empty($u['avatar_path'])?app_path('/profile/avatar/'.(int)$u['id']):'';
            $out[]=$u;
        }
        return $out;
    }

    public function rooms(int $userId): array {
        $sql="SELECT r.*,rm.muted,
            (SELECT CASE WHEN m.deleted_at IS NOT NULL THEN 'Mesaj sters' WHEN COALESCE(m.message,'')<>'' THEN m.message WHEN m.attachment_name IS NOT NULL THEN 'Atasament' ELSE '' END FROM chat_messages m WHERE m.room_id=r.id ORDER BY m.id DESC LIMIT 1) last_message,
            (SELECT m.id FROM chat_messages m WHERE m.room_id=r.id ORDER BY m.id DESC LIMIT 1) last_message_id,
            (SELECT m.sender_id FROM chat_messages m WHERE m.room_id=r.id ORDER BY m.id DESC LIMIT 1) last_sender_id,
            (SELECT m.created_at FROM chat_messages m WHERE m.room_id=r.id ORDER BY m.id DESC LIMIT 1) last_message_at,
            (SELECT COUNT(*) FROM chat_messages m LEFT JOIN chat_message_reads mr ON mr.message_id=m.id AND mr.user_id=? WHERE m.room_id=r.id AND m.sender_id<>? AND mr.message_id IS NULL) unread_count
            FROM chat_rooms r JOIN chat_room_members rm ON rm.room_id=r.id
            WHERE rm.user_id=? ORDER BY COALESCE(last_message_at,r.created_at) DESC,r.id DESC";
        $st=$this->db->prepare($sql);$st->execute([$userId,$userId,$userId]);$rooms=$st->fetchAll();
        foreach($rooms as &$room){
            $members=$this->roomMembers((int)$room['id']);$room['members']=$members;$room['muted']=(bool)($room['muted']??false);
            if(($room['type']??'')==='direct'){
                foreach($members as $m) if((int)$m['id']!==$userId){$room['display_name']=$m['name'];$room['peer_user_id']=(int)$m['id'];$room['avatar_url']=!empty($m['avatar_path'])?app_path('/profile/avatar/'.(int)$m['id']):'';break;}
                if(empty($room['display_name']))$room['display_name']='Conversatie';
            } else $room['display_name']=(string)($room['name']?:'Grup');
        }unset($room);
        return $rooms;
    }

    public function directRoom(int $userId,int $otherUserId): int {
        if($userId<=0||$otherUserId<=0||$userId===$otherUserId) throw new RuntimeException('Utilizator invalid pentru conversatie.');
        $q=$this->db->prepare('SELECT id FROM users WHERE id=? AND is_active=1 AND deleted_at IS NULL');$q->execute([$otherUserId]);if(!$q->fetchColumn())throw new RuntimeException('Utilizatorul nu exista sau este inactiv.');
        $a=min($userId,$otherUserId);$b=max($userId,$otherUserId);$key=$a.':'.$b;
        $q=$this->db->prepare("SELECT id FROM chat_rooms WHERE type='direct' AND direct_key=? LIMIT 1");$q->execute([$key]);$existing=(int)($q->fetchColumn()?:0);if($existing)return $existing;
        $this->db->beginTransaction();
        try{
            try{$st=$this->db->prepare("INSERT INTO chat_rooms(type,name,direct_key,created_by) VALUES('direct',NULL,?,?)");$st->execute([$key,$userId]);$roomId=(int)$this->db->lastInsertId();}
            catch(\Throwable $e){$q=$this->db->prepare("SELECT id FROM chat_rooms WHERE type='direct' AND direct_key=? LIMIT 1");$q->execute([$key]);$roomId=(int)($q->fetchColumn()?:0);if(!$roomId)throw $e;}
            $this->addMember($roomId,$userId);$this->addMember($roomId,$otherUserId);
            $this->db->commit();return $roomId;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function createGroup(int $creatorId,string $name,array $memberIds): int {
        if(!$this->settings->bool('module.team-chat.allow_group_creation',true))throw new RuntimeException('Crearea grupurilor este dezactivata din setarile modulului.');
        $name=trim($name);if($name==='')throw new RuntimeException('Numele grupului este obligatoriu.');if(strlen($name)>190)throw new RuntimeException('Numele grupului este prea lung.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$memberIds),fn($v)=>$v>0&&$v!==$creatorId)));if(!$ids)throw new RuntimeException('Selecteaza cel putin un utilizator pentru grup.');
        $st=$this->db->prepare("INSERT INTO chat_rooms(type,name,direct_key,created_by) VALUES('group',?,NULL,?)");$st->execute([$name,$creatorId]);$roomId=(int)$this->db->lastInsertId();$this->addMember($roomId,$creatorId);foreach($ids as $id){$q=$this->db->prepare('SELECT id FROM users WHERE id=? AND is_active=1 AND deleted_at IS NULL');$q->execute([$id]);if($q->fetchColumn())$this->addMember($roomId,$id);}return $roomId;
    }

    public function messages(int $roomId,int $userId,int $afterId=0,int $limit=80): array {
        $this->assertMember($roomId,$userId);$limit=max(1,min(150,$limit));
        if($afterId>0){$st=$this->db->prepare('SELECT m.*,u.name sender_name,u.job_title sender_job,u.avatar_path sender_avatar_path FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.room_id=? AND m.id>? ORDER BY m.id ASC LIMIT '.$limit);$st->execute([$roomId,$afterId]);$rows=$st->fetchAll();}
        else {$st=$this->db->prepare('SELECT * FROM (SELECT m.*,u.name sender_name,u.job_title sender_job,u.avatar_path sender_avatar_path FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.room_id=? ORDER BY m.id DESC LIMIT '.$limit.') x ORDER BY id ASC');$st->execute([$roomId]);$rows=$st->fetchAll();}
        $this->markRoomRead($roomId,$userId);$now=time();
        foreach($rows as &$r){
            $r['mine']=(int)$r['sender_id']===$userId;$r['deleted']=!empty($r['deleted_at']);$r['edited']=!empty($r['edited_at']);
            $created=strtotime((string)($r['created_at']??''))?:0;$remaining=max(0,self::MODIFY_WINDOW_SECONDS-($now-$created));
            $r['can_edit']=$r['mine']&&!$r['deleted']&&$remaining>0&&trim((string)($r['message']??''))!=='';
            $r['can_delete']=$r['mine']&&!$r['deleted']&&$remaining>0;
            $r['modify_seconds_left']=$remaining;$r['sender_avatar_url']=!empty($r['sender_avatar_path'])?app_path('/profile/avatar/'.(int)$r['sender_id']):'';
            if($r['deleted']){$r['message']=null;$r['attachment_url']='';$r['attachment_name']=null;$r['attachment_mime']=null;}
            else $r['attachment_url']=!empty($r['attachment_path'])?app_path('/chat/files/'.$r['id']):'';
        }unset($r);
        return $rows;
    }

    public function send(int $roomId,int $userId,string $message,array $file=[]): array {
        $this->assertMember($roomId,$userId);$message=trim($message);if(strlen($message)>5000)throw new RuntimeException('Mesajul poate avea maximum 5000 de caractere.');
        $attachment=$this->storeAttachment($file);
        if($message===''&&!$attachment)throw new RuntimeException('Scrie un mesaj sau ataseaza un fisier.');
        $st=$this->db->prepare('INSERT INTO chat_messages(room_id,sender_id,message,attachment_name,attachment_path,attachment_mime,attachment_size) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$roomId,$userId,$message?:null,$attachment['name']??null,$attachment['path']??null,$attachment['mime']??null,$attachment['size']??null]);
        $id=(int)$this->db->lastInsertId();$this->markMessageRead($id,$userId);
        $this->db->prepare('UPDATE chat_rooms SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$roomId]);
        $this->pushMessage($roomId,$userId,$id,$message,$attachment);
        return ['id'=>$id];
    }

    public function editMessage(int $messageId,int $userId,string $message): void {
        $message=trim($message);if($message==='')throw new RuntimeException('Mesajul nu poate fi gol.');if(strlen($message)>5000)throw new RuntimeException('Mesajul poate avea maximum 5000 de caractere.');
        $row=$this->ownedMessage($messageId,$userId);$this->assertWithinModifyWindow($row);
        if(!empty($row['deleted_at']))throw new RuntimeException('Mesajul a fost deja sters.');
        $this->db->prepare('UPDATE chat_messages SET message=?,edited_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$message,$messageId]);
    }

    public function deleteMessage(int $messageId,int $userId): void {
        $row=$this->ownedMessage($messageId,$userId);$this->assertWithinModifyWindow($row);if(!empty($row['deleted_at']))return;
        if(!empty($row['attachment_path'])){@unlink($this->root.'/storage/chat_uploads/'.basename((string)$row['attachment_path']));}
        $this->db->prepare('UPDATE chat_messages SET message=NULL,attachment_name=NULL,attachment_path=NULL,attachment_mime=NULL,attachment_size=NULL,deleted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$messageId]);
    }

    public function setMuted(int $roomId,int $userId,bool $muted): void {
        $this->assertMember($roomId,$userId);$st=$this->db->prepare('UPDATE chat_room_members SET muted=? WHERE room_id=? AND user_id=?');$st->execute([$muted?1:0,$roomId,$userId]);
    }

    public function attachment(int $messageId,int $userId): ?array {
        $st=$this->db->prepare('SELECT m.* FROM chat_messages m JOIN chat_room_members rm ON rm.room_id=m.room_id AND rm.user_id=? WHERE m.id=? LIMIT 1');$st->execute([$userId,$messageId]);$m=$st->fetch();if(!$m||!empty($m['deleted_at'])||empty($m['attachment_path']))return null;
        $path=$this->root.'/storage/chat_uploads/'.basename((string)$m['attachment_path']);if(!is_file($path))return null;
        return ['path'=>$path,'name'=>(string)($m['attachment_name']?:'atasament'),'mime'=>(string)($m['attachment_mime']?:'application/octet-stream'),'size'=>(int)($m['attachment_size']??filesize($path))];
    }

    public function summary(int $userId): array {
        $rooms=$this->rooms($userId);
        $unread=0;foreach($rooms as $r)if(empty($r['muted']))$unread+=(int)($r['unread_count']??0);
        return [
            'rooms'=>$rooms,'users'=>$this->users($userId),'unread'=>$unread,
            'sound_enabled'=>$this->settings->bool('module.team-chat.sound_enabled',true),
            'voice_enabled'=>$this->settings->bool('module.team-chat.voice_enabled',true),
            'camera_enabled'=>$this->settings->bool('module.team-chat.camera_enabled',true),
            'modify_window_seconds'=>self::MODIFY_WINDOW_SECONDS,
        ];
    }

    private function pushMessage(int $roomId,int $senderId,int $messageId,string $message,?array $attachment): void {
        try{
            $st=$this->db->prepare('SELECT rm.user_id FROM chat_room_members rm JOIN users u ON u.id=rm.user_id AND u.is_active=1 AND u.deleted_at IS NULL WHERE rm.room_id=? AND rm.user_id<>? AND COALESCE(rm.muted,0)=0');
            $st->execute([$roomId,$senderId]);$recipients=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
            if(!$recipients)return;
            $q=$this->db->prepare('SELECT r.type,r.name,u.name sender_name FROM chat_rooms r JOIN users u ON u.id=? WHERE r.id=? LIMIT 1');$q->execute([$senderId,$roomId]);$meta=$q->fetch();if(!$meta)return;
            $sender=trim((string)($meta['sender_name']??'Colegul tau'))?:'Colegul tau';
            $room=(string)($meta['type']??'')==='group'?(trim((string)($meta['name']??''))?:'Grup'):$sender;
            $preview=trim($message);
            if($preview===''){
                $mime=(string)($attachment['mime']??'');
                $preview=str_starts_with($mime,'image/')?'A trimis o fotografie.':(str_starts_with($mime,'audio/')?'A trimis un mesaj vocal.':'A trimis un atasament.');
            }
            $preview=$this->clip($preview,180);
            $vars=['sender'=>$sender,'room'=>$room,'message'=>$preview];
            $title=$this->chatTemplate('push_title','{sender} · Chat ALFAMED',$vars);
            $body=$this->chatTemplate('push_message','{message}',$vars);
            $url=app_absolute_path('/?chat_room='.$roomId.'&chat_focus=1');
            (new WebPushService($this->db))->sendToUsers($recipients,[
                'id'=>'chat-'.$messageId,'type'=>'chat_message','title'=>$title,'message'=>$body,'url'=>$url,
                'room_id'=>$roomId,'message_id'=>$messageId,'sender_id'=>$senderId,'actions'=>[['action'=>'reply','title'=>'Raspunde']],
            ],'chat_message');
        }catch(\Throwable){}
    }

    private function chatTemplate(string $key,string $default,array $vars): string {
        $value=trim((string)$this->settings->get('module.team-chat.'.$key,$default));if($value==='')$value=$default;
        foreach($vars as $name=>$replacement)$value=str_replace('{'.$name.'}',(string)$replacement,$value);
        return $value;
    }

    private function clip(string $value,int $limit): string {return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);}

    private function ownedMessage(int $messageId,int $userId): array {
        $st=$this->db->prepare('SELECT * FROM chat_messages WHERE id=? AND sender_id=? LIMIT 1');$st->execute([$messageId,$userId]);$row=$st->fetch();if(!$row)throw new RuntimeException('Mesajul nu exista sau nu iti apartine.');return $row;
    }
    private function assertWithinModifyWindow(array $row): void {$ts=strtotime((string)($row['created_at']??''))?:0;if($ts<=0||time()-$ts>self::MODIFY_WINDOW_SECONDS)throw new RuntimeException('Mesajele pot fi editate sau sterse doar in primele 5 minute.');}
    private function roomMembers(int $roomId): array {$st=$this->db->prepare('SELECT u.id,u.name,u.email,u.job_title,u.avatar_path,u.last_active_at,u.is_active FROM chat_room_members rm JOIN users u ON u.id=rm.user_id AND u.deleted_at IS NULL WHERE rm.room_id=? ORDER BY u.name');$st->execute([$roomId]);return $st->fetchAll();}
    private function assertMember(int $roomId,int $userId): void {$st=$this->db->prepare('SELECT 1 FROM chat_room_members WHERE room_id=? AND user_id=?');$st->execute([$roomId,$userId]);if(!$st->fetchColumn())throw new RuntimeException('Nu ai acces la aceasta conversatie.');}
    private function addMember(int $roomId,int $userId): void {$driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='mysql'?'INSERT IGNORE INTO chat_room_members(room_id,user_id) VALUES(?,?)':'INSERT OR IGNORE INTO chat_room_members(room_id,user_id) VALUES(?,?)';$this->db->prepare($sql)->execute([$roomId,$userId]);}
    private function markRoomRead(int $roomId,int $userId): void {$st=$this->db->prepare('SELECT id FROM chat_messages WHERE room_id=? AND sender_id<>?');$st->execute([$roomId,$userId]);foreach($st->fetchAll(PDO::FETCH_COLUMN) as $id)$this->markMessageRead((int)$id,$userId);}
    private function markMessageRead(int $messageId,int $userId): void {$driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='mysql'?'INSERT IGNORE INTO chat_message_reads(user_id,message_id) VALUES(?,?)':'INSERT OR IGNORE INTO chat_message_reads(user_id,message_id) VALUES(?,?)';$this->db->prepare($sql)->execute([$userId,$messageId]);}

    private function storeAttachment(array $file): ?array {
        if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Atasamentul nu a putut fi incarcat.');
        $max=max(1,min(25,(int)$this->settings->get('module.team-chat.max_attachment_mb','10')))*1024*1024;$size=(int)($file['size']??0);if($size<=0||$size>$max)throw new RuntimeException('Atasamentul depaseste limita configurata.');
        $original=trim((string)($file['name']??'fisier'));$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));$allowed=array_values(array_filter(array_map('trim',explode(',',strtolower((string)$this->settings->get('module.team-chat.allowed_extensions','jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip,webm,ogg,mp3,m4a,wav'))))));if($ext===''||!in_array($ext,$allowed,true))throw new RuntimeException('Tipul fisierului nu este permis in chat.');
        $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Fisier temporar invalid.');
        $dir=$this->root.'/storage/chat_uploads';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Nu pot crea folderul pentru atasamente.');
        $stored=bin2hex(random_bytes(20)).'.'.$ext;$dest=$dir.'/'.$stored;if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Nu am putut salva atasamentul.');
        $mime='application/octet-stream';if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$dest);if(is_string($det)&&$det!=='')$mime=$det;finfo_close($f);}}
        // Some servers identify an audio-only WebM recording as video/webm. Keep voice notes playable.
        $audioMime=['webm'=>'audio/webm','ogg'=>'audio/ogg','mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav'];if(isset($audioMime[$ext])&&!str_starts_with($mime,'audio/'))$mime=$audioMime[$ext];
        return ['name'=>basename($original),'path'=>$stored,'mime'=>$mime,'size'=>$size];
    }

    private function lastSeenLabel(int $ts): string {
        if($ts<=0)return 'Nu a fost online';$diff=max(0,time()-$ts);if($diff<60)return 'Activ acum';if($diff<3600)return 'Activ acum '.(int)floor($diff/60).' min';if(date('Y-m-d',$ts)===date('Y-m-d'))return 'Activ azi la '.date('H:i',$ts);if(date('Y-m-d',$ts)===date('Y-m-d',strtotime('-1 day')))return 'Activ ieri la '.date('H:i',$ts);return 'Activ '.date('d.m.Y H:i',$ts);
    }
}

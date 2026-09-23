<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Services\SettingService;
use App\Services\WebPushService;
use App\Services\ActivityService;
use AlfamedModules\TeamChat\TeamChatService;

require_once __DIR__.'/src/TeamChatService.php';

$permissionLabels=[
    'dashboard.view'=>'Dashboard - vizualizare',
    'orders.view'=>'Comenzi - vizualizare',
    'orders.manage'=>'Comenzi - operare / documente / AWB',
    'products.view'=>'Produse - vizualizare',
    'products.manage'=>'Produse - creare / editare / transfer / stergere',
    'invoices.view'=>'Facturi - vizualizare',
    'awb.view'=>'AWB si scanare - vizualizare',
    'modules.manage'=>'Module - administrare',
    'settings.manage'=>'Setari - administrare',
    'chat.use'=>'Chat intern - utilizare',
];

$json=static function(array $payload,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);};
$api=static function(callable $fn) use ($json):void{try{$json(['ok'=>true]+(array)$fn());}catch(Throwable $e){$json(['ok'=>false,'error'=>$e->getMessage()],422);}};
$requireAdmin=static function():void{if(!Auth::isAdmin())throw new RuntimeException('Doar administratorul poate administra utilizatorii si drepturile de acces.');};
$cleanPermissions=static function(array $values) use ($permissionLabels):array{return array_values(array_intersect(array_keys($permissionLabels),array_values(array_filter(array_map('strval',$values)))));};
$moduleEnabled=static function(string $slug):bool{try{$st=Database::connection()->prepare('SELECT enabled FROM modules WHERE slug=? LIMIT 1');$st->execute([$slug]);return (bool)$st->fetchColumn();}catch(Throwable){return false;}};

$storeProfileAvatar=static function(int $userId,array $file,string $old=''): ?string{
    if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return $old!==''?$old:null;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Poza de profil nu a putut fi incarcata.');
    $size=(int)($file['size']??0);if($size<=0||$size>5*1024*1024)throw new RuntimeException('Poza de profil poate avea maximum 5 MB.');
    $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Fisier temporar invalid pentru poza de profil.');
    $mime='';if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$tmp);if(is_string($det))$mime=$det;finfo_close($f);}}
    $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($map[$mime]))throw new RuntimeException('Poza de profil trebuie sa fie JPG, PNG sau WEBP.');
    $imageInfo=@getimagesize($tmp);if(!$imageInfo||($imageInfo[0]??0)<40||($imageInfo[1]??0)<40)throw new RuntimeException('Imaginea de profil este invalida sau prea mica.');
    $root=dirname(__DIR__,2);$dir=$root.'/storage/profile_uploads';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Nu pot crea folderul pentru pozele de profil.');
    $stored='user-'.$userId.'-'.bin2hex(random_bytes(10)).'.'.$map[$mime];$dest=$dir.'/'.$stored;if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Poza de profil nu a putut fi salvata.');
    if($old!==''){@unlink($dir.'/'.basename($old));}
    return $stored;
};

return [
    'nav'=>[
        ['label'=>'Utilizatori','url'=>'/users','permission'=>'admin'],
        ['label'=>'Activitate','url'=>'/users/activity','permission'=>'admin'],
    ],
    'routes'=>[
        ['method'=>'GET','path'=>'/users','handler'=>static function() use ($requireAdmin):void{
            $requireAdmin();$db=Database::connection();$users=$db->query("SELECT id,name,email,role,job_title,avatar_path,is_active,last_active_at,created_at FROM users WHERE deleted_at IS NULL ORDER BY CASE WHEN is_active=1 THEN 0 ELSE 1 END,name")->fetchAll();
            $summary=['total'=>count($users),'active'=>count(array_filter($users,static fn($u)=>(bool)$u['is_active'])),'suspended'=>count(array_filter($users,static fn($u)=>!(bool)$u['is_active']))];
            $online=0;foreach($users as $u){$ts=!empty($u['last_active_at'])?strtotime((string)$u['last_active_at']):false;if((bool)$u['is_active']&&$ts!==false&&(time()-$ts)<=90)$online++;}$summary['online']=$online;
            View::render('users',compact('users','summary')+['title'=>'Utilizatori']);
        }],
        ['method'=>'GET','path'=>'/users/activity','handler'=>static function() use ($requireAdmin):void{
            $requireAdmin();$db=Database::connection();$svc=new ActivityService($db);$filters=['user_id'=>(int)($_GET['user_id']??0),'category'=>trim((string)($_GET['category']??'')),'status'=>trim((string)($_GET['status']??'')),'days'=>max(0,(int)($_GET['days']??30)),'q'=>trim((string)($_GET['q']??''))];
            $perPage=50;$total=$svc->count($filters);$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,(int)($_GET['page']??1)));$rows=$svc->list($filters,$perPage,($page-1)*$perPage);$activityUsers=$svc->usersForFilter();
            View::render('user_activity',compact('rows','activityUsers','filters','total','pages','page','perPage')+['title'=>'Jurnal activitate']);
        }],
        ['method'=>'GET','path'=>'/users/new','handler'=>static function() use ($requireAdmin,$permissionLabels):void{$requireAdmin();$editUser=null;View::render('user_edit',compact('editUser','permissionLabels')+['title'=>'Utilizator nou']);}],
        ['method'=>'POST','path'=>'/users/new','handler'=>static function() use ($requireAdmin,$cleanPermissions):void{
            $requireAdmin();Csrf::verify();$db=Database::connection();$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');$role=(string)($_POST['role']??'operator');$role=in_array($role,['admin','manager','operator','warehouse','viewer'],true)?$role:'operator';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Numele si emailul valid sunt obligatorii.');if(strlen($password)<10)throw new RuntimeException('Parola trebuie sa aiba minimum 10 caractere.');$perms=$role==='admin'?[]:$cleanPermissions((array)($_POST['permissions']??[]));$st=$db->prepare('INSERT INTO users(name,email,password_hash,role,permissions_json,is_active,job_title,last_active_at) VALUES(?,?,?,?,?,?,?,NULL)');$st->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,json_encode($perms),isset($_POST['is_active'])?1:0,trim((string)($_POST['job_title']??''))?:null]);flash('success','Utilizatorul a fost creat.');redirect('/users');
        }],
        ['method'=>'GET','path'=>'#^/users/(\d+)$#','handler'=>static function(array $m) use ($requireAdmin,$permissionLabels):void{$requireAdmin();$db=Database::connection();$st=$db->prepare('SELECT * FROM users WHERE id=? AND deleted_at IS NULL');$st->execute([(int)$m[1]]);$editUser=$st->fetch();if(!$editUser)throw new RuntimeException('Utilizator inexistent.');View::render('user_edit',compact('editUser','permissionLabels')+['title'=>'Editeaza utilizator']);}],
        ['method'=>'POST','path'=>'#^/users/(\d+)$#','handler'=>static function(array $m) use ($requireAdmin,$cleanPermissions):void{
            $requireAdmin();Csrf::verify();$db=Database::connection();$id=(int)$m[1];$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$role=(string)($_POST['role']??'operator');$role=in_array($role,['admin','manager','operator','warehouse','viewer'],true)?$role:'operator';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Numele si emailul valid sunt obligatorii.');$active=isset($_POST['is_active'])?1:0;if($id===(int)(Auth::user()['id']??0)&&(!$active||$role!=='admin'))throw new RuntimeException('Nu iti poti dezactiva propriul cont si nu iti poti elimina rolul de administrator.');$perms=$role==='admin'?[]:$cleanPermissions((array)($_POST['permissions']??[]));$password=(string)($_POST['password']??'');if($password!==''&&strlen($password)<10)throw new RuntimeException('Parola noua trebuie sa aiba minimum 10 caractere.');if($password!==''){$sql='UPDATE users SET name=?,email=?,role=?,permissions_json=?,is_active=?,job_title=?,password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?';$args=[$name,$email,$role,json_encode($perms),$active,trim((string)($_POST['job_title']??''))?:null,password_hash($password,PASSWORD_DEFAULT),$id];}else{$sql='UPDATE users SET name=?,email=?,role=?,permissions_json=?,is_active=?,job_title=?,updated_at=CURRENT_TIMESTAMP WHERE id=?';$args=[$name,$email,$role,json_encode($perms),$active,trim((string)($_POST['job_title']??''))?:null,$id];}$db->prepare($sql)->execute($args);flash('success','Utilizatorul a fost actualizat.');redirect('/users');
        }],
        ['method'=>'POST','path'=>'#^/users/(\d+)/toggle$#','handler'=>static function(array $m) use ($requireAdmin):void{
            $requireAdmin();Csrf::verify();$db=Database::connection();$id=(int)$m[1];if($id===(int)(Auth::user()['id']??0))throw new RuntimeException('Nu iti poti suspenda propriul cont.');$st=$db->prepare('SELECT id,is_active FROM users WHERE id=? AND deleted_at IS NULL');$st->execute([$id]);$u=$st->fetch();if(!$u)throw new RuntimeException('Utilizator inexistent.');$active=((int)($_POST['set_active']??0))===1?1:0;$db->prepare('UPDATE users SET is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$active,$id]);if(!$active){try{$db->prepare('DELETE FROM auth_remember_tokens WHERE user_id=?')->execute([$id]);}catch(Throwable){}try{$db->prepare('UPDATE push_subscriptions SET active=0 WHERE user_id=?')->execute([$id]);}catch(Throwable){}}flash('success',$active?'Utilizatorul a fost reactivat.':'Utilizatorul a fost suspendat.');redirect('/users');
        }],
        ['method'=>'POST','path'=>'#^/users/(\d+)/delete$#','handler'=>static function(array $m) use ($requireAdmin):void{
            $requireAdmin();Csrf::verify();$db=Database::connection();$id=(int)$m[1];if($id===(int)(Auth::user()['id']??0))throw new RuntimeException('Nu iti poti sterge propriul cont.');$st=$db->prepare('SELECT id,name,email FROM users WHERE id=? AND deleted_at IS NULL');$st->execute([$id]);$u=$st->fetch();if(!$u)throw new RuntimeException('Utilizator inexistent.');$replacement='deleted+'.(int)$id.'-'.time().'@invalid.local';$db->prepare("UPDATE users SET is_active=0,email=?,deleted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$replacement,$id]);foreach(['auth_remember_tokens'=>'user_id','push_subscriptions'=>'user_id','notification_reads'=>'user_id','chat_message_reads'=>'user_id','chat_room_members'=>'user_id'] as $table=>$column){try{$db->prepare("DELETE FROM {$table} WHERE {$column}=?")->execute([$id]);}catch(Throwable){}}flash('success','Utilizatorul a fost sters. Istoricul activitatii ramane disponibil administratorilor.');redirect('/users');
        }],
        ['method'=>'GET','path'=>'/profile','handler'=>static function() use ($moduleEnabled):void{
            $db=Database::connection();$st=$db->prepare('SELECT id,name,email,role,job_title,avatar_path,last_active_at,push_orders_enabled,push_stock_enabled,push_chat_enabled FROM users WHERE id=? AND deleted_at IS NULL');$st->execute([(int)Auth::user()['id']]);$profile=$st->fetch();
            $notificationModuleEnabled=$moduleEnabled('new-order-popup');$chatModuleEnabled=$moduleEnabled('team-chat');$pushSubscriptions=0;$pushSupported=false;$cronActive=false;$cronLastSeen='';
            if($notificationModuleEnabled||$chatModuleEnabled){try{$push=new WebPushService($db);$pushSubscriptions=$push->userSubscriptionCount((int)Auth::user()['id']);$pushSupported=$push->supported();}catch(Throwable){}}
            if($notificationModuleEnabled){try{$settings=new SettingService($db);$last=(int)$settings->get('runtime.cron_last_seen','0');$cronActive=$last>0&&(time()-$last)<=180;$cronLastSeen=(string)$settings->get('runtime.cron_last_seen_at','');}catch(Throwable){}}
            View::render('profile',compact('profile','notificationModuleEnabled','chatModuleEnabled','pushSubscriptions','pushSupported','cronActive','cronLastSeen')+['title'=>'Profilul meu']);
        }],
        ['method'=>'POST','path'=>'/profile','handler'=>static function() use ($storeProfileAvatar,$moduleEnabled):void{
            Csrf::verify();$db=Database::connection();$id=(int)Auth::user()['id'];$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$job=trim((string)($_POST['job_title']??''));if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Numele si emailul valid sunt obligatorii.');
            $q=$db->prepare('SELECT password_hash,avatar_path,push_orders_enabled,push_stock_enabled,push_chat_enabled FROM users WHERE id=? AND deleted_at IS NULL');$q->execute([$id]);$currentRow=$q->fetch();if(!$currentRow)throw new RuntimeException('Utilizator inexistent.');$avatar=$storeProfileAvatar($id,$_FILES['avatar']??[],(string)($currentRow['avatar_path']??''));
            $pushOrders=$moduleEnabled('new-order-popup')?(isset($_POST['push_orders_enabled'])?1:0):(int)($currentRow['push_orders_enabled']??1);
            $pushStock=$moduleEnabled('new-order-popup')?(isset($_POST['push_stock_enabled'])?1:0):(int)($currentRow['push_stock_enabled']??1);
            $pushChat=$moduleEnabled('team-chat')?(isset($_POST['push_chat_enabled'])?1:0):(int)($currentRow['push_chat_enabled']??1);
            $new=(string)($_POST['new_password']??'');if($new!==''){$current=(string)($_POST['current_password']??'');if(!password_verify($current,(string)$currentRow['password_hash']))throw new RuntimeException('Parola curenta nu este corecta.');if(strlen($new)<10)throw new RuntimeException('Parola noua trebuie sa aiba minimum 10 caractere.');$db->prepare('UPDATE users SET name=?,email=?,job_title=?,avatar_path=?,push_orders_enabled=?,push_stock_enabled=?,push_chat_enabled=?,password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$email,$job?:null,$avatar,$pushOrders,$pushStock,$pushChat,password_hash($new,PASSWORD_DEFAULT),$id]);}else{$db->prepare('UPDATE users SET name=?,email=?,job_title=?,avatar_path=?,push_orders_enabled=?,push_stock_enabled=?,push_chat_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$email,$job?:null,$avatar,$pushOrders,$pushStock,$pushChat,$id]);}Auth::syncSession();flash('success','Profilul a fost actualizat.');redirect('/profile');
        }],
        ['method'=>'GET','path'=>'#^/profile/avatar/(\d+)$#','handler'=>static function(array $m):void{
            $db=Database::connection();$st=$db->prepare('SELECT avatar_path FROM users WHERE id=? AND is_active=1 AND deleted_at IS NULL');$st->execute([(int)$m[1]]);$stored=(string)($st->fetchColumn()?:'');if($stored===''){http_response_code(404);echo 'Imagine inexistenta.';return;}$path=dirname(__DIR__,2).'/storage/profile_uploads/'.basename($stored);if(!is_file($path)){http_response_code(404);echo 'Imagine inexistenta.';return;}$mime='image/jpeg';if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$path);if(is_string($det)&&str_starts_with($det,'image/'))$mime=$det;finfo_close($f);}}header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');readfile($path);
        }],
        ['method'=>'GET','path'=>'/api/chat/summary','handler'=>static function() use ($json):void{Auth::requirePermission('chat.use');$u=(int)Auth::user()['id'];$poll=max(2,min(30,(int)(new SettingService())->get('module.team-chat.poll_seconds','4')));$json(['ok'=>true,'poll_seconds'=>$poll]+(new TeamChatService())->summary($u));}],
        ['method'=>'POST','path'=>'/api/chat/heartbeat','handler'=>static function() use ($json):void{Auth::requirePermission('chat.use');Csrf::verify();Auth::touchActivity(true);$json(['ok'=>true]);}],
        ['method'=>'POST','path'=>'/api/chat/direct','handler'=>static function() use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(fn()=>['room_id'=>(new TeamChatService())->directRoom((int)Auth::user()['id'],(int)($_POST['user_id']??0))]);}],
        ['method'=>'POST','path'=>'/api/chat/group','handler'=>static function() use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(fn()=>['room_id'=>(new TeamChatService())->createGroup((int)Auth::user()['id'],(string)($_POST['name']??''),(array)($_POST['members']??[]))]);}],
        ['method'=>'GET','path'=>'#^/api/chat/messages/(\d+)$#','handler'=>static function(array $m) use ($json):void{Auth::requirePermission('chat.use');$after=max(0,(int)($_GET['after']??0));$rows=(new TeamChatService())->messages((int)$m[1],(int)Auth::user()['id'],$after);$json(['ok'=>true,'messages'=>$rows]);}],
        ['method'=>'POST','path'=>'#^/api/chat/messages/(\d+)$#','handler'=>static function(array $m) use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(function() use($m){$r=(new TeamChatService())->send((int)$m[1],(int)Auth::user()['id'],(string)($_POST['message']??''),$_FILES['attachment']??[]);Auth::touchActivity(true);return $r;});}],
        ['method'=>'POST','path'=>'#^/api/chat/message/(\d+)/edit$#','handler'=>static function(array $m) use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(function() use($m){(new TeamChatService())->editMessage((int)$m[1],(int)Auth::user()['id'],(string)($_POST['message']??''));return [];});}],
        ['method'=>'POST','path'=>'#^/api/chat/message/(\d+)/delete$#','handler'=>static function(array $m) use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(function() use($m){(new TeamChatService())->deleteMessage((int)$m[1],(int)Auth::user()['id']);return [];});}],
        ['method'=>'POST','path'=>'#^/api/chat/rooms/(\d+)/mute$#','handler'=>static function(array $m) use ($api):void{Auth::requirePermission('chat.use');Csrf::verify();$api(function() use($m){$muted=filter_var($_POST['muted']??false,FILTER_VALIDATE_BOOL);(new TeamChatService())->setMuted((int)$m[1],(int)Auth::user()['id'],$muted);return ['muted'=>$muted];});}],
        ['method'=>'GET','path'=>'#^/chat/files/(\d+)$#','handler'=>static function(array $m):void{Auth::requirePermission('chat.use');$f=(new TeamChatService())->attachment((int)$m[1],(int)Auth::user()['id']);if(!$f){http_response_code(404);echo 'Fisier inexistent.';return;}$mime=(string)$f['mime'];$inline=str_starts_with($mime,'image/')||str_starts_with($mime,'audio/');$disposition=$inline?'inline':'attachment';header('Content-Type: '.$mime);header('Content-Length: '.(string)$f['size']);header('Content-Disposition: '.$disposition.'; filename="'.str_replace(['"','\r','\n'],'',(string)$f['name']).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');readfile($f['path']);}],
    ],
    'renderers'=>[
        'layout.footer'=>static function():void{
            if(!Auth::can('chat.use'))return;
            $allowGroups=(new SettingService())->bool('module.team-chat.allow_group_creation',true);
            echo '<div id="teamChat" class="team-chat" data-user-id="'.(int)Auth::user()['id'].'"><button type="button" id="chatLauncher" class="chat-launcher" aria-label="Deschide chat"><span>💬</span><b id="chatUnreadBadge" class="chat-unread-badge" hidden>0</b></button><section id="chatPanel" class="chat-panel" hidden><header class="chat-header"><div><strong>Chat echipa</strong><small id="chatConnection">Conectat</small></div><button type="button" id="chatClose" aria-label="Inchide">×</button></header><div class="chat-body"><aside class="chat-sidebar"><div class="chat-sidebar-actions">'.($allowGroups?'<button type="button" id="chatNewGroup" class="small">+ Grup</button>':'').'</div><h4 id="chatGroupsLabel" hidden>Grupuri</h4><div id="chatRooms" class="chat-room-list" hidden></div><h4>Utilizatori</h4><div id="chatUsers" class="chat-user-list"></div></aside><div class="chat-conversation"><div id="chatConversationHead" class="chat-conversation-head"><button type="button" id="chatBack" class="chat-mobile-back" aria-label="Inapoi la conversatii">‹</button><div><strong>Alege o conversatie</strong><small>Mesaje interne ALFAMED</small></div><button type="button" id="chatMuteBtn" class="chat-head-tool" hidden title="Opreste notificarile">🔔</button></div><div id="chatMessages" class="chat-messages"></div><form id="chatComposer" class="chat-composer" enctype="multipart/form-data"><div id="chatEmojiPanel" class="chat-emoji-panel" hidden></div><button type="button" id="chatEmojiBtn" class="chat-tool" title="Emoji">😊</button><label class="chat-tool chat-file-tool" title="Incarca atasament">📎<input id="chatAttachment" type="file" name="attachment" hidden></label><button type="button" id="chatVoiceBtn" class="chat-tool" title="Mesaj vocal">🎙️</button><button type="button" id="chatCameraBtn" class="chat-tool chat-camera-live-tool" title="Fa o fotografie">📷</button><label id="chatCameraNativeTool" class="chat-tool chat-camera-native-tool" title="Fa o fotografie">📷<input id="chatCameraFallbackInput" type="file" accept="image/*" capture="environment" hidden></label><textarea id="chatMessageInput" name="message" rows="1" placeholder="Scrie un mesaj..."></textarea><button class="primary chat-send" type="submit">Trimite</button><div id="chatAttachmentBar" class="chat-attachment-bar" hidden><span>📎</span><span id="chatAttachmentName"></span><button type="button" id="chatAttachmentClear" aria-label="Sterge atasamentul">×</button></div><div id="chatRecordStatus" class="chat-record-status" hidden><span class="record-dot"></span><span id="chatRecordTime">00:00</span><span>Inregistrare vocala</span></div></form></div></div><div id="chatGroupCreator" class="chat-group-creator" hidden><div class="chat-group-card"><h3>Grup nou</h3><input id="chatGroupName" placeholder="Numele grupului"><div id="chatGroupMembers" class="chat-group-members"></div><div class="actions"><button type="button" id="chatGroupCancel">Renunta</button><button type="button" id="chatGroupSave" class="primary">Creeaza grup</button></div></div></div><div id="chatCameraModal" class="chat-camera-modal" hidden><div class="chat-camera-card"><video id="chatCameraVideo" autoplay playsinline muted></video><canvas id="chatCameraCanvas" hidden></canvas><div class="actions"><button type="button" id="chatCameraCancel">Renunta</button><button type="button" id="chatCameraCapture" class="primary">📷 Fotografiaza</button></div></div></div></section></div>';
        },
    ],
    'settings'=>[[
        'title'=>'Echipa & chat intern','description'=>'Prezenta online, sunet, mesaje vocale, camera, grupuri si fisiere. Editarea/stergerea mesajelor este permisa strict 5 minute de la trimitere.',
        'fields'=>[
            ['key'=>'section_live','label'=>'Mesaje live','type'=>'heading'],
            ['key'=>'poll_seconds','label'=>'Actualizare chat la fiecare (secunde)','type'=>'number','min'=>2,'max'=>30,'default'=>'4'],
            ['key'=>'online_window_seconds','label'=>'Considera utilizator online pentru (secunde)','type'=>'number','min'=>30,'max'=>600,'default'=>'90'],
            ['key'=>'sound_enabled','label'=>'Reda sunet la mesaj nou (exceptand conversatiile pe mute)','type'=>'checkbox','default'=>'true'],
            ['key'=>'section_media','label'=>'Media','type'=>'heading'],
            ['key'=>'voice_enabled','label'=>'Permite mesaje vocale din microfon','type'=>'checkbox','default'=>'true'],
            ['key'=>'camera_enabled','label'=>'Permite fotografii direct din camera','type'=>'checkbox','default'=>'true'],
            ['key'=>'section_push','label'=>'Notificari push chat','type'=>'heading'],
            ['key'=>'push_enabled','label'=>'Trimite notificari push pentru chat','type'=>'checkbox','default'=>'true'],
            ['key'=>'push_messages','label'=>'Notifica mesajele noi ale utilizatorilor','type'=>'checkbox','default'=>'true'],
            ['key'=>'push_title','label'=>'Titlu notificare chat','type'=>'text','default'=>'{sender} · Chat ALFAMED','help'=>'Variabile: {sender}, {room}.'],
            ['key'=>'push_message','label'=>'Text notificare chat','type'=>'text','default'=>'{message}','help'=>'Variabile: {sender}, {room}, {message}. Dispozitivul trebuie sa aiba Push activat din Profilul meu sau din clopotel.'],
            ['key'=>'allow_group_creation','label'=>'Permite utilizatorilor sa creeze grupuri','type'=>'checkbox','default'=>'true'],
            ['key'=>'max_attachment_mb','label'=>'Dimensiune maxima atasament (MB)','type'=>'number','min'=>1,'max'=>25,'default'=>'10'],
            ['key'=>'allowed_extensions','label'=>'Extensii permise, separate prin virgula','type'=>'text','default'=>'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip,webm,ogg,mp3,m4a,wav'],
        ],
    ]],
];

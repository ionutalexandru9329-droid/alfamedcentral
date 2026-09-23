<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\Database;
use App\Core\Env;
use App\Core\ModuleManager;
try{$db=Database::connection();}catch(Throwable $e){fwrite(STDERR,"Conexiune DB esuata: {$e->getMessage()}\n");exit(1);}
$dsn=(string)Env::get('DB_DSN');$mysql=str_starts_with($dsn,'mysql:');$schema=file_get_contents(dirname(__DIR__).'/database/'.($mysql?'schema_mysql.sql':'schema_sqlite.sql'));
try{$db->exec((string)$schema);}catch(Throwable $e){fwrite(STDERR,"Schema: {$e->getMessage()}\n");exit(1);}
$channels=[['emag_ro','eMAG Romania','emag','RO','RON'],['emag_bg','eMAG Bulgaria','emag','BG','EUR'],['univera','Univera.ro','woocommerce','RO','RON'],['alfamed','Alfamedclinic.ro','woocommerce','RO','RON']];
foreach($channels as $c){$q=$db->prepare('SELECT id FROM channels WHERE code=?');$q->execute([$c[0]]);if(!$q->fetchColumn()){$st=$db->prepare('INSERT INTO channels(code,name,type,country,currency,enabled) VALUES(?,?,?,?,?,0)');$st->execute($c);}}
$email=getenv('ADMIN_EMAIL')?:'admin@alfamed.local';$password=getenv('ADMIN_PASSWORD')?:bin2hex(random_bytes(6)).'!A9';$q=$db->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);if(!$q->fetchColumn()){$st=$db->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');$st->execute(['Administrator',$email,password_hash($password,PASSWORD_DEFAULT),'admin']);echo "Admin creat: {$email}\nParola initiala: {$password}\n";}else echo "Admin exista deja: {$email}\n";
$manager=new ModuleManager($db);foreach($manager->discover() as $slug=>$manifest)$manager->install($slug,(bool)($manifest['default_enabled']??false));
$lock=['installed_at'=>date(DATE_ATOM),'version'=>trim((string)file_get_contents(dirname(__DIR__).'/VERSION')),'cli'=>true];file_put_contents(dirname(__DIR__).'/storage/installed.lock',json_encode($lock,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Instalare finalizata.\n";

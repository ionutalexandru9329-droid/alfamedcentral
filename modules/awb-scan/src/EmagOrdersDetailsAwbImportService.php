<?php
namespace AlfamedModules\AwbScan;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class EmagOrdersDetailsAwbImportService {
    public function __construct(private PDO $db) {}

    /**
     * Importa maparea Nr. comanda -> Numar AWB din exportul eMAG "Orders Details".
     * Exportul este tratat ca sursa autoritativa pentru asocierea AWB <-> comanda eMAG.
     * Accepta XLSX si CSV. Nu atinge expedieri WooCommerce.
     *
     * @return array{rows:int,mappings:int,matched_orders:int,inserted:int,existing:int,reassigned:int,reactivated:int,missing_orders:int,conflicts:int,invalid:int,examples:array}
     */
    public function importUpload(array $file): array {
        $err=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($err!==UPLOAD_ERR_OK)throw new RuntimeException($err===UPLOAD_ERR_NO_FILE?'Alege fisierul Orders Details exportat din eMAG.':'Fisierul nu a putut fi incarcat (cod '.$err.').');
        $tmp=(string)($file['tmp_name']??'');$name=(string)($file['name']??'');
        if($tmp===''||!is_file($tmp))throw new RuntimeException('Fisierul incarcat nu este disponibil.');
        if((int)($file['size']??0)>15*1024*1024)throw new RuntimeException('Fisierul Orders Details este prea mare. Limita este 15 MB.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $rows=$ext==='csv'?$this->readCsv($tmp):($ext==='xlsx'?$this->readXlsx($tmp):throw new RuntimeException('Format invalid. Foloseste exportul eMAG .xlsx sau .csv.'));
        return $this->importRows($rows);
    }

    /** @return array<int,array<string,string>> */
    private function readCsv(string $path): array {
        $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Nu pot citi fisierul CSV.');
        $first=fgets($fh);if($first===false){fclose($fh);return [];}
        $first=preg_replace('/^\xEF\xBB\xBF/','',$first)??$first;
        $delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';
        rewind($fh);$header=fgetcsv($fh,0,$delimiter);if(!is_array($header)){fclose($fh);return [];}
        $header=array_map(fn($v)=>$this->normalizeHeader((string)$v),$header);$out=[];
        while(($r=fgetcsv($fh,0,$delimiter))!==false){$row=[];foreach($header as $i=>$key)if($key!=='')$row[$key]=(string)($r[$i]??'');if($row)$out[]=$row;}
        fclose($fh);return $out;
    }

    /** @return array<int,array<string,string>> */
    private function readXlsx(string $path): array {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('Extensia PHP ZipArchive trebuie activata pentru import XLSX. Poti exporta Orders Details ca CSV daca este nevoie.');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Fisierul XLSX nu poate fi deschis.');
        try{
            $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
            $shared=$this->readSharedStringsXml(is_string($sharedXml)?$sharedXml:'');
            $sheetName=$this->firstWorksheetPath($zip);if($sheetName==='')throw new RuntimeException('Fisierul XLSX nu contine nicio foaie de calcul.');
            $xml=$zip->getFromName($sheetName);if(!is_string($xml)||$xml==='')throw new RuntimeException('Foaia Orders Details nu poate fi citita.');
            $rows=$this->parseWorksheetRows($xml,$shared);
            if($rows)return $rows;
            return $this->parseEmagFixedColumnsFallback($xml,$shared);
        } finally {$zip->close();}
    }

    /** @return array<int,string> */
    private function readSharedStringsXml(string $xml): array {
        if($xml==='')return [];$out=[];
        foreach($this->extractXmlBlocks($xml,'si') as $si){
            $parts=[];
            if(preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/si',$si,$tMatches)){
                foreach($tMatches[1] as $txt)$parts[]=$this->xmlText((string)$txt);
            }
            $out[]=implode('',$parts);
        }
        return $out;
    }

    /** @param array<int,string> $shared @return array<int,array<string,string>> */
    private function parseWorksheetRows(string $xml,array $shared): array {
        $rawRows=[];
        foreach($this->extractXmlBlocks($xml,'row') as $rowXml){$cells=$this->parseRowCells($rowXml,$shared);if($cells)$rawRows[]=$cells;}
        if(!$rawRows)return [];
        $headerIndex=-1;$headers=[];
        foreach($rawRows as $idx=>$cells){
            $candidate=[];foreach($cells as $col=>$value){$key=$this->normalizeHeader($value);if($key!=='')$candidate[$col]=$key;}
            $values=array_values($candidate);
            if((in_array('nr_comanda',$values,true)||in_array('numar_comanda',$values,true))&&(in_array('numar_awb',$values,true)||in_array('awb',$values,true)||in_array('awb_number',$values,true))){$headerIndex=$idx;$headers=$candidate;break;}
        }
        if($headerIndex<0||!$headers)return [];
        $rows=[];
        for($i=$headerIndex+1,$n=count($rawRows);$i<$n;$i++){$cells=$rawRows[$i];$row=[];foreach($headers as $col=>$key)$row[$key]=(string)($cells[$col]??'');if(array_filter($row,static fn($v)=>trim((string)$v)!==''))$rows[]=$row;}
        return $rows;
    }

    /** @param array<int,string> $shared @return array<int,array<string,string>> */
    private function parseEmagFixedColumnsFallback(string $xml,array $shared): array {
        $out=[];$rowNo=0;
        foreach($this->extractXmlBlocks($xml,'row') as $rowXml){$rowNo++;if($rowNo===1)continue;$cells=$this->parseRowCells($rowXml,$shared);$external=trim((string)($cells['A']??''));$awb=trim((string)($cells['C']??''));if($external===''&&$awb==='')continue;$out[]=['nr_comanda'=>$external,'numar_awb'=>$awb];}
        return $out;
    }

    /** @param array<int,string> $shared @return array<string,string> */
    private function parseRowCells(string $rowXml,array $shared): array {
        $cells=[];if(!preg_match_all('/<c\s+([^>]*?)>(.*?)<\/c>/si',$rowXml,$matches,PREG_SET_ORDER))return $cells;
        foreach($matches as $cell){$attrs=(string)$cell[1];$body=(string)$cell[2];if(!preg_match('/(?:^|\s)r=["\']([A-Z]+)\d+["\']/i',$attrs,$rm))continue;$col=strtoupper((string)$rm[1]);$type='';if(preg_match('/(?:^|\s)t=["\']([^"\']+)["\']/i',$attrs,$tm))$type=(string)$tm[1];$value='';if($type==='inlineStr'){$parts=[];if(preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/si',$body,$tmatches))foreach($tmatches[1] as $txt)$parts[]=$this->xmlText((string)$txt);$value=implode('',$parts);}elseif(preg_match('/<v(?:\s[^>]*)?>(.*?)<\/v>/si',$body,$vm)){$raw=$this->xmlText((string)$vm[1]);if($type==='s'&&ctype_digit(trim($raw)))$value=(string)($shared[(int)trim($raw)]??'');else$value=$raw;}$cells[$col]=$value;}
        return $cells;
    }

    /** @return array<int,string> */
    private function extractXmlBlocks(string $xml,string $tag): array {
        $out=[];$offset=0;$openNeedle='<'.$tag;$closeNeedle='</'.$tag.'>';$len=strlen($xml);
        while($offset<$len){$start=strpos($xml,$openNeedle,$offset);if($start===false)break;$after=$start+strlen($openNeedle);$next=$xml[$after]??'';if($next!==''&&!ctype_space($next)&&$next!=='>'){$offset=$after;continue;}$openEnd=strpos($xml,'>',$after);if($openEnd===false)break;$end=strpos($xml,$closeNeedle,$openEnd+1);if($end===false)break;$out[]=substr($xml,$openEnd+1,$end-$openEnd-1);$offset=$end+strlen($closeNeedle);}
        return $out;
    }

    private function xmlText(string $value): string {return html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_XML1,'UTF-8');}
    private function firstWorksheetPath(ZipArchive $zip): string {$names=[];for($i=0;$i<$zip->numFiles;$i++){$n=(string)$zip->getNameIndex($i);if(preg_match('#^xl/worksheets/sheet\d+\.xml$#i',$n))$names[]=$n;}natsort($names);return (string)(reset($names)?:'');}
    private function normalizeHeader(string $value): string {$v=preg_replace('/^\xEF\xBB\xBF/','',trim($value))??trim($value);$v=str_replace("\xC2\xA0",' ',$v);$v=strtr($v,['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','Ă'=>'A','Â'=>'A','Î'=>'I','Ș'=>'S','Ş'=>'S','Ț'=>'T','Ţ'=>'T']);$v=strtolower($v);$v=preg_replace('/\s+/',' ',$v)??$v;$v=preg_replace('/[^a-z0-9]+/','_',$v)??$v;return trim($v,'_');}

    /** @param array<int,array<string,string>> $rows */
    private function importRows(array $rows): array {
        $stats=['rows'=>count($rows),'mappings'=>0,'matched_orders'=>0,'inserted'=>0,'existing'=>0,'reassigned'=>0,'reactivated'=>0,'missing_orders'=>0,'conflicts'=>0,'invalid'=>0,'examples'=>[]];$seen=[];
        $findExact=$this->db->prepare("SELECT o.id,o.code,o.external_id,c.code channel_code,c.type channel_type,c.name channel_name FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.remote_deleted=0 AND TRIM(o.external_id)=? AND (c.type='emag' OR c.code LIKE 'emag_%') ORDER BY CASE WHEN c.type='emag' THEN 0 ELSE 1 END,o.id DESC LIMIT 1");
        $findFallback=$this->db->prepare("SELECT o.id,o.code,o.external_id,c.code channel_code,c.type channel_type,c.name channel_name FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.remote_deleted=0 AND (c.type='emag' OR c.code LIKE 'emag_%') AND o.external_id LIKE ? ORDER BY o.id DESC LIMIT 50");
        $findAwb=$this->db->prepare("SELECT s.*,o.code current_order_code,o.external_id current_external_id,c.type current_channel_type,c.code current_channel_code FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id WHERE s.awb=? OR s.awb_barcode=? ORDER BY s.id DESC");
        $findOrderShipments=$this->db->prepare("SELECT id,awb,awb_barcode,status FROM shipments WHERE order_id=? ORDER BY id DESC");
        $insert=$this->db->prepare('INSERT INTO shipments(order_id,courier,awb,awb_barcode,provider_awb_id,status,tracking_url,raw_json) VALUES(?,?,?,?,?,?,?,?)');
        $updateExisting=$this->db->prepare("UPDATE shipments SET order_id=?,courier='eMAG Marketplace',awb=?,status='created',raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $this->db->beginTransaction();
        try{
            foreach($rows as $row){
                $external=trim((string)($row['nr_comanda']??$row['numar_comanda']??$row['order_id']??''));$awb=trim((string)($row['numar_awb']??$row['awb']??$row['awb_number']??''));
                if($external===''||$awb===''){$stats['invalid']++;continue;}
                $external=preg_replace('/\.0$/','',$external)??$external;$external=trim($external);$awb=trim($awb);
                $key=$this->canonicalExternalId($external).'|'.$this->scanKey($awb);if(isset($seen[$key]))continue;$seen[$key]=true;$stats['mappings']++;

                $order=$this->findLocalEmagOrder($external,$findExact,$findFallback);
                if(!$order){$stats['missing_orders']++;$this->example($stats,'NU AM GASIT local comanda eMAG '.$external.'.');continue;}
                $stats['matched_orders']++;$orderId=(int)$order['id'];$orderCode=(string)$order['code'];

                $findAwb->execute([$awb,$awb]);$globalRows=$findAwb->fetchAll();$handled=false;
                foreach($globalRows as $global){
                    $sameOrder=(int)$global['order_id']===$orderId;$active=!in_array((string)$global['status'],['deleted','cancelled'],true);$currentIsEmag=((string)($global['current_channel_type']??'')==='emag'||str_starts_with((string)($global['current_channel_code']??''),'emag_'));
                    if($sameOrder&&$active){$stats['existing']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (deja asociat).');$handled=true;break;}
                    if($sameOrder&&!$active){$meta=$this->mergeAudit((string)($global['raw_json']??''),$external,$awb,'reactivated_from_orders_details');$updateExisting->execute([$orderId,$awb,$meta,(int)$global['id']]);$stats['reactivated']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (reactivat).');$handled=true;break;}
                    if(!$sameOrder&&$currentIsEmag){$meta=$this->mergeAudit((string)($global['raw_json']??''),$external,$awb,'reassigned_from_orders_details',['previous_order_id'=>(int)$global['order_id'],'previous_order_code'=>(string)($global['current_order_code']??''),'previous_external_id'=>(string)($global['current_external_id']??'')]);$updateExisting->execute([$orderId,$awb,$meta,(int)$global['id']]);$stats['reassigned']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (mutat de la '.((string)($global['current_order_code']??'alta comanda')).').');$handled=true;break;}
                    if(!$sameOrder&&!$currentIsEmag){$stats['conflicts']++;$this->example($stats,'CONFLICT: '.$awb.' este legat de '.((string)($global['current_order_code']??'alta comanda')).' pe alt canal; nu a fost mutat.');$handled=true;break;}
                }
                if($handled)continue;

                $needle=$this->scanKey($awb);$findOrderShipments->execute([$orderId]);$same=false;
                foreach($findOrderShipments->fetchAll() as $s){foreach([(string)($s['awb']??''),(string)($s['awb_barcode']??'')] as $v){$k=$this->scanKey($v);if($k!==''&&($k===$needle||$this->packageAliasMatch($k,$needle))){if(in_array((string)$s['status'],['deleted','cancelled'],true)){$meta=$this->mergeAudit('', $external,$awb,'reactivated_alias_from_orders_details');$updateExisting->execute([$orderId,$awb,$meta,(int)$s['id']]);$stats['reactivated']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (alias reactivat).');}else{$stats['existing']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (alias deja asociat).');}$same=true;break 2;}}}
                if($same)continue;

                $meta=['source'=>'emag_orders_details_export','external_order_id'=>$external,'local_order_id'=>$orderId,'local_order_code'=>$orderCode,'awb'=>$awb,'imported_at'=>date(DATE_ATOM)];
                $insert->execute([$orderId,'eMAG Marketplace',$awb,null,null,'created',null,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$stats['inserted']++;$this->example($stats,$external.' -> '.$orderCode.' -> '.$awb.' (importat).');
            }
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $stats;
    }

    private function findLocalEmagOrder(string $external,\PDOStatement $findExact,\PDOStatement $findFallback): ?array {
        $findExact->execute([$external]);$row=$findExact->fetch();if(is_array($row))return $row;
        $canonical=$this->canonicalExternalId($external);if($canonical==='')return null;
        $needle='%'.preg_replace('/[^A-Za-z0-9]/','%',$external).'%';$findFallback->execute([$needle]);
        foreach($findFallback->fetchAll() as $candidate){if($this->canonicalExternalId((string)($candidate['external_id']??''))===$canonical)return $candidate;}
        return null;
    }

    private function canonicalExternalId(string $value): string {
        $value=trim($value);$value=preg_replace('/\.0$/','',$value)??$value;$compact=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');
        if($compact!==''&&ctype_digit($compact))$compact=ltrim($compact,'0')?:'0';return $compact;
    }
    private function scanKey(string $value): string {return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');}
    private function packageAliasMatch(string $a,string $b): bool {if($a===$b)return true;$variants=static function(string $v):array{$out=[$v];if(strlen($v)>=11&&preg_match('/^(.+[A-Z].*?)(\d{3})$/',$v,$m)&&$m[1]!=='')$out[]=$m[1];return array_values(array_unique($out));};return (bool)array_intersect($variants($a),$variants($b));}
    private function mergeAudit(string $raw,string $external,string $awb,string $action,array $extra=[]): string {$data=json_decode($raw,true);if(!is_array($data))$data=[];$data=array_merge($data,$extra,['source'=>'emag_orders_details_export','orders_details_action'=>$action,'external_order_id'=>$external,'awb'=>$awb,'orders_details_updated_at'=>date(DATE_ATOM)]);return (string)json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private function example(array &$stats,string $message): void {if(count($stats['examples'])<12)$stats['examples'][]=$message;}
}

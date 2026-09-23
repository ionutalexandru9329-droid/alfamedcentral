<?php
namespace Modules\InvoicesDocuments;
use App\Core\Http;
use App\Services\EmagOrderNormalizer;
use RuntimeException;
use Throwable;

final class OblioClient {
    private ?string $token=null;
    private ?array $seriesCache=null;
    private ?array $managementCache=null;
    private ?array $vatCache=null;
    private ?array $companiesCache=null;
    private array $productCache=[];
    private array $equivalenceCache=[];
    private static array $catalogueIndexCache=[];
    private bool $cifResolved=false;

    public function __construct(private string $clientId,private string $clientSecret,private string $cif,private string $series,private string $management='',private string $workStation='',private string $issuer='ALFAMED CLINIC SRL',private string $salesAgent=''){}

    private function token(): string {
        if($this->token)return $this->token;
        $r=Http::request('POST','https://www.oblio.eu/api/authorize/token',['Content-Type: application/x-www-form-urlencoded'],['client_id'=>$this->clientId,'client_secret'=>$this->clientSecret]);
        if($r['status']!==200||empty($r['json']['access_token']))throw new RuntimeException('Oblio autorizare esuata: '.$this->remoteMessage($r));
        return $this->token=(string)$r['json']['access_token'];
    }

    private function api(string $method,string $path,array $payload=[]): array {
        $method=strtoupper($method);$url='https://www.oblio.eu/api/'.ltrim($path,'/');
        $headers=['Authorization: Bearer '.$this->token(),'Accept: application/json'];$body=null;
        if($method==='POST'){$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        elseif($method==='GET'){if($payload)$url.=(str_contains($url,'?')?'&':'?').http_build_query($payload);}
        else{$headers[]='Content-Type: application/x-www-form-urlencoded';$body=$payload;}
        $r=Http::request($method,$url,$headers,$body);
        $json=is_array($r['json']??null)?$r['json']:[];$apiStatus=(int)($json['status']??200);
        if($r['status']<200||$r['status']>=300||$apiStatus>=400)throw new RuntimeException('Oblio API: '.$this->remoteMessage($r));
        return $json;
    }

    private function remoteMessage(array $r): string {
        $json=is_array($r['json']??null)?$r['json']:[];$parts=[];
        foreach(['statusMessage','message','error_description','error'] as $k){$v=$json[$k]??null;if(is_scalar($v)&&trim((string)$v)!=='')$parts[]=trim((string)$v);}
        if(isset($json['data'])&&is_array($json['data'])&&!array_is_list($json['data'])){foreach(['message','error'] as $k){$v=$json['data'][$k]??null;if(is_scalar($v)&&trim((string)$v)!=='')$parts[]=trim((string)$v);}}
        if(!$parts){$body=trim(strip_tags((string)($r['body']??'')));$parts[]=$body!==''?$body:'HTTP '.(int)($r['status']??0);}
        $msg=implode(' | ',array_values(array_unique($parts)));return function_exists('mb_substr')?mb_substr($msg,0,700,'UTF-8'):substr($msg,0,700);
    }

    public function diagnose(): array {
        $this->resolveCif();$known=[];foreach($this->companyRows() as $row)if(is_array($row))$known[]=trim((string)($row['cif']??''));
        $series=$this->seriesRows();$invoiceSeries=[];foreach($series as $row)if(strtolower((string)($row['type']??''))==='factura')$invoiceSeries[]=(string)($row['name']??'');
        if($invoiceSeries && !in_array($this->series,$invoiceSeries,true))throw new RuntimeException('Seria de factura "'.$this->series.'" nu exista in Oblio. Serii disponibile: '.implode(', ',$invoiceSeries).'.');
        $mgmt=$this->managementRows();$managements=[];$locations=[];
        foreach($mgmt as $row){if(!is_array($row))continue;$management=trim((string)($row['management']??''));$workStation=trim((string)($row['workStation']??''));if($management==='')continue;$managements[]=$management;$locations[]=trim(($workStation!==''?$workStation.' / ':'').$management);}
        $stock=$this->stockContext();
        return ['ok'=>true,'companies'=>$known,'invoice_series'=>$invoiceSeries,'managements'=>array_values(array_unique($managements)),'stock_locations'=>array_values(array_unique($locations)),'stock_context'=>$stock,'stocks_active'=>(bool)$managements];
    }

    private function companyRows(): array {if($this->companiesCache!==null)return $this->companiesCache;return $this->companiesCache=(array)($this->api('GET','nomenclature/companies')['data']??[]);}
    private function resolveCif(): void {
        if($this->cifResolved)return;$configured=trim($this->cif);$rows=$this->companyRows();$known=[];$digits=preg_replace('/\D+/','',$configured)??'';
        foreach($rows as $row){if(!is_array($row))continue;$candidate=trim((string)($row['cif']??''));if($candidate==='')continue;$known[]=$candidate;$candidateDigits=preg_replace('/\D+/','',$candidate)??'';if(strcasecmp($candidate,$configured)===0||($digits!==''&&$candidateDigits===$digits)){$this->cif=$candidate;$this->cifResolved=true;return;}}
        if(!$rows){$this->cifResolved=true;return;}
        throw new RuntimeException('CIF-ul configurat nu apartine contului Oblio. Disponibil: '.implode(', ',$known).'.');
    }
    private function seriesRows(): array {$this->resolveCif();if($this->seriesCache!==null)return $this->seriesCache;return $this->seriesCache=(array)($this->api('GET','nomenclature/series',['cif'=>$this->cif])['data']??[]);}
    private function vatRows(): array {$this->resolveCif();if($this->vatCache!==null)return $this->vatCache;return $this->vatCache=(array)($this->api('GET','nomenclature/vat_rates',['cif'=>$this->cif])['data']??[]);}
    private function managementRows(): array {
        $this->resolveCif();if($this->managementCache!==null)return $this->managementCache;
        try{return $this->managementCache=(array)($this->api('GET','nomenclature/management',['cif'=>$this->cif])['data']??[]);}
        catch(Throwable){return $this->managementCache=[];}
    }
    private function vatName(float $percent): string {
        foreach($this->vatRows() as $row)if(is_array($row)&&abs((float)($row['percent']??-999)-$percent)<0.01)return trim((string)($row['name']??''))?:'Normala';
        $available=[];foreach($this->vatRows() as $row)if(is_array($row))$available[]=(string)($row['percent']??'').'% '.(string)($row['name']??'');
        throw new RuntimeException('Cota TVA '.rtrim(rtrim(number_format($percent,2,'.',''),'0'),'.').'% nu exista in nomenclatorul Oblio'.($available?' (disponibile: '.implode(', ',$available).')':'').'.');
    }
    public function searchProducts(string $query,int $limit=25): array {
        $this->resolveCif();$query=trim($query);if($query==='')return [];
        $limit=max(1,min(50,$limit));$rows=[];$seen=[];
        $filters=[['code'=>$query],['name'=>$query]];
        foreach($filters as $filter){
            try{$data=(array)($this->api('GET','nomenclature/products',['cif'=>$this->cif]+$filter)['data']??[]);}catch(Throwable){$data=[];}
            foreach($data as $row){
                if(!is_array($row))continue;$code=trim((string)($row['code']??''));$name=trim((string)($row['name']??''));if($code===''&&$name==='')continue;
                $key=$code.'|'.$name;if(isset($seen[$key]))continue;$seen[$key]=true;$rows[]=['code'=>$code,'name'=>$name,'management'=>(string)($row['management']??''),'workStation'=>(string)($row['workStation']??''),'measuringUnit'=>(string)($row['measuringUnit']??$row['measuring_unit']??''),'productType'=>(string)($row['productType']??''),'stock'=>(array)($row['stock']??[])];
                if(count($rows)>=$limit)break 2;
            }
        }
        return $rows;
    }

    /**
     * Download the Oblio product nomenclature in documented 250-row pages.
     * Used by ALFAMED CENTRAL to maintain a local, read-only cache so invoice
     * creation does not need to scan the remote catalogue on every order.
     *
     * @return array{rows:array<int,array>,pages:int,complete:bool,cif:string}
     */
    public function allProducts(int $maxRows=20000): array {
        $this->resolveCif();
        $maxRows=max(250,min(50000,$maxRows));
        $pageSize=250;$offset=0;$pages=0;$rows=[];$complete=false;
        do{
            $batch=(array)($this->api('GET','nomenclature/products',[
                'cif'=>$this->cif,
                'includeUnsalable'=>1,
                'offset'=>$offset,
            ])['data']??[]);
            $pages++;$count=0;
            foreach($batch as $row){
                if(!is_array($row))continue;
                $rows[]=$row;$count++;
                if(count($rows)>=$maxRows)break 2;
            }
            if($count<$pageSize){$complete=true;break;}
            $offset+=$pageSize;
        }while(count($rows)<$maxRows);
        return ['rows'=>$rows,'pages'=>$pages,'complete'=>$complete,'cif'=>$this->cif];
    }

    /**
     * Resolve a channel product against the Oblio catalogue without guessing ambiguous matches.
     * Oblio's public REST API does not expose the integration-specific "Echivaleaza coduri" list,
     * so we first ask the product nomenclature by the external code. If Oblio resolves that code
     * to a different catalogue code, we accept it only when the product name also matches.
     * As a safe fallback we accept a unique exact-name match (or a unique exact-name row that has
     * stock in the configured location). The caller may persist the resolved pair locally.
     */
    public function resolveEquivalentProduct(array $sourceCodes,string $name): ?array {
        $this->resolveCif();
        $name=trim($name);$wantedName=$this->normalizeProductName($name);$stock=$this->stockContext();
        $codes=[];foreach($sourceCodes as $code){$code=trim((string)$code);if($code!==''&&!in_array($code,$codes,true))$codes[]=$code;if(count($codes)>=12)break;}
        $cacheKey=implode('|',$codes).'|'.$wantedName.'|'.($stock['management']??'').'|'.($stock['workStation']??'');
        if(array_key_exists($cacheKey,$this->equivalenceCache))return $this->equivalenceCache[$cacheKey];

        // Fast path: ask Oblio by its native product code.
        foreach($codes as $sourceCode){
            try{$rows=(array)($this->api('GET','nomenclature/products',['cif'=>$this->cif,'code'=>$sourceCode,'includeUnsalable'=>1])['data']??[]);}catch(Throwable){$rows=[];}
            foreach(array_values(array_filter($rows,'is_array')) as $row){
                if($this->normalizeProductKey((string)($row['code']??''))===$this->normalizeProductKey($sourceCode))return $this->equivalenceCache[$cacheKey]=$row+['_match_source_code'=>$sourceCode,'_match_reason'=>'exact_code'];
            }
        }

        // Reliable alternate-code path: the public filter does not search codeClient/codeEAN,
        // therefore index the catalogue once and compare these fields locally.
        $index=$this->catalogueIndex();
        foreach($codes as $sourceCode){
            $key=$this->normalizeProductKey($sourceCode);if($key==='')continue;
            if(isset($index['by_code'][$key]))return $this->equivalenceCache[$cacheKey]=$index['by_code'][$key]+['_match_source_code'=>$sourceCode,'_match_reason'=>'catalogue_code'];
            if(isset($index['by_alt'][$key])){
                $candidate=$this->uniqueIndexedCandidate((array)$index['by_alt'][$key],$stock);
                if($candidate!==null)return $this->equivalenceCache[$cacheKey]=$candidate+['_match_source_code'=>$sourceCode,'_match_reason'=>'catalogue_alternate_code'];
            }
        }

        // Last safe automatic fallback: one exact normalized product name.
        if($wantedName!==''){
            $candidate=$this->uniqueIndexedCandidate((array)($index['by_name'][$wantedName]??[]),$stock);
            if($candidate!==null)return $this->equivalenceCache[$cacheKey]=$candidate+['_match_source_code'=>($codes[0]??''),'_match_reason'=>'unique_exact_name'];
        }
        return $this->equivalenceCache[$cacheKey]=null;
    }

    /**
     * Build a read-only index of the existing Oblio catalogue. The public API can filter
     * directly only by name/code, so alternate identifiers (codeClient / codeEAN) need a
     * paged catalogue scan. Cache it per company for the lifetime of the PHP request so a
     * bulk invoice run does not download the catalogue repeatedly.
     *
     * @return array{by_code:array<string,array>,by_alt:array<string,array<int,array>>,by_name:array<string,array<int,array>>}
     */
    private function catalogueIndex(): array {
        $this->resolveCif();$cacheKey=$this->normalizeProductKey($this->cif);
        if(isset(self::$catalogueIndexCache[$cacheKey]))return self::$catalogueIndexCache[$cacheKey];
        $byCode=[];$byAlt=[];$byName=[];$offset=0;$pageSize=250;$maxRows=10000;$loaded=0;
        do{
            try{$rows=(array)($this->api('GET','nomenclature/products',['cif'=>$this->cif,'includeUnsalable'=>1,'offset'=>$offset])['data']??[]);}catch(Throwable){$rows=[];}
            $count=0;
            foreach($rows as $row){
                if(!is_array($row))continue;$count++;$loaded++;
                $codeKey=$this->normalizeProductKey((string)($row['code']??''));if($codeKey!==''&&!isset($byCode[$codeKey]))$byCode[$codeKey]=$row;
                foreach(['codeClient','codeEAN','ean','eanCode'] as $alt){$k=$this->normalizeProductKey((string)($row[$alt]??''));if($k!=='')$byAlt[$k][]=$row;}
                $nameKey=$this->normalizeProductName((string)($row['name']??''));if($nameKey!=='')$byName[$nameKey][]=$row;
                if($loaded>=$maxRows)break 2;
            }
            if($count<$pageSize)break;$offset+=$pageSize;
        }while($loaded<$maxRows);
        return self::$catalogueIndexCache[$cacheKey]=['by_code'=>$byCode,'by_alt'=>$byAlt,'by_name'=>$byName];
    }

    private function uniqueIndexedCandidate(array $rows,array $stock): ?array {
        $rows=array_values(array_filter($rows,'is_array'));if(count($rows)===1)return $rows[0];
        return $this->uniqueStockCandidate($rows,$stock);
    }

    private function normalizeProductName(string $value): string {
        $value=$this->normalizeProductKey($value);
        // Channel titles often omit Romanian diacritics while Oblio nomenclature keeps them.
        // Fold only deterministic orthographic variants; do not remove words or quantities.
        $value=strtr($value,['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t']);
        $value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value)??$value;
        return trim(preg_replace('/\s+/u',' ',$value)??$value);
    }

    private function uniqueStockCandidate(array $rows,array $stock): ?array {
        if(!$stock['enabled']||!$rows)return null;$withStock=[];
        foreach($rows as $row){if(!is_array($row))continue;foreach((array)($row['stock']??[]) as $sr){if(!is_array($sr))continue;$m=trim((string)($sr['management']??''));$w=trim((string)($sr['workStation']??''));$q=(float)($sr['quantity']??0);if($m===$stock['management']&&($stock['workStation']===''||$w===$stock['workStation'])&&$q>0.000001){$withStock[]=$row;break;}}}
        return count($withStock)===1?$withStock[0]:null;
    }

    private function stockContext(): array {
        $management=trim($this->management);$configuredWorkStation=trim($this->workStation);
        if($management==='')return ['enabled'=>false,'management'=>'','workStation'=>''];
        $rows=$this->managementRows();if(!$rows)return ['enabled'=>false,'management'=>'','workStation'=>''];
        $matches=[];$available=[];
        foreach($rows as $row){
            if(!is_array($row))continue;$m=trim((string)($row['management']??''));$w=trim((string)($row['workStation']??''));if($m==='')continue;
            $available[]=trim(($w!==''?$w.' / ':'').$m);
            if($m===$management && ($configuredWorkStation==='' || $w===$configuredWorkStation))$matches[]=['management'=>$m,'workStation'=>$w];
        }
        if(!$matches){
            $label=$configuredWorkStation!==''?$configuredWorkStation.' / '.$management:$management;
            throw new RuntimeException('Gestiunea/punctul de lucru "'.$label.'" nu exista in Oblio. Disponibile: '.implode(', ',array_values(array_unique($available))).'.');
        }
        $workStations=array_values(array_unique(array_map(static fn(array $r):string=>(string)$r['workStation'],$matches)));
        if($configuredWorkStation==='' && count($workStations)>1)throw new RuntimeException('Gestiunea "'.$management.'" exista in mai multe puncte de lucru ('.implode(', ',$workStations).'). Completeaza Punct de lucru in setarile Oblio.');
        $workStation=$configuredWorkStation!==''?$configuredWorkStation:(string)($workStations[0]??'');
        return ['enabled'=>true,'management'=>$management,'workStation'=>$workStation];
    }

    private function normalizeProductKey(string $value): string {
        $value=trim($value);return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    private function catalogueProduct(string $code,string $name): ?array {
        $this->resolveCif();$code=trim($code);$name=trim($name);$cacheKey=$code.'|'.$name;if(array_key_exists($cacheKey,$this->productCache))return $this->productCache[$cacheKey];
        $candidates=[];
        if($code!==''){
            try{$candidates=(array)($this->api('GET','nomenclature/products',['cif'=>$this->cif,'code'=>$code,'includeUnsalable'=>1])['data']??[]);}catch(Throwable){$candidates=[];}
            foreach($candidates as $row)if(is_array($row)&&$this->normalizeProductKey((string)($row['code']??''))===$this->normalizeProductKey($code))return $this->productCache[$cacheKey]=$row;
        }
        if($name!==''){
            try{$candidates=(array)($this->api('GET','nomenclature/products',['cif'=>$this->cif,'name'=>$name,'includeUnsalable'=>1])['data']??[]);}catch(Throwable){$candidates=[];}
            $wanted=$this->normalizeProductName($name);$exact=array_values(array_filter($candidates,fn($row)=>is_array($row)&&$this->normalizeProductName((string)($row['name']??''))===$wanted));
            if(count($exact)===1)return $this->productCache[$cacheKey]=$exact[0];
            $stockChoice=$this->uniqueStockCandidate($exact,$this->stockContext());if($stockChoice!==null)return $this->productCache[$cacheKey]=$stockChoice;
        }
        return $this->productCache[$cacheKey]=null;
    }

    private function lineFromCatalogue(array $item,array $line,array $stock,float $qty): array {
        $catalogue=is_array($item['_oblio_catalogue_row']??null)?$item['_oblio_catalogue_row']:$this->catalogueProduct((string)($line['code']??''),(string)($line['name']??''));
        if(!$catalogue){
            if(!empty($item['_require_oblio_catalogue']))throw new RuntimeException('Produsul eMAG "'.(string)($line['name']??'').'" (SKU '.(string)($item['_channel_code']??$line['code']??'').') nu a putut fi asociat sigur cu un produs existent in nomenclatorul Oblio. Factura nu a fost emisa pentru a evita crearea sau folosirea unui cod gresit. Verifica Echivalare coduri produse in Setari > Module > Oblio.');
            if($stock['enabled'])throw new RuntimeException('Produsul "'.(string)($line['name']??'').'" (cod '.(string)($line['code']??'').') nu a fost gasit exact in nomenclatorul Oblio. Verifica SKU-ul sau echivalarea produsului din Setari > Module > Oblio.');
            return $line;
        }
        $catalogueCode=trim((string)($catalogue['code']??''));$catalogueName=trim((string)($catalogue['name']??''));$unit=trim((string)($catalogue['measuringUnit']??$catalogue['measuring_unit']??''));$type=trim((string)($catalogue['productType']??''));
        if($catalogueCode!=='')$line['code']=$catalogueCode;if($catalogueName!=='')$line['name']=$catalogueName;if($unit!=='')$line['measuringUnit']=$unit;if($type!=='')$line['productType']=$type;
        if(!$stock['enabled'] || strcasecmp($type,'Serviciu')===0)return $line;
        $line['management']=$stock['management'];
        $stockRows=(array)($catalogue['stock']??[]);$available=[];$selected=null;
        foreach($stockRows as $row){
            if(!is_array($row))continue;$m=trim((string)($row['management']??''));$w=trim((string)($row['workStation']??''));$q=(float)($row['quantity']??0);$available[]=rtrim(rtrim(number_format($q,4,'.',''),'0'),'.').' buc in '.trim(($w!==''?$w.' / ':'').($m!==''?$m:'gestiune necunoscuta'));
            if($m===$stock['management'] && ($stock['workStation']==='' || $w===$stock['workStation']))$selected=$q;
        }
        $selected??=0.0;
        if($selected+0.000001<$qty){
            $location=trim(($stock['workStation']!==''?$stock['workStation'].' / ':'').$stock['management']);$details=$available?(' Stocuri raportate de Oblio: '.implode('; ',$available).'.'):'';
            throw new RuntimeException('Produsul "'.$line['name'].'" are stoc '.rtrim(rtrim(number_format($selected,4,'.',''),'0'),'.').' in '.$location.', dar comanda cere '.rtrim(rtrim(number_format($qty,4,'.',''),'0'),'.').'.'.$details);
        }
        return $line;
    }
    private function validateSeries(string $series,string $type): void {
        $wanted=strtolower($type);$available=[];
        foreach($this->seriesRows() as $row){if(!is_array($row))continue;$t=strtolower((string)($row['type']??''));$name=(string)($row['name']??'');if($t===$wanted)$available[]=$name;if($t===$wanted&&$name===$series)return;}
        if($available)throw new RuntimeException('Seria "'.$series.'" nu este configurata in Oblio pentru '.$type.'. Serii disponibile: '.implode(', ',$available).'.');
        throw new RuntimeException('Oblio nu a returnat nicio serie pentru '.$type.'. Verifica nomenclatorul de serii din Oblio.');
    }

    private function documentPayload(array $order,array $items,string $series,string $type='Factura'): array {
        $this->validateSeries($series,$type);
        $stock=$this->stockContext();
        $billing=is_array($order['billing']??null)?$order['billing']:(json_decode((string)($order['billing_json']??'{}'),true)?:[]);
        $raw=is_array($order['raw']??null)?$order['raw']:(json_decode((string)($order['raw_json']??'{}'),true)?:[]);
        $isEmag=(string)($order['channel_type']??'')==='emag';$rawProducts=[];
        if($isEmag){foreach((array)($raw['products']??[]) as $rp)if(is_array($rp)){$key=(string)($rp['id']??$rp['product_id']??'');if($key!=='')$rawProducts[$key]=$rp;}}
        $products=[];$computedTotal=0.0;
        foreach($items as $i){
            $price=(float)$i['unit_price'];$vat=(float)($i['vat_rate']??0);
            if($isEmag){$key=(string)($i['external_item_id']??'');$rp=$rawProducts[$key]??null;if(is_array($rp)){$price=EmagOrderNormalizer::grossPrice($rp);$vat=EmagOrderNormalizer::vatPercent($rp['vat']??$rp['vat_rate']??$vat);}}
            $qty=(float)$i['qty'];$computedTotal+=$price*$qty;
            $line=['name'=>(string)$i['name'],'code'=>(string)($i['sku']?:('ITEM-'.$i['id'])),'price'=>round($price,4),'measuringUnit'=>'buc','vatName'=>$this->vatName($vat),'vatPercentage'=>$vat,'vatIncluded'=>1,'quantity'=>$qty];
            $channelCode=trim((string)($i['_channel_code']??''));if($channelCode==='')$channelCode=trim((string)($i['_source_sku']??''));if($channelCode!==''&&$this->normalizeProductKey($channelCode)!==$this->normalizeProductKey((string)$line['code']))$line['codeClient']=$channelCode;
            $ean=preg_replace('/\s+/','',trim((string)($i['_source_ean']??'')))??'';if($ean!==''&&preg_match('/^[0-9]{8,14}$/',$ean))$line['codeEAN']=$ean;
            $line=$this->lineFromCatalogue($i,$line,$stock,$qty);
            $products[]=$line;
        }
        $shipping=$this->shippingLine($order,$raw,$items,$stock);
        if($shipping!==null){$computedTotal+=(float)$shipping['price'];$products[]=$shipping;}
        if($isEmag){$voucherTotal=EmagOrderNormalizer::voucherTotal($raw);if($voucherTotal<0){$discount=round(abs($voucherTotal),2);$computedTotal-=$discount;$products[]=['name'=>'Voucher / discount eMAG','discountType'=>'valoric','discount'=>$discount,'discountAllAbove'=>1];}}
        if($isEmag && abs($computedTotal-(float)$order['total'])>0.08)throw new RuntimeException('Totalul calculat pentru factura eMAG ('.number_format($computedTotal,2,'.','').' '.$order['currency'].') nu corespunde totalului comenzii ('.number_format((float)$order['total'],2,'.','').' '.$order['currency'].'). Comanda poate contine voucher/discount sau ajustari eMAG; factura nu a fost emisa pentru a evita o valoare gresita.');
        $fiscal=(array)($billing['_fiscal']??[]);$clientName=trim((string)($billing['company']??''));if($clientName==='')$clientName=trim((string)($billing['name']??$order['customer_name']??'Client'));
        $client=['name'=>$clientName?:'Client','address'=>(string)($billing['address_1']??''),'state'=>(string)($billing['state']??''),'city'=>(string)($billing['city']??''),'country'=>(string)($billing['country']??'RO'),'email'=>(string)($order['customer_email']??''),'phone'=>(string)($order['customer_phone']??''),'vatPayer'=>(bool)($fiscal['vat_payer']??false)];
        $clientCif=trim((string)($fiscal['vat_number']??$billing['company_vat']??''));if($clientCif!=='')$client['cif']=$clientCif;
        if(!empty($fiscal['registration_number']))$client['rc']=(string)$fiscal['registration_number'];if(!empty($fiscal['bank']))$client['bank']=(string)$fiscal['bank'];if(!empty($fiscal['iban']))$client['iban']=(string)$fiscal['iban'];
        $payload=['cif'=>$this->cif,'client'=>$client,'issueDate'=>date('Y-m-d'),'seriesName'=>$series,'language'=>'RO','precision'=>2,'currency'=>(string)$order['currency'],'products'=>$products,'issuerName'=>$this->issuer,'internalNote'=>'Comanda '.$order['code'],'mentions'=>'Canal '.$order['channel_name'].' / comanda externa '.$order['external_id']];
        if($stock['enabled'] && $stock['workStation']!=='')$payload['workStation']=$stock['workStation'];
        if(trim($this->salesAgent)!=='')$payload['selesAgent']=trim($this->salesAgent);
        $payload['idempotencyKey']=hash('sha256','ALFAMED-CENTRAL|'.(string)($order['channel_code']??'').'|'.(string)($order['external_id']??$order['id']??'').'|'.$series.'|'.$type);
        return $payload;
    }

    private function shippingLine(array $order,array $raw,array $items,array $stock): ?array {
        $price=0.0;$vat=0.0;
        if((string)($order['channel_type']??'')==='emag'){$price=(float)($raw['shipping_tax']??0);if($price<=0)return null;foreach((array)($raw['products']??[]) as $p)if(is_array($p)){$vat=EmagOrderNormalizer::vatPercent($p['vat']??$p['vat_rate']??0);if($vat>0)break;}if($vat<=0&&$items)$vat=(float)($items[0]['vat_rate']??0);}
        elseif((string)($order['channel_type']??'')==='woocommerce'){$net=(float)($raw['shipping_total']??0);$tax=(float)($raw['shipping_tax']??0);$price=$net+$tax;if($price<=0)return null;if($net>0&&$tax>0)$vat=round(($tax/$net)*100,4);elseif($items)$vat=(float)($items[0]['vat_rate']??0);}
        if($price<=0)return null;$line=['name'=>'Transport','code'=>'TRANSPORT','price'=>round($price,4),'measuringUnit'=>'buc','vatName'=>$this->vatName($vat),'vatPercentage'=>max(0,$vat),'vatIncluded'=>1,'quantity'=>1];if($stock['enabled'])$line['productType']='Serviciu';return $line;
    }

    public function createInvoice(array $order,array $items,?string $series=null): array {return $this->api('POST','docs/invoice',$this->documentPayload($order,$items,$series?:$this->series,'Factura'));}
    public function createProforma(array $order,array $items,string $series='PR'): array {return $this->api('POST','docs/proforma',$this->documentPayload($order,$items,$series,'Proforma'));}
    public function createNotice(array $order,array $items,string $series='AV'): array {$p=$this->documentPayload($order,$items,$series,'Aviz');$p['useStock']=1;return $this->api('POST','docs/notice',$p);}
    public function storno(string $series,string $number): array {
        $this->resolveCif();$this->validateSeries($this->series,'Factura');$stock=$this->stockContext();
        $payload=['cif'=>$this->cif,'seriesName'=>$this->series,'issueDate'=>date('Y-m-d'),'referenceDocument'=>['type'=>'Factura','refund'=>1,'seriesName'=>$series,'number'=>$number],'issuerName'=>$this->issuer,'mentions'=>'Storno total factura '.$series.' '.$number];
        if($stock['enabled']){$payload['useStock']=1;if($stock['workStation']!=='')$payload['workStation']=$stock['workStation'];}
        if(trim($this->salesAgent)!=='')$payload['selesAgent']=trim($this->salesAgent);
        return $this->api('POST','docs/invoice',$payload);
    }
    public function cancel(string $series,string $number): array {return $this->api('PUT','docs/invoice/cancel',['cif'=>$this->cif,'seriesName'=>$series,'number'=>$number]);}
}

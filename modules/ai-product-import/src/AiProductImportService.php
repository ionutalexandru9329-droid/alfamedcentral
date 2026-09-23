<?php
namespace AlfamedModules\AiProductImport;

use App\Services\ProductService;
use App\Services\SettingService;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class AiProductImportService {
    private SettingService $settings;
    public function __construct(){ $this->settings=new SettingService(); }

    public function preview(string $rawUrls): array {
        $urls=[];
        foreach(preg_split('/[\r\n,]+/u',$rawUrls)?:[] as $raw){$u=trim($raw);if($u!==''&&!in_array($u,$urls,true))$urls[]=$u;}
        $max=max(1,min(25,(int)$this->settings->get('module.ai-product-import.max_urls','10')));
        if(!$urls) throw new RuntimeException('Introdu cel putin un URL public de produs sau categorie.');
        if(count($urls)>$max) throw new RuntimeException('Poti procesa maximum '.$max.' URL-uri odata.');
        $products=[];$errors=[];
        foreach($urls as $url){
            try{
                $page=$this->extractPage($url);
                if($page['products']){$products=array_merge($products,$page['products']);continue;}
                // Unele pagini de categorie expun ItemList cu URL-uri catre produse.
                foreach(array_slice($page['linked_urls'],0,max(0,$max-count($products))) as $child){
                    try{$childPage=$this->extractPage($child);if($childPage['products'])$products=array_merge($products,$childPage['products']);}
                    catch(\Throwable $e){$errors[]=$child.': '.$e->getMessage();}
                    if(count($products)>=$max)break;
                }
                if(!$page['products']&&!$page['linked_urls'])$errors[]=$url.': nu am identificat date de produs in pagina.';
            }catch(\Throwable $e){$errors[]=$url.': '.$e->getMessage();}
            if(count($products)>=$max)break;
        }
        $unique=[];$out=[];
        foreach($products as $p){$key=sha1((string)($p['source_url']??'')."\0".(string)($p['name']??''));if(isset($unique[$key]))continue;$unique[$key]=true;$out[]=$p;if(count($out)>=$max)break;}
        if(!$out && $errors) throw new RuntimeException('Importul nu a gasit produse. '.implode(' | ',array_slice($errors,0,3)));
        return ['products'=>$out,'errors'=>$errors];
    }

    public function createDrafts(array $products,array $selected,array $targets): array {
        $targets=array_values(array_intersect(['univera','alfamed'],$targets));
        if(!$targets) throw new RuntimeException('Selecteaza cel putin un magazin destinatie.');
        $selected=array_values(array_unique(array_map('intval',$selected)));if(!$selected)throw new RuntimeException('Selecteaza cel putin un produs din previzualizare.');
        $service=new ProductService();$ok=0;$errors=[];$created=[];
        foreach($selected as $i){
            if(!isset($products[$i])||!is_array($products[$i]))continue;$p=$products[$i];
            try{
                $post=[
                    'targets'=>$targets,'name'=>(string)($p['name']??'Produs importat'),'sku'=>'','ean'=>(string)($p['ean']??''),
                    '_auto_sku'=>'1',
                    'regular_price'=>(string)($p['price']??''),'sale_price'=>'','description'=>(string)($p['description']??''),
                    'short_description'=>(string)($p['short_description']??''),'status'=>'draft','stock_quantity'=>(int)($p['stock_quantity']??0),
                    'images'=>implode("\n",(array)($p['images']??[])),'categories'=>implode(', ',(array)($p['categories']??[])),
                    'tags'=>implode(', ',(array)($p['tags']??[])),'brands'=>(string)($p['brand']??''),
                    'seo_title'=>(string)($p['seo_title']??$p['name']??''),'seo_description'=>(string)($p['seo_description']??$p['short_description']??''),
                    'seo_focus_keyword'=>(string)($p['focus_keyword']??''),
                ];
                $r=$service->createFromForm($post);$created[]=['source_url'=>$p['source_url']??'','name'=>$post['name'],'result'=>$r];$ok++;
            }catch(\Throwable $e){$errors[]=(string)($p['name']??('#'.($i+1))).': '.$e->getMessage();}
        }
        return ['ok'=>$ok,'errors'=>$errors,'created'=>$created];
    }

    private function extractPage(string $url): array {
        if(!class_exists(DOMDocument::class)||!class_exists(DOMXPath::class))throw new RuntimeException('Modulul Auto Import necesita extensia PHP DOM/XML.');
        $html=$this->fetch($url);if(strlen($html)>3*1024*1024)throw new RuntimeException('Pagina depaseste limita de 3 MB.');
        $dom=new DOMDocument();$prev=libxml_use_internal_errors(true);$loaded=$dom->loadHTML($html,LIBXML_NOWARNING|LIBXML_NOERROR|LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($prev);if(!$loaded)throw new RuntimeException('HTML-ul paginii nu a putut fi analizat.');
        $xp=new DOMXPath($dom);$products=[];$linked=[];
        foreach($xp->query('//script[@type="application/ld+json"]')?:[] as $node){$data=json_decode(trim((string)$node->textContent),true);if(!is_array($data))continue;foreach($this->walkJsonLd($data) as $item){$type=$item['@type']??'';$types=is_array($type)?$type:[$type];if(in_array('Product',$types,true)){$products[]=$this->fromJsonLd($item,$url,$xp);continue;}if(in_array('ItemList',$types,true)){foreach((array)($item['itemListElement']??[]) as $el){$candidate=is_array($el)?($el['url']??($el['item']['url']??null)):null;if(is_string($candidate)&&$candidate!=='')$linked[]=$this->absoluteUrl($url,$candidate);}}}}
        if(!$products){$fallback=$this->fromMeta($url,$xp);if($fallback!==null)$products[]=$fallback;}
        $linked=array_values(array_unique(array_filter($linked,fn($u)=>$u!==$url)));
        return ['products'=>$products,'linked_urls'=>$linked];
    }

    private function walkJsonLd(array $data): array {
        $out=[];$stack=[$data];while($stack){$cur=array_pop($stack);if(!is_array($cur))continue;if(isset($cur['@type']))$out[]=$cur;foreach(['@graph','itemListElement'] as $k){if(!isset($cur[$k])||!is_array($cur[$k]))continue;foreach($cur[$k] as $v)if(is_array($v)){$stack[]=$v;if(isset($v['item'])&&is_array($v['item']))$stack[]=$v['item'];}}}return $out;
    }

    private function fromJsonLd(array $p,string $url,DOMXPath $xp): array {
        $name=$this->scalar($p['name']??'');if($name==='')$name=$this->meta($xp,'property','og:title');if($name==='')throw new RuntimeException('Produs JSON-LD fara titlu.');
        $desc=$this->scalar($p['description']??'');$images=[];$rawImages=$p['image']??[];if(is_string($rawImages))$rawImages=[$rawImages];elseif(is_array($rawImages)&&isset($rawImages['url']))$rawImages=[$rawImages['url']];foreach((array)$rawImages as $im){if(is_array($im))$im=$im['url']??$im['contentUrl']??'';if(is_string($im)&&$im!=='')$images[]=$this->absoluteUrl($url,$im);}if(!$images){$og=$this->meta($xp,'property','og:image');if($og!=='')$images[]=$this->absoluteUrl($url,$og);}
        $offers=$p['offers']??[];if(isset($offers['price'])||isset($offers['lowPrice']))$offers=[$offers];$price='';$currency='';$stock=0;foreach((array)$offers as $o){if(!is_array($o))continue;$price=(string)($o['price']??$o['lowPrice']??$price);$currency=(string)($o['priceCurrency']??$currency);$availability=(string)($o['availability']??'');if(stripos($availability,'InStock')!==false||stripos($availability,'LimitedAvailability')!==false)$stock=max($stock,1);if($price!=='')break;}
        $brand=$p['brand']??'';if(is_array($brand))$brand=$brand['name']??'';
        $category=$p['category']??'';$categories=[];if(is_string($category)&&trim($category)!=='')$categories=preg_split('/\s*[>\/|]\s*/u',$category)?:[$category];
        $keywords=$this->meta($xp,'name','keywords');$tags=$keywords!==''?(preg_split('/\s*,\s*/u',$keywords)?:[]):[];
        $canonical=$this->canonical($xp,$url);
        return ['source_url'=>$canonical,'name'=>$name,'sku'=>$this->scalar($p['sku']??$p['mpn']??''),'ean'=>$this->scalar($p['gtin13']??$p['gtin14']??$p['gtin12']??$p['gtin']??''),'price'=>$price,'currency'=>$currency,'stock_quantity'=>$stock,'description'=>$desc,'short_description'=>$this->plainExcerpt($desc),'images'=>array_values(array_unique($images)),'categories'=>array_values(array_unique(array_filter(array_map('trim',$categories)))),'tags'=>array_values(array_unique(array_filter(array_map('trim',$tags)))),'brand'=>$this->scalar($brand),'seo_title'=>$this->meta($xp,'property','og:title')?:$name,'seo_description'=>$this->meta($xp,'name','description')?:$this->plainExcerpt($desc),'focus_keyword'=>''];
    }

    private function fromMeta(string $url,DOMXPath $xp): ?array {
        $name=$this->meta($xp,'property','og:title');if($name==='')$name=$this->meta($xp,'name','twitter:title');if($name===''){foreach($xp->query('//title')?:[] as $n){$name=trim((string)$n->textContent);break;}}
        $image=$this->meta($xp,'property','og:image');$desc=$this->meta($xp,'property','og:description')?:$this->meta($xp,'name','description');$price=$this->meta($xp,'property','product:price:amount');if($price==='')$price=$this->itemProp($xp,'price');
        // Avoid importing generic category/home pages just because they have OpenGraph tags.
        $productSignal=$price!==''||$this->meta($xp,'property','og:type')==='product'||$this->itemProp($xp,'sku')!=='';
        if($name===''||!$productSignal)return null;
        $keywords=$this->meta($xp,'name','keywords');return ['source_url'=>$this->canonical($xp,$url),'name'=>$name,'sku'=>$this->itemProp($xp,'sku'),'ean'=>$this->itemProp($xp,'gtin13')?:$this->itemProp($xp,'gtin'),'price'=>$price,'currency'=>$this->meta($xp,'property','product:price:currency'),'stock_quantity'=>0,'description'=>$desc,'short_description'=>$this->plainExcerpt($desc),'images'=>$image!==''?[$this->absoluteUrl($url,$image)]:[],'categories'=>[],'tags'=>$keywords!==''?(preg_split('/\s*,\s*/u',$keywords)?:[]):[],'brand'=>$this->itemProp($xp,'brand'),'seo_title'=>$name,'seo_description'=>$desc,'focus_keyword'=>''];
    }

    private function fetch(string $url): string {
        if(!function_exists('curl_init'))throw new RuntimeException('Modulul Auto Import necesita extensia PHP cURL.');
        $timeout=max(3,min(30,(int)$this->settings->get('module.ai-product-import.fetch_timeout','10')));$current=$this->validateUrl($url);
        for($hop=0;$hop<4;$hop++){
            $headers=[];$ch=curl_init($current);if(!$ch)throw new RuntimeException('Nu pot initializa cURL.');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; ALFAMED-CENTRAL-SmartImport/1.0)',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.2'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'']);
            curl_setopt($ch,CURLOPT_HEADERFUNCTION,static function($ch,string $line) use (&$headers):int{$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return $len;});
            $body=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);if($body===false)throw new RuntimeException('Eroare HTTP: '.$err);
            if(in_array($code,[301,302,303,307,308],true)&&!empty($headers['location'])){$current=$this->validateUrl($this->absoluteUrl($current,$headers['location']));continue;}
            if($code<200||$code>=300)throw new RuntimeException('Site-ul a raspuns HTTP '.$code.'.');if($type!==''&&!str_contains(strtolower($type),'html'))throw new RuntimeException('URL-ul nu pare sa fie o pagina HTML.');return (string)$body;
        }
        throw new RuntimeException('Prea multe redirectari.');
    }

    private function validateUrl(string $url): string {
        $url=trim($url);if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('URL invalid.');$p=parse_url($url);$scheme=strtolower((string)($p['scheme']??''));if(!in_array($scheme,['http','https'],true))throw new RuntimeException('Sunt permise doar URL-uri HTTP/HTTPS.');if(isset($p['user'])||isset($p['pass']))throw new RuntimeException('URL-urile cu credentiale nu sunt permise.');$port=(int)($p['port']??($scheme==='https'?443:80));if(!in_array($port,[80,443],true))throw new RuntimeException('Portul URL nu este permis.');$host=(string)($p['host']??'');if($host===''||strtolower($host)==='localhost')throw new RuntimeException('Host invalid.');
        $ips=[];if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;else{foreach((array)@gethostbynamel($host) as $ip)$ips[]=$ip;if(function_exists('dns_get_record'))foreach((array)@dns_get_record($host,DNS_AAAA) as $r)if(!empty($r['ipv6']))$ips[]=$r['ipv6'];}
        if(!$ips)throw new RuntimeException('Domeniul nu poate fi rezolvat.');foreach(array_unique($ips) as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Adresele locale/private nu sunt permise.');}
        return $url;
    }

    private function meta(DOMXPath $xp,string $attr,string $value): string {$q='//meta[translate(@'.$attr.',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.strtolower($value).'"]/@content';foreach($xp->query($q)?:[] as $n)return trim((string)$n->nodeValue);return '';}
    private function itemProp(DOMXPath $xp,string $value): string {$q='//*[@itemprop="'.$value.'"]';foreach($xp->query($q)?:[] as $n){$v=$n->attributes?->getNamedItem('content')?->nodeValue;if(!$v)$v=$n->attributes?->getNamedItem('value')?->nodeValue;if(!$v)$v=$n->textContent;return trim((string)$v);}return '';}
    private function canonical(DOMXPath $xp,string $url): string {foreach($xp->query('//link[contains(concat(" ",normalize-space(@rel)," ")," canonical ")]/@href')?:[] as $n){$v=trim((string)$n->nodeValue);if($v!=='')return $this->absoluteUrl($url,$v);}return $url;}
    private function scalar(mixed $v): string {if(is_scalar($v))return trim((string)$v);return '';}
    private function plainExcerpt(string $v): string {$plain=trim(preg_replace('/\s+/u',' ',strip_tags($v))??'');return function_exists('mb_substr')?mb_substr($plain,0,600,'UTF-8'):substr($plain,0,600);}
    private function absoluteUrl(string $base,string $relative): string {$relative=trim($relative);if($relative==='')return $base;if(preg_match('#^https?://#i',$relative))return $relative;$p=parse_url($base);$scheme=(string)($p['scheme']??'https');$host=(string)($p['host']??'');$port=isset($p['port'])?':'.$p['port']:'';if(str_starts_with($relative,'//'))return $scheme.':'.$relative;if(str_starts_with($relative,'/'))return $scheme.'://'.$host.$port.$relative;$path=(string)($p['path']??'/');$dir=preg_replace('#/[^/]*$#','/',$path)?:'/';$full=$dir.$relative;$parts=[];foreach(explode('/',$full) as $part){if($part===''||$part==='.')continue;if($part==='..')array_pop($parts);else$parts[]=$part;}return $scheme.'://'.$host.$port.'/'.implode('/',$parts);}
}

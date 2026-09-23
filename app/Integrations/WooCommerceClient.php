<?php
namespace App\Integrations;

use App\Core\Http;
use RuntimeException;

final class WooCommerceClient {
    public function __construct(private string $baseUrl, private string $key, private string $secret) {}

    public function baseUrl(): string { return rtrim($this->baseUrl,'/'); }

    private function request(string $method,string $path,array $query=[],?array $payload=null): array {
        $url=$this->baseUrl().'/wp-json/wc/v3/'.ltrim($path,'/');
        if($query) $url.='?'.http_build_query($query);
        $headers=['Authorization: Basic '.base64_encode($this->key.':'.$this->secret),'Accept: application/json'];
        $body=null;
        if($payload!==null){
            $headers[]='Content-Type: application/json';
            $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        $r=Http::request($method,$url,$headers,$body);
        if($r['status']<200||$r['status']>=300) throw new RuntimeException("WooCommerce HTTP {$r['status']}: ".substr($r['body'],0,900));
        return $r['json']??[];
    }

    public function readOrders(array $query=[]): array { return $this->request('GET','orders',$query+['per_page'=>100,'orderby'=>'date','order'=>'desc']); }
    public function readOrder(string|int $id): array { return $this->request('GET','orders/'.$id); }
    public function updateOrder(string|int $id,array $payload): array { return $this->request('PUT','orders/'.$id,[],$payload); }
    public function deleteOrder(string|int $id,bool $force=false): array { return $this->request('DELETE','orders/'.$id,['force'=>$force?'true':'false']); }
    public function createOrderNote(string|int $id,string $note,bool $customerNote=true): array { return $this->request('POST','orders/'.$id.'/notes',[],['note'=>$note,'customer_note'=>$customerNote]); }
    public function deleteOrderNote(string|int $orderId,string|int $noteId): array { return $this->request('DELETE','orders/'.$orderId.'/notes/'.$noteId,['force'=>'true']); }

    public function readProducts(array $query=[]): array { return $this->request('GET','products',$query+['per_page'=>100]); }
    public function readProduct(string|int $id): array { return $this->request('GET','products/'.$id); }
    public function updateProduct(string|int $id,array $payload): array { return $this->request('PUT','products/'.$id,[],$payload); }
    public function createProduct(array $payload): array { return $this->request('POST','products',[],$payload); }
    public function deleteProduct(string|int $id,bool $force=true): array { return $this->request('DELETE','products/'.$id,['force'=>$force?'true':'false']); }

    public function readWebhooks(array $query=[]): array { return $this->request('GET','webhooks',$query+['per_page'=>100]); }
    public function createWebhook(array $payload): array { return $this->request('POST','webhooks',[],$payload); }
    public function updateWebhook(string|int $id,array $payload): array { return $this->request('PUT','webhooks/'.$id,[],$payload); }

    public function readTerms(string $kind,array $query=[]): array {
        $path=match($kind){
            'categories'=>'products/categories',
            'tags'=>'products/tags',
            'brands'=>'products/brands',
            default=>throw new RuntimeException('Taxonomie WooCommerce necunoscuta: '.$kind),
        };
        return $this->request('GET',$path,$query+['per_page'=>100]);
    }

    public function createTerm(string $kind,string $name): array {
        $path=match($kind){
            'categories'=>'products/categories',
            'tags'=>'products/tags',
            'brands'=>'products/brands',
            default=>throw new RuntimeException('Taxonomie WooCommerce necunoscuta: '.$kind),
        };
        return $this->request('POST',$path,[],['name'=>$name]);
    }
}

<?php
namespace App\Core;
use RuntimeException;

final class Http {
    public static function request(string $method,string $url,array $headers=[],array|string|null $body=null,int $timeout=30): array {
        if(is_array($body)) $body=http_build_query($body);
        $info=[];
        if(function_exists('curl_init')){
            $ch=curl_init($url); if(!$ch) throw new RuntimeException('Nu se poate initializa cURL');
            $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true];
            if($headers) $opts[CURLOPT_HTTPHEADER]=$headers; if($body!==null) $opts[CURLOPT_POSTFIELDS]=$body;
            curl_setopt_array($ch,$opts); $raw=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $curlInfo=curl_getinfo($ch);
            $info=[
                'effective_url'=>(string)($curlInfo['url']??$url),
                'primary_ip'=>(string)($curlInfo['primary_ip']??''),
                'local_ip'=>(string)($curlInfo['local_ip']??''),
                'total_time_ms'=>(int)round(((float)($curlInfo['total_time']??0))*1000),
            ];
            curl_close($ch);
            if($raw===false) throw new RuntimeException('HTTP error: '.$err);
        } else {
            $headerText=implode("\r\n",$headers); $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headerText,'content'=>$body??'','timeout'=>$timeout,'ignore_errors'=>true]]);
            $started=microtime(true);$raw=@file_get_contents($url,false,$ctx);$elapsed=(int)round((microtime(true)-$started)*1000);if($raw===false) throw new RuntimeException('HTTP request esuat; activeaza extensia cURL sau allow_url_fopen.');
            $code=0; foreach($http_response_header??[] as $h){ if(preg_match('#^HTTP/\S+\s+(\d+)#',$h,$m)){$code=(int)$m[1];break;} }
            $info=['effective_url'=>$url,'primary_ip'=>'','local_ip'=>'','total_time_ms'=>$elapsed];
        }
        $json=json_decode($raw,true); return ['status'=>$code,'body'=>$raw,'json'=>is_array($json)?$json:null,'info'=>$info];
    }
}

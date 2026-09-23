<?php
namespace App\Services;
use App\Core\Env;
use App\Integrations\EmagClient;
use App\Integrations\WooCommerceClient;

final class ChannelFactory {
    public static function client(string $code): EmagClient|WooCommerceClient|null {
        return match($code){
            'emag_ro' => Env::bool('EMAG_RO_ENABLED') ? new EmagClient((string)Env::get('EMAG_RO_BASE_URL'),(string)Env::get('EMAG_RO_USERNAME'),(string)Env::get('EMAG_RO_PASSWORD'),'RON') : null,
            'emag_bg' => Env::bool('EMAG_BG_ENABLED') ? new EmagClient((string)Env::get('EMAG_BG_BASE_URL'),(string)Env::get('EMAG_BG_USERNAME'),(string)Env::get('EMAG_BG_PASSWORD'),'EUR') : null,
            'univera' => Env::bool('WC_UNIVERA_ENABLED') ? new WooCommerceClient((string)Env::get('WC_UNIVERA_BASE_URL'),(string)Env::get('WC_UNIVERA_KEY'),(string)Env::get('WC_UNIVERA_SECRET')) : null,
            'alfamed' => Env::bool('WC_ALFAMED_ENABLED') ? new WooCommerceClient((string)Env::get('WC_ALFAMED_BASE_URL'),(string)Env::get('WC_ALFAMED_KEY'),(string)Env::get('WC_ALFAMED_SECRET')) : null,
            default => null,
        };
    }
}

<?php
namespace App\Services;

/**
 * Normalize billing/company fields returned by WooCommerce and common Romanian
 * checkout/company-field plugins. The original payload is still stored in
 * raw_json; this class only exposes a stable subset used by the admin UI.
 */
final class WooOrderNormalizer
{
    /** @var array<string,array<int,string>> */
    private const IGNORED_EXTRAS = [
        'prices_include_tax'=>true,
        'prices_include_taxes'=>true,
        'discount_tax'=>true,
        'shipping_tax'=>true,
        'cart_tax'=>true,
        'total_tax'=>true,
        'is_vat_exempt'=>true,
        'is_tva_exempt'=>true,
    ];

    private const ALIASES = [
        'person_type' => [
            'billing_customer_type','billing_person_type','billing_pers_type','billing_tip_persoana',
            'billing_type','billing_company_type','customer_type','person_type','tip_persoana','tip_client',
        ],
        'vat_number' => [
            'billing_cui','billing_cif','billing_vat','billing_vat_number','billing_vat_id','billing_tax_id',
            'billing_fiscal_code','billing_cod_fiscal','cui','cif','vat','vat_number','tax_id','cod_fiscal',
        ],
        'registration_number' => [
            'billing_nr_reg_com','billing_reg_com','billing_reg_comert','billing_registration_number',
            'billing_company_reg_number','billing_reg_no','billing_j','nr_reg_com','reg_com','reg_comert',
            'registration_number','company_reg_number','reg_no','j',
        ],
        'bank' => [
            'billing_bank','billing_banca','billing_bank_name','bank','banca','bank_name',
        ],
        'iban' => [
            'billing_iban','billing_bank_account','billing_cont_bancar','billing_cont','iban','bank_account','cont_bancar','cont',
        ],
        'cnp' => [
            'billing_cnp','billing_personal_id','billing_national_id','cnp','personal_id','national_id',
        ],
    ];

    /** @return array<string,mixed> */
    public static function enrichBilling(array $billing, array $order): array
    {
        $meta = self::collect($billing, $order);
        $fiscal = [];
        foreach (self::ALIASES as $target => $aliases) {
            foreach ($aliases as $alias) {
                $key = self::normalizeKey($alias);
                if (!array_key_exists($key, $meta)) continue;
                $value = self::scalar($meta[$key]);
                if ($value === '') continue;
                $fiscal[$target] = $value;
                break;
            }
        }

        $company = trim((string)($billing['company'] ?? ''));
        if ($company === '') {
            foreach (['billing_company','company','company_name','firma','denumire_firma'] as $alias) {
                $key=self::normalizeKey($alias);
                if(isset($meta[$key]) && ($v=self::scalar($meta[$key]))!=='') { $company=$v; break; }
            }
            if($company!=='') $billing['company']=$company;
        }

        $explicit = strtolower(trim((string)($fiscal['person_type'] ?? '')));
        $personType = '';
        if ($explicit !== '') {
            if (preg_match('/jurid|firma|companie|company|legal|pj|business/u', $explicit)) $personType='Persoana juridica';
            elseif (preg_match('/fiz|individual|personal|pf|persoana fizica/u', $explicit)) $personType='Persoana fizica';
        }
        if ($personType === '') {
            $personType = ($company !== '' || !empty($fiscal['vat_number']) || !empty($fiscal['registration_number']) || !empty($fiscal['iban']))
                ? 'Persoana juridica' : 'Persoana fizica';
        }
        $fiscal['person_type']=$personType;

        // Keep additional relevant Romanian billing metadata that is not covered by
        // the canonical fields. This makes plugin-specific checkout fields visible
        // without exposing unrelated private/internal WooCommerce metadata.
        $used=[];
        foreach(self::ALIASES as $aliases) foreach($aliases as $alias) $used[self::normalizeKey($alias)]=true;
        $used[self::normalizeKey('billing_company')]=true;
        $extras=[];
        foreach($meta as $key=>$value){
            if(isset($used[$key]) || isset(self::IGNORED_EXTRAS[$key])) continue;
            if(!preg_match('/(^|_)(billing_)?(cui|cif|vat|tax|fiscal|reg|registr|iban|bank|banca|cont|cnp|firma|company|societ|jurid|persoana)(_|$)/u',$key)) continue;
            $v=self::scalar($value); if($v==='') continue;
            $extras[$key]=['label'=>self::labelForKey($key),'value'=>$v];
        }

        $billing['_fiscal']=$fiscal;
        $billing['_fiscal_extra']=array_values($extras);
        return $billing;
    }

    /** @return array<string,mixed> */
    private static function collect(array $billing,array $order): array
    {
        $out=[];
        foreach($billing as $key=>$value){
            if(is_scalar($value)||$value===null) $out[self::normalizeKey((string)$key)]=$value;
        }
        foreach($order as $key=>$value){
            if((is_scalar($value)||$value===null) && preg_match('/billing|cui|cif|vat|tax|fiscal|reg|iban|bank|banca|cont|cnp|firma|company|jurid|persoana/i',(string)$key)){
                $out[self::normalizeKey((string)$key)]=$value;
            }
        }
        $metaLists=[];
        if(isset($order['meta_data'])&&is_array($order['meta_data']))$metaLists[]=$order['meta_data'];
        if(isset($billing['meta_data'])&&is_array($billing['meta_data']))$metaLists[]=$billing['meta_data'];
        foreach($metaLists as $list){
            foreach($list as $row){
                if(!is_array($row))continue;
                $key=(string)($row['key']??''); if($key==='')continue;
                $out[self::normalizeKey($key)]=$row['value']??'';
            }
        }
        return $out;
    }

    private static function normalizeKey(string $key): string
    {
        $key=strtolower(trim($key));
        $key=ltrim($key,'_');
        $key=preg_replace('/[^a-z0-9]+/','_',$key)??$key;
        return trim($key,'_');
    }

    private static function scalar(mixed $value): string
    {
        if(is_bool($value)) return $value?'Da':'Nu';
        if(is_int($value)||is_float($value)||is_string($value)) return trim((string)$value);
        if(is_array($value)){
            $flat=[]; foreach($value as $v) if(is_scalar($v))$flat[]=trim((string)$v);
            return trim(implode(', ',array_filter($flat,fn($v)=>$v!=='')));
        }
        return '';
    }

    private static function labelForKey(string $key): string
    {
        $key=preg_replace('/^billing_/','',$key)??$key;
        $parts=array_filter(explode('_',$key));
        return ucfirst(implode(' ',array_map(static fn($v)=>match($v){'cui'=>'CUI','cif'=>'CIF','vat'=>'TVA','iban'=>'IBAN','cnp'=>'CNP','reg'=>'Reg.','nr'=>'Nr.','j'=>'J',default=>$v},$parts)));
    }
}

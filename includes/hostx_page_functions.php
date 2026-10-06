<?php
/**
 * Shared helper functions for the HostX custom landing pages.
 *
 * Previously every landing page (web-hosting.php, vps-hosting.php, ...) carried
 * its own identical copy of these helpers. They are consolidated here so there
 * is a single implementation to maintain. Each function is guarded with
 * function_exists() so the file is safe to include more than once.
 *
 * @package CloudHost247
 */

if (!defined('WHMCS') && !defined('CLIENTAREA')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

if (!function_exists('get_currency')) {
    function get_currency(){
        $clientCurrency = '';
        if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
            $clientCurrency = Capsule::table('tblclients')->select('currency')->where('id', $_SESSION['uid'])->get();
        }
        if (isset($clientCurrency) && !empty($clientCurrency) && $clientCurrency[0]->currency != '0') {
            $currency = Capsule::table('tblcurrencies')->where('id', $clientCurrency[0]->currency)->first();
        } else if (isset($_SESSION['currency']) && !empty($_SESSION['currency'])) {
            $currency = Capsule::table('tblcurrencies')->where('id', $_SESSION['currency'])->first();
        } else {
            $currency = Capsule::table('tblcurrencies')->where('default', '1')->first();
        }
        return $currency;
    }
}

if (!function_exists('wgs_fetch_product_detail_according_to_language_hostx')) {
    function wgs_fetch_product_detail_according_to_language_hostx($language,$relid,$for){
    	if($for == 'pname'){
    		$dataReturn = Capsule::table('tbldynamic_translations')->where('related_type','product.{id}.name')->where('related_id',$relid)->where('language',$language)->first();
    	}else if($for == 'pdescp'){
    		$dataReturn = Capsule::table('tbldynamic_translations')->where('related_type','product.{id}.description')->where('related_id',$relid)->where('language',$language)->first();			
    	}else if($for == 'pgroupname'){
    		$dataReturn = Capsule::table('tbldynamic_translations')->where('related_type','product_group.{id}.name')->where('related_id',$relid)->where('language',$language)->first();			
    	}
    	return $dataReturn;
    }
}

if (!function_exists('wgs_get_dynmic_translation_page')) {
    function wgs_get_dynmic_translation_page($related_type,$related_id,$language){
    	return Capsule::table('mod_hostx_dynmic_translation')->where('related_type',$related_type)->where('related_id',$related_id)->where('language',$language)->first();
    }
}

if (!function_exists('wgs_pricing_format_data')) {
    function wgs_pricing_format_data($priceProduct,$currencyId){
    	$currencySettingGet = Capsule::table('mod_hostx_setting')->where('setting','currency_setting')->first();
    	$currencySettingGetCount = Capsule::table('mod_hostx_setting')->where('setting','currency_setting')->count();
    	if($currencySettingGetCount > 0){
    		if($currencySettingGet->value == 'prefix'){
    			return formatCurrency($priceProduct,$currencyId)->toPrefixed();
    		}elseif($currencySettingGet->value == 'suffix'){
    			return formatCurrency($priceProduct,$currencyId)->toSuffixed();
    		}elseif($currencySettingGet->value == 'both'){
    			return formatCurrency($priceProduct,$currencyId)->toFull();
    		}
    	}else{
    		return formatCurrency($priceProduct,$currencyId)->toPrefixed();
    	}
    }
}

<?php
/**
 * Plugin Name: TaxoCommerce Campaigns
 * Description: Offer campaigns, campaign pricing, public banners, social offer publishing, and a standalone commercial calendar.
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Text Domain: taxocommerce-campaigns
 */

defined('ABSPATH') || exit;

define('TAXOCOMMERCE_CAMPAIGNS_VERSION','0.1.0');
define('TAXOCOMMERCE_CAMPAIGNS_PATH',plugin_dir_path(__FILE__));
define('TAXOCOMMERCE_CAMPAIGNS_URL',plugin_dir_url(__FILE__));

require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/campaigns.php';
require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/campaign-images.php';
require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/social-publication.php';
require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/calendar.php';
require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/shortcodes.php';
require_once TAXOCOMMERCE_CAMPAIGNS_PATH.'includes/admin.php';

register_activation_hook(__FILE__, static function(){
    tccampaigns_maybe_install_tables();
    tccampaigns_register_public_template();
    delete_option('tccampaigns_schedule_owner_v1');
});

function taxocommerce_campaigns_register_core($core){
    if(!is_object($core)||!method_exists($core,'register_module')) return;
    $core->register_module('campaigns',array(
        'name'=>'TaxoCommerce Campaigns',
        'version'=>TAXOCOMMERCE_CAMPAIGNS_VERSION,
        'status'=>'active',
        'description'=>'Offer campaigns, prices, banners, social offers, and commercial calendar.',
        'admin_url'=>admin_url('admin.php?page=taxocommerce-campaigns'),
    ));
}
add_action('taxocommerce_core_register_modules','taxocommerce_campaigns_register_core');

function taxocommerce_campaigns_family_catalog($extensions){
    $extensions=is_array($extensions)?$extensions:array();
    $extensions['campaigns']=array(
        'id'=>'campaigns',
        'name'=>'TaxoCommerce Campaigns',
        'plugin_file'=>'taxocommerce-campaigns/taxocommerce-campaigns.php',
        'org_slug'=>'taxocommerce-campaigns',
        'search_term'=>'TaxoCommerce Campaigns',
        'wordpress_org'=>false,
        'admin_url'=>admin_url('admin.php?page=taxocommerce-campaigns'),
        'description'=>'Offer campaigns, campaign pricing, banners, social offer publication, and standalone commercial calendar.',
    );
    return $extensions;
}
add_filter('taxocommerce_core_family_extensions','taxocommerce_campaigns_family_catalog');

function tccampaigns_claim_schedule_when_legacy_is_off(){
    if(function_exists('seo_marketing_campaigns_get')) return;
    if(get_option('tccampaigns_schedule_owner_v1')) return;
    global $wpdb;
    $tables=tccampaigns_tables();
    $ids=(array)$wpdb->get_col("SELECT id FROM {$tables['campaigns']} WHERE is_enabled=1");
    foreach($ids as $id) tccampaigns_reschedule(absint($id));
    update_option('tccampaigns_schedule_owner_v1',1,false);
}
add_action('init','tccampaigns_claim_schedule_when_legacy_is_off',30);

/**
 * Compatibility for TaxoCommerce Templates while the old monolith is disabled.
 * These functions are declared only after all active plugins have loaded.
 */
add_action('plugins_loaded', static function(){
    if(!function_exists('seo_marketing_campaigns_get_public_active')){
        function seo_marketing_campaigns_get_public_active(){ return tccampaigns_get_public_active(); }
    }
    if(!function_exists('seo_marketing_campaigns_public_template_is_enabled')){
        function seo_marketing_campaigns_public_template_is_enabled(){ return tccampaigns_public_template_is_enabled(); }
    }
    if(!function_exists('seo_marketing_campaigns_get_social_catalog')){
        function seo_marketing_campaigns_get_social_catalog(){ return tccampaigns_get_social_catalog(); }
    }
    if(!function_exists('seo_marketing_campaigns_get_social_campaign')){
        function seo_marketing_campaigns_get_social_campaign($id){ return tccampaigns_get_social_campaign($id); }
    }
    if(!function_exists('seo_marketing_campaigns_timestamp')){
        function seo_marketing_campaigns_timestamp($value){ return tccampaigns_timestamp($value); }
    }
    if(!function_exists('seo_marketing_campaigns_get')){
        function seo_marketing_campaigns_get($id){ return tccampaigns_get($id); }
    }
},99);

<?php
defined('ABSPATH') or die("you do not have acces to this page!");



add_action('cmplz_notice_dpo_or_gdpr', 'cmplz_dpo_or_gdpr');
function cmplz_dpo_or_gdpr(){

    if (!cmplz_company_in_eu()){
        cmplz_notice(__("Your company is located outside the EU, so should appoint a GDPR representative in the EU.", 'complianz'));
    } else {
        cmplz_notice(__("Your company is located in the EU, so you do not need to appoint a GDPR representative in the EU.", 'complianz'));
    }

}

add_action('cmplz_notice_uses_social_media', 'cmplz_uses_social_media_notice');
function cmplz_uses_social_media_notice(){
    $social_media = cmplz_scan_detected_social_media();
    if ($social_media){
        $social_media = implode(', ', $social_media);
        cmplz_notice(sprintf(__("The scan found social media buttons or widgets for %s on your site, which means the answer should be yes", 'complianz'), $social_media));
    }
}

add_action('cmplz_notice_purpose_personaldata', 'cmplz_purpose_personaldata');
function cmplz_purpose_personaldata(){
    $contact_forms = cmplz_site_uses_contact_forms();
    if ($contact_forms){
        cmplz_notice(__('The scan found forms on your site, which means answer should probably include "contact".', 'complianz'));
    }
}

add_action('cmplz_notice_uses_thirdparty_services', 'cmplz_uses_thirdparty_services_notice');
function cmplz_uses_thirdparty_services_notice(){
    $thirdparty = cmplz_scan_detected_thirdparty_services();
    if ($thirdparty){
        $thirdparty = implode(', ', $thirdparty);
        cmplz_notice(sprintf(__("The scan found third party services for %s on your site, which means the answer should be yes", 'complianz'), $thirdparty));
    }
}

add_action('cmplz_notice_purpose_personaldata', 'cmplz_purpose_personaldata_notice');
function cmplz_purpose_personaldata_notice(){
    if (cmplz_has_region('us') && COMPLIANZ()->cookie->uses_non_functional_cookies()){
        cmplz_notice(__("The cookie scan detected non-functional cookies on your site. This means you should at least select the option that you sell data to third parties", 'complianz'));
    }
}

add_action('cmplz_notice_data_disclosed_us', 'cmplz_data_disclosed_us');
function cmplz_data_disclosed_us(){

    if (COMPLIANZ()->cookie->uses_non_functional_cookies()) {
        cmplz_notice(__("The cookie scan detected non-functional cookies on your site. If these cookies were also used in the past 12 months, you should at least select the option 'Internet activity...'", 'complianz'));
    }

}

add_action('cmplz_notice_data_sold_us', 'cmplz_data_sold_us');
function cmplz_data_sold_us(){

    if (COMPLIANZ()->cookie->uses_non_functional_cookies()) {
        cmplz_notice(__("The cookie scan detected non-functional cookies on your site. If these cookies were also used in the past 12 months, you should at least select the option 'Internet activity...'", 'complianz'));
    }

}

add_action('cmplz_notice_no_cookies_used', 'cmplz_notice_no_cookies_used');
function cmplz_notice_no_cookies_used(){

    if (cmplz_get_value('uses_cookies')!=='yes') {
        cmplz_notice(__("You have indicated your site does not use cookies. If you're sure about this, you can skip this step", 'complianz'),'warning');
    }

}


/*
 * For the cookie page and the US banner we need a link to the privacy policy.
 * In free, and in premium when the privacy statement is not enabled, we choose the WP privacy page. If it is not set, the user needs to create one.
 *
 *
 * */

add_action('cmplz_notice_missing_privacy_page', 'cmplz_notice_missing_privacy_page');
function cmplz_notice_missing_privacy_page(){

    if (cmplz_get_value('privacy-statement')!=='yes' && !get_option('wp_page_for_privacy_policy')) {
        cmplz_notice(sprintf(__("You do not have a privacy statement page selected, which is needed to configure your site. You can either let Complianz Privacy Suite premium handle it for you, or create one yourself and set it as the WordPress privacy page %shere%s", 'complianz'),'<a href="'.admin_url('privacy.php').'">','</a>'),'warning');
    }

}




add_filter('cmplz_default_value', 'cmplz_set_default', 10, 2);
function cmplz_set_default($value, $fieldname)
{
    if ($fieldname == 'purpose_personaldata') {
        if (cmplz_has_region('us') && COMPLIANZ()->cookie->uses_non_functional_cookies()) {
            //possibly not an array yet, when it's empty
            if (!is_array($value)) $value = array();
            $value['selling-data-thirdparty'] = 1;
            return $value;
        }
    }


    if ($fieldname == 'popup_background_color' || $fieldname == 'button_text_color') {
        $brand = cmplz_get_value('brand_color');
        if (!empty($brand)) return $brand;
    }

    if ($fieldname == 'purpose_personaldata') {
        $contact_forms = cmplz_site_uses_contact_forms();
        if ($contact_forms) {
            //possibly not an array yet, when it's empty
            if (!is_array($value)) $value = array();
            $value['contact'] = 1;
            return $value;
        }
    }

    if ($fieldname == 'dpo_or_gdpr') {
        if (!cmplz_company_in_eu()) return 'gdpr_rep';
    }

    if ($fieldname == 'country_company') {
        $country_code = substr(get_locale(),3,2);
        if (isset(COMPLIANZ()->config->countries[$country_code])) {
            $value = $country_code;
        }

    }

    if ($fieldname === 'uses_social_media'){
        $social_media = cmplz_scan_detected_social_media();
        if ($social_media) {
            return 'yes';
        }
    }

    if ($fieldname === 'socialmedia_on_site') {
        $social_media = cmplz_scan_detected_social_media();
        if ($social_media) {
            $current_social_media = array();
            foreach ($social_media as $key) {
                $current_social_media[$key] = 1;
            }
            return $current_social_media;
        }
    }

    if ($fieldname === 'uses_thirdparty_services'){
        $thirdparty = cmplz_scan_detected_thirdparty_services();
        if ($thirdparty) return 'yes';
    }

    if ($fieldname === 'thirdparty_services_on_site') {
        $thirdparty = cmplz_scan_detected_thirdparty_services();
        if ($thirdparty) {
            $current_thirdparty = array();
            foreach ($thirdparty as $key) {
                $current_thirdparty[$key] = 1;
            }

            return $current_thirdparty;
        }
    }

    if ($fieldname === 'data_disclosed_us' || $fieldname === 'data_sold_us'){
        if (COMPLIANZ()->cookie->uses_non_functional_cookies()) {
            //possibly not an array yet.
            if (!is_array($value)) $value = array();
            $value['internet'] = 1;
            return $value;
        }
    }

    return $value;
}

<?php

// e_sales_category_c's list where a tenant has none: ADM's Kinetic sales categories (codes as keys, read 2026-10-17).
// A tenant's own list is kept: this _override_ fragment runs after every plain one and after Studio's file.
if (!isset($app_list_strings['e_sales_category_list'])) {
    $app_list_strings['e_sales_category_list'] = array(
        '' => '',
        'BRKR' => 'ZZBROKER',
        'BRND' => 'BRAND',
        'CUST' => 'ZZCUSTOM CABI',
        'DISP' => 'DISPLAYS',
        'DRCT' => 'ZZDIRECT',
        'DRRT' => 'ZZDIRECT/RETAIL',
        'FIRM' => 'ZZGENERAL FIRMS',
        'INDY' => 'ZZINDUSTRY',
        'PRTN' => 'PARTNER',
        'RETL' => 'RETAIL ENVIRON',
    );
}

<?php
// created: 2026-10-23 04:39:42
$viewdefs['Quotes']['base']['view']['list'] = array (
  'panels' => 
  array (
    0 => 
    array (
      'name' => 'panel_header',
      'label' => 'LBL_PANEL_1',
      'fields' => 
      array (
        0 => 
        array (
          'name' => 'e_expiration_date_c',
          'label' => 'LBL_E_EXPIRATION_DATE_C',
          'enabled' => true,
          'default' => false,
        ),
        1 => 
        array (
          'name' => 'e_best_case_pct_c',
          'label' => 'LBL_E_BEST_CASE_PCT_C',
          'enabled' => true,
          'default' => false,
        ),
        2 => 
        array (
          'name' => 'e_confidence_pct_c',
          'label' => 'LBL_E_CONFIDENCE_PCT_C',
          'enabled' => true,
          'default' => false,
        ),
        3 => 
        array (
          'name' => 'shipping_address_name',
          'label' => 'LBL_SHIPPING_ADDRESS_NAME',
          'default' => false,
          'related_fields' => 
          array (
            0 => 'account_id',
            1 => 'account_name',
          ),
          'featureFilter' => 
          array (
            0 => 'shippingAddressesEnabled',
          ),
          'enabled' => true,
        ),
        4 => 
        array (
          'name' => 'quote_num',
          'enabled' => true,
          'default' => true,
        ),
        5 => 
        array (
          'name' => 'name',
          'link' => true,
          'enabled' => true,
          'default' => true,
        ),
        6 => 
        array (
          'name' => 'billing_account_name',
          'enabled' => true,
          'default' => true,
        ),
        7 => 
        array (
          'name' => 'total',
          'enabled' => true,
          'default' => true,
        ),
        8 => 
        array (
          'name' => 'total_usdollar',
          'enabled' => true,
          'default' => true,
        ),
        9 => 
        array (
          'name' => 'date_quote_expected_closed',
          'enabled' => true,
          'default' => true,
        ),
        10 => 
        array (
          'name' => 'assigned_user_name',
          'enabled' => true,
          'default' => true,
        ),
        11 => 
        array (
          'name' => 'date_modified',
          'enabled' => true,
          'default' => true,
        ),
        12 => 
        array (
          'name' => 'date_entered',
          'enabled' => true,
          'default' => true,
        ),
        13 => 
        array (
          'name' => 'erp_quote_type',
          'label' => 'LBL_ERP_QUOTE_TYPE',
          'enabled' => true,
          'default' => true,
        ),
        14 => 
        array (
          'name' => 'erp_display_sync_key',
          'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
          'type' => 'erp-id-link',
          'enabled' => true,
          'default' => true,
        ),
        15 => 
        array (
          'name' => 'erp_companies_quotes_name',
          'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE',
          'enabled' => true,
          'id' => 'ERP_COMPANIES_QUOTESERP_COMPANIES_IDA',
          'link' => true,
          'sortable' => false,
          'default' => true,
        ),
        16 => 
        array (
          'name' => 'quote_stage',
          'label' => 'LBL_QUOTE_STAGE',
          'enabled' => true,
          'default' => true,
        ),
        17 => 
        array (
          'name' => 'order_stage',
          'label' => 'LBL_ORDER_STAGE',
          'enabled' => true,
          'default' => false,
        ),
        18 => 
        array (
          'name' => 'erp_is_primary_quote',
          'label' => 'LBL_ERP_IS_PRIMARY_QUOTE',
          'enabled' => true,
          'default' => false,
        ),
        19 => 
        array (
          'name' => 'erp_estimate_stage',
          'label' => 'LBL_ERP_ESTIMATE_STAGE',
          'enabled' => true,
          'readonly' => true,
          'default' => false,
        ),
        20 => 
        array (
          'name' => 'erp_priced_at',
          'label' => 'LBL_ERP_PRICED_AT',
          'enabled' => true,
          'readonly' => true,
          'default' => false,
        ),
        21 => 
        array (
          'name' => 'erp_writeback_status',
          'label' => 'LBL_ERP_WRITEBACK_STATUS',
          'enabled' => true,
          'readonly' => true,
          'default' => false,
        ),
        22 => 
        array (
          'name' => 'epicor_deeplink_url',
          'label' => 'LBL_EPICOR_DEEPLINK_URL',
          'enabled' => true,
          'default' => false,
        ),
      ),
    ),
  ),
);
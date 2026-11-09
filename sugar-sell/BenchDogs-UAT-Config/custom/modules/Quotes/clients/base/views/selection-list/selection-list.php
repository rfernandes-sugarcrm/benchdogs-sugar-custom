<?php
$viewdefs['Quotes'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'selection-list' => 
      array (
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
                'label' => 'LBL_LIST_QUOTE_NAME',
                'enabled' => true,
                'default' => true,
                'name' => 'name',
                'link' => true,
              ),
              1 => 
              array (
                'target_record_key' => 'account_id',
                'target_module' => 'Accounts',
                'label' => 'LBL_LIST_ACCOUNT_NAME',
                'enabled' => true,
                'default' => true,
                'name' => 'account_name',
                'sortable' => false,
              ),
              2 => 
              array (
                'label' => 'LBL_LIST_AMOUNT_USDOLLAR',
                'enabled' => true,
                'default' => true,
                'name' => 'total_usdollar',
              ),
              3 => 
              array (
                'name' => 'date_quote_expected_closed',
                'label' => 'LBL_LIST_DATE_QUOTE_EXPECTED_CLOSED',
                'enabled' => true,
                'default' => true,
              ),
              4 => 
              array (
                'name' => 'assigned_user_name',
                'target_record_key' => 'assigned_user_id',
                'target_module' => 'Employees',
                'label' => 'LBL_LIST_ASSIGNED_TO_NAME',
                'enabled' => true,
                'default' => true,
                'sortable' => false,
              ),
              5 => 
              array (
                'name' => 'date_entered',
                'label' => 'LBL_DATE_ENTERED',
                'enabled' => true,
                'default' => false,
              ),
              6 => 
              array (
                'name' => 'created_by_name',
                'label' => 'LBL_CREATED',
                'enabled' => true,
                'id' => 'CREATED_BY',
                'link' => true,
                'default' => false,
              ),
              7 => 
              array (
                'name' => 'date_modified',
                'label' => 'LBL_DATE_MODIFIED',
                'enabled' => true,
                'default' => false,
              ),
              8 => 
              array (
                'name' => 'modified_by_name',
                'label' => 'LBL_MODIFIED',
                'enabled' => true,
                'id' => 'MODIFIED_USER_ID',
                'link' => true,
                'default' => false,
              ),
              9 => 
              array (
                'name' => 'bd_marketing_campaign',
                'label' => 'LBL_BD_MARKETING_CAMPAIGN',
                'enabled' => true,
                'default' => false,
              ),
              10 => 
              array (
                'name' => 'bd_marketing_event',
                'label' => 'LBL_BD_MARKETING_EVENT',
                'enabled' => true,
                'default' => false,
              ),
              11 => 
              array (
                'name' => 'e_best_case_pct_c',
                'label' => 'LBL_E_BEST_CASE_PCT_C',
                'enabled' => true,
                'default' => false,
              ),
              12 => 
              array (
                'name' => 'e_confidence_pct_c',
                'label' => 'LBL_E_CONFIDENCE_PCT_C',
                'enabled' => true,
                'default' => false,
              ),
              13 => 
              array (
                'name' => 'erp_reason_code',
                'label' => 'LBL_ERP_REASON_CODE',
                'enabled' => true,
                'default' => false,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
);

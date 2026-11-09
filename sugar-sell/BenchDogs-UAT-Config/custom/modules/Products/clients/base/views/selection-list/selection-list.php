<?php
$viewdefs['Products'] = 
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
                'name' => 'name',
                'link' => true,
                'label' => 'LBL_NAME',
                'enabled' => true,
                'default' => true,
              ),
              1 => 
              array (
                'name' => 'account_name',
                'label' => 'LBL_ACCOUNT_NAME',
                'related_fields' => 
                array (
                  0 => 'account_id',
                ),
                'default' => true,
                'enabled' => true,
              ),
              2 => 
              array (
                'name' => 'status',
                'label' => 'LBL_STATUS',
                'default' => true,
                'enabled' => true,
              ),
              3 => 
              array (
                'name' => 'quote_name',
                'link' => true,
                'label' => 'LBL_ASSOCIATED_QUOTE',
                'related_fields' => 
                array (
                  0 => 'quote_id',
                ),
                'enabled' => true,
                'default' => true,
              ),
              4 => 
              array (
                'type' => 'decimal',
                'default' => true,
                'label' => 'LBL_QUANTITY',
                'name' => 'quantity',
                'enabled' => true,
              ),
              5 => 
              array (
                'name' => 'e_sales_category_c',
                'label' => 'LBL_E_SALES_CATEGORY_C',
                'enabled' => true,
                'default' => false,
              ),
              6 => 
              array (
                'name' => 'category_name',
                'label' => 'LBL_CATEGORY_NAME',
                'enabled' => true,
                'id' => 'CATEGORY_ID',
                'link' => true,
                'sortable' => false,
                'default' => false,
              ),
              7 => 
              array (
                'name' => 'date_entered',
                'label' => 'LBL_DATE_ENTERED',
                'enabled' => true,
                'default' => false,
              ),
              8 => 
              array (
                'name' => 'created_by_name',
                'label' => 'LBL_CREATED',
                'enabled' => true,
                'id' => 'CREATED_BY',
                'link' => true,
                'default' => false,
              ),
              9 => 
              array (
                'name' => 'date_modified',
                'label' => 'LBL_DATE_MODIFIED',
                'enabled' => true,
                'default' => false,
              ),
              10 => 
              array (
                'name' => 'modified_by_name',
                'label' => 'LBL_MODIFIED',
                'enabled' => true,
                'id' => 'MODIFIED_USER_ID',
                'link' => true,
                'default' => false,
              ),
              11 => 
              array (
                'name' => 'discount_price',
                'type' => 'currency',
                'related_fields' => 
                array (
                  0 => 'discount_price',
                  1 => 'currency_id',
                  2 => 'base_rate',
                ),
                'convertToBase' => true,
                'showTransactionalAmount' => true,
                'currency_field' => 'currency_id',
                'base_rate_field' => 'base_rate',
                'default' => false,
                'enabled' => true,
              ),
              12 => 
              array (
                'name' => 'cost_price',
                'readonly' => true,
                'type' => 'currency',
                'related_fields' => 
                array (
                  0 => 'cost_price',
                  1 => 'currency_id',
                  2 => 'base_rate',
                ),
                'convertToBase' => true,
                'showTransactionalAmount' => true,
                'currency_field' => 'currency_id',
                'base_rate_field' => 'base_rate',
                'default' => false,
                'enabled' => true,
              ),
              13 => 
              array (
                'name' => 'assigned_user_name',
                'default' => false,
                'enabled' => true,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
);

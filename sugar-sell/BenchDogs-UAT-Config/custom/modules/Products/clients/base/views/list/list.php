<?php
$viewdefs['Products'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'list' => 
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
                'name' => 'quantity',
                'default' => true,
                'enabled' => true,
              ),
              5 => 
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
                'default' => true,
                'enabled' => true,
              ),
              6 => 
              array (
                'name' => 'cost_price',
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
                'default' => true,
                'enabled' => true,
              ),
              7 => 
              array (
                'name' => 'assigned_user_name',
                'default' => true,
                'enabled' => true,
              ),
              8 => 
              array (
                'name' => 'date_entered',
                'label' => 'LBL_DATE_ENTERED',
                'enabled' => true,
                'default' => false,
              ),
              9 => 
              array (
                'name' => 'created_by_name',
                'label' => 'LBL_CREATED',
                'enabled' => true,
                'id' => 'CREATED_BY',
                'link' => true,
                'default' => false,
              ),
              10 => 
              array (
                'name' => 'date_modified',
                'label' => 'LBL_DATE_MODIFIED',
                'enabled' => true,
                'default' => false,
              ),
              11 => 
              array (
                'name' => 'modified_by_name',
                'label' => 'LBL_MODIFIED',
                'enabled' => true,
                'id' => 'MODIFIED_USER_ID',
                'link' => true,
                'default' => false,
              ),
              12 => 
              array (
                'name' => 'mft_part_num',
                'label' => 'LBL_MFT_PART_NUM',
                'enabled' => true,
                'default' => false,
              ),
              13 => 
              array (
                'name' => 'e_expected_qty_c',
                'label' => 'LBL_E_EXPECTED_QTY_C',
                'enabled' => true,
                'default' => false,
              ),
              14 => 
              array (
                'name' => 'e_expected_qty_uom_c',
                'label' => 'LBL_E_EXPECTED_QTY_UOM_C',
                'enabled' => true,
                'default' => false,
              ),
              15 => 
              array (
                'name' => 'e_gross_margin_c',
                'label' => 'LBL_E_GROSS_MARGIN_C',
                'enabled' => true,
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'currency_format' => true,
                'default' => false,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
);

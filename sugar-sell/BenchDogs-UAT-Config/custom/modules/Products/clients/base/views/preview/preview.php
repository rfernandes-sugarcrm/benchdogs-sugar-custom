<?php
$viewdefs['Products'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'preview' => 
      array (
        'panels' => 
        array (
          0 => 
          array (
            'name' => 'panel_header',
            'header' => true,
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'picture',
                'type' => 'avatar',
                'size' => 'medium',
                'dismiss_label' => true,
                'readonly' => true,
              ),
              1 => 
              array (
                'name' => 'name',
                'label' => 'LBL_MODULE_NAME_SINGULAR',
              ),
            ),
          ),
          1 => 
          array (
            'name' => 'panel_body',
            'label' => 'LBL_RECORD_BODY',
            'columns' => 2,
            'labels' => true,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'product_template_name',
                'label' => 'LBL_PRODUCT_TEMPLATE',
              ),
              1 => 
              array (
                'name' => 'account_name',
                'label' => 'LBL_ACCOUNT_NAME',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'account_id',
                ),
              ),
              2 => 
              array (
                'name' => 'e_sales_category_c',
                'label' => 'LBL_E_SALES_CATEGORY_C',
              ),
              3 => 
              array (
                'name' => 'category_name',
                'studio' => 
                array (
                  'editview' => false,
                  'detailview' => false,
                  'quickcreate' => false,
                ),
                'comment' => 'Name of the Product Category this Product belongs to, drawn from category_id',
                'label' => 'LBL_CATEGORY_NAME',
              ),
              4 => 
              array (
                'name' => 'quote_name',
                'label' => 'LBL_QUOTE_NAME',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'quote_id',
                ),
              ),
              5 => 
              array (
                'name' => 'status',
                'label' => 'LBL_STATUS',
              ),
              6 => 'quantity',
              7 => 
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
              ),
              8 => 
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
              ),
              9 => 
              array (
                'name' => 'list_price',
                'type' => 'currency',
                'related_fields' => 
                array (
                  0 => 'list_price',
                  1 => 'currency_id',
                  2 => 'base_rate',
                ),
                'convertToBase' => true,
                'showTransactionalAmount' => true,
                'currency_field' => 'currency_id',
                'base_rate_field' => 'base_rate',
              ),
              10 => 'mft_part_num',
              11 => 
              array (
                'name' => 'erp_unit_of_measure',
                'label' => 'LBL_ERP_UNIT_OF_MEASURE',
              ),
              12 => 
              array (
                'name' => 'e_expected_qty_c',
                'label' => 'LBL_E_EXPECTED_QTY_C',
              ),
              13 => 
              array (
                'name' => 'e_expected_qty_uom_c',
                'label' => 'LBL_E_EXPECTED_QTY_UOM_C',
              ),
              14 => 
              array (
                'name' => 'erp_need_by_date',
                'label' => 'LBL_ERP_NEED_BY_DATE',
              ),
              15 => 
              array (
                'name' => 'erp_ship_by_date',
                'label' => 'LBL_ERP_SHIP_BY_DATE',
              ),
              16 => 'contact_name',
              17 => 
              array (
                'name' => 'tag',
              ),
            ),
          ),
          2 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_ERP_COST_WORKSHEET',
            'label' => 'LBL_RECORDVIEW_PANEL_ERP_COST_WORKSHEET',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'e_quantity_quoted_c',
                'label' => 'LBL_E_QUANTITY_QUOTED_C',
              ),
              1 => 
              array (
                'name' => 'e_gross_margin_c',
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'label' => 'LBL_E_GROSS_MARGIN_C',
              ),
              2 => 
              array (
                'span' => 12,
              ),
              3 => 
              array (
                'name' => 'e_est_engineering_hours_c',
                'label' => 'LBL_E_EST_ENGINEERING_HOURS_C',
              ),
              4 => 
              array (
                'name' => 'erp_material_cost',
                'label' => 'LBL_ERP_MATERIAL_COST',
              ),
              5 => 
              array (
                'name' => 'erp_labor_cost',
                'label' => 'LBL_ERP_LABOR_COST',
              ),
              6 => 
              array (
                'name' => 'erp_material_burden',
                'label' => 'LBL_ERP_MATERIAL_BURDEN',
              ),
              7 => 
              array (
                'name' => 'erp_labor_burden',
                'label' => 'LBL_ERP_LABOR_BURDEN',
              ),
              8 => 
              array (
                'name' => 'erp_subcontract_cost',
                'label' => 'LBL_ERP_SUBCONTRACT_COST',
              ),
              9 => 
              array (
                'name' => 'erp_misc_cost',
                'label' => 'LBL_ERP_MISC_COST',
              ),
              10 => 
              array (
                'name' => 'e_material_pct_c',
                'label' => 'LBL_E_MATERIAL_PCT_C',
              ),
              11 => 
              array (
                'name' => 'e_labor_pct_c',
                'label' => 'LBL_E_LABOR_PCT_C',
              ),
              12 => 
              array (
                'name' => 'e_material_labor_pct_c',
                'label' => 'LBL_E_MATERIAL_LABOR_PCT_C',
              ),
              13 => 
              array (
                'span' => 12,
              ),
              14 => 
              array (
                'name' => 'e_machine_hours_c',
                'label' => 'LBL_E_MACHINE_HOURS_C',
              ),
              15 => 
              array (
                'name' => 'e_finish_hours_c',
                'label' => 'LBL_E_FINISH_HOURS_C',
              ),
              16 => 
              array (
                'name' => 'e_assembly_hours_c',
                'label' => 'LBL_E_ASSEMBLY_HOURS_C',
              ),
              17 => 
              array (
                'name' => 'e_case_goods_hours_c',
                'label' => 'LBL_E_CASE_GOODS_HOURS_C',
              ),
              18 => 
              array (
                'name' => 'e_install_hours_c',
                'label' => 'LBL_E_INSTALL_HOURS_C',
              ),
              19 => 
              array (
                'span' => 12,
              ),
              20 => 
              array (
                'name' => 'e_total_cost_c',
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'label' => 'LBL_E_TOTAL_COST_C',
              ),
              21 => 
              array (
                'name' => 'e_total_markup_c',
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'label' => 'LBL_E_TOTAL_MARKUP_C',
              ),
              22 => 
              array (
                'name' => 'e_total_markup_price_c',
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'label' => 'LBL_E_TOTAL_MARKUP_PRICE_C',
              ),
              23 => 
              array (
                'span' => 12,
              ),
              24 => 
              array (
                'name' => 'erp_quoted_markup_pct',
                'label' => 'LBL_ERP_QUOTED_MARKUP_PCT',
              ),
              25 => 
              array (
                'name' => 'erp_quoted_profit_pct',
                'label' => 'LBL_ERP_QUOTED_PROFIT_PCT',
              ),
            ),
          ),
          3 => 
          array (
            'name' => 'panel_hidden',
            'label' => 'LBL_RECORD_SHOWMORE',
            'hide' => true,
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'assigned_user_name',
              1 => 'team_name',
              2 => 
              array (
                'name' => 'description',
              ),
              3 => 
              array (
                'name' => 'date_entered_by',
                'readonly' => true,
                'inline' => true,
                'type' => 'fieldset',
                'label' => 'LBL_DATE_ENTERED',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'date_entered',
                  ),
                  1 => 
                  array (
                    'type' => 'label',
                    'default_value' => 'LBL_BY',
                  ),
                  2 => 
                  array (
                    'name' => 'created_by_name',
                  ),
                ),
              ),
              4 => 
              array (
                'name' => 'date_modified_by',
                'readonly' => true,
                'inline' => true,
                'type' => 'fieldset',
                'label' => 'LBL_DATE_MODIFIED',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'date_modified',
                  ),
                  1 => 
                  array (
                    'type' => 'label',
                    'default_value' => 'LBL_BY',
                  ),
                  2 => 
                  array (
                    'name' => 'modified_by_name',
                  ),
                ),
              ),
            ),
          ),
        ),
        'templateMeta' => 
        array (
          'maxColumns' => 1,
          'useTabs' => false,
        ),
      ),
    ),
  ),
);

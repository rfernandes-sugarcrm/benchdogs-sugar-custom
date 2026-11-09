<?php
// created: 2026-10-20 21:38:28
$viewdefs['Products']['base']['view']['record'] = array (
  'buttons' => 
  array (
    0 => 
    array (
      'type' => 'rowaction',
      'event' => 'button:cancel_button:click',
      'name' => 'cancel_button',
      'label' => 'LBL_CANCEL_BUTTON_LABEL',
      'css_class' => 'btn btn-invisible btn-link',
      'showOn' => 'edit',
    ),
    1 => 
    array (
      'type' => 'rowaction',
      'event' => 'button:save_button:click',
      'name' => 'save_button',
      'label' => 'LBL_SAVE_BUTTON_LABEL',
      'css_class' => 'btn btn-primary',
      'showOn' => 'edit',
      'acl_action' => 'edit',
    ),
    2 => 
    array (
      'type' => 'actiondropdown',
      'name' => 'main_dropdown',
      'primary' => true,
      'showOn' => 'view',
      'buttons' => 
      array (
        0 => 
        array (
          'type' => 'rowaction',
          'event' => 'button:edit_button:click',
          'name' => 'edit_button',
          'label' => 'LBL_EDIT_BUTTON_LABEL',
          'acl_action' => 'edit',
        ),
        1 => 
        array (
          'type' => 'shareaction',
          'name' => 'share',
          'label' => 'LBL_RECORD_SHARE_BUTTON',
          'acl_action' => 'view',
        ),
        2 => 
        array (
          'type' => 'pdfaction',
          'name' => 'download-pdf',
          'label' => 'LBL_PDF_VIEW',
          'action' => 'download',
          'acl_action' => 'view',
        ),
        3 => 
        array (
          'type' => 'pdfaction',
          'name' => 'email-pdf',
          'label' => 'LBL_PDF_EMAIL',
          'action' => 'email',
          'acl_action' => 'view',
        ),
        4 => 
        array (
          'type' => 'divider',
        ),
        5 => 
        array (
          'type' => 'rowaction',
          'event' => 'button:find_duplicates_button:click',
          'name' => 'find_duplicates_button',
          'label' => 'LBL_DUP_MERGE',
          'acl_action' => 'edit',
        ),
        6 => 
        array (
          'type' => 'rowaction',
          'event' => 'button:duplicate_button:click',
          'name' => 'duplicate_button',
          'label' => 'LBL_DUPLICATE_BUTTON_LABEL',
          'acl_module' => 'Products',
          'acl_action' => 'create',
        ),
        7 => 
        array (
          'type' => 'rowaction',
          'event' => 'button:audit_button:click',
          'name' => 'audit_button',
          'label' => 'LNK_VIEW_CHANGE_LOG',
          'acl_action' => 'view',
        ),
        8 => 
        array (
          'type' => 'divider',
        ),
        9 => 
        array (
          'type' => 'rowaction',
          'event' => 'button:delete_button:click',
          'name' => 'delete_button',
          'label' => 'LBL_DELETE_BUTTON_LABEL',
          'acl_action' => 'delete',
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'sidebar_toggle',
      'type' => 'sidebartoggle',
    ),
  ),
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
        2 => 
        array (
          'name' => 'favorite',
          'label' => 'LBL_FAVORITE',
          'type' => 'favorite',
          'dismiss_label' => true,
        ),
        3 => 
        array (
          'name' => 'follow',
          'label' => 'LBL_FOLLOW',
          'type' => 'follow',
          'readonly' => true,
          'dismiss_label' => true,
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
        16 => 'service',
        17 => 
        array (
          'name' => 'service_start_date',
          'label' => 'LBL_SERVICE_START_DATE',
          'type' => 'date',
        ),
        18 => 
        array (
          'name' => 'service_end_date',
          'label' => 'LBL_SERVICE_END_DATE',
          'type' => 'service-enddate',
        ),
        19 => 
        array (
          'name' => 'renewable',
          'label' => 'LBL_RENEWABLE',
          'type' => 'bool',
        ),
        20 => 
        array (
          'name' => 'add_on_to_name',
          'type' => 'add-on-to',
        ),
        21 => 'contact_name',
        22 => 
        array (
          'name' => 'tag',
          'span' => 12,
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
      'panelDefault' => 'collapsed',
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
          'span' => 12,
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
  'dependencies' => 
  array (
  ),
  'templateMeta' => 
  array (
    'useTabs' => false,
  ),
);
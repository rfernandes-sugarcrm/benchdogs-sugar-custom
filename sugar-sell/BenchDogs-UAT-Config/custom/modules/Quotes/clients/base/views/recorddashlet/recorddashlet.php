<?php
$viewdefs['Quotes'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'recorddashlet' => 
      array (
        'buttons' => 
        array (
          0 => 
          array (
            'type' => 'button',
            'name' => 'cancel_button',
            'label' => 'LBL_CANCEL_BUTTON_LABEL',
            'css_class' => 'btn-invisible btn-link',
            'showOn' => 'edit',
            'events' => 
            array (
              'click' => 'button:cancel_button:click',
            ),
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
            'type' => 'refresh-price-availability',
            'event' => 'button:refresh_price_availability_button:click',
            'name' => 'refresh_price_availability_button',
            'label' => 'LBL_REFRESH_PRICE_AVAILABILITY_BUTTON',
            'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
            'showOn' => 'view',
            'acl_action' => 'edit',
          ),
          3 => 
          array (
            'type' => 'erp-order-selected',
            'event' => 'button:erp_order_selected_button:click',
            'name' => 'erp_order_selected_button',
            'label' => 'LBL_ERP_ORDER_SELECTED_BUTTON',
            'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
            'showOn' => 'view',
            'acl_action' => 'edit',
          ),
          4 => 
          array (
            'type' => 'create-erp-order',
            'event' => 'button:create_erp_order_button:click',
            'name' => 'create_erp_order_button',
            'label' => 'LBL_CREATE_ERP_ORDER_BUTTON',
            'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-primary ml-2',
            'showOn' => 'view',
            'acl_action' => 'edit',
          ),
          5 => 
          array (
            'type' => 'advanced-quote',
            'event' => 'button:advanced_quote_button:click',
            'name' => 'advanced_quote_button',
            'label' => 'LBL_ADVANCED_QUOTE_BUTTON',
            'css_class' => 'rowaction actionbuttons actionbuttons-button btn btn-secondary ml-2',
            'showOn' => 'view',
            'acl_action' => 'edit',
          ),
          6 => 
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
            ),
            'erp_pdf_original_actions' => 
            array (
              2 => 
              array (
                'type' => 'pdfaction',
                'name' => 'download-pdf',
                'label' => 'LBL_PDF_VIEW',
                'action' => 'download',
                'acl_action' => 'view',
                'erp_excluded_template_ids' => 
                array (
                  0 => '919a721a-c34f-11f1-86c9-02b131184c4f',
                  1 => '919cbf2a-c34f-11f1-a3e2-02b131184c4f',
                ),
              ),
              3 => 
              array (
                'type' => 'pdfaction',
                'name' => 'email-pdf',
                'label' => 'LBL_PDF_EMAIL',
                'action' => 'email',
                'acl_action' => 'view',
                'erp_excluded_template_ids' => 
                array (
                  0 => '919a721a-c34f-11f1-86c9-02b131184c4f',
                  1 => '919cbf2a-c34f-11f1-a3e2-02b131184c4f',
                ),
              ),
            ),
          ),
          7 => 
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
            'label' => 'LBL_PANEL_HEADER',
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
                'events' => 
                array (
                  'keyup' => 'update:quote',
                ),
                'related_fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'bundles',
                    'fields' => 
                    array (
                      0 => 'id',
                      1 => 'bundle_stage',
                      2 => 'currency_id',
                      3 => 'base_rate',
                      4 => 'currencies',
                      5 => 'name',
                      6 => 'deal_tot',
                      7 => 'deal_tot_usdollar',
                      8 => 'deal_tot_discount_percentage',
                      9 => 'new_sub',
                      10 => 'new_sub_usdollar',
                      11 => 'position',
                      12 => 'related_records',
                      13 => 'shipping',
                      14 => 'shipping_usdollar',
                      15 => 'subtotal',
                      16 => 'subtotal_usdollar',
                      17 => 'tax',
                      18 => 'tax_usdollar',
                      19 => 'taxrate_id',
                      20 => 'team_count',
                      21 => 'team_count_link',
                      22 => 'team_name',
                      23 => 'taxable_subtotal',
                      24 => 'total',
                      25 => 'total_usdollar',
                      26 => 'default_group',
                      27 => 
                      array (
                        'name' => 'product_bundle_items',
                        'fields' => 
                        array (
                          0 => 'account_id',
                          1 => 'name',
                          2 => 'quote_id',
                          3 => 'description',
                          4 => 'quantity',
                          5 => 'product_template_name',
                          6 => 'product_template_id',
                          7 => 'deal_calc',
                          8 => 'mft_part_num',
                          9 => 'discount_price',
                          10 => 'discount_amount',
                          11 => 'tax',
                          12 => 'tax_class',
                          13 => 'subtotal',
                          14 => 'position',
                          15 => 'currency_id',
                          16 => 'base_rate',
                          17 => 'discount_select',
                          18 => 'total_amount',
                          19 => 'service',
                          20 => 'service_start_date',
                          21 => 'service_end_date',
                          22 => 'renewable',
                          23 => 'service_duration_value',
                          24 => 'service_duration_unit',
                          25 => 'catalog_service_duration_value',
                          26 => 'catalog_service_duration_unit',
                          27 => 'erp_available_qty',
                          28 => 'erp_price_availability_synced_at',
                          29 => 'erp_stock_availability',
                          30 => 'epicor_line_deeplink_url',
                          31 => 'epicor_eto_deeplink_url',
                          32 => 'erp_unit_of_measure',
                          33 => 'erp_estimation_placeholder',
                          34 => 'erp_need_by_date',
                          35 => 'erp_ship_by_date',
                          36 => 'category_id',
                          37 => 'category_name',
                          38 => 'erp_break_quantity',
                          39 => 'erp_break_unit_price',
                          40 => 'erp_applied_break_quantity',
                          41 => 'erp_total_role',
                          42 => 'erp_sync_key',
                          43 => 'erp_ladder_group',
                          44 => 'erp_ordered',
                          45 => 'erp_ordered_order_num',
                          46 => 'erp_ordered_at',
                          47 => 'erp_governing',
                        ),
                        'max_num' => -1,
                      ),
                    ),
                    'max_num' => -1,
                    'order_by' => 'position:asc',
                  ),
                ),
              ),
            ),
          ),
          1 => 
          array (
            'name' => 'panel_body',
            'label' => 'LBL_RECORD_BODY',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => true,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_quote_type',
                'type' => 'erp-quote-type-switch',
                'label' => 'LBL_ERP_QUOTE_TYPE_SWITCH',
                'required' => true,
                'related_fields' => 
                array (
                  0 => 'order_stage',
                  1 => 'opportunity_id',
                  2 => 'erp_sync_key',
                  3 => 'taxrate_id',
                  4 => 'taxrate_value',
                  5 => 'base_rate',
                  6 => 'lock_conversion_rates',
                  7 => 'epicor_deeplink_url',
                  8 => 'erp_shipping_amount',
                ),
                'span' => 12,
              ),
              1 => 'quote_num',
              2 => 
              array (
                'name' => 'erp_display_sync_key',
                'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
                'type' => 'erp-id-link',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'epicor_deeplink_url',
                ),
              ),
              3 => 
              array (
                'name' => 'opportunity_name',
                'related_fields' => 
                array (
                  0 => 'subtotal',
                  1 => 'discount',
                  2 => 'new_sub',
                  3 => 'tax',
                  4 => 'shipping',
                ),
              ),
              4 => 
              array (
                'name' => 'account_name',
                'studio' => 
                array (
                  'edit' => 'false',
                  'detail' => 'false',
                  'list' => 'false',
                ),
                'comment' => 'Legacy alias for the billing Account name on this quote',
                'label' => 'LBL_ACCOUNT_NAME',
              ),
              5 => 
              array (
                'name' => 'purchase_order_num',
                'readonly' => true,
              ),
              6 => 
              array (
                'name' => 'e_expiration_date_c',
                'label' => 'LBL_E_EXPIRATION_DATE_C',
              ),
              7 => 'quote_stage',
              8 => 'date_quote_expected_closed',
              9 => 
              array (
                'name' => 'e_best_case_pct_c',
                'label' => 'LBL_E_BEST_CASE_PCT_C',
              ),
              10 => 
              array (
                'name' => 'e_confidence_pct_c',
                'label' => 'LBL_E_CONFIDENCE_PCT_C',
              ),
              11 => 
              array (
                'name' => 'erp_is_primary_quote',
                'label' => 'LBL_ERP_IS_PRIMARY_QUOTE',
              ),
              12 => 
              array (
              ),
            ),
          ),
          2 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_ERP',
            'label' => 'LBL_RECORDVIEW_PANEL_ERP',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_companies_quotes_name',
                'label' => 'LBL_ERP_COMPANIES_QUOTES_FROM_ERP_COMPANIES_TITLE',
              ),
              1 => 
              array (
                'name' => 'erp_quotes_billing_terms_name',
                'label' => 'LBL_ERP_QUOTES_BILLING_TERMS_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              2 => 
              array (
                'name' => 'erp_quotes_fob_name',
                'label' => 'LBL_ERP_QUOTES_FOB_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              3 => 
              array (
                'name' => 'erp_quotes_ship_via_name',
                'label' => 'LBL_ERP_QUOTES_SHIP_VIA_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              4 => 
              array (
                'name' => 'erp_reference',
                'label' => 'LBL_ERP_REFERENCE',
              ),
              5 => 
              array (
                'name' => 'erp_need_by_date',
                'label' => 'LBL_ERP_NEED_BY_DATE',
              ),
              6 => 
              array (
                'name' => 'erp_ship_by_date',
                'label' => 'LBL_ERP_SHIP_BY_DATE',
              ),
              7 => 
              array (
                'name' => 'erp_parent_quote_num',
                'label' => 'LBL_ERP_PARENT_QUOTE_NUM',
                'readonly' => true,
              ),
              8 => 
              array (
                'name' => 'erp_discount_refusal',
                'label' => 'LBL_ERP_DISCOUNT_REFUSAL',
                'readonly' => true,
              ),
              9 => 
              array (
                'name' => 'erp_estimate_stage',
                'label' => 'LBL_ERP_ESTIMATE_STAGE',
                'readonly' => true,
              ),
              10 => 
              array (
                'name' => 'erp_priced_at',
                'label' => 'LBL_ERP_PRICED_AT',
                'readonly' => true,
                'type' => 'erp-readonly-datetime',
              ),
              11 => 
              array (
                'name' => 'erp_reason_code',
                'label' => 'LBL_ERP_REASON_CODE',
                'type' => 'erp-reason',
                'related_fields' => 
                array (
                  0 => 'erp_reason_label',
                ),
                'readonly' => false,
              ),
              12 => 
              array (
                'name' => 'bd_lead_source',
                'label' => 'LBL_BD_LEAD_SOURCE',
                'type' => 'erp-dependent-enum',
              ),
              13 => 
              array (
                'name' => 'bd_lead_type',
                'label' => 'LBL_BD_LEAD_TYPE',
                'type' => 'erp-dependent-enum',
              ),
              14 => 
              array (
                'name' => 'bd_marketing_campaign',
                'label' => 'LBL_BD_MARKETING_CAMPAIGN',
                'type' => 'erp-dependent-enum',
              ),
              15 => 
              array (
                'name' => 'bd_marketing_event',
                'label' => 'LBL_BD_MARKETING_EVENT',
                'type' => 'erp-dependent-enum',
              ),
              16 => 
              array (
                'name' => 'erp_writeback_status',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_STATUS',
                'related_fields' => 
                array (
                  0 => 'order_stage',
                  1 => 'opportunity_id',
                  2 => 'erp_sync_key',
                  3 => 'taxrate_id',
                  4 => 'taxrate_value',
                  5 => 'base_rate',
                  6 => 'lock_conversion_rates',
                  7 => 'epicor_deeplink_url',
                  8 => 'erp_shipping_amount',
                ),
              ),
              17 => 
              array (
                'name' => 'erp_writeback_at',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_AT',
                'type' => 'erp-readonly-datetime',
              ),
              18 => 
              array (
              ),
              19 => 
              array (
                'name' => 'erp_writeback_msg',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_MSG',
                'span' => 12,
              ),
              20 => 
              array (
                'name' => 'erp_comment_log_status',
                'type' => 'erp-comment-log-status',
                'label' => 'LBL_ERP_COMMENT_LOG_STATUS',
                'dismiss_label' => true,
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_commentlog_sync',
                  1 => 'erp_display_sync_key',
                  2 => 'erp_quote_type',
                ),
                'span' => 12,
              ),
            ),
          ),
          3 => 
          array (
            'name' => 'panel_shipping_body',
            'label' => 'LBL_SHIPPING_BODY',
            'panelDefault' => 'collapsed',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'fields' => 
            array (
              0 => 'billing_account_name',
              1 => 
              array (
                'name' => 'shipping_address_name',
              ),
              2 => 'billing_contact_name',
              3 => 'shipping_contact_name',
              4 => 
              array (
                'name' => 'billing_address',
                'type' => 'fieldset',
                'css_class' => 'address',
                'label' => 'LBL_BILLING_ADDRESS',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'billing_address_street',
                    'css_class' => 'address_street',
                    'placeholder' => 'LBL_BILLING_ADDRESS_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'billing_address_city',
                    'css_class' => 'address_city',
                    'placeholder' => 'LBL_BILLING_ADDRESS_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'billing_address_state',
                    'css_class' => 'address_state',
                    'placeholder' => 'LBL_BILLING_ADDRESS_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'billing_address_postalcode',
                    'css_class' => 'address_zip',
                    'placeholder' => 'LBL_BILLING_ADDRESS_POSTAL_CODE',
                  ),
                  4 => 
                  array (
                    'name' => 'billing_address_country',
                    'css_class' => 'address_country',
                    'placeholder' => 'LBL_BILLING_ADDRESS_COUNTRY',
                  ),
                ),
              ),
              5 => 
              array (
                'name' => 'shipping_address',
                'type' => 'fieldset',
                'css_class' => 'address',
                'label' => 'LBL_SHIPPING_ADDRESS',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'shipping_address_street',
                    'css_class' => 'address_street',
                    'placeholder' => 'LBL_SHIPPING_ADDRESS_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'shipping_address_city',
                    'css_class' => 'address_city',
                    'placeholder' => 'LBL_SHIPPING_ADDRESS_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'shipping_address_state',
                    'css_class' => 'address_state',
                    'placeholder' => 'LBL_SHIPPING_ADDRESS_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'shipping_address_postalcode',
                    'css_class' => 'address_zip',
                    'placeholder' => 'LBL_SHIPPING_ADDRESS_POSTAL_CODE',
                  ),
                  4 => 
                  array (
                    'name' => 'shipping_address_country',
                    'css_class' => 'address_country',
                    'placeholder' => 'LBL_SHIPPING_ADDRESS_COUNTRY',
                  ),
                  5 => 
                  array (
                    'name' => 'copy',
                    'label' => 'NTC_COPY_BILLING_ADDRESS',
                    'type' => 'copy',
                    'mapping' => 
                    array (
                      'billing_account_name' => 'shipping_account_name',
                      'billing_account_id' => 'shipping_account_id',
                      'billing_address_street' => 'shipping_address_street',
                      'billing_address_city' => 'shipping_address_city',
                      'billing_address_state' => 'shipping_address_state',
                      'billing_address_postalcode' => 'shipping_address_postalcode',
                      'billing_address_country' => 'shipping_address_country',
                    ),
                  ),
                ),
              ),
            ),
          ),
          4 => 
          array (
            'name' => 'panel_hidden',
            'label' => 'LBL_RECORD_SHOWMORE',
            'panelDefault' => 'collapsed',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'erpBusinessCard' => true,
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'shipper_name',
                'initial_filter' => 'active_shippers',
                'filter_populate' => 
                array (
                  'module' => 
                  array (
                    0 => 'Shippers',
                  ),
                ),
              ),
              1 => 
              array (
                'name' => 'geocode_status',
                'licenseFilter' => 
                array (
                  0 => 'MAPS',
                ),
              ),
              2 => 'team_name',
              3 => 'assigned_user_name',
              4 => 
              array (
                'name' => 'date_entered_by',
                'readonly' => true,
                'type' => 'fieldset',
                'inline' => true,
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
              5 => 
              array (
                'name' => 'date_modified_by',
                'readonly' => true,
                'type' => 'fieldset',
                'inline' => true,
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
              6 => 
              array (
                'name' => 'tag',
                'span' => 12,
              ),
              7 => 
              array (
                'name' => 'erp_sent_to_estimating_at',
                'type' => 'erp-readonly-datetime',
                'label' => 'LBL_ERP_SENT_TO_ESTIMATING_AT',
                'readonly' => true,
              ),
              8 => 
              array (
              ),
            ),
          ),
          5 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT',
            'label' => 'LBL_RECORDVIEW_PANEL_ERP_DISCOUNT',
            'columns' => 2,
            'newTab' => false,
            'panelDefault' => 'collapsed',
            'erpBusinessCard' => true,
            'placeholders' => 1,
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_discount_panel',
                'type' => 'erp-discount',
                'label' => 'LBL_ERP_DISCOUNT_TITLE',
                'readonly' => true,
                'span' => 12,
              ),
            ),
          ),
          6 => 
          array (
            'name' => 'panel_setting_body',
            'label' => 'LBL_QUOTESETTINGS',
            'panelDefault' => 'collapsed',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => true,
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'currency_id',
                'type' => 'currency-type-dropdown',
                'label' => 'LBL_CURRENCY',
                'related_fields' => 
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
                'currency_field' => 'currency_id',
                'base_rate_field' => 'base_rate',
              ),
              1 => 'show_line_nums',
            ),
          ),
        ),
        'templateMeta' => 
        array (
          'useTabs' => true,
        ),
      ),
    ),
  ),
);

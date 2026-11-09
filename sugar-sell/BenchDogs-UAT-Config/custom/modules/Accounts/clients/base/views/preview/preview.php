<?php
$viewdefs['Accounts'] = 
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
                'white_list' => true,
                'licenseDependency' => 
                array (
                  'HINT' => 
                  array (
                    'name' => 'hint_account_pic',
                    'type' => 'hint-accounts-logo',
                  ),
                ),
              ),
              1 => 
              array (
                'name' => 'name',
                'type' => 'name',
                'licenseDependency' => 
                array (
                  'HINT' => 
                  array (
                    'type' => 'hint-accounts-search-dropdown',
                  ),
                ),
              ),
              3 => 
              array (
                'name' => 'is_escalated',
                'type' => 'badge',
                'badge_label' => 'LBL_ESCALATED',
                'warning_level' => 'important',
                'dismiss_label' => true,
              ),
              4 => 
              array (
                'name' => 'erp_credit_hold_badge',
                'type' => 'erp-credit-badge',
                'readonly' => true,
                'dismiss_label' => true,
                'related_fields' => 
                array (
                  0 => 'erp_account_id',
                  1 => 'erp_credit_hold',
                  2 => 'erp_credit_available',
                  3 => 'erp_credit_limit',
                ),
              ),
              5 => 
              array (
                'name' => 'erp_inactive_account_badge',
                'type' => 'erp-source-badge',
                'source_field' => 'erp_is_inactive_account',
                'badge_label' => 'LBL_ERP_IS_INACTIVE_ACCOUNT',
                'warning_level' => 'important',
                'dismiss_label' => true,
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_is_inactive_account',
                ),
              ),
            ),
          ),
          1 => 
          array (
            'name' => 'panel_overview',
            'label' => 'LBL_RECORD_PANEL_OVERVIEW',
            'newTab' => true,
            'columns' => 2,
            'placeholders' => true,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'parent_name',
              1 => 'assigned_user_name',
              2 => 'account_type',
              3 => 'industry',
              4 => 
              array (
                'name' => 'bd_customer_group',
                'label' => 'LBL_BD_CUSTOMER_GROUP',
              ),
              5 => 
              array (
                'name' => 'bd_customer_group_code',
                'label' => 'LBL_BD_CUSTOMER_GROUP_CODE',
              ),
              6 => 
              array (
                'name' => 'health_c',
                'label' => 'LBL_HEALTH_C',
              ),
              7 => 
              array (
                'name' => 'last_interaction_date',
                'readonly' => true,
                'type' => 'last_interaction',
                'label' => 'LBL_LAST_INTERACTION',
                'fields' => 
                array (
                  0 => 'last_interaction_date',
                  1 => 'last_interaction_parent_type',
                  2 => 'last_interaction_parent_id',
                  3 => 'last_interaction_parent_name',
                ),
              ),
              8 => 
              array (
                'name' => 'days_since_last_interaction_c',
                'label' => 'LBL_DAYS_SINCE_LAST_INTERACTION_C',
              ),
              9 => 
              array (
                'name' => 'last_opportunity_date',
                'label' => 'LBL_LAST_OPPORTUNITY_DATE',
                'enabled' => true,
                'default' => false,
              ),
              10 => 
              array (
                'name' => 'days_since_last_sale_c',
                'label' => 'LBL_DAYS_SINCE_LAST_SALE_C',
              ),
              11 => 
              array (
                'name' => 'description',
              ),
              12 => 
              array (
                'name' => 'tag',
              ),
            ),
          ),
          2 => 
          array (
            'name' => 'panel_phone_and_address',
            'label' => 'LBL_RECORD_PANEL_PHONE_AND_ADDRESS',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'phone_office',
              1 => 'phone_fax',
              2 => 
              array (
                'name' => 'phone_alternate',
                'label' => 'LBL_PHONE_ALT',
              ),
              3 => 'website',
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
                    'placeholder' => 'LBL_BILLING_ADDRESS_POSTALCODE',
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
                'name' => 'email',
                'studio' => 
                array (
                  'visible' => true,
                  'searchview' => true,
                  'editview' => true,
                  'editField' => true,
                ),
                'comment' => 'Non-db search/subquery field used to match this record against any of its related Email Address records by address text.',
                'label' => 'LBL_EMAIL_ADDRESS',
              ),
            ),
          ),
          3 => 
          array (
            'name' => 'panel_social_media',
            'label' => 'LBL_RECORD_PANEL_SOCIAL_MEDIA',
            'newTab' => true,
            'columns' => 2,
            'placeholders' => true,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'facebook',
              1 => 'twitter',
              2 => 'linkedin',
              3 => 'instagram',
            ),
          ),
          4 => 
          array (
            'name' => 'panel_information',
            'label' => 'LBL_RECORD_PANEL_INFORMATION',
            'newTab' => true,
            'columns' => 2,
            'placeholders' => true,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'team_name',
              1 => 
              array (
                'span' => 12,
              ),
              2 => 
              array (
                'name' => 'date_entered_by',
                'readonly' => true,
                'inline' => true,
                'type' => 'fieldset',
                'label' => 'LBL_DATE_ENTERED',
                'fields' => 
                array (
                  0 => 'date_entered',
                  1 => 
                  array (
                    'type' => 'label',
                    'default_value' => 'LBL_BY',
                  ),
                  2 => 'created_by_name',
                ),
              ),
              3 => 
              array (
                'name' => 'date_modified_by',
                'readonly' => true,
                'inline' => true,
                'type' => 'fieldset',
                'label' => 'LBL_DATE_MODIFIED',
                'fields' => 
                array (
                  0 => 'date_modified',
                  1 => 
                  array (
                    'type' => 'label',
                    'default_value' => 'LBL_BY',
                  ),
                  2 => 'modified_by_name',
                ),
              ),
            ),
          ),
          5 => 
          array (
            'name' => 'panel_hidden',
            'label' => 'LBL_RECORD_SHOWMORE',
            'hide' => true,
            'placeholders' => true,
            'columns' => 2,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_display_sync_key',
                'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
              ),
              1 => 
              array (
                'span' => 12,
              ),
            ),
          ),
          6 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_ERP',
            'label' => 'LBL_RECORDVIEW_PANEL_ERP',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => true,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_companies_accounts_name',
                'label' => 'LBL_ERP_COMPANIES_ACCOUNTS_FROM_ERP_COMPANIES_TITLE',
              ),
              1 => 
              array (
                'name' => 'erp_territory_name',
                'label' => 'LBL_ERP_TERRITORY_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              2 => 
              array (
                'name' => 'erp_account_id',
                'label' => 'LBL_ERP_ACCOUNT_ID',
                'type' => 'erp-id-link',
                'related_fields' => 
                array (
                  0 => 'erp_sync_key',
                  1 => 'epicor_deeplink_url',
                ),
                'readonly' => true,
              ),
              3 => 
              array (
                'name' => 'erp_is_inactive_account',
                'label' => 'LBL_ERP_PANEL_INACTIVE',
                'readonly' => true,
              ),
              4 => 
              array (
                'name' => 'erp_valid_payer',
                'label' => 'LBL_ERP_VALID_PAYER',
                'readonly' => true,
              ),
              5 => 
              array (
                'name' => 'erp_valid_sold_to',
                'label' => 'LBL_ERP_VALID_SOLD_TO',
                'readonly' => true,
              ),
              6 => 
              array (
                'name' => 'erp_valid_ship_to',
                'label' => 'LBL_ERP_VALID_SHIP_TO',
                'readonly' => true,
              ),
              7 => 
              array (
                'name' => 'erp_allow_one_time_ship_to',
                'label' => 'LBL_ERP_PANEL_ONE_TIME_SHIP_TO',
                'readonly' => true,
              ),
            ),
          ),
          7 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_BILLING_DETAIL',
            'label' => 'LBL_RECORDVIEW_PANEL_BILLING_DETAIL',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_currency_id',
                'type' => 'erp-currency-type-dropdown',
                'label' => 'LBL_CURRENCY',
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
              1 => 
              array (
                'name' => 'erp_fob_name',
                'label' => 'LBL_ERP_FOB_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              2 => 
              array (
                'name' => 'erp_billing_terms_name',
                'label' => 'LBL_ERP_BILLING_TERMS_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              3 => 
              array (
                'name' => 'erp_billing_frequency_name',
                'label' => 'LBL_ERP_PANEL_BILLING_FREQUENCY',
              ),
              4 => 
              array (
                'name' => 'erp_discount_percent',
                'label' => 'LBL_ERP_DISCOUNT_PERCENT',
              ),
              5 => 
              array (
                'name' => 'erp_ship_via_name',
                'label' => 'LBL_ERP_SHIP_VIA_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
            ),
          ),
          8 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_CREDIT_DETAIL',
            'label' => 'LBL_RECORDVIEW_PANEL_CREDIT_DETAIL',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_credit_limit',
                'label' => 'LBL_ERP_PANEL_CREDIT_LIMIT',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
              1 => 
              array (
                'name' => 'erp_credit_available',
                'label' => 'LBL_ERP_PANEL_CREDIT_AVAILABLE',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
              2 => 
              array (
                'name' => 'erp_credit_hold',
                'label' => 'LBL_ERP_CREDIT_HOLD',
                'readonly' => true,
              ),
              3 => 
              array (
                'name' => 'erp_credit_hold_reason_name',
                'label' => 'LBL_ERP_PANEL_HOLD_REASON',
                'readonly' => true,
              ),
              4 => 
              array (
                'name' => 'erp_open_orders',
                'label' => 'LBL_ERP_OPEN_ORDERS',
                'readonly' => true,
              ),
              5 => 
              array (
                'name' => 'erp_open_invoices',
                'label' => 'LBL_ERP_OPEN_INVOICES',
                'readonly' => true,
              ),
              6 => 
              array (
                'name' => 'erp_last_sale_date',
                'label' => 'LBL_ERP_PANEL_LAST_SALE_DATE',
                'readonly' => true,
              ),
              7 => 
              array (
                'span' => 12,
              ),
              8 => 
              array (
                'name' => 'erp_last_payment_date',
                'label' => 'LBL_ERP_PANEL_LAST_PAYMENT_DATE',
                'readonly' => true,
              ),
              9 => 
              array (
                'name' => 'erp_last_payment_amount',
                'label' => 'LBL_ERP_PANEL_LAST_PAYMENT_AMOUNT',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
              10 => 
              array (
                'name' => 'erp_ytd_revenue_amount',
                'label' => 'LBL_ERP_PANEL_YTD_REVENUE',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
              11 => 
              array (
                'name' => 'erp_last_year_revenue_amount',
                'label' => 'LBL_ERP_PANEL_LAST_YEAR_REVENUE',
                'readonly' => true,
                'related_fields' => 
                array (
                  0 => 'erp_currency_id',
                  1 => 'erp_base_rate',
                ),
                'currency_field' => 'erp_currency_id',
                'base_rate_field' => 'erp_base_rate',
              ),
            ),
          ),
          9 => 
          array (
            'name' => 'LBL_RECORDVIEW_PANEL_SYNC_STATUS',
            'label' => 'LBL_RECORDVIEW_PANEL_SYNC_STATUS',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'erp_writeback_status',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_STATUS',
              ),
              1 => 
              array (
                'name' => 'erp_writeback_at',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_AT',
              ),
              2 => 
              array (
                'name' => 'erp_writeback_msg',
                'readonly' => true,
                'label' => 'LBL_ERP_WRITEBACK_MSG',
              ),
            ),
          ),
        ),
        'templateMeta' => 
        array (
          'maxColumns' => 1,
          'useTabs' => true,
        ),
      ),
    ),
  ),
);

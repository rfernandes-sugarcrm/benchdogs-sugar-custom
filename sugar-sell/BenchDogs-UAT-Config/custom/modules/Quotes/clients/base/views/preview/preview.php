<?php
$viewdefs['Quotes'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'preview' => 
      array (
        'templateMeta' => 
        array (
          'maxColumns' => 1,
          'useTabs' => false,
        ),
        'panels' => 
        array (
          0 => 
          array (
            'name' => 'panel_header',
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
              1 => 'name',
              2 => 
              array (
                'name' => 'quote_stage',
                'type' => 'event-status',
                'enum_width' => 'auto',
                'dropdown_width' => 'auto',
                'dropdown_class' => 'select2-menu-only',
                'container_class' => 'select2-menu-only',
              ),
            ),
          ),
          1 => 
          array (
            'name' => 'panel_body',
            'newTab' => false,
            'panelDefault' => 'expanded',
            'label' => 'LBL_RECORD_BODY',
            'columns' => 1,
            'placeholders' => 1,
            'fields' => 
            array (
              0 => 'opportunity_name',
              1 => 'quote_num',
              2 => 'purchase_order_num',
              3 => 'date_quote_expected_closed',
              4 => 
              array (
                'name' => 'e_expiration_date_c',
                'label' => 'LBL_E_EXPIRATION_DATE_C',
              ),
              5 => 
              array (
                'name' => 'e_best_case_pct_c',
                'label' => 'LBL_E_BEST_CASE_PCT_C',
              ),
              6 => 
              array (
                'name' => 'e_confidence_pct_c',
                'label' => 'LBL_E_CONFIDENCE_PCT_C',
              ),
              7 => 'payment_terms',
              8 => 'original_po_date',
              9 => 'billing_account_name',
              10 => 'billing_contact_name',
              11 => 
              array (
                'name' => 'billing_address_fieldset',
                'inline' => false,
                'type' => 'fieldset',
                'label' => 'LBL_BILLING_ADDRESS_STREET',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'billing_address_street',
                    'placeholder' => 'LBL_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'billing_address_city',
                    'placeholder' => 'LBL_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'billing_address_state',
                    'placeholder' => 'LBL_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'billing_address_postalcode',
                    'placeholder' => 'LBL_POSTAL_CODE',
                  ),
                  4 => 
                  array (
                    'name' => 'billing_address_country',
                    'placeholder' => 'LBL_COUNTRY',
                  ),
                ),
              ),
              12 => 
              array (
                'name' => 'new_sub',
                'css_class' => 'quote-totals-row-item',
              ),
              13 => 
              array (
                'name' => 'tax',
                'label' => 'LBL_TAX_TOTAL',
                'css_class' => 'quote-totals-row-item',
              ),
              14 => 
              array (
                'name' => 'shipping',
                'css_class' => 'quote-totals-row-item',
              ),
              15 => 
              array (
                'name' => 'total',
                'label' => 'LBL_LIST_GRAND_TOTAL',
                'css_class' => 'quote-totals-row-item',
              ),
              16 => 
              array (
                'name' => 'shipping_address_name',
                'featureFilter' => 
                array (
                  0 => 'shippingAddressesEnabled',
                ),
              ),
              17 => 'shipping_contact_name',
              18 => 
              array (
                'name' => 'shipping_address_fieldset',
                'inline' => false,
                'type' => 'fieldset',
                'label' => 'LBL_SHIPPING_ADDRESS_STREET',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'shipping_address_street',
                    'placeholder' => 'LBL_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'shipping_address_city',
                    'placeholder' => 'LBL_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'shipping_address_state',
                    'placeholder' => 'LBL_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'shipping_address_postalcode',
                    'placeholder' => 'LBL_POSTAL_CODE',
                  ),
                  4 => 
                  array (
                    'name' => 'shipping_address_country',
                    'placeholder' => 'LBL_COUNTRY',
                  ),
                ),
              ),
              19 => 'description',
              20 => 'tag',
            ),
          ),
          2 => 
          array (
            'name' => 'panel_hidden',
            'hide' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'label' => 'LBL_RECORD_SHOWMORE',
            'columns' => 1,
            'placeholders' => 1,
            'fields' => 
            array (
              0 => 'assigned_user_name',
              1 => 'team_name',
              2 => 
              array (
                'name' => 'date_entered_by',
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
              3 => 
              array (
                'name' => 'date_modified_by',
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
            ),
          ),
        ),
      ),
    ),
  ),
);

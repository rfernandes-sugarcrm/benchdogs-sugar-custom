<?php
$viewdefs['Contacts'] = 
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
                'type' => 'hint-contacts-photo',
                'size' => 'medium',
                'dismiss_label' => true,
                'white_list' => true,
                'related_fields' => 
                array (
                  0 => 'hint_contact_pic',
                ),
              ),
              1 => 
              array (
                'name' => 'name',
                'label' => 'LBL_NAME',
                'dismiss_label' => true,
                'type' => 'fullname',
                'fields' => 
                array (
                  0 => 'salutation',
                  1 => 'first_name',
                  2 => 'last_name',
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
              0 => 'account_name',
              1 => 'assigned_user_name',
              2 => 'title',
              3 => 'department',
              4 => 
              array (
                'name' => 'epicor_function_c',
                'label' => 'LBL_EPICOR_FUNCTION_C',
              ),
              5 => 
              array (
                'name' => 'role_c',
                'label' => 'LBL_ROLE_C',
              ),
              6 => 
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
              7 => 
              array (
                'name' => 'primary_billing_c',
                'label' => 'LBL_PRIMARY_BILLING_C',
              ),
              8 => 
              array (
                'name' => 'primary_purchasing_c',
                'label' => 'LBL_PRIMARY_PURCHASING_C',
              ),
              9 => 
              array (
                'name' => 'primary_shipping_c',
                'label' => 'LBL_PRIMARY_SHIPPING_C',
              ),
              10 => 
              array (
                'name' => 'description',
              ),
              11 => 
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
              0 => 'phone_work',
              1 => 'phone_fax',
              2 => 'phone_mobile',
              3 => 
              array (
                'name' => 'phone_other',
                'type' => 'phone',
              ),
              4 => 
              array (
                'name' => 'email',
                'licenseDependency' => 
                array (
                  'HINT' => 
                  array (
                    'type' => 'hint-email',
                  ),
                ),
              ),
              5 => 
              array (
                'span' => 12,
              ),
              6 => 
              array (
                'name' => 'primary_address',
                'type' => 'fieldset',
                'css_class' => 'address',
                'label' => 'LBL_PRIMARY_ADDRESS',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'primary_address_street',
                    'css_class' => 'address_street',
                    'placeholder' => 'LBL_PRIMARY_ADDRESS_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'primary_address_city',
                    'css_class' => 'address_city',
                    'placeholder' => 'LBL_PRIMARY_ADDRESS_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'primary_address_state',
                    'css_class' => 'address_state',
                    'placeholder' => 'LBL_PRIMARY_ADDRESS_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'primary_address_postalcode',
                    'css_class' => 'address_zip',
                    'placeholder' => 'LBL_PRIMARY_ADDRESS_POSTALCODE',
                  ),
                  4 => 
                  array (
                    'name' => 'primary_address_country',
                    'css_class' => 'address_country',
                    'placeholder' => 'LBL_PRIMARY_ADDRESS_COUNTRY',
                  ),
                ),
              ),
              7 => 
              array (
                'name' => 'alt_address',
                'type' => 'fieldset',
                'css_class' => 'address',
                'label' => 'LBL_ALT_ADDRESS',
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'alt_address_street',
                    'css_class' => 'address_street',
                    'placeholder' => 'LBL_ALT_ADDRESS_STREET',
                  ),
                  1 => 
                  array (
                    'name' => 'alt_address_city',
                    'css_class' => 'address_city',
                    'placeholder' => 'LBL_ALT_ADDRESS_CITY',
                  ),
                  2 => 
                  array (
                    'name' => 'alt_address_state',
                    'css_class' => 'address_state',
                    'placeholder' => 'LBL_ALT_ADDRESS_STATE',
                  ),
                  3 => 
                  array (
                    'name' => 'alt_address_postalcode',
                    'css_class' => 'address_zip',
                    'placeholder' => 'LBL_ALT_ADDRESS_POSTALCODE',
                  ),
                  4 => 
                  array (
                    'name' => 'alt_address_country',
                    'css_class' => 'address_country',
                    'placeholder' => 'LBL_ALT_ADDRESS_COUNTRY',
                  ),
                  5 => 
                  array (
                    'name' => 'copy',
                    'label' => 'NTC_COPY_PRIMARY_ADDRESS',
                    'type' => 'copy',
                    'mapping' => 
                    array (
                      'primary_address_street' => 'alt_address_street',
                      'primary_address_city' => 'alt_address_city',
                      'primary_address_state' => 'alt_address_state',
                      'primary_address_postalcode' => 'alt_address_postalcode',
                      'primary_address_country' => 'alt_address_country',
                    ),
                  ),
                ),
              ),
            ),
          ),
          3 => 
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
              1 => 'lead_source',
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
          4 => 
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
                'readonly' => true,
                'name' => 'erp_display_sync_key',
                'label' => 'LBL_ERP_DISPLAY_SYNC_KEY',
                'type' => 'erp-contact-key',
                'related_fields' => 
                array (
                  0 => 'erp_sync_key',
                ),
              ),
              1 => 
              array (
                'readonly' => true,
                'name' => 'erp_writeback_status',
                'label' => 'LBL_ERP_WRITEBACK_STATUS',
              ),
              2 => 
              array (
                'readonly' => true,
                'name' => 'erp_writeback_at',
                'label' => 'LBL_ERP_WRITEBACK_AT',
                'type' => 'erp-readonly-datetime',
              ),
              3 => 
              array (
                'span' => 12,
              ),
              4 => 
              array (
                'readonly' => true,
                'name' => 'erp_writeback_msg',
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

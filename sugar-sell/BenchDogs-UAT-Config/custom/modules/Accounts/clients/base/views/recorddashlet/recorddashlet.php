<?php
$viewdefs['Accounts'] = 
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
                'type' => 'escalate-action',
                'event' => 'button:escalate_button:click',
                'name' => 'escalate_button',
                'label' => 'LBL_ESCALATE_BUTTON_LABEL',
                'acl_action' => 'create',
                'module' => 'Accounts',
              ),
            ),
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
              1 => 'name',
            ),
          ),
          1 => 
          array (
            'name' => 'panel_overview',
            'label' => 'LBL_RECORD_PANEL_OVERVIEW',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
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
                'comment' => 'Epicor CustGrup.GroupDesc for bd_customer_group_code - the human-readable group name',
                'label' => 'LBL_BD_CUSTOMER_GROUP',
              ),
              5 => 
              array (
                'name' => 'bd_customer_group_code',
                'comment' => 'Epicor Customer.GroupCode verbatim - the stable key to group and filter on',
                'label' => 'LBL_BD_CUSTOMER_GROUP_CODE',
              ),
              6 => 
              array (
                'name' => 'last_opportunity_date',
                'label' => 'LBL_LAST_OPPORTUNITY_DATE',
                'enabled' => true,
                'default' => false,
              ),
              7 => 
              array (
              ),
              8 => 
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
              9 => 
              array (
                'name' => 'days_since_last_interaction_c',
                'label' => 'LBL_DAYS_SINCE_LAST_INTERACTION_C',
              ),
              10 => 
              array (
              ),
              11 => 
              array (
                'name' => 'days_since_last_sale_c',
                'label' => 'LBL_DAYS_SINCE_LAST_SALE_C',
              ),
              12 => 
              array (
                'name' => 'description',
                'span' => 12,
              ),
              13 => 
              array (
                'name' => 'tag',
                'span' => 12,
              ),
            ),
          ),
          2 => 
          array (
            'newTab' => false,
            'panelDefault' => 'expanded',
            'name' => 'LBL_RECORDDASHLETVIEW_PANEL1',
            'label' => 'LBL_RECORDDASHLETVIEW_PANEL1',
            'columns' => 2,
            'placeholders' => 1,
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
            'name' => 'panel_information',
            'label' => 'LBL_RECORD_PANEL_INFORMATION',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 'team_name',
              1 => 
              array (
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
              4 => 
              array (
                'name' => 'erp_territory_name',
                'label' => 'LBL_ERP_TERRITORY_FROM_ERP_LOOKUPVALUES_TITLE',
              ),
              5 => 
              array (
              ),
            ),
          ),
        ),
        'templateMeta' => 
        array (
          'useTabs' => false,
        ),
      ),
    ),
  ),
);

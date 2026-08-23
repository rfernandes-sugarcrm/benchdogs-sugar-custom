<?php
$module_name = 'bd01_ERP_Quote_Cost';
$viewdefs[$module_name] =
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
            'label' => 'LBL_RECORD_HEADER',
            'header' => true,
            'fields' =>
            array (
              0 =>
              array (
                'name' => 'picture',
                'type' => 'avatar',
                'width' => 32,
                'height' => 32,
                'dismiss_label' => true,
                'readonly' => true,
                'size' => 'medium',
              ),
              1 => 'name',
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
                'name' => 'bd01_erp_line_costs_name',
              ),
              1 => 'quote_num',
              2 => 'line_num',
              3 => 'qty_num',
              4 => 'quantity',
              5 =>
              array (
                'name' => 'currency_id',
                'type' => 'currency_id',
                'label' => 'LBL_CURRENCY',
                'related_fields' =>
                array (
                  0 => 'currency_id',
                  1 => 'base_rate',
                ),
              ),
            ),
          ),
          2 =>
          array (
            'newTab' => false,
            'panelDefault' => 'expanded',
            'name' => 'LBL_RECORDVIEW_PANEL_COST_BREAKDOWN',
            'label' => 'LBL_RECORDVIEW_PANEL_COST_BREAKDOWN',
            'columns' => 2,
            'placeholders' => 1,
            'fields' =>
            array (
              0 => 'material_cost',
              1 => 'material_burden',
              2 => 'labor_cost',
              3 => 'labor_burden',
              4 => 'subcontract_cost',
              5 => 'misc_cost',
              6 => 'hours',
              7 =>
              array (
              ),
              // No markup field exists on this module - profit and the margin
              // percentage are what the ERP actually sends, so they close the
              // breakdown instead.
              8 => 'profit',
              9 => 'gross_margin_pct',
            ),
          ),
          3 =>
          array (
            'name' => 'panel_hidden',
            'label' => 'LBL_SHOW_MORE',
            'hide' => true,
            'columns' => 2,
            'placeholders' => true,
            'newTab' => true,
            'panelDefault' => 'expanded',
            'fields' =>
            array (
              0 =>
              array (
                'name' => 'erp_sync_key',
                'readonly' => true,
              ),
              1 =>
              array (
              ),
              2 => 'team_name',
              3 => 'assigned_user_name',
              4 =>
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
              5 =>
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
      ),
    ),
  ),
);

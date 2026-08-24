<?php
$module_name = 'bd01_ERP_Quote_Line';
$viewdefs[$module_name] =
array (
  'base' =>
  array (
    'view' =>
    array (
      'record' =>
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
                'type' => 'shareaction',
                'name' => 'share',
                'label' => 'LBL_RECORD_SHARE_BUTTON',
                'acl_action' => 'view',
              ),
              2 =>
              array (
                'type' => 'rowaction',
                'event' => 'button:duplicate_button:click',
                'name' => 'duplicate_button',
                'label' => 'LBL_DUPLICATE_BUTTON_LABEL',
                'acl_module' => 'bd01_ERP_Quote_Line',
                'acl_action' => 'create',
              ),
              3 =>
              array (
                'type' => 'rowaction',
                'event' => 'button:audit_button:click',
                'name' => 'audit_button',
                'label' => 'LNK_VIEW_CHANGE_LOG',
                'acl_action' => 'view',
              ),
              4 =>
              array (
                'type' => 'divider',
              ),
              5 =>
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
              2 =>
              array (
                'name' => 'favorite',
                'label' => 'LBL_FAVORITE',
                'type' => 'favorite',
                'readonly' => true,
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
            'placeholders' => true,
            'newTab' => true,
            'panelDefault' => 'expanded',
            'fields' =>
            array (
              0 => 'bd01_erp_quote_lines_name',
              1 => 'quote_num',
              2 => 'line_num',
              3 => 'part_num',
              4 => 'selling_qty',
              5 => 'doc_unit_price',
              6 => 'doc_ext_price',
              7 => 'erp_sync_key',
            ),
          ),
          2 =>
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
              0 => 'team_name',
              1 => 'assigned_user_name',
              2 =>
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
              3 =>
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
              4 =>
              array (
                'name' => 'tag',
                'enabled' => true,
                'default' => false,
              ),
              5 =>
              array (
                'name' => 'description',
                'span' => 12,
                'enabled' => true,
                'default' => false,
              ),
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

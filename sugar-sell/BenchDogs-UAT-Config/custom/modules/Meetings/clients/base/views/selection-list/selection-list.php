<?php
$viewdefs['Meetings'] = 
array (
  'base' => 
  array (
    'view' => 
    array (
      'selection-list' => 
      array (
        'panels' => 
        array (
          0 => 
          array (
            'label' => 'LBL_PANEL_1',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'name',
                'label' => 'LBL_LIST_SUBJECT',
                'link' => true,
                'default' => true,
                'enabled' => true,
              ),
              1 => 
              array (
                'name' => 'assigned_user_name',
                'label' => 'LBL_LIST_ASSIGNED_TO_NAME',
                'id' => 'ASSIGNED_USER_ID',
                'default' => true,
                'enabled' => true,
              ),
              2 => 
              array (
                'name' => 'status',
                'label' => 'LBL_LIST_STATUS',
                'link' => false,
                'default' => true,
                'enabled' => true,
                'type' => 'event-status',
                'css_class' => 'full-width',
              ),
              3 => 
              array (
                'name' => 'parent_name',
                'label' => 'LBL_LIST_RELATED_TO',
                'id' => 'PARENT_ID',
                'link' => true,
                'default' => true,
                'enabled' => true,
                'sortable' => false,
              ),
              4 => 
              array (
                'name' => 'date_start',
                'label' => 'LBL_LIST_DATE',
                'link' => false,
                'default' => true,
                'enabled' => true,
              ),
              5 => 
              array (
                'name' => 'team_name',
                'label' => 'LBL_LIST_TEAM',
                'default' => false,
                'enabled' => true,
              ),
              6 => 
              array (
                'name' => 'interaction_type_c',
                'label' => 'LBL_INTERACTION_TYPE',
                'enabled' => true,
                'default' => false,
              ),
              7 => 
              array (
                'name' => 'direction',
                'type' => 'enum',
                'label' => 'LBL_LIST_DIRECTION',
                'default' => false,
                'enabled' => true,
              ),
              8 => 
              array (
                'name' => 'date_entered',
                'label' => 'LBL_DATE_ENTERED',
                'default' => false,
                'enabled' => true,
                'readonly' => true,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
);

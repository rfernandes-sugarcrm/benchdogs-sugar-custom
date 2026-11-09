<?php
$viewdefs['Meetings'] = 
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
                'name' => 'status',
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
              0 => 
              array (
                'name' => 'duration',
                'type' => 'duration',
                'label' => 'LBL_START_AND_END_DATE_DETAIL_VIEW',
                'dismiss_label' => false,
                'inline' => false,
                'show_child_labels' => true,
                'fields' => 
                array (
                  0 => 
                  array (
                    'name' => 'date_start',
                    'time' => 
                    array (
                      'step' => 15,
                    ),
                    'readonly' => false,
                  ),
                  1 => 
                  array (
                    'type' => 'label',
                    'default_value' => 'LBL_START_AND_END_DATE_TO',
                  ),
                  2 => 
                  array (
                    'name' => 'date_end',
                    'time' => 
                    array (
                      'step' => 15,
                      'duration' => 
                      array (
                        'relative_to' => 'date_start',
                      ),
                    ),
                    'readonly' => false,
                  ),
                ),
                'related_fields' => 
                array (
                  0 => 'duration_hours',
                  1 => 'duration_minutes',
                ),
                'span' => 9,
              ),
              1 => 
              array (
                'name' => 'repeat_type',
                'related_fields' => 
                array (
                  0 => 'repeat_parent_id',
                ),
                'readonly' => true,
                'span' => 3,
              ),
              2 => 'location',
              3 => 
              array (
                'name' => 'interaction_type_c',
                'label' => 'LBL_INTERACTION_TYPE',
              ),
              4 => 'parent_name',
              5 => 
              array (
                'name' => 'invitees',
                'type' => 'participants',
                'label' => 'LBL_INVITEES',
                'fields' => 
                array (
                  0 => 'name',
                  1 => 'accept_status_meetings',
                  2 => 'picture',
                  3 => 'email',
                ),
              ),
              6 => 'assigned_user_name',
              7 => 
              array (
                'name' => 'internal_notes',
                'comment' => 'Internal notes for the meeting',
                'label' => 'LBL_INTERNAL_NOTES',
              ),
              8 => 
              array (
                'name' => 'tag',
                'span' => 12,
              ),
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
              0 => 'team_name',
              1 => 
              array (
                'name' => 'description',
                'rows' => 3,
              ),
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

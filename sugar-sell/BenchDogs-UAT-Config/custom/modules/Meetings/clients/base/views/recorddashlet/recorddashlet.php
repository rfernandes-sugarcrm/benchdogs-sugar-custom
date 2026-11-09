<?php
$viewdefs['Meetings'] = 
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
              1 => 'name',
            ),
          ),
          1 => 
          array (
            'name' => 'panel_body',
            'label' => 'LBL_RECORD_BODY',
            'columns' => 2,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'duration',
                'type' => 'duration',
                'label' => 'LBL_START_AND_END_DATE_DETAIL_VIEW',
                'dismiss_label' => false,
                'inline' => true,
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
                'span' => 3,
              ),
              2 => 
              array (
                'name' => 'recurrence',
                'type' => 'recurrence',
                'inline' => true,
                'show_child_labels' => true,
                'fields' => 
                array (
                  0 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT_INTERVAL',
                    'name' => 'repeat_interval',
                    'type' => 'enum',
                    'options' => 'repeat_interval_number',
                    'required' => true,
                    'default' => 1,
                  ),
                  1 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT_ON',
                    'name' => 'repeat_dow',
                    'type' => 'repeat-dow',
                    'options' => 'dom_cal_day_of_week',
                    'isMultiSelect' => true,
                  ),
                  2 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT_ON',
                    'name' => 'repeat_selector',
                    'type' => 'enum',
                    'options' => 'repeat_selector_dom',
                    'default' => 'None',
                  ),
                  3 => 
                  array (
                    'label' => ' ',
                    'name' => 'repeat_month',
                    'type' => 'enum',
                    'options' => 'repeat_month_dom',
                  ),
                  4 => 
                  array (
                    'label' => ' ',
                    'name' => 'repeat_days',
                    'type' => 'repeat-days',
                    'options' => 
                    array (
                      '' => '',
                    ),
                    'isMultiSelect' => true,
                    'dropdown_class' => 'recurring-date-dropdown',
                    'container_class' => 'recurring-date-container select2-choices-pills-close',
                  ),
                  5 => 
                  array (
                    'label' => ' ',
                    'name' => 'repeat_ordinal',
                    'type' => 'enum',
                    'options' => 'repeat_ordinal_dom',
                  ),
                  6 => 
                  array (
                    'label' => ' ',
                    'name' => 'repeat_unit',
                    'type' => 'enum',
                    'options' => 'repeat_unit_dom',
                  ),
                  7 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT',
                    'name' => 'repeat_end_type',
                    'type' => 'enum',
                    'options' => 'repeat_end_types',
                    'default' => 'Until',
                  ),
                  8 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT_UNTIL_DATE',
                    'name' => 'repeat_until',
                    'type' => 'repeat-until',
                  ),
                  9 => 
                  array (
                    'label' => 'LBL_CALENDAR_REPEAT_COUNT',
                    'name' => 'repeat_count',
                    'type' => 'repeat-count',
                  ),
                ),
                'span' => 12,
              ),
              3 => 'location',
              4 => 
              array (
                'name' => 'reminders',
                'type' => 'fieldset',
                'inline' => true,
                'equal_spacing' => true,
                'show_child_labels' => true,
                'fields' => 
                array (
                  0 => 'reminder_time',
                  1 => 'email_reminder_time',
                ),
              ),
              5 => 
              array (
                'name' => 'interaction_type_c',
                'label' => 'LBL_INTERACTION_TYPE',
              ),
              6 => 'parent_name',
              7 => 
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
                'related_fields' => 
                array (
                  0 => 'date_start',
                  1 => 'date_end',
                  2 => 'duration_hours',
                  3 => 'duration_minutes',
                ),
                'max_num' => 20,
                'span' => 12,
              ),
              8 => 'assigned_user_name',
              9 => 'team_name',
              10 => 
              array (
                'name' => 'internal_notes',
                'comment' => 'Internal notes for the meeting',
                'label' => 'LBL_INTERNAL_NOTES',
                'span' => 12,
              ),
              11 => 
              array (
                'name' => 'tag',
                'span' => 12,
              ),
            ),
          ),
          2 => 
          array (
            'name' => 'panel_hidden',
            'label' => 'LBL_RECORD_SHOWMORE',
            'columns' => 2,
            'hide' => true,
            'placeholders' => true,
            'newTab' => false,
            'panelDefault' => 'expanded',
            'fields' => 
            array (
              0 => 
              array (
                'name' => 'description',
                'rows' => 3,
                'span' => 12,
              ),
              1 => 
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
              2 => 
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
        'templateMeta' => 
        array (
          'useTabs' => false,
        ),
      ),
    ),
  ),
);

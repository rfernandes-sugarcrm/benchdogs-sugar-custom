<?php
// created: 2026-10-23 04:39:43
$viewdefs['Opportunities']['base']['view']['list'] = array (
  'panels' => 
  array (
    0 => 
    array (
      'name' => 'panel_header',
      'label' => 'LBL_PANEL_1',
      'fields' => 
      array (
        0 => 
        array (
          'name' => 'name',
          'label' => 'LBL_LIST_OPPORTUNITY_NAME',
          'enabled' => true,
          'default' => true,
          'related_fields' => 
          array (
            0 => 'total_revenue_line_items',
            1 => 'closed_revenue_line_items',
            2 => 'included_revenue_line_items',
          ),
          'link' => true,
        ),
        1 => 
        array (
          'name' => 'account_name',
          'label' => 'LBL_LIST_ACCOUNT_NAME',
          'enabled' => true,
          'default' => true,
          'related_fields' => 
          array (
            0 => 'account_id',
          ),
          'id' => 'ACCOUNT_ID',
          'link' => true,
          'sortable' => true,
        ),
        2 => 
        array (
          'name' => 'date_closed',
          'label' => 'LBL_DATE_CLOSED',
          'enabled' => true,
          'default' => true,
          'type' => 'date-cascade',
          'disable_field' => 
          array (
            0 => 'total_revenue_line_items',
            1 => 'closed_revenue_line_items',
          ),
        ),
        3 => 
        array (
          'name' => 'sales_stage',
          'label' => 'LBL_LIST_SALES_STAGE',
          'enabled' => true,
          'default' => true,
          'type' => 'enum-cascade',
          'disable_field' => 
          array (
            0 => 'total_revenue_line_items',
            1 => 'closed_revenue_line_items',
          ),
        ),
        4 => 
        array (
          'name' => 'is_escalated',
          'label' => 'LBL_ESCALATED',
          'enabled' => true,
          'default' => false,
          'badge_label' => 'LBL_ESCALATED',
          'warning_level' => 'important',
          'type' => 'badge',
        ),
        5 => 
        array (
          'name' => 'lost',
          'label' => 'LBL_LOST',
          'enabled' => true,
          'default' => true,
          'related_fields' => 
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'comment' => 'Rollup of lost RLIs on the Opportunity',
          'readonly' => true,
        ),
        6 => 
        array (
          'name' => 'amount',
          'label' => 'LBL_LIKELY',
          'enabled' => true,
          'default' => true,
          'related_fields' => 
          array (
            0 => 'amount',
            1 => 'currency_id',
            2 => 'base_rate',
          ),
          'currency_format' => true,
          'type' => 'currency',
          'currency_field' => 'currency_id',
          'base_rate_field' => 'base_rate',
        ),
        7 => 
        array (
          'name' => 'opportunity_type',
          'label' => 'LBL_TYPE',
          'enabled' => true,
          'default' => true,
        ),
        8 => 
        array (
          'name' => 'next_step',
          'label' => 'LBL_NEXT_STEP',
          'enabled' => true,
          'default' => true,
        ),
        9 => 
        array (
          'name' => 'assigned_user_name',
          'label' => 'LBL_ASSIGNED_TO_NAME',
          'enabled' => true,
          'default' => true,
          'related_fields' => 
          array (
            0 => 'assigned_user_id',
          ),
          'id' => 'ASSIGNED_USER_ID',
          'link' => true,
          'sortable' => true,
        ),
        10 => 
        array (
          'name' => 'date_entered',
          'label' => 'LBL_DATE_ENTERED',
          'enabled' => true,
          'default' => true,
          'readonly' => true,
        ),
        11 => 
        array (
          'name' => 'last_interaction_date',
          'label' => 'LBL_LAST_INTERACTION',
          'enabled' => true,
          'default' => true,
          'type' => 'last_interaction',
          'fields' => 
          array (
            0 => 'last_interaction_parent_type',
            1 => 'last_interaction_parent_id',
            2 => 'last_interaction_parent_name',
          ),
          'readonly' => true,
        ),
        12 => 
        array (
          'name' => 'date_modified',
          'label' => 'LBL_DATE_MODIFIED',
          'enabled' => true,
          'default' => false,
          'readonly' => true,
          'sortable' => true,
        ),
        13 => 
        array (
          'name' => 'probability',
          'label' => 'LBL_PROBABILITY',
          'enabled' => true,
          'default' => true,
        ),
        14 => 
        array (
          'name' => 'erp_ordered_amount',
          'label' => 'LBL_ERP_ORDERED_AMOUNT',
          'enabled' => true,
          'default' => false,
        ),
        15 => 
        array (
          'name' => 'erp_open_amount',
          'label' => 'LBL_ERP_OPEN_AMOUNT',
          'enabled' => true,
          'default' => false,
        ),
        16 => 
        array (
          'name' => 'lead_source',
          'label' => 'LBL_LEAD_SOURCE',
          'enabled' => true,
          'default' => false,
        ),
        17 => 
        array (
          'name' => 'created_by_name',
          'label' => 'LBL_CREATED',
          'enabled' => true,
          'default' => false,
          'id' => 'CREATED_BY',
          'link' => true,
          'readonly' => true,
          'sortable' => true,
        ),
        18 => 
        array (
          'name' => 'team_name',
          'label' => 'LBL_LIST_TEAM',
          'enabled' => true,
          'default' => false,
          'type' => 'teamset',
        ),
        19 => 
        array (
          'name' => 'modified_by_name',
          'label' => 'LBL_MODIFIED',
          'enabled' => true,
          'default' => false,
          'id' => 'MODIFIED_USER_ID',
          'link' => true,
          'readonly' => true,
          'sortable' => true,
        ),
      ),
    ),
  ),
);
<?php
$viewdefs['bd01_ERP_Quote']['base']['view']['subpanel-for-quotes-bd01_erp_quote_quotes'] = array (
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
          'label' => 'LBL_SUBPANEL_ERP_QUOTE_NAME',
          'enabled' => true,
          'default' => true,
          'name' => 'name',
          'link' => true,
        ),
        1 =>
        array (
          'name' => 'quote_num',
          'label' => 'LBL_ERP_QUOTE_NUM',
          'enabled' => true,
          'default' => true,
        ),
        2 =>
        array (
          'name' => 'current_stage',
          'label' => 'LBL_CURRENT_STAGE',
          'enabled' => true,
          'default' => true,
        ),
        3 =>
        array (
          'name' => 'quote_amt',
          'label' => 'LBL_QUOTE_AMT',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        4 =>
        array (
          'name' => 'quote_total',
          'label' => 'LBL_QUOTE_TOTAL',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        5 =>
        array (
          'name' => 'due_date',
          'label' => 'LBL_DUE_DATE',
          'enabled' => true,
          'default' => true,
        ),
        6 =>
        array (
          'name' => 'quote_closed',
          'label' => 'LBL_QUOTE_CLOSED',
          'enabled' => true,
          'default' => true,
        ),
        7 =>
        array (
          'label' => 'LBL_DATE_MODIFIED',
          'enabled' => true,
          'default' => true,
          'name' => 'date_modified',
        ),
        8 =>
        array (
          'name' => 'reason_code',
          'label' => 'LBL_REASON_CODE',
          'enabled' => true,
          'default' => false,
        ),
        9 =>
        array (
          'name' => 'engineered',
          'label' => 'LBL_ENGINEERED',
          'enabled' => true,
          'default' => false,
        ),
        10 =>
        array (
          'name' => 'erp_sync_key',
          'label' => 'LBL_ERP_SYNC_KEY',
          'enabled' => true,
          'readonly' => true,
          'default' => false,
        ),
      ),
    ),
  ),
  'orderBy' =>
  array (
    'field' => 'quote_num',
    'direction' => 'desc',
  ),
  'type' => 'subpanel-list',
);

<?php
$viewdefs['bd01_ERP_Quote_Cost']['base']['view']['subpanel-for-bd01_erp_quote_line-bd01_erp_line_costs'] = array (
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
          'name' => 'qty_num',
          'label' => 'LBL_QTY_BREAK',
          'enabled' => true,
          'default' => true,
        ),
        1 =>
        array (
          'label' => 'LBL_SUBPANEL_QUOTE_COST_NAME',
          'enabled' => true,
          'default' => true,
          'name' => 'name',
          'link' => true,
        ),
        2 =>
        array (
          'name' => 'quantity',
          'label' => 'LBL_QUANTITY',
          'enabled' => true,
          'default' => true,
        ),
        3 =>
        array (
          'name' => 'material_cost',
          'label' => 'LBL_MATERIAL_COST',
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
          'name' => 'labor_cost',
          'label' => 'LBL_LABOR_COST',
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
          'name' => 'material_burden',
          'label' => 'LBL_MATERIAL_BURDEN',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        6 =>
        array (
          'name' => 'labor_burden',
          'label' => 'LBL_LABOR_BURDEN',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        7 =>
        array (
          'name' => 'misc_cost',
          'label' => 'LBL_MISC_COST',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        8 =>
        array (
          // The nearest thing this module has to a markup column - there is
          // no markup field in the vardefs, so the margin is carried by the
          // profit amount and the percentage beside it.
          'name' => 'profit',
          'label' => 'LBL_PROFIT',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => true,
        ),
        9 =>
        array (
          'name' => 'gross_margin_pct',
          'label' => 'LBL_GROSS_MARGIN_PCT',
          'enabled' => true,
          'default' => true,
        ),
        10 =>
        array (
          'name' => 'subcontract_cost',
          'label' => 'LBL_SUBCONTRACT_COST',
          'enabled' => true,
          'related_fields' =>
          array (
            0 => 'currency_id',
            1 => 'base_rate',
          ),
          'currency_format' => true,
          'default' => false,
        ),
        11 =>
        array (
          'name' => 'hours',
          'label' => 'LBL_HOURS',
          'enabled' => true,
          'default' => false,
        ),
        12 =>
        array (
          'label' => 'LBL_DATE_MODIFIED',
          'enabled' => true,
          'default' => false,
          'name' => 'date_modified',
        ),
        13 =>
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
    'field' => 'qty_num',
    'direction' => 'asc',
  ),
  'type' => 'subpanel-list',
);

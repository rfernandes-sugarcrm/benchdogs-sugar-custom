<?php
/**
 * Cost worksheet subpanel, shown under a quote line.
 *
 * COLUMN ORDER IS THE POINT OF THIS FILE. A subpanel only has room for the
 * first handful of columns; everything past that is off the right edge and,
 * in practice, invisible. This used to lead with the component breakdown
 * (material, labor, the two burdens) and push total cost, profit and margin
 * off-screen - which reads as "the ERP sent us nothing" on exactly the shops
 * where it sent the most, because a part quoted without a full BOM books its
 * whole cost as MiscCost and leaves every component field at a legitimate
 * $0.00. Verified live on quote 1190: four lines, real Epicor costs of
 * 480/75/50/40, and a subpanel showing $0.00 twice.
 *
 * So: quantity, then what Epicor actually rolled up (total cost, profit,
 * margin), then the breakdown for the shops that do carry one. `rolled_up`
 * rides along as the honest disambiguator - a zero total with rolled_up
 * false means "not costed yet", not "free".
 */
$viewdefs['bd01_ERP_Quote_Cost']['base']['view']['subpanel-for-bd01_erp_quote_line-bd01_erp_line_costs'] = array (
  'panels' =>
  array (
    0 =>
    array (
      'name' => 'panel_header',
      'label' => 'LBL_PANEL_1',
      'fields' =>
      array (
        0 => array (
          'name' => 'qty_num',
          'label' => 'LBL_QTY_BREAK',
          'enabled' => true,
          'default' => true,
        ),
        1 => array (
          'label' => 'LBL_SUBPANEL_QUOTE_COST_NAME',
          'enabled' => true,
          'default' => true,
          'name' => 'name',
          'link' => true,
        ),
        2 => array (
          'name' => 'quantity',
          'label' => 'LBL_QUANTITY',
          'enabled' => true,
          'default' => true,
        ),
        3 => array (
          'name' => 'total_cost',
          'label' => 'LBL_TOTAL_COST',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => true,
        ),
        4 => array (
          'name' => 'unit_price',
          'label' => 'LBL_UNIT_PRICE',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => true,
        ),
        5 => array (
          'name' => 'profit',
          'label' => 'LBL_PROFIT',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => true,
        ),
        6 => array (
          'name' => 'gross_margin_pct',
          'label' => 'LBL_GROSS_MARGIN_PCT',
          'enabled' => true,
          'default' => true,
        ),
        7 => array (
          'name' => 'rolled_up',
          'label' => 'LBL_ROLLED_UP',
          'enabled' => true,
          'default' => true,
        ),
        // --- component breakdown: real on shops that carry a BOM, and
        //     legitimately zero on those that do not. Kept, but behind the
        //     rollup rather than in front of it.
        8 => array (
          'name' => 'material_cost',
          'label' => 'LBL_MATERIAL_COST',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => true,
        ),
        9 => array (
          'name' => 'labor_cost',
          'label' => 'LBL_LABOR_COST',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => true,
        ),
        10 => array (
          'name' => 'material_burden',
          'label' => 'LBL_MATERIAL_BURDEN',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => false,
        ),
        11 => array (
          'name' => 'labor_burden',
          'label' => 'LBL_LABOR_BURDEN',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => false,
        ),
        12 => array (
          'name' => 'misc_cost',
          'label' => 'LBL_MISC_COST',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => false,
        ),
        13 => array (
          'name' => 'subcontract_cost',
          'label' => 'LBL_SUBCONTRACT_COST',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => false,
        ),
        14 => array (
          'name' => 'total_quoted_price',
          'label' => 'LBL_TOTAL_QUOTED_PRICE',
          'enabled' => true,
          'related_fields' => array (0 => 'currency_id', 1 => 'base_rate'),
          'currency_format' => true,
          'default' => false,
        ),
        15 => array (
          'name' => 'quoted_markup_pct',
          'label' => 'LBL_QUOTED_MARKUP_PCT',
          'enabled' => true,
          'default' => false,
        ),
        16 => array (
          'name' => 'hours',
          'label' => 'LBL_HOURS',
          'enabled' => true,
          'default' => false,
        ),
        17 => array (
          'label' => 'LBL_DATE_MODIFIED',
          'enabled' => true,
          'default' => false,
          'name' => 'date_modified',
        ),
        18 => array (
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

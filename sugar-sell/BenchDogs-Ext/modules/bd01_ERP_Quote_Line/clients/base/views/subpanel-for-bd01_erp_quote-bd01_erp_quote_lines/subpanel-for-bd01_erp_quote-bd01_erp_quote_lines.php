<?php
$viewdefs['bd01_ERP_Quote_Line']['base']['view']['subpanel-for-bd01_erp_quote-bd01_erp_quote_lines'] = array (
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
          'name' => 'line_num',
          'label' => 'LBL_LINE_NUM',
          'enabled' => true,
          'default' => true,
        ),
        1 =>
        array (
          'label' => 'LBL_SUBPANEL_QUOTE_LINE_NAME',
          'enabled' => true,
          'default' => true,
          'name' => 'name',
          'link' => true,
        ),
        2 =>
        array (
          'name' => 'part_num',
          'label' => 'LBL_PART_NUM',
          'enabled' => true,
          'default' => true,
        ),
        3 =>
        array (
          'name' => 'description',
          'label' => 'LBL_DESCRIPTION',
          'enabled' => true,
          'sortable' => false,
          'default' => true,
        ),
        4 =>
        array (
          'name' => 'selling_qty',
          'label' => 'LBL_SELLING_QTY',
          'enabled' => true,
          'default' => true,
        ),
        5 =>
        array (
          'name' => 'doc_unit_price',
          'label' => 'LBL_DOC_UNIT_PRICE',
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
          'name' => 'doc_ext_price',
          'label' => 'LBL_DOC_EXT_PRICE',
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
          // Editable in place (REQ-5/REQ-6) - this subpanel under
          // bd01_ERP_Quote is where the flag is actually toggled, and
          // BdGoverningLineHook keeps it unique per quote.
          'name' => 'governing',
          'label' => 'LBL_GOVERNING',
          'enabled' => true,
          'default' => true,
        ),
        8 =>
        array (
          'name' => 'prototype',
          'label' => 'LBL_PROTOTYPE',
          'enabled' => true,
          'default' => true,
        ),
        9 =>
        array (
          'label' => 'LBL_DATE_MODIFIED',
          'enabled' => true,
          'default' => false,
          'name' => 'date_modified',
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
    'field' => 'line_num',
    'direction' => 'asc',
  ),
  'type' => 'subpanel-list',
);

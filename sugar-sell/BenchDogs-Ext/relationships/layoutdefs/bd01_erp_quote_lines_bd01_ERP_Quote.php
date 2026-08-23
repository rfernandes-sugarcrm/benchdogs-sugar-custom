<?php
$layout_defs["bd01_ERP_Quote"]["subpanel_setup"]['bd01_erp_quote_lines'] = array (
  'order' => 100,
  'module' => 'bd01_ERP_Quote_Line',
  'subpanel_name' => 'default',
  'sort_order' => 'asc',
  'sort_by' => 'line_num',
  'title_key' => 'LBL_BD01_ERP_QUOTE_LINES_FROM_BD01_ERP_QUOTE_LINE_TITLE',
  'get_subpanel_data' => 'bd01_erp_quote_lines',
  'top_buttons' =>
  array (
    0 =>
    array (
      'widget_class' => 'SubPanelTopButtonQuickCreate',
    ),
    1 =>
    array (
      'widget_class' => 'SubPanelTopSelectButton',
      'mode' => 'MultiSelect',
    ),
  ),
);

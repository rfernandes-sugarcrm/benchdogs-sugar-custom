<?php
$layout_defs["bd01_ERP_Quote_Line"]["subpanel_setup"]['bd01_erp_line_costs'] = array (
  'order' => 100,
  'module' => 'bd01_ERP_Quote_Cost',
  'subpanel_name' => 'default',
  'sort_order' => 'asc',
  'sort_by' => 'qty_num',
  'title_key' => 'LBL_BD01_ERP_LINE_COSTS_FROM_BD01_ERP_QUOTE_COST_TITLE',
  'get_subpanel_data' => 'bd01_erp_line_costs',
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

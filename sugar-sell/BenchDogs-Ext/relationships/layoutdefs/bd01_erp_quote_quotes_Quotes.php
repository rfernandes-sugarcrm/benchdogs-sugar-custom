<?php
$layout_defs["Quotes"]["subpanel_setup"]['bd01_erp_quote_quotes'] = array (
  'order' => 110,
  'module' => 'bd01_ERP_Quote',
  'subpanel_name' => 'default',
  'sort_order' => 'asc',
  'sort_by' => 'quote_num',
  'title_key' => 'LBL_BD01_ERP_QUOTE_QUOTES_FROM_BD01_ERP_QUOTE_TITLE',
  'get_subpanel_data' => 'bd01_erp_quote_quotes',
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

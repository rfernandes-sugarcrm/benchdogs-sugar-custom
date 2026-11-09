<?php
 // created: 2026-10-18 20:26:54
$dictionary['Account']['fields']['days_since_last_sale_c']['duplicate_merge_dom_value']=0;
$dictionary['Account']['fields']['days_since_last_sale_c']['labelValue']='Days since last Sales Order';
$dictionary['Account']['fields']['days_since_last_sale_c']['full_text_search']=array (
  'enabled' => '0',
  'boost' => '1',
  'searchable' => false,
);
$dictionary['Account']['fields']['days_since_last_sale_c']['calculated']='true';
$dictionary['Account']['fields']['days_since_last_sale_c']['formula']='ifElse(equal($erp_last_sale_date,""),"",subtract(0,daysUntil($erp_last_sale_date)))';
$dictionary['Account']['fields']['days_since_last_sale_c']['enforced']='true';
$dictionary['Account']['fields']['days_since_last_sale_c']['dependency']='';
$dictionary['Account']['fields']['days_since_last_sale_c']['required_formula']='';
$dictionary['Account']['fields']['days_since_last_sale_c']['readonly_formula']='';

 ?>
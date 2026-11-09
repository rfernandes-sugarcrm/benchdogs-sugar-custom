<?php
 // created: 2026-10-18 19:26:53
$dictionary['Account']['fields']['days_since_last_interaction_c']['duplicate_merge_dom_value']=0;
$dictionary['Account']['fields']['days_since_last_interaction_c']['labelValue']='Days since Last Interaction';
$dictionary['Account']['fields']['days_since_last_interaction_c']['full_text_search']=array (
  'enabled' => '0',
  'boost' => '1',
  'searchable' => false,
);
$dictionary['Account']['fields']['days_since_last_interaction_c']['calculated']='true';
$dictionary['Account']['fields']['days_since_last_interaction_c']['formula']='abs(subtract(daysUntil($last_interaction_date),daysUntil(today())))';
$dictionary['Account']['fields']['days_since_last_interaction_c']['enforced']='true';
$dictionary['Account']['fields']['days_since_last_interaction_c']['dependency']='';
$dictionary['Account']['fields']['days_since_last_interaction_c']['required_formula']='';
$dictionary['Account']['fields']['days_since_last_interaction_c']['readonly_formula']='';

 ?>
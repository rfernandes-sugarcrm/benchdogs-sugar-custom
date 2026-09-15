<?php
// Bench Dogs ERP stage vocabulary for Quotes.bd_erp_stage (REQ-13/REQ-27).
//
// Application-level language extension, for the same reason
// en_us.bd_stage_doms.php is one: LanguageManager::loadModuleLanguage()
// declares `global $mod_strings` ONLY and returns $mod_strings, so an
// $app_list_strings assignment inside a module Language ext is a discarded
// local - the list reaches neither app_list_strings nor the module's
// mod_strings. This list used to live in the Quotes Language ext beside the
// field's own LBL_* labels (which do land, because they are $mod_strings),
// and was therefore absent everywhere; bd_erp_stage rendered blank.
//
// The precedent cited there - ERP-Core's en_us.erp_quote_type.php - carries
// the same inert module-scoped copy, but core's working declaration of
// erp_quote_type_list is the application-scoped one in
// ERP-Core/src/SugarModules/language/application/en_us.lang.php.
//
// Keys are the values BdQuoteReflectionHook and bd-send-to-estimating write.
$app_list_strings['bd_erp_stage_list'] = array(
    '' => '',
    'draft' => 'Draft',
    'in_estimating' => 'In Estimating',
    'priced' => 'Priced',
    'revision' => 'Revision',
    'accepted' => 'Accepted',
    'ordered' => 'Ordered',
    'lost' => 'Lost',
);

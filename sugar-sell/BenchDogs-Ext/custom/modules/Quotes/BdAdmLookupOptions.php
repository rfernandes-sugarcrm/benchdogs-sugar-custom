<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/**
 * G380 / G381: the option sources behind the three Bench Dogs quote pickers
 * (vardef 'function' => array('name' => ..., 'include' => this file)).
 *
 * PLAIN FUNCTIONS, AND THEY IGNORE THEIR ARGUMENTS, ON PURPOSE. Sugar calls a
 * vardef option function through more than one path with more than one
 * signature: the REST enum endpoint (ModuleApi::getEnumValues ->
 * getOptionsFromVardef -> getFunctionValue) passes the vardef's 'params';
 * the legacy SugarFieldBase path calls $funcName($fields, $name, $value,
 * $view); workflow and import call it bare. A function whose answer depended
 * on its arguments would answer differently per path. One list per function,
 * named, keeps every path on the same answer.
 *
 * The whole list logic is BdAdmRules::lookupOptions(); these are one line each.
 */

if (!class_exists('BdAdmRules', false)) {
    require_once 'custom/modules/Quotes/BdAdmRules.php';
}

if (!function_exists('bd_adm_lead_source_options')) {
    /** ADM's active Lead Source codes (user-code type LEADSRC). */
    function bd_adm_lead_source_options(...$ignored): array
    {
        return BdAdmRules::lookupOptions(BdAdmRules::TYPE_LEAD_SOURCES);
    }
}

if (!function_exists('bd_adm_lead_type_options')) {
    /** ADM's active Lead Type codes (user-code type LEADTYPE). */
    function bd_adm_lead_type_options(...$ignored): array
    {
        return BdAdmRules::lookupOptions(BdAdmRules::TYPE_LEAD_TYPES);
    }
}

if (!function_exists('bd_adm_project_options')) {
    /** ADM's active projects (Erp.BO.ProjectSvc). */
    function bd_adm_project_options(...$ignored): array
    {
        return BdAdmRules::lookupOptions(BdAdmRules::TYPE_PROJECTS);
    }
}

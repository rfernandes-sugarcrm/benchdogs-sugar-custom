<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/** G380 / G381 / G460, G804: the option functions behind the five ADM quote pickers and the Account's Cust. Group picker; they ignore their arguments on purpose. */

use Sugarcrm\Sugarcrm\custom\BenchDogs\BdAdmRules;

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

if (!function_exists('bd_adm_marketing_campaign_options')) {
    /** G460: ADM's active campaigns that have an active event. */
    function bd_adm_marketing_campaign_options(...$ignored): array
    {
        return BdAdmRules::marketingOptions(BdAdmRules::TYPE_MARKETING_CAMPAIGNS);
    }
}

if (!function_exists('bd_adm_customer_group_options')) {
    /** G804: ADM's active customer groups (Epicor CustGrup), for an Account not yet in the ERP. */
    function bd_adm_customer_group_options(...$ignored): array
    {
        return BdAdmRules::lookupOptions(BdAdmRules::TYPE_CUSTOMER_GROUPS);
    }
}

if (!function_exists('bd_adm_marketing_event_options')) {
    /** G460: ADM's active events of active campaigns, keyed "<campaign>/<seq>". */
    function bd_adm_marketing_event_options(...$ignored): array
    {
        return BdAdmRules::marketingOptions(BdAdmRules::TYPE_MARKETING_EVENTS);
    }
}

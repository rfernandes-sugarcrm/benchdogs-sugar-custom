<?php
$admin_option_defs = array();

$admin_option_defs['Administration']['erp_integration_setup'] = array(
    'Administration',
    'LBL_ERP_INTEGRATION_SETUP',
    'LBL_ERP_INTEGRATION_SETUP_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("Home/layout/erp-config", {trigger: true});',
);

$admin_option_defs['Administration']['erp_admin_erp_companies'] = array(
    'Administration',
    'LBL_ERP_COMPANIES_ADMIN_LINK',
    'LBL_ERP_COMPANIES_ADMIN_LINK_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("ERP_Companies", {trigger: true});',
);

$admin_option_defs['Administration']['erp_admin_erp_lookupvalues'] = array(
    'Administration',
    'LBL_ERP_LOOKUPVALUES_ADMIN_LINK',
    'LBL_ERP_LOOKUPVALUES_ADMIN_LINK_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("ERP_LookupValues", {trigger: true});',
);

$admin_option_defs['Administration']['erp_admin_erp_salesreps'] = array(
    'Administration',
    'LBL_ERP_SALESREPS_ADMIN_LINK',
    'LBL_ERP_SALESREPS_ADMIN_LINK_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("ERP_SalesReps", {trigger: true});',
);

$admin_group_header[] = array(
    'LBL_ERP_INTEGRATION_SECTION_HEADER',
    '',
    false,
    $admin_option_defs,
    'LBL_ERP_INTEGRATION_SECTION_DESCRIPTION'
);

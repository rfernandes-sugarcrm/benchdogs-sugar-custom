<?php

// The default owner link: inside ERP-Core's ERP Integration group, after ERP Sales Reps, when that group is there; else
// in a Bench Dogs box of its own. Sugar merges every _override_ fragment after the plain ones (sortExtensionFiles), so
// ERP-Core's group is defined by the time this runs, whichever package was installed last.
$bdDefaultOwnerLink = array(
    'Administration',
    'LBL_BD_DEFAULT_OWNER',
    'LBL_BD_DEFAULT_OWNER_DESCRIPTION',
    'javascript:parent.SUGAR.App.router.navigate("Administration/layout/bd-default-owner", {trigger: true});',
);
$bdErpGroup = null;
if (isset($admin_group_header) && is_array($admin_group_header)) {
    foreach ($admin_group_header as $bdIndex => $bdGroup) {
        if (is_array($bdGroup) && ($bdGroup[0] ?? null) === 'LBL_ERP_INTEGRATION_SECTION_HEADER'
            && is_array($bdGroup[3] ?? null)) {
            $bdErpGroup = $bdIndex;
        }
    }
}
if ($bdErpGroup !== null) {
    $admin_group_header[$bdErpGroup][3]['Administration']['bd_default_owner'] = $bdDefaultOwnerLink;
} else {
    $admin_group_header[] = array(
        'LBL_BD_ADMIN_SECTION',
        '',
        false,
        array('Administration' => array('bd_default_owner' => $bdDefaultOwnerLink)),
        'LBL_BD_ADMIN_SECTION_DESCRIPTION',
    );
}
unset($bdDefaultOwnerLink, $bdErpGroup, $bdIndex, $bdGroup);

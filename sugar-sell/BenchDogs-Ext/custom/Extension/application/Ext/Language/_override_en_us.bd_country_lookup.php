<?php

// Append-only label for the Bench-owned ERP_LookupValues type the Bench
// extension publishes for REQ-15 option (c): one row per Epicor country, read by
// custom/modules/Accounts/BdAccountCountryGuard.php. The guard reads the stored
// type value, not this label, so the check does not depend on this list being
// compiled; the label only makes the rows readable in list views and filters.
$app_list_strings['erp_lookup_type_list']['bd_country'] = 'Country (Bench Dogs)';

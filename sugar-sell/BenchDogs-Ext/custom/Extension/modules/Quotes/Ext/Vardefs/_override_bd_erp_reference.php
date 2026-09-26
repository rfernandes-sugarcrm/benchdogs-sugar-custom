<?php

// G606 (owner rulings 1796b/1797b): Bench claims Reference's placement;
// ERP-Core owns the field, storage and validation. Sugar merges _override
// fragments last, including when ERP-Epicor is installed after this package.
$dictionary['Quote']['fields']['erp_reference']['erp_layout'] = array(
    'view' => 'record',
    'panel' => 'LBL_RECORDVIEW_PANEL_ERP',
    'after' => 'erp_quotes_ship_via_name',
);

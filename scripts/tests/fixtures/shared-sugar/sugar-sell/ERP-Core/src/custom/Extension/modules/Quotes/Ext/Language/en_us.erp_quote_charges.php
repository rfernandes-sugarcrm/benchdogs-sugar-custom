<?php

// Named for what a seller reads, not for the mechanism. (G165)
$mod_strings['LBL_ERP_TAX_AMOUNT'] = 'Tax (via ERP)';
$mod_strings['LBL_ERP_SHIPPING_AMOUNT'] = 'ERP Shipping';

// G166: the document-level (order-level) discount the ERP states, as its own row. (G321, 🔒 1544, 🔒 1433)
$mod_strings['LBL_ERP_DOCUMENT_DISCOUNT_AMOUNT'] = 'Order Level Discount';

// G171 / 🔒 1433: Sugar's deal_tot (the roll-up of line discounts) is labelled "Order Discount" wherever stock renders it alone: Quotes' preview view. (🔒 1544)
$mod_strings['LBL_LIST_DEAL_TOT'] = 'Order Discount';

// G321 / 🔒 1544b: the owner asked for "Line Items Discounted Subtotal" instead of "Discounted Subtotal". (G242)
$mod_strings['LBL_NEW_SUB'] = 'Line Items Discounted Subtotal';

// 🔒 1544a: the top bar's Order Level Discount tile states the base of its percentage in its hover. (G303)
$mod_strings['LBL_ERP_ORDER_LEVEL_DISCOUNT_HELP'] =
    'The order level discount {{document}} is {{percent}} of the '
    . '{{subtotal}} line items discounted subtotal.';

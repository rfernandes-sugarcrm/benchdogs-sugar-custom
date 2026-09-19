<?php

/**
 * Quoted line items grid - column order, authored explicitly.
 *
 * The order is stated as a file rather than negotiated at runtime through
 * ModuleBuilder. Two attempts to position columns through
 * DeployedMetaDataImplementation (the mechanism ERP-Core uses to append its
 * own columns) left the grid untouched - 0.9.17 removed and re-inserted, and
 * 0.9.19 rewrote the field list in a single read-modify-deploy. Both times
 * the rendered header was unchanged (confirmed in the live DOM, 24 Aug 2026).
 * That mechanism reliably APPENDS a new column and does not reliably REORDER
 * an existing one. A file also means the order is reviewable in the package
 * rather than being an emergent property of install sequence.
 *
 * REQ-1's ordering controls are NOT columns here. "To Order" and "Ordered"
 * used to be two checkbox columns, which put three checkboxes on every row
 * next to the grid's own multi-select box. The flow now rides that stock
 * checkbox instead: tick the lines to release, press "Order Selected Lines",
 * and ordered rows render greyed and locked. See
 * custom/modules/ProductBundles/clients/base/views/quote-data-group-list/
 * quote-data-group-list.js for the row treatment, and BdQliColumnsLayout for
 * the field allowlist that still carries bd_ordered onto each row.
 *
 * Two stock columns are deliberately absent:
 *   - base_rate ("Currency Rate"), a display of the conversion rate. Both
 *     currency columns still declare it in related_fields, so the VALUE is
 *     still fetched and conversion still works; only the column is gone.
 *   - erp_available_qty, ERP-Core's availability readout, which is not part
 *     of any Bench Dogs flow.
 * Discount is kept: it is an editing surface, not just a readout, and
 * dropping it would take the discount-select widget with it.
 *
 * Adding a column later: add it to this array. Do not add it by calling the
 * ModuleBuilder helpers as well, or the two definitions will disagree and the
 * one that wins will depend on install order.
 */

$viewdefs['Products']['base']['view']['quote-data-group-list'] = array(
    'panels' => array(
        array(
            'name' => 'products_quote_data_group_list',
            'label' => 'LBL_PRODUCTS_QUOTE_DATA_LIST',
            'fields' => array(
                array(
                    'name' => 'line_num',
                    'label' => null,
                    'widthClass' => 'cell-xsmall',
                    'css_class' => 'line_num text-center',
                    'type' => 'line-num',
                    'readonly' => true,
                ),
                array(
                    'name' => 'quantity',
                    'label' => 'LBL_QUANTITY',
                    'labelModule' => 'Products',
                    'widthClass' => 'cell-small',
                    'css_class' => 'quantity',
                    'type' => 'float',
                ),
                array(
                    'name' => 'product_template_name',
                    'label' => 'LBL_PRODUCT_TEMPLATE',
                    'labelModule' => 'Products',
                    'widthClass' => 'cell-large',
                    'type' => 'quote-data-relate',
                    'required' => true,
                    'related_fields' => array(
                        'service',
                        'service_start_date',
                        'service_end_date',
                        'renewable',
                        'service_duration_value',
                        'service_duration_unit',
                    ),
                ),
                array(
                    'name' => 'mft_part_num',
                    'label' => 'LBL_MFT_PART_NUM',
                    'labelModule' => 'Products',
                    'type' => 'base',
                ),
                array(
                    'name' => 'discount_price',
                    'label' => 'LBL_DISCOUNT_PRICE',
                    'labelModule' => 'Products',
                    'type' => 'currency',
                    'convertToBase' => true,
                    'showTransactionalAmount' => true,
                    'related_fields' => array(
                        'discount_price',
                        'currency_id',
                        'base_rate',
                    ),
                ),
                array(
                    'name' => 'discount_field',
                    'type' => 'fieldset',
                    'css_class' => 'discount-field quote-discount-percent',
                    'label' => 'LBL_DISCOUNT_AMOUNT',
                    'labelModule' => 'Products',
                    'show_child_labels' => false,
                    'sortable' => false,
                    'fields' => array(
                        array(
                            'name' => 'discount_amount',
                            'label' => 'LBL_DISCOUNT_AMOUNT',
                            'type' => 'discount-amount',
                            'discountFieldName' => 'discount_select',
                            'related_fields' => array(
                                'currency_id',
                            ),
                            'convertToBase' => true,
                            'base_rate_field' => 'base_rate',
                            'showTransactionalAmount' => true,
                        ),
                        array(
                            'type' => 'discount-select',
                            'name' => 'discount_select',
                            'options' => array(
                            ),
                        ),
                    ),
                ),
                array(
                    'name' => 'total_amount',
                    'label' => 'LBL_LINE_ITEM_TOTAL',
                    'labelModule' => 'Quotes',
                    'type' => 'currency',
                    'widthClass' => 'cell-medium',
                    'showTransactionalAmount' => true,
                    'related_fields' => array(
                        'total_amount',
                        'currency_id',
                        'base_rate',
                    ),
                ),
                array(
                    'name' => 'erp_line_links',
                    'type' => 'fieldset',
                    'label' => 'LBL_ERP_LINE_LINKS',
                    'labelModule' => 'Products',
                    'inline' => true,
                    'equal_spacing' => true,
                    'show_child_labels' => false,
                    'sortable' => false,
                    'fields' => array(
                        array(
                            'name' => 'epicor_line_deeplink_url',
                            'type' => 'erp-line-link',
                            'url_field' => 'epicor_line_deeplink_url',
                            'icon_class' => 'sicon-link',
                            'tooltip_label' => 'LBL_EPICOR_LINE_DEEPLINK_URL',
                            'drawer_title_label' => 'LBL_EPICOR_LINE_DEEPLINK_URL',
                            'readonly' => true,
                        ),
                        array(
                            'name' => 'epicor_eto_deeplink_url',
                            'type' => 'erp-line-link',
                            'url_field' => 'epicor_eto_deeplink_url',
                            'icon_class' => 'sicon-settings',
                            'tooltip_label' => 'LBL_EPICOR_ETO_DEEPLINK_URL',
                            'drawer_title_label' => 'LBL_EPICOR_ETO_DEEPLINK_URL',
                            'readonly' => true,
                        ),
                    ),
                ),
                array(
                    'name' => 'subtotal',
                    'type' => 'currency',
                    'label' => 'LBL_SUBTOTAL',
                    'labelModule' => 'Products',
                ),
            ),
        ),
    ),
);

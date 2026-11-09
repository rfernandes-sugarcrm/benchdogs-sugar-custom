/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */
(function(app) {
    app.events.on('app:init', function() {
        /**
         * Grays out the Delete row action for restricted Shipping Addresses: records
         * that are ERP-managed (erp_sync_key set, checked via erp_display_sync_key in UI) or
         * referenced by one or more Quotes (quotes_count > 0). Mirrors the Forecasts
         * DisableDelete plugin used by Opportunities. This is UX only — deletion is
         * enforced server-side by the ShippingAddresses before_delete logic hook.
         */
        app.plugins.register('DisableDeleteShippingAddress', ['field'], {

            /**
             * Semaphore to prevent overlapping executions on the same element.
             */
            running: false,

            /**
             * The delete events this plugin acts on (record view + list row).
             */
            deleteEvents: ['list:deleterow:fire', 'button:delete_button:click'],

            /**
             * @param {object} component
             * @param {object} plugin
             */
            onAttach: function(component, plugin) {
                this.once('init', function() {
                    if (_.contains(this.deleteEvents, this.def.event)) {
                        this.on('render', this.removeDelete, this);
                        const changeEvents = 'change:erp_sync_key change:erp_display_sync_key change:quotes_count';
                        this.model.on(changeEvents, this.removeDelete, this);
                    }
                }, this);
            },

            /**
             * Disables the Delete action and adds an explanatory tooltip when the
             * record is restricted; re-enables it otherwise.
             *
             * @returns {string|null} the message that was set, if any
             */
            removeDelete: function() {
                if (this.running) {
                    return;
                }
                this.running = true;

                const button = this.getFieldElement();
                let message = null;

                if (button.length && _.contains(this.deleteEvents, this.def.event)) {
                    const hasSyncKey = !_.isEmpty(this.model.get('erp_sync_key'));
                    const hasDisplaySyncKey = !_.isEmpty(this.model.get('erp_display_sync_key'));
                    if (hasSyncKey || hasDisplaySyncKey) {
                        message = app.lang.get('LBL_NO_DELETE_ERP_MANAGED', 'ShippingAddresses');
                    } else if (this.model.get('quotes_count') > 0) {
                        message = app.lang.get('LBL_NO_DELETE_REFERENCED_QUOTE', 'ShippingAddresses');
                    }

                    if (!_.isEmpty(message)) {
                        this.setDisabled(true);
                        button.attr('data-event', '');
                        button.tooltip({title: message});
                    } else {
                        this.setDisabled(false);
                        button.attr('data-event', this.def.event);
                        if ($.fn.tooltip && $.fn.tooltip.Constructor) {
                            button.tooltip('dispose');
                        }
                    }
                }

                this.running = false;
                return message;
            },
        });
    });
})(SUGAR.App);

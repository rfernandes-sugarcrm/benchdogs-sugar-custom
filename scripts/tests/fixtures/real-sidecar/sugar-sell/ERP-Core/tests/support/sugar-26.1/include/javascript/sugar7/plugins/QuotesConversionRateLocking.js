// Recorded from SugarEnt 26.1.0 (build 319): include/javascript/sugar7/plugins/QuotesConversionRateLocking.js; sha256 b2cf4a47a9b77e6779c86d980369da45f1977095a5d24cf69646dfe1594048b7 of the stock file, which follows unchanged.
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
/**
 * QuotesConversionRateLocking assists with functionality surrounding
 * conversion rate locking
 */
(function(app) {
    app.events.on('app:init', function() {
        app.plugins.register('QuotesConversionRateLocking', ['view', 'field'], {
            /**
             * Displays a confirmation message asking the user if they are sure
             * they want to change the conversion rate locking option
             *
             * @param {Function} onConfirm optional callback to run when the user gives confirmation
             * @param {Bean|undefined} model optional model to check with, defaults to this.model
             */
            checkConversionRateLock: function(onConfirm, model) {
                model = model || this.model;
                if (this._conversionRateLockHasChanged(model)) {
                    let label = model.get('lock_conversion_rates') ? 'LBL_QUOTES_CONVERSION_LOCK_CONFIRM' :
                        'LBL_QUOTES_CONVERSION_UNLOCK_CONFIRM';
                    let message = app.lang.get(label, 'Quotes', {
                        lockConversionRatesName: app.lang.get(this._getLockFieldLabel(), 'Quotes')
                    });
                    app.alert.show('quote_confirm_lock_change', {
                        level: 'confirmation',
                        messages: message,
                        onConfirm: _.isFunction(onConfirm) ? onConfirm : () => {}
                    });
                } else if (_.isFunction(onConfirm)) {
                    onConfirm();
                }
            },

            /**
             * Checks to see whether the lock_conversion_rates value has
             * changed on the given model
             *
             * @param {Bean} model model to check with
             * @return {boolean} true if the lock_conversion_rates value has changed
             * @private
             */
            _conversionRateLockHasChanged(model) {
                let changedAttrs = model.changedAttributes(model.getSynced());
                return changedAttrs && !_.isUndefined(changedAttrs.lock_conversion_rates);
            },

            /**
             * Gets the label used for the lock_conversion_rates field name on
             * this particular view
             *
             * @return {string} the lock_conversion_rates field name label
             */
            _getLockFieldLabel: function() {
                let lockFieldMeta = this._getLockFieldMeta();
                return lockFieldMeta.label || lockFieldMeta.vname || '';
            },

            /**
             * Gets the metadata for the lock_conversion_rates field on the view
             *
             * @return {Object} the lock_conversion_rates field metadata
             */
            _getLockFieldMeta: function() {
                let fieldsMeta = app.metadata.getModule('Quotes', 'fields');
                let lockFieldMeta = fieldsMeta.lock_conversion_rates || {};

                let isField = this instanceof app.view.Field;
                let view = isField ? this.view : this;
                return _.extend({}, lockFieldMeta, view.getFieldMeta('lock_conversion_rates'));
            }
        });
    });
})(SUGAR.App);

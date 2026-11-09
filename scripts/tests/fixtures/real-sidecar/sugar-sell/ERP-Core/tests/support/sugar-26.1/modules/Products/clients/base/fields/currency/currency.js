// Recorded from SugarEnt 26.1.0 (build 319): modules/Products/clients/base/fields/currency/currency.js; sha256 0d8bde86c8ef1f2e154c67fd7959abad95df5cfe323b513b6241b5e2dbd66336 of the stock file, which follows unchanged.
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
 * @class View.Fields.Base.Products.CurrencyField
 * @alias SUGAR.App.view.fields.BaseProductsCurrencyField
 * @extends View.Fields.Base.CurrencyField
 */
({
    extendsFrom: 'BaseCurrencyField',

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this._super('initialize', [options]);

        // Enabling currency dropdown on Qli ist views
        this.hideCurrencyDropdown = false;
    },

    /**
     * @inheritdoc
     */
    format: function(value) {
        // Skipping the core currencyField call
        // app.view.Field.prototype.format.call(this, value);
        this._super('format', [value]);

        //Check if in 'Edit' mode
        if (this.tplName === 'edit') {
            //Display just currency value without currency symbol when entering edit mode for the first time
            //We want the correct value in input field corresponding to the currency in the dropdown
            //Example: Dropdown has Euro then display '100.00' instead of '$111.11'
            return app.utils.formatNumberLocale(value);
        }

        var transactionalCurrencyId = this.model.get(this.def.currency_field || 'currency_id');
        var convertedCurrencyId = transactionalCurrencyId;
        var origTransactionValue = value;

        // If necessary, do a conversion to the preferred currency. Otherwise,
        // just display the currency as-is.
        var preferredCurrencyId = this.getPreferredCurrencyId();
        if (preferredCurrencyId && preferredCurrencyId !== transactionalCurrencyId) {
            convertedCurrencyId = preferredCurrencyId;

            this.transactionValue = app.currency.formatAmountLocale(
                this.model.get(this.name) || 0,
                transactionalCurrencyId
            );

            let quoteRate = this._getQuoteRate();
            let toRate = quoteRate[preferredCurrencyId] ||
                app.metadata.getCurrency(preferredCurrencyId).conversion_rate;

            value = app.currency.convertWithRate(
                value,
                this.model.get('base_rate'),
                toRate
            );
        } else {
            // user preferred same as transactional, no conversion required
            this.transactionValue = '';
            convertedCurrencyId = transactionalCurrencyId;
            value = origTransactionValue;
        }
        return app.currency.formatAmountLocale(value, convertedCurrencyId);
    },

    /**
     * @inheritdoc
     */
    _render: function() {
        if (
            this.view.name === 'quote-data-group-list' &&
            !app.acl.hasAccessToModel('edit', this.model, this.name) &&
            this.options.viewName === 'edit'
        ) {
            this.options.viewName = this.action = 'list';
        }
        this._super('_render');
    },

    /**
     * Determines the correct preferred currency ID to convert to depending on
     * the context this currency field is being displayed in
     * @return {string|undefined} the ID of the preferred currency if it exists
     */
    getPreferredCurrencyId: function() {
        // If this is a QLI subpanel, and the user has opted to show in their
        // preferred currency, use that currency. Otherwise, use the system currency.
        if (this.context.get('isSubpanel')) {
            if (app.user.getPreference('currency_show_preferred')) {
                return app.user.getPreference('currency_id');
            }
            return app.currency.getBaseCurrencyId();
        }

        // Get the preferred currency of the parent context or this context. For
        // Quotes record view, this will get the Quote's preferred currency
        var context = this.context.parent || this.context;
        const currency = context.get('model').get('currency_id');

        if (!currency && app.user.getPreference('currency_show_preferred') && app.user.getPreference('currency_id')) {
            return app.user.getPreference('currency_id');
        }

        return currency;
    },

    /**
     * Get the quote rate from the parent context
     * @return {Object}
     */
    _getQuoteRate: function() {
        let lockedCurrencyRates = this.model.get('quote_locked_currency_rates');
        if (lockedCurrencyRates) {
            return JSON.parse(lockedCurrencyRates);
        } else if (this.context.parent && this.context.parent.get('model')) {
            return this.context.parent.get('model').get('locked_currency_rates') || {};
        }
        return {};
    },

    /**
     * @inheritdoc
     */
    updateModelWithValue: function(model, currencyId, val) {
        // Convert the discount amount value only if it is not in %
        // Other values will be converted as usual
        if (val && !(this.name === 'discount_amount' && this.model.get('discount_select'))) {
            let quoteRate = this._getQuoteRate();
            if (quoteRate[currencyId] && quoteRate[this._lastCurrencyId]) {
                let previousCurrency = quoteRate[this._lastCurrencyId];
                let currentCurrency = quoteRate[currencyId];
                this.model.set('base_rate', currentCurrency);
                this.model.set(
                    this.name,
                    app.currency.convertWithRate(
                        val,
                        previousCurrency,
                        currentCurrency
                    )
                );

                // now defer changes to the end of the thread to avoid conflicts
                // with other events (from SugarLogic, etc.)
                this._deferModelChange();
            } else {
                this._super('updateModelWithValue', [model, currencyId, val]);
            }
        }
    }
})

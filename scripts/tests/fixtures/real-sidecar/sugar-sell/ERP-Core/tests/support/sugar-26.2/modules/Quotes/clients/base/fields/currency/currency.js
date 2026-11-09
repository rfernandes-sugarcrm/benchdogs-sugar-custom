// Recorded from SugarEnt 26.2.0 (build 292): modules/Quotes/clients/base/fields/currency/currency.js; sha256 1271962bd476383c5017db868018edc145ba129a758ccb7be6d619701467ae62 of the stock file, which follows unchanged.
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
 * @class View.Fields.Base.Quotes.CurrencyField
 * @alias SUGAR.App.view.fields.BaseQuotesCurrencyField
 * @extends View.Fields.Base.CurrencyField
 */
({
    extendsFrom: 'CurrencyField',

    /**
     * The field's value in Percent
     */
    valuePercent: undefined,

    /**
     * The closed statuses of Quotes stage.
     * @type {Array}
     */
    closedStatuses: ['Closed Accepted', 'Closed Dead', 'Closed Lost'],

    /**
     * @inheritdoc
     *
     * For a locked/closed quote's rollup (calculated) currency fields, skip
     * the base CurrencyField's live-rate base_rate write and direct value
     * conversion on a currency switch. The Quotes record view's
     * reconcileAndRecomputeCurrency() (record.js) is the single owner of
     * base_rate + rollup recompute in that state, sourcing base_rate from
     * locked_currency_rates; this base class handler would otherwise stomp
     * that reconciled value with the live conversion rate (SUS-302 Defect C).
     */
    handleCurrencyFieldChange: function(model, currencyId, options) {
        var isLockedOrClosed = model.get('lock_conversion_rates') ||
            _.includes(this.closedStatuses, model.get('quote_stage'));

        if (isLockedOrClosed && this.def.calculated) {
            this._lastCurrencyId = currencyId;
            return;
        }

        this._super('handleCurrencyFieldChange', [model, currencyId, options]);
    },

    /**
     * @inheritdoc
     */
    bindDataChange: function() {
        this._super('bindDataChange');

        if (this.name === 'deal_tot' && this.view.name === 'quote-data-grand-totals-header') {
            this.model.on('change:deal_tot_discount_percentage', function() {
                this._updateDiscountPercent();
            }, this);

            if (this.context.get('create')) {
                // if this is deal_tot and on the create view, update the discount percent
                this._updateDiscountPercent();
            }
        }
    },

    /**
     * Needed to override loadTemplate to check field permissions for Quotes header and footer views
     *
     * @inheritdoc
     */
    _loadTemplate: function() {
        var viewName = this.view.name;
        if ((viewName === 'quote-data-grand-totals-header' || viewName === 'quote-data-grand-totals-footer') &&
            !this._checkAccessToAction('list')) {
            // set the action to noaccess so the field template will get the right class
            this.action = 'noaccess';
            // if this is a header or footer currency field and there's no access, show noaccess
            this.tplName = 'noaccess-' + viewName;
            this.template = app.template.getField('currency', this.tplName, this.module);
        } else {
            this._super('_loadTemplate');
        }
    },

    /**
     * Updates `this.valuePercent` for the deal_tot field in the quote-data-grand-totals-header view.
     *
     * @private
     */
    _updateDiscountPercent: function() {
        var percent = this.model.get('deal_tot_discount_percentage');

        if (!_.isUndefined(percent)) {
            //clean up precision
            percent = app.utils.formatNumber(
                percent,
                false,
                app.user.getPreference('decimal_precision'),
                app.user.getPreference('number_grouping_separator'),
                app.user.getPreference('decimal_separator')
            );

            if (app.lang.direction === 'rtl') {
                this.valuePercent = '%' + percent;
            } else {
                this.valuePercent =  percent + '%';
            }

            // re-render after update
            this.render();
        }
    },

    /**
     * Check is transaction value is equal to value and returns answer.
     *
     * @return {boolean} True if the values are equal or false if not
     * @private
     */
    isTransactionValueEqualToValue: function() {
        return this.transactionValue === this.value;
    },
});

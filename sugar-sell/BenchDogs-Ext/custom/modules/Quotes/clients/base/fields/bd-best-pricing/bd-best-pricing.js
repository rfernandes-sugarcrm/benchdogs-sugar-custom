/**
 * @class View.Fields.Base.Quotes.BdBestPricing
 * @alias SUGAR.App.view.fields.BaseQuotesBdBestPricing
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button: reprices the CATALOG-linked line items from the live
 * Epicor price lists (per customer, per quantity break) and reports, by
 * name, which lines were repriced and which were skipped because they are
 * not in the Product Catalog (engineered/free-text lines are never touched).
 *
 * Visible on any open quote - the pricing lookup is read-only against the
 * ERP and the API guards the no-ERP-customer case with a clear message.
 */
({
    extendsFrom: 'RowactionField',

    initialize: function(options) {
        this._super('initialize', [options]);
        this.type = 'rowaction';
        this.context.on(this.def.event, this._onClicked, this);
        this.model.on('change:quote_stage', this._checkVisibility, this);
    },

    render: function() {
        this._super('render');
        this._checkVisibility();
    },

    _onClicked: function() {
        var self = this;
        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-best-pricing');

        app.alert.show('bd-best-pricing', {
            level: 'process',
            title: app.lang.get('LBL_BD_BEST_PRICING_RUNNING', 'Quotes')
        });

        app.api.call('create', url, {}, {
            success: function(data) {
                app.alert.show('bd-best-pricing-done', {
                    level: (data && data.status === 'success') ? 'success' : 'error',
                    messages: (data && data.message) || 'Catalog pricing failed.',
                    autoClose: false
                });
                self.model.fetch();
            },
            error: function(err) {
                app.alert.show('bd-best-pricing-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Catalog pricing failed.',
                    autoClose: true
                });
            },
            // Dismissed here rather than in each branch: an exception inside
            // a success handler used to leave the spinner on screen with no
            // way back, which reads as a hung server even though the request
            // had already returned.
            complete: function() {
                app.alert.dismiss('bd-best-pricing');
            }
        });
    },

    _checkVisibility: function() {
        // WITHDRAWN. Catalog best-pricing is not part of the Bench Dogs quote
        // model on either simple or advanced quotes: the estimator's price on
        // the quote is the only price, so a second, ERP-catalog price in the
        // header offers an answer the story does not have. The button is also
        // dropped from BdQuotesLayoutExtensions::$wanted and named in its
        // $unwanted list; this hide is the belt to that braces, because the
        // layout removal only reaches views the deployed viewdef writer
        // actually rewrites, and a stale deployed viewdef would keep
        // rendering the button. Hiding at the field means it cannot appear in
        // ANY layout that still names it. Restore by reverting both.
        this.$el.hide();
    },

    isAllowedDropdownButton: function() {
        return this.view.name !== 'dashlet-toolbar';
    }
})

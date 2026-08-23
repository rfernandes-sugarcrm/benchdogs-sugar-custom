/**
 * @class View.Fields.Base.Quotes.BdOrderWinning
 * @alias SUGAR.App.view.fields.BaseQuotesBdOrderWinning
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button: raises an Epicor sales order from ONLY the winning
 * (governing) Kinetic quote line, at the quoted price, and the quote stays
 * open (Partially Fulfilled) when lines remain - REQ-1/REQ-2/REQ-22. The
 * winning line is ticked on the ERP quote line subpanel; BdGoverningLineHook
 * keeps it single-winner.
 *
 * Visible once a Kinetic quote exists (erp_display_sync_key stamped) and
 * until the quote is closed or an order is in flight. Deliberately NOT
 * gated on quote_stage 'Closed Accepted' - that gate (the product's Submit
 * Order) is the whole-quote model REQ-1 rejects.
 */
({
    extendsFrom: 'RowactionField',

    initialize: function(options) {
        this._super('initialize', [options]);
        this.type = 'rowaction';
        this.context.on(this.def.event, this._onClicked, this);
        this.model.on(
            'change:erp_display_sync_key change:quote_stage change:order_stage',
            this._checkVisibility,
            this
        );
    },

    render: function() {
        this._super('render');
        this._checkVisibility();
    },

    _onClicked: function() {
        var self = this;
        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-order-winning-line');

        app.alert.show('bd-order-winning', {
            level: 'process',
            title: app.lang.get('LBL_BD_ORDER_WINNING_RUNNING', 'Quotes')
        });

        app.api.call('create', url, {}, {
            success: function(data) {
                app.alert.dismiss('bd-order-winning');
                app.alert.show('bd-order-winning-done', {
                    level: (data && data.status === 'success') ? 'success' : 'error',
                    messages: (data && data.message) || 'Order submit failed.',
                    autoClose: false
                });
                self.model.fetch();
            },
            error: function(err) {
                app.alert.dismiss('bd-order-winning');
                app.alert.show('bd-order-winning-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Order submit failed.',
                    autoClose: true
                });
            }
        });
    },

    _checkVisibility: function() {
        var hasErpQuote = !!this.model.get('erp_display_sync_key');
        var stage = this.model.get('quote_stage');
        var orderStage = this.model.get('order_stage');
        var open = stage !== 'Closed Lost' && stage !== 'Closed Accepted';
        // Only an in-flight request hides the button. A LANDED order must
        // not: the quote stays open and the customer can order another line
        // later (one quote, many orders - REQ-1/REQ-22, UC7).
        var noOrderInFlight = orderStage !== 'Pending';

        if (hasErpQuote && open && noOrderInFlight) {
            this.$el.show();
        } else {
            this.$el.hide();
        }
    },

    isAllowedDropdownButton: function() {
        return this.view.name !== 'dashlet-toolbar';
    }
})

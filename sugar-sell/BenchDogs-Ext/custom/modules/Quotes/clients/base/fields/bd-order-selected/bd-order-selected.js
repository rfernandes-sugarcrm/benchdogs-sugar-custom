/**
 * @class View.Fields.Base.Quotes.BdOrderSelected
 * @alias SUGAR.App.view.fields.BaseQuotesBdOrderSelected
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button for the native-line partial order (1:1 CRM quote to
 * ERP quote): raises an Epicor sales order from ONLY the quoted line items
 * ticked "To Order" (bd_to_order) that have not already been ordered
 * (bd_ordered). The untouched lines stay on the quote and the quote moves to
 * Partially Fulfilled - REQ-1/REQ-2/REQ-22 expressed on the quote's own
 * line items instead of the ERP reflection subpanel.
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
        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-order-selected-lines');

        app.alert.show('bd-order-selected', {
            level: 'process',
            title: app.lang.get('LBL_BD_ORDER_SELECTED_RUNNING', 'Quotes')
        });

        app.api.call('create', url, {}, {
            success: function(data) {
                app.alert.dismiss('bd-order-selected');
                app.alert.show('bd-order-selected-done', {
                    level: (data && data.status === 'success') ? 'success' : 'error',
                    messages: (data && data.message) || 'Order submit failed.',
                    autoClose: false
                });
                self.model.fetch();
            },
            error: function(err) {
                app.alert.dismiss('bd-order-selected');
                app.alert.show('bd-order-selected-done', {
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
        // Only an in-flight request hides the button - a LANDED order must
        // not: the remaining lines stay orderable (one quote, many orders).
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

/**
 * @class View.Fields.Base.Quotes.BdOrderSelected
 * @alias SUGAR.App.view.fields.BaseQuotesBdOrderSelected
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button for the native-line partial order (1:1 CRM quote to
 * ERP quote): raises an Epicor sales order from the quoted line items the
 * user has TICKED in the grid. The untouched lines stay on the quote and the
 * quote moves to Partially Fulfilled - REQ-1/REQ-2/REQ-22 expressed on the
 * quote's own line items instead of the ERP reflection subpanel.
 *
 * Up to 0.9.20 the selection was a stored bd_to_order flag on each line,
 * which meant the tick had to be SAVED before this button could see it: a
 * user who ticked and pressed immediately got "tick To Order first" on rows
 * that visibly had it ticked. The selection is now the grid's own
 * multi-select checkbox and travels in the request, so what the button
 * orders is exactly what the screen shows selected.
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

    /**
     * The ticked, still-orderable rows.
     *
     * Read from the DOM rather than from a mass collection: the grid draws
     * one collection per bundle group, the button sits outside all of them,
     * and the rendered checkbox is the only thing that is guaranteed to
     * agree with what the user believes they selected. Rows already ordered
     * carry bd-ordered-row and have a disabled checkbox (see the
     * ProductBundles quote-data-group-list override); they are excluded here
     * as well so a stale DOM state can never resubmit one.
     */
    _selectedLineIds: function() {
        var ids = [];
        $('tr.product-row').each(function() {
            var $row = $(this);
            if ($row.hasClass('bd-ordered-row')) {
                return;
            }
            if (!$row.find('input[name=check]:checked').length) {
                return;
            }
            var id = $row.attr('record-id');
            if (id && _.indexOf(ids, id) === -1) {
                ids.push(id);
            }
        });
        return ids;
    },

    _onClicked: function() {
        var self = this;
        var lineIds = this._selectedLineIds();

        if (!lineIds.length) {
            app.alert.show('bd-order-selected-none', {
                level: 'warning',
                messages: app.lang.get('LBL_BD_ORDER_SELECTED_NONE', 'Quotes'),
                autoClose: true
            });
            return;
        }

        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-order-selected-lines');

        app.alert.show('bd-order-selected', {
            level: 'process',
            title: app.lang.get('LBL_BD_ORDER_SELECTED_RUNNING', 'Quotes')
        });

        app.api.call('create', url, {line_ids: lineIds}, {
            success: function(data) {
                app.alert.show('bd-order-selected-done', {
                    level: (data && data.status === 'success') ? 'success' : 'error',
                    messages: (data && data.message) || 'Order submit failed.',
                    autoClose: false
                });
                self.model.fetch();
            },
            error: function(err) {
                app.alert.show('bd-order-selected-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Order submit failed.',
                    autoClose: true
                });
            },
            // The process alert is dismissed here rather than in each branch:
            // an exception thrown inside a success handler used to leave the
            // spinner on screen forever, which reads as a hung server.
            complete: function() {
                app.alert.dismiss('bd-order-selected');
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

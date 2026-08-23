/**
 * @class View.Fields.Base.Quotes.BdSendEstimating
 * @alias SUGAR.App.view.fields.BaseQuotesBdSendEstimating
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button: creates the Kinetic quote for this Sugar Quote via
 * the product's quote_to_quote write-back and flags the hand-off
 * (bd_erp_stage=in_estimating -> estimating owner is notified). REQ-27 +
 * REQ-13/UC-6 - the email-with-a-folder-link step this replaces.
 *
 * Visible only while no Kinetic quote exists yet (erp_display_sync_key is
 * stamped by the write-back, so non-empty means already sent - same gate
 * the product's advanced-quote button reads in reverse).
 */
({
    extendsFrom: 'RowactionField',

    initialize: function(options) {
        this._super('initialize', [options]);
        this.type = 'rowaction';
        this.context.on(this.def.event, this._onClicked, this);
        this.model.on('change:erp_display_sync_key', this._checkVisibility, this);
    },

    render: function() {
        this._super('render');
        this._checkVisibility();
    },

    _onClicked: function() {
        var self = this;
        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-send-to-estimating');

        app.alert.show('bd-send-estimating', {
            level: 'process',
            title: app.lang.get('LBL_BD_SEND_ESTIMATING_RUNNING', 'Quotes')
        });

        app.api.call('create', url, {}, {
            success: function(data) {
                app.alert.dismiss('bd-send-estimating');
                app.alert.show('bd-send-estimating-done', {
                    level: (data && data.status === 'success') ? 'success' : 'error',
                    messages: (data && data.message) || 'Send to estimating failed.',
                    autoClose: true
                });
                self.model.fetch();
            },
            error: function(err) {
                app.alert.dismiss('bd-send-estimating');
                app.alert.show('bd-send-estimating-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Send to estimating failed.',
                    autoClose: true
                });
            }
        });
    },

    _checkVisibility: function() {
        if (this.model.get('erp_display_sync_key')) {
            this.$el.hide();
        } else {
            this.$el.show();
        }
    },

    isAllowedDropdownButton: function() {
        return this.view.name !== 'dashlet-toolbar';
    }
})

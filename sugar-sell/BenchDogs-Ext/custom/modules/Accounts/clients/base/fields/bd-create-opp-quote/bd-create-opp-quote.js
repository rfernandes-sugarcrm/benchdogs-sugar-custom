/**
 * @class View.Fields.Base.Accounts.BdCreateOppQuote
 * @alias SUGAR.App.view.fields.BaseAccountsBdCreateOppQuote
 * @extends View.Fields.Base.RowactionField
 *
 * Account header button: one click creates a linked Opportunity + Quote with
 * a free-text placeholder line (REQ-20 / build commitments #1-#2), then
 * lands the rep on the new Quote - the leading object from here on.
 * Event-binding pattern follows ERP-Epicor's advanced-quote button field.
 */
({
    extendsFrom: 'RowactionField',

    initialize: function(options) {
        this._super('initialize', [options]);
        this.type = 'rowaction';
        this.context.on(this.def.event, this._onClicked, this);
    },

    _onClicked: function() {
        var self = this;
        var url = app.api.buildURL('Accounts/' + this.model.get('id') + '/bd-create-opp-quote');

        app.alert.show('bd-create-opp-quote', {
            level: 'process',
            title: app.lang.get('LBL_BD_CREATE_OPP_QUOTE_RUNNING', 'Accounts')
        });

        app.api.call('create', url, {}, {
            success: function(data) {
                app.alert.dismiss('bd-create-opp-quote');
                if (data && data.status === 'success' && data.quote_id) {
                    app.alert.show('bd-create-opp-quote-done', {
                        level: 'success',
                        messages: data.message,
                        autoClose: true
                    });
                    app.router.navigate('#Quotes/' + data.quote_id, {trigger: true});
                } else {
                    app.alert.show('bd-create-opp-quote-done', {
                        level: 'error',
                        messages: (data && data.message) || 'Could not create the opportunity and quote.',
                        autoClose: true
                    });
                }
            },
            error: function(err) {
                app.alert.dismiss('bd-create-opp-quote');
                app.alert.show('bd-create-opp-quote-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Could not create the opportunity and quote.',
                    autoClose: true
                });
            }
        });
    },

    isAllowedDropdownButton: function() {
        return this.view.name !== 'dashlet-toolbar';
    }
})

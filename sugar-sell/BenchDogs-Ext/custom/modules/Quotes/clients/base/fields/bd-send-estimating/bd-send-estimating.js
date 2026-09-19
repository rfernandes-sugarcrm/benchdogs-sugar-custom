/**
 * @class View.Fields.Base.Quotes.BdSendEstimating
 * @alias SUGAR.App.view.fields.BaseQuotesBdSendEstimating
 * @extends View.Fields.Base.RowactionField
 *
 * Quote header button: creates the Kinetic quote for this Sugar Quote via
 * the product's quote_to_quote write-back and flags the hand-off
 * (bd_erp_stage=in_estimating). Notification delivery is reported as a
 * separate, secondary result. REQ-27 +
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

    _render: function() {
        this._super('_render');
        this._checkVisibility();
        this._setSendPending(!!this._sendPending);
    },

    _onClicked: function() {
        // A context event can arrive twice before a disabled anchor repaints.
        // Keep the request guard in the field instance as well as in the DOM.
        if (this._sendPending) {
            return;
        }
        var self = this;
        var url = app.api.buildURL('Quotes/' + this.model.get('id') + '/bd-send-to-estimating');
        this._setSendPending(true);

        var callbacks = {
            success: function(data) {
                app.alert.dismiss('bd-send-estimating');
                var succeeded = data && data.status === 'success';
                var notificationStatus = data && data.notification_status;
                var notificationDelivered = notificationStatus === 'created' ||
                    notificationStatus === 'already_created';
                var notificationWarning = succeeded && !notificationDelivered;
                var message = (data && data.message) || 'Send to estimating failed.';
                if (notificationWarning) {
                    message += ' ' + ((data && data.notification_message) ||
                        'The hand-off completed, but Sugar could not confirm the in-app notification. Use the In Estimating view and ask an administrator to inspect notification delivery.');
                }
                app.alert.show('bd-send-estimating-done', {
                    level: notificationWarning ?
                        'warning' : (succeeded ? 'success' : 'error'),
                    messages: message,
                    autoClose: !notificationWarning
                });
                if (!succeeded) {
                    if (data && data.partial_success) {
                        // ERP creation succeeded but the local stage did not.
                        // Refresh the stamped ERP identity and keep this field
                        // guarded if it is still not visible; a second tab or
                        // blind retry is not a safe recovery mechanism.
                        self.model.fetch({
                            success: function() {
                                self._checkVisibility();
                                if (self.model.get('erp_display_sync_key')) {
                                    self._setSendPending(false);
                                }
                            }
                        });
                        return;
                    }
                    self._setSendPending(false);
                    return;
                }

                // Keep the action guarded until the successful server-side
                // stage transition and ERP identity are visible locally.
                self.model.fetch({
                    success: function() {
                        self._checkVisibility();
                        if (self.model.get('erp_display_sync_key')) {
                            self._setSendPending(false);
                        } else {
                            app.alert.show('bd-send-estimating-identity-pending', {
                                level: 'warning',
                                messages: 'Kinetic accepted the Quote, but its ERP identity is not visible yet. Refresh to check the identity; do not retry unless an administrator verifies that no Kinetic quote exists.',
                                autoClose: false
                            });
                        }
                    }
                });
            },
            error: function(err) {
                self._setSendPending(false);
                app.alert.dismiss('bd-send-estimating');
                app.alert.show('bd-send-estimating-done', {
                    level: 'error',
                    messages: (err && err.message) || 'Send to estimating failed.',
                    autoClose: true
                });
            }
        };

        try {
            app.alert.show('bd-send-estimating', {
                level: 'process',
                title: app.lang.get('LBL_BD_SEND_ESTIMATING_RUNNING', 'Quotes')
            });
            app.api.call('create', url, {}, callbacks);
        } catch (error) {
            this._setSendPending(false);
            app.alert.dismiss('bd-send-estimating');
            throw error;
        }
    },

    /** Keep the in-flight guard independent of the rendered element. */
    _setSendPending: function(pending) {
        this._sendPending = pending;
        this.$el.find('[data-key-action="press"]')
            .attr('aria-disabled', pending)
            .attr('aria-busy', pending)
            .prop('disabled', pending);
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

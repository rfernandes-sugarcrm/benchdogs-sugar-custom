({
    /**
     * The default owner for ERP-created records: shows the saved user by name and saves the picked user's id
     * (an empty pick turns it off). The server refuses any id that is not an active user.
     */
    events: {
        'click [data-action=bd-save]': 'saveOwner',
        'click [data-action=bd-cancel]': 'closePage'
    },

    initialize: function(options) {
        this._super('initialize', [options]);
        // The page reads its one setting itself; without this the layout would also list Administration records.
        this.context.set('fetch', false);
    },

    /** The setting as the server holds it, read when the page opens. */
    loadData: function() {
        app.api.call('read', app.api.buildURL('BenchDogs', 'default-owner'), null, {
            success: _.bind(this.showOwner, this),
            error: _.bind(this.showFailure, this)
        });
    },

    /** The saved user's id and name on the field; a saved user who is no longer active is said so. */
    showOwner: function(state) {
        if (this.disposed) {
            return;
        }
        state = state || {};
        this.ownerInactive = !!state.default_owner_user_id && !state.default_owner_user_active;
        this.model.set({
            default_owner_user_id: state.default_owner_user_id || '',
            default_owner_user_name: state.default_owner_user_name || ''
        });
        this.render();
    },

    saveOwner: function() {
        app.alert.show('bd-default-owner-save', {level: 'process', title: app.lang.get('LBL_SAVING')});
        app.api.call('update', app.api.buildURL('BenchDogs', 'default-owner'), {
            default_owner_user_id: this.model.get('default_owner_user_id') || ''
        }, {
            success: _.bind(function(state) {
                app.alert.dismiss('bd-default-owner-save');
                this.showOwner(state);
                app.alert.show('bd-default-owner-saved', {
                    level: 'success',
                    messages: app.lang.get('LBL_BD_DEFAULT_OWNER_SAVED', this.module),
                    autoClose: true
                });
            }, this),
            error: _.bind(this.showFailure, this)
        });
    },

    /** The server's own reason (a refused user, a lost connection), or a plain one. */
    showFailure: function(error) {
        app.alert.dismiss('bd-default-owner-save');
        app.alert.show('bd-default-owner-error', {
            level: 'error',
            messages: (error && error.message) || app.lang.get('LBL_BD_DEFAULT_OWNER_FAILED', this.module),
            autoClose: false
        });
    },

    closePage: function() {
        app.router.navigate('#Administration', {trigger: true});
    }
})

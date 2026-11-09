({
    /**
     * On a create form, Assigned To follows the account's owner (an active user) until the person picks an owner;
     * their pick then stands, and the save says so to the server (BdAccountOwner). Draws nothing. The same file ships
     * in Quotes and Opportunities, each module's create layout adding it after Sugar's create view.
     */

    /** Each module's account fields, the first one set used, and the account link a subpanel's create arrives by. */
    accountOf: {
        Opportunities: {fields: ['account_id'], link: 'opportunities'},
        Quotes: {fields: ['billing_account_id', 'account_id'], link: 'quotes'}
    },

    /** The query parameter the server reads as "the person picked the owner" (BdAccountOwner::CHOSEN_PARAM). */
    chosenParam: 'bd_owner_chosen',

    initialize: function(options) {
        this._super('initialize', [options]);
        this.account = this.accountOf[this.module] || null;
        this.chosen = false;
        this.filling = false;
        this.filled = '';
        this.asked = '';
        if (!this.account || !this.model || !this.model.isNew()) {
            return;
        }
        this.listenTo(this.model, 'change:assigned_user_id', this.noteChoice);
        _.each(this.account.fields, function(field) {
            this.listenTo(this.model, 'change:' + field, this.followAccount);
        }, this);
        this.listenTo(this.model, 'data:sync:start', this.sendChoice);
        this.followAccount();
    },

    /** A change of owner this view did not make is the person's own pick. */
    noteChoice: function() {
        if (!this.filling) {
            this.chosen = true;
        }
    },

    /** The first account set on the form, else the account whose subpanel the form was opened from. */
    accountId: function() {
        var link = this.model.link;
        var parent = link && link.bean && link.bean.module === 'Accounts' && link.name === this.account.link
            ? link.bean.id : '';
        var onForm = _.find(_.map(this.account.fields, function(field) {
            return this.model.get(field);
        }, this), _.identity);

        return onForm || parent || '';
    },

    /** Reads the account's owner, then whether that user is active; only the latest account's answer is used. */
    followAccount: function() {
        var accountId = this.accountId();
        if (this.chosen || !accountId || accountId === this.asked) {
            return;
        }
        this.asked = accountId;
        var url = app.api.buildURL('Accounts', null, {id: accountId}, {fields: 'assigned_user_id,assigned_user_name'});
        app.api.call('read', url, null, {
            success: _.bind(function(account) {
                var userId = account && account.assigned_user_id;
                if (userId) {
                    this.checkActive(accountId, userId, account.assigned_user_name);
                } else {
                    this.settle(accountId, '', '');
                }
            }, this),
            // A read the server refuses leaves the owner as it is, with no alert: the form works as Sugar's own.
            error: function() {}
        });
    },

    checkActive: function(accountId, userId, userName) {
        var url = app.api.buildURL('Users', null, null, {
            filter: [{id: {$equals: userId}, status: {$equals: 'Active'}}],
            fields: 'id,full_name',
            max_num: 1
        });
        app.api.call('read', url, null, {
            success: _.bind(function(answer) {
                var user = answer && answer.records && answer.records[0];
                if (user && user.id === userId) {
                    this.settle(accountId, userId, userName || user.full_name || '');
                } else {
                    this.settle(accountId, '', '');
                }
            }, this),
            error: function() {}
        });
    },

    /**
     * The owner for the account asked last: its owner, or with none eligible the creator again where this view had
     * filled another account's. Never once the person picked, nor over an owner the form came with.
     */
    settle: function(accountId, userId, userName) {
        if (this.disposed || this.chosen || this.asked !== accountId) {
            return;
        }
        var now = this.model.get('assigned_user_id');
        if (!userId) {
            if (!this.filled || now !== this.filled) {
                return;
            }
            userId = app.user.id;
            userName = app.user.get('full_name');
        } else if (now === userId || (now && now !== app.user.id && now !== this.filled)) {
            return;
        }
        var owner = {assigned_user_id: userId, assigned_user_name: userName};
        this.filling = true;
        try {
            this.model.set(owner);
        } finally {
            this.filling = false;
        }
        this.filled = userId === app.user.id ? '' : userId;
        // Not the person's change: Cancel does not ask about it.
        this.model.setDefault(owner);
    },

    sendChoice: function(method, options) {
        if (method === 'create' && this.chosen && options) {
            options.params = options.params || {};
            options.params[this.chosenParam] = '1';
        }
    }
})

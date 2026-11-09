/**
 * @class View.Fields.Base.ErpDependentEnumField
 * @alias SUGAR.App.view.fields.BaseErpDependentEnumField
 * @extends View.Fields.Base.EnumField
 *
 * G571 (absorbing G570 and G574) - A PICK LIST THAT FOLLOWS ANOTHER FIELD, AND A
 * PICK LIST THAT IS REQUIRED ONLY WHEN THERE IS SOMETHING TO PICK.
 *
 * MEASURED (a customer's Sales Order and Advanced Quote smokes, 2026-09-25):
 * with a marketing campaign chosen, the marketing event picker still listed all
 * 68 events, and Sugar SAVED a campaign/event pair the ERP company does not
 * have. Project was not marked required, so the seller learned only at Submit
 * Order that the ERP company needs one.
 *
 * WHAT A PACKAGE WRITES (vardef keys; this file reads them, nothing else does):
 *
 *   'erp_lookup_parent'              => 'marketing_campaign'
 *        the field whose value picks this list's options. An option belongs to
 *        the parent value P when its key, split at the LAST separator, is
 *        exactly P plus a non-empty rest ("26DISCNV/2" belongs to 26DISCNV;
 *        "26DISCNV/2" does NOT belong to 26DISC - no prefix matching).
 *   'erp_lookup_parent_separator'    => '/'   (the default)
 *   'erp_lookup_parent_empty_label'  => 'LBL_...'
 *        the placeholder while the parent is empty ("pick a campaign first").
 *   'erp_required_when_options'      => true
 *        the field is required in the browser when the tenant has something to
 *        pick (see REQUIRED below). A tenant with an empty list (its ERP
 *        connection publishes none of these rows) keeps the field optional.
 *   'erp_required_until_synced'      => true
 *        G809: the same rule, but only while the RECORD is not in the ERP yet
 *        (see REQUIRED below). A record the ERP already holds is never blocked:
 *        its values are the ERP's. On its own, never combined with the key
 *        above (which, when also set, requires the field on every record).
 *        An ERP-Core older than G809 ignores the key: the field stays optional,
 *        as before, so a package may declare it before this file ships.
 *   'erp_prefill_from_account_latest' => 'billing_account_id'
 *        G809: on a CREATE form, fill this field from the newest record of the
 *        same account that holds a value this field can use (see PREFILL
 *        below). The value names the record's account id field. Each marked
 *        field finds its OWN newest record. On a dependent field the pair is
 *        filled from ONE record (parent and this field together): mark the
 *        dependent field, not its parent.
 *
 * and the field's record-view entry gets `'type' => 'erp-dependent-enum'`,
 * written by ErpLayoutExtraFields from the vardef's `erp_layout['type']`.
 * NEVER the vardef `custom_type` key: SugarEnt 26.1.0 DBManager skips a field
 * with a custom_type on insert and update (include/database/DBManager.php, the
 * "custom fields handle their save separately" checks), so a pick would be
 * silently lost. The vardef stays a plain `enum` with its option function.
 *
 * WHAT IT DOES
 *
 *  1. FILTER. After the stock filter (_filterOptions), the list keeps the blank
 *     option, the options that belong to the parent's current value, and the
 *     field's current value. It always returns a NEW object and always derives
 *     it from the FULL list, never from its own previous answer: stock _render
 *     and _initSelection store the answer back into this.items and hand it in
 *     again, and the full list is also what the stock loader caches in the
 *     context. Trimming either would leave nothing to widen when the parent
 *     changes. With no parent configured the list passes through unchanged.
 *  2. CLEAR. When the parent changes, the current value is cleared if it no
 *     longer belongs - EXCEPT when the change came from the server (a fetch or
 *     a save: options.parse), from a revert (Cancel: options.revert), or when
 *     both fields are back at their saved values. Then the list is re-derived
 *     from the full list and redrawn. The stock option loader is untouched: the
 *     REST enum endpoint still runs the vardef's option function (which does not
 *     know the parent), so a package's own option rules survive.
 *  3. REQUIRED. With 'erp_required_when_options', the field instance's def is
 *     marked required when the full list has a non-blank option and there is
 *     something to pick now or once the parent is picked (parent empty, or the
 *     parent has options). The record and create views validate each shown
 *     field's def, so an empty value blocks the save and Sugar turns the field
 *     red with its own message. No "Required" text is drawn, before or after
 *     the save (decorateRequired below). A vardef `required` is never lowered.
 *     Browser-only: the server-side refusal at send time stays the guard for
 *     every other path.
 *     With 'erp_required_until_synced' the same test applies only while the
 *     record is NOT IN THE ERP: a new record (the create view, a copy), or a
 *     loaded record whose ERP key fields (erpSyncKeyFields) are all empty. A
 *     record holding either key is in the ERP and never required by this. An
 *     existing record whose keys the view did not load is UNKNOWN and is not
 *     required (fail open: the send-time refusal still names the field), so a
 *     view that lacks the key can never block every ERP record. The keys
 *     arriving (Send to Estimation stamps them; the next fetch brings them)
 *     re-apply the rule at once.
 *     A field a Sugar Logic visibility rule hides is left as that rule set
 *     it (not required, Sugar's own rule for a hidden field), whatever the
 *     vardef says; shown again, it is drawn again and the rule applies.
 *  4. PLACEHOLDER. While the parent is empty, the placeholder is the
 *     empty-parent label ("Pick a campaign first"), required or not.
 *  5. PREFILL (G809, 'erp_prefill_from_account_latest'). Asked for by the
 *     product owner: "maybe we should put defaults". Measured on a pilot
 *     customer's ERP company: consecutive quotes of one customer repeat Lead
 *     Source 93 %, Lead Type 95 %, Campaign 74 %. On a NEW record only, when
 *     the account id field is set (the seller picks the account, or the form
 *     opens with it) and this field is empty, ONE read per marked field: the
 *     module's records of that account where THIS field (and, on a dependent
 *     field, the parent) is not empty, newest date_entered first, up to
 *     erpPrefillScan (20) of them. So each field comes from the newest record
 *     holding IT, not from one record for all fields. The FIRST of those
 *     records that is usable fills the field, only while it is still empty
 *     (never over what the seller picked): usable means its value is one this
 *     field's loaded list still OFFERS - a code retired since (a campaign of
 *     an earlier year) is skipped for the next newer-to-older record, never filled.
 *     No usable record among them: the field stays empty for the seller. A
 *     dependent field fills the pair from ONE record, usable only when its
 *     value belongs to that record's parent value and the parent's loaded
 *     list (when loaded) offers it: both when both are empty, only this field
 *     when the parent already holds that record's value; a record with
 *     another parent value than the seller chose is skipped (the seller's
 *     parent is never changed). When the account changes, a value
 *     this prefill set and the seller left untouched is cleared and filled
 *     again from the new account; an answer for an account no longer on the
 *     form is dropped. An answer that arrives before the list has loaded waits
 *     for it (the stock loader re-renders when the list arrives). A saved
 *     record (the record view) is never prefilled: a server-side default is
 *     the customer package's own business. The read runs as the
 *     seller, so it sees only quotes the seller may see.
 *
 * A dependent field never picks for the seller: 'defaultToBlank' defaults to
 * true when a parent is configured (the stock enum otherwise sets an empty
 * field to its first option on a create form, which after a campaign pick
 * would be one of that campaign's events).
 *
 * SAVED MISMATCH (a pair saved before this field existed, e.g. quote #4): it
 * stays displayed - the current value is always kept in the list - and is not
 * cleared on edit or on Cancel; it is cleared only when the seller next changes
 * the parent to a value it does not belong to. Nothing the seller saved is
 * overwritten by opening a record.
 *
 * Templates: this type has none of its own; it borrows the enum templates by
 * loading them under the 'enum' type, as stock module-enum.js does.
 * Single-select only.
 *
 * Tests: scripts/tests/test_erp_dependent_enum.cjs (with SugarEnt 26.1.0 /
 * 25.2.0's own EnumField when a tree is present).
 */
({
    extendsFrom: 'EnumField',

    /** The separator when the vardef names none. */
    erpDefaultSeparator: '/',

    /**
     * G809: how many of the account's newest records holding this field one
     * prefill read returns. The first of them whose value is USABLE fills the
     * field (PREFILL below), so a code retired since the newest record was
     * saved does not hide the customer's newest usable one (graded
     * 2026-09-30: one row per read left Campaign and Event empty). A customer
     * package's server-side twin of this rule reads the same number.
     */
    erpPrefillScan: 20,

    /**
     * G809: the fields that say a record is in the ERP (ERP-Core's two keys:
     * the scoped erp_sync_key and the number the seller sees). Either one set
     * means the ERP holds the record.
     */
    erpSyncKeyFields: ['erp_display_sync_key', 'erp_sync_key'],

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this._super('initialize', [options]);
        // What the metadata said, so a "required when options" pass can never
        // lower a field the vardef or viewdef already requires.
        this._erpBaseRequired = this.def.required === true;
        if (this._erpParentName() !== null && _.isUndefined(this.def.defaultToBlank)) {
            this.def.defaultToBlank = true;
        }
    },

    /**
     * Borrow the enum templates (no templates of our own), as module-enum does.
     *
     * @override
     */
    _loadTemplate: function() {
        var type = this.type;
        this.type = 'enum';
        try {
            this._super('_loadTemplate');
        } finally {
            this.type = type;
        }
    },

    /**
     * @inheritdoc
     */
    bindDataChange: function() {
        this._super('bindDataChange');
        var parent = this._erpParentName();
        if (this.model && parent !== null) {
            this.listenTo(this.model, 'change:' + parent, this._erpParentChanged);
        }
        if (this.model && this._erpFlag(this.def.erp_required_until_synced)) {
            for (var i = 0; i < this.erpSyncKeyFields.length; i++) {
                this.listenTo(this.model, 'change:' + this.erpSyncKeyFields[i], this._erpSyncKeyChanged);
            }
        }
        var accountField = this._erpPrefillAccountField();
        if (this.model && accountField !== null) {
            this.listenTo(this.model, 'change:' + accountField, this._erpPrefillFromAccount);
            // A form that opens with its account already set (from the Account).
            this._erpPrefillFromAccount();
        }
    },

    /**
     * G809: an account answer that came back before the list had loaded is
     * applied after the render that has it (the stock loader re-renders when
     * the list arrives).
     *
     * @override
     */
    _render: function() {
        var result = this._super('_render');
        if (this._erpPrefillRows && _.isObject(this._erpAllItems)) {
            this._erpApplyPrefill();
        }
        return result;
    },

    /**
     * G809: the record's ERP key changed (Send to Estimation stamped it, or a
     * fetch brought it): redraw, which re-applies the required rule.
     *
     * @private
     */
    _erpSyncKeyChanged: function() {
        if (!this.disposed) {
            this.render();
        }
    },

    /**
     * The stock filter, then the parent's options. Always derived from the
     * full list; always a new object.
     *
     * @param {Object} options
     * @return {Object}
     * @override
     */
    _filterOptions: function(options) {
        var source = options;
        if (options && options === this._erpLastList && this._erpAllItems) {
            // Our own previous answer, handed back by stock _render /
            // _initSelection: filter the full list instead.
            source = this._erpAllItems;
        }
        this._erpAllItems = source;
        var stock = this._super('_filterOptions', [source]);
        if (!_.isObject(stock) || _.isArray(stock)) {
            return stock;
        }
        var list = this._erpNarrow(stock);
        this._erpApplyRequired(stock, list);
        this._erpLastList = list;
        return list;
    },

    /**
     * Placeholder while the parent is empty.
     *
     * @return {string}
     * @override
     */
    getPlaceHolder: function() {
        var waiting = this._erpEmptyParentLabel();
        return waiting !== null ? waiting : this._super('getPlaceHolder');
    },

    /**
     * A required pick list carries no "Required" text, before or after a Save.
     * Sugar's field.js draws it from here: the "(Required)" placeholder on every
     * render of a required field, and again after a failed Save for a field
     * flagged no_required_placeholder. Draws nothing, so the input keeps the
     * placeholder getPlaceHolder() gives select2; Sugar's own check still
     * refuses the Save and turns the field red with its own message.
     *
     * @override
     */
    decorateRequired: function() {},

    /**
     * @param {Object} stock The stock-filtered full list.
     * @return {Object} A new object.
     * @private
     */
    _erpNarrow: function(stock) {
        var result = {};
        var parent = this._erpParentName();
        if (parent === null) {
            _.each(stock, function(label, key) {
                result[key] = label;
            });
            return result;
        }
        var parentValue = this._erpText(this.model ? this.model.get(parent) : '');
        var current = this._erpText(this.model ? this.model.get(this.name) : '');
        // The blank is always offered: the value a parent change clears to,
        // and a list that is never empty (an empty one makes stock _render
        // reload on every render).
        result[''] = _.has(stock, '') ? stock[''] : '';
        _.each(stock, function(label, key) {
            if (key === '') {
                return;
            }
            if (key === current || this._erpBelongs(key, parentValue)) {
                result[key] = label;
            }
        }, this);
        return result;
    },

    /**
     * @param {Object} stock The stock-filtered full list.
     * @param {Object} list What the seller can pick now.
     * @private
     */
    _erpApplyRequired: function(stock, list) {
        var always = this._erpFlag(this.def.erp_required_when_options);
        var untilSynced = this._erpFlag(this.def.erp_required_until_synced);
        if (!always && !untilSynced) {
            return;
        }
        if (this.dependencyStates && this.dependencyStates.visible === false) {
            // Hidden by a visibility rule: Sugar has made the field not
            // required, and setting that draws it, which runs this again.
            // Leave Sugar's value, as its own SetRequired action does for a
            // hidden field. Shown again, the field is drawn and this applies.
            return;
        }
        var parent = this._erpParentName();
        var somethingToPick = this._erpHasChoice(stock);
        if (somethingToPick && parent !== null && this.model &&
            this._erpText(this.model.get(parent)) !== '') {
            // The parent is picked: required only if it has options.
            somethingToPick = this._erpHasChoice(list);
        }
        if (somethingToPick && !always && !this._erpNotYetInErp()) {
            // G809: a record the ERP holds (or one whose keys were not loaded).
            somethingToPick = false;
        }
        this.def.required = this._erpBaseRequired || somethingToPick;
    },

    /**
     * G809: is this record known NOT to be in the ERP yet? True for a new
     * record, and for a loaded record whose ERP keys are all empty. False when
     * either key holds a value, and when the record exists but the view loaded
     * neither key (unknown: never block on a guess).
     *
     * @return {boolean}
     * @private
     */
    _erpNotYetInErp: function() {
        if (!this.model) {
            return false;
        }
        var attributes = _.isObject(this.model.attributes) ? this.model.attributes : {};
        var loaded = false;
        for (var i = 0; i < this.erpSyncKeyFields.length; i++) {
            var name = this.erpSyncKeyFields[i];
            if (_.has(attributes, name)) {
                loaded = true;
            }
            if (this._erpText(this.model.get(name)).replace(/^\s+|\s+$/g, '') !== '') {
                return false;
            }
        }
        if (_.isFunction(this.model.isNew) && this.model.isNew()) {
            return true;
        }
        return loaded;
    },

    /**
     * @param {Data.Bean} model
     * @param {*} value The parent's new value.
     * @param {Object} [options] Backbone set options.
     * @private
     */
    _erpParentChanged: function(model, value, options) {
        if (this.disposed) {
            return;
        }
        options = options || {};
        if (!options.parse && !options.revert && !this._erpPairIsSaved()) {
            var current = this._erpText(this.model.get(this.name));
            if (current !== '' && !this._erpBelongs(current, this._erpText(value))) {
                this.model.set(this.name, '');
            }
        }
        if (!this.disposed) {
            this.render();
        }
    },

    /**
     * Both fields hold their saved values (e.g. the seller changed the parent
     * and changed it back): nothing of the seller's is cleared then.
     *
     * @return {boolean}
     * @private
     */
    _erpPairIsSaved: function() {
        if (!this.model || !_.isFunction(this.model.getSynced)) {
            return false;
        }
        var synced = this.model.getSynced();
        if (!_.isObject(synced)) {
            return false;
        }
        var parent = this._erpParentName();
        return this._erpText(this.model.get(parent)) === this._erpText(synced[parent]) &&
            this._erpText(this.model.get(this.name)) === this._erpText(synced[this.name]);
    },

    /**
     * Does option `key` belong to parent value `parentValue`? Split at the LAST
     * separator: exactly the parent value, then a non-empty rest.
     *
     * @param {string} key
     * @param {string} parentValue
     * @return {boolean}
     * @private
     */
    _erpBelongs: function(key, parentValue) {
        if (parentValue === '') {
            return false;
        }
        var separator = this._erpSeparator();
        var at = key.lastIndexOf(separator);
        return at > 0 && key.slice(0, at) === parentValue && at + separator.length < key.length;
    },

    /**
     * The empty-parent placeholder, or null (no parent, parent picked, no
     * label, or a label the language cache does not know).
     *
     * @return {string|null}
     * @private
     */
    _erpEmptyParentLabel: function() {
        var parent = this._erpParentName();
        var key = this.def.erp_lookup_parent_empty_label;
        if (parent === null || !_.isString(key) || key === '' || !this.model) {
            return null;
        }
        if (this._erpText(this.model.get(parent)) !== '') {
            return null;
        }
        var text = app.lang.get(key, this.module || this.model.module);
        if (!_.isString(text) || text === '' || (text === key && /^LBL_/.test(key))) {
            return null;
        }
        return text;
    },

    /**
     * G809: the record's account id field named by
     * 'erp_prefill_from_account_latest', or null (not configured).
     *
     * @private
     */
    _erpPrefillAccountField: function() {
        var name = this.def && this.def.erp_prefill_from_account_latest;
        return _.isString(name) && name !== '' && name !== this.name ? name : null;
    },

    /**
     * G809: ask for the account's newest records holding this field (and the
     * parent, on a dependent field), up to erpPrefillScan of them. New records
     * only; empty fields only.
     *
     * @private
     */
    _erpPrefillFromAccount: function() {
        if (this.disposed || !this.model || !_.isFunction(this.model.isNew) || !this.model.isNew()) {
            return;
        }
        var accountField = this._erpPrefillAccountField();
        if (accountField === null) {
            return;
        }
        // The previous account's untouched prefill does not belong to this one.
        this._erpUndoPrefill();
        this._erpPrefillRows = null;
        var account = this._erpText(this.model.get(accountField));
        this._erpPrefillAccount = account;
        if (account === '' || this._erpText(this.model.get(this.name)) !== '' ||
            !app.api || !_.isFunction(app.api.records)) {
            return;
        }
        var parent = this._erpParentName();
        var byAccount = {};
        byAccount[accountField] = account;
        var holdsThis = {};
        holdsThis[this.name] = {'$not_empty': ''};
        var filter = [byAccount, holdsThis];
        var fields = ['id', this.name];
        if (parent !== null) {
            var holdsParent = {};
            holdsParent[parent] = {'$not_empty': ''};
            filter.push(holdsParent);
            fields.push(parent);
        }
        var self = this;
        app.api.records('read', this.module || this.model.module, {}, {
            filter: filter,
            order_by: 'date_entered:desc',
            max_num: this.erpPrefillScan,
            fields: fields.join(','),
        }, {
            success: function(data) {
                if (self.disposed || !self.model || self._erpPrefillAccount !== account ||
                    self._erpText(self.model.get(accountField)) !== account) {
                    return;   // the seller moved on to another account
                }
                var records = data && _.isArray(data.records) ? data.records : [];
                if (records.length > 0) {
                    self._erpPrefillRows = records;
                    self._erpApplyPrefill();
                }
            },
        });
    },

    /**
     * G809: fill from the account's answer, if the list has loaded (else it
     * waits for loadEnumOptions / _render). Empty fields only. The rows are
     * newest first; the FIRST usable one fills (_erpUsablePrefill), and a
     * dependent field fills its pair from that one record.
     *
     * @private
     */
    _erpApplyPrefill: function() {
        var rows = this._erpPrefillRows;
        if (!_.isArray(rows) || this.disposed || !this.model) {
            return;
        }
        var full = this._erpAllItems;
        if (!_.isObject(full)) {
            return;   // the list has not loaded yet
        }
        this._erpPrefillRows = null;
        if (this._erpText(this.model.get(this.name)) !== '') {
            return;   // the seller's own pick
        }
        var parent = this._erpParentName();
        var pick = null;
        for (var i = 0; i < rows.length && pick === null; i++) {
            pick = this._erpUsablePrefill(rows[i], full, parent);
        }
        if (pick === null) {
            return;
        }
        var prefilled = {};
        var setParent = parent !== null && this._erpText(this.model.get(parent)) === '';
        if (setParent) {
            prefilled[parent] = pick.parentValue;
        }
        prefilled[this.name] = pick.value;
        this._erpPrefilled = prefilled;
        if (setParent) {
            this.model.set(parent, pick.parentValue);
        }
        this.model.set(this.name, pick.value);
    },

    /**
     * G809: what one of the account's records would fill, or null when it
     * cannot be used and the next (older) record is tried: its value is empty
     * or not offered by this field's loaded list (a retired code); on a
     * dependent field also when its value does not belong to its own parent
     * value (quote #4's mismatch), when the parent's loaded list does not
     * offer that parent value, or when the seller already chose ANOTHER parent
     * value (a record is never mixed with the seller's choice).
     *
     * @param {Object} row
     * @param {Object} full This field's full loaded list.
     * @param {string|null} parent
     * @return {{value: string, parentValue: string}|null}
     * @private
     */
    _erpUsablePrefill: function(row, full, parent) {
        if (!_.isObject(row)) {
            return null;
        }
        var value = this._erpText(row[this.name]);
        if (value === '' || !_.has(full, value)) {
            return null;
        }
        if (parent === null) {
            return {value: value, parentValue: ''};
        }
        var parentValue = this._erpText(row[parent]);
        if (!this._erpBelongs(value, parentValue) || !this._erpParentOffers(parent, parentValue)) {
            return null;
        }
        var currentParent = this._erpText(this.model.get(parent));
        if (currentParent !== '' && currentParent !== parentValue) {
            return null;
        }
        return {value: value, parentValue: parentValue};
    },

    /**
     * G809: clear what this prefill set that the seller has not changed (the
     * account changed, so it belonged to another customer). This field first,
     * then its parent.
     *
     * @private
     */
    _erpUndoPrefill: function() {
        var prefilled = this._erpPrefilled;
        this._erpPrefilled = null;
        if (!_.isObject(prefilled) || !this.model) {
            return;
        }
        var names = [this.name];
        var parent = this._erpParentName();
        if (parent !== null) {
            names.push(parent);
        }
        for (var i = 0; i < names.length; i++) {
            var name = names[i];
            if (_.has(prefilled, name) && this._erpText(this.model.get(name)) === prefilled[name]) {
                this.model.set(name, '');
            }
        }
    },

    /**
     * G809: does the parent's loaded list offer this value? Unknown (the
     * parent's list is not in the context cache) counts as yes: this field's
     * own list only offers a key under a parent value it was built for.
     *
     * @private
     */
    _erpParentOffers: function(parent, value) {
        if (value === '') {
            return false;
        }
        var module = this.module || (this.model && this.model.module);
        var cached = this.context && _.isFunction(this.context.get)
            ? this.context.get('cache:' + module + ':' + parent + ':items') : null;
        return !_.isObject(cached) || _.has(cached, value);
    },

    /** @private */
    _erpParentName: function() {
        var parent = this.def && this.def.erp_lookup_parent;
        return _.isString(parent) && parent !== '' && parent !== this.name ? parent : null;
    },

    /** @private */
    _erpSeparator: function() {
        var separator = this.def && this.def.erp_lookup_parent_separator;
        return _.isString(separator) && separator !== '' ? separator : this.erpDefaultSeparator;
    },

    /** @private */
    _erpHasChoice: function(list) {
        return _.some(_.keys(list || {}), function(key) {
            return key !== '';
        });
    },

    /** PHP true reaches the client as true; tolerate the string forms too. @private */
    _erpFlag: function(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    },

    /** @private */
    _erpText: function(value) {
        return value === null || _.isUndefined(value) ? '' : String(value);
    },
})

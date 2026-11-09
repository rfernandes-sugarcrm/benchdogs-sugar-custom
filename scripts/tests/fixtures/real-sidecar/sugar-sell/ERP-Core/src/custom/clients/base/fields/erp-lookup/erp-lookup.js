/**
 * @class View.Fields.Base.ErpLookupField
 * @alias SUGAR.App.view.fields.BaseErpLookupField
 * @extends View.Fields.Base.RelateField
 *
 * G527 — AN ERP LOOKUP FIELD IS A SIMPLE DROPDOWN, NOT A RECORD SEARCH.
 *
 * Owner, 2026-09-24, over a screenshot of the Account FOB field opening a
 * search box and "Search and Select...": lookup tables should show as simple
 * drop downs. Every Epicor lookup (FOB, Ship Via, Billing Terms, Billing
 * Frequency, Credit Hold Reason, Territory on Accounts; FOB, Ship Via,
 * Billing Terms on Quotes) is a RELATE to ERP_LookupValues, so Sugar drew its
 * generic record picker for it.
 *
 * 🛑 DISPLAY ONLY. THE STORED VALUE IS EXACTLY WHAT THE PICKER STORED: the
 * relate's id (`id_name`) and its name, written through RelateField's own
 * setValue(). The vardef stays `relate`, so the sync, core's writers, the
 * Epicor code mapping, filters, reports and mass update are untouched. An
 * enum with a function list was rejected for G527 because it would change
 * the stored value and core's writer.
 *
 * WHAT THE SELLER GETS:
 *   - the list opens on click with no typing (minimumInputLength 0), holding
 *     the ACTIVE ERP_LookupValues of this field's `type` for the record's ERP
 *     company, by name;
 *   - a type-ahead box only when the list is long (16+ values by default,
 *     `searchBarThreshold` in the def overrides it, the same key Sugar's enum
 *     reads), and never a "Search and Select..." drawer;
 *   - a current value that has since gone inactive in the ERP still shows,
 *     marked "(Inactive)", and is never cleared by opening the field;
 *   - no values at all reads "No values synced from the ERP", not a blank box;
 *   - read views show the name as plain text (a lookup value is a choice, not
 *     a record to open).
 *
 * WHERE THE LIST COMES FROM, MEASURED (stock QA ossugarcube2, 2026-09-24,
 * read-only REST): ERP_LookupValues.`type` is an enum column
 * (BillingTerms | ShippingTerms | Territories | FOB | BillingFrequency |
 * CreditHoldReason | Country, erp_lookup_type_list); the owning company is
 * the one-to-many erp_companies_erp_lookupvalues link, filterable through
 * its id field erp_companies_erp_lookupvalueserp_companies_ida - a filter on
 * the real company returned 6 FOB rows, on an unknown company id 0, so the
 * filter is applied, not ignored. `is_active` is a bool.
 *
 * ONE REQUEST PER VIEW AND COMPANY. Every erp-lookup field of a view asks the
 * view's store; the store batches what was asked in the same tick into one
 * `type $in [...]` read and caches it for the view's life. A record view of
 * an Account costs one GET for its six fields, and its Edit costs none.
 *
 * THE COMPANY is read from `erp_lookup_company_field` (set by
 * custom/include/javascript/sugar7/erp-lookup-fields.js, which also keeps it
 * fetched). G511: when "ERP Company" is hidden on a one-company tenant the
 * value is still fetched, a new record defaults to it, and if a record has
 * none the published single company is used. With no company at all the
 * list is filtered by type only - exact on a one-company tenant.
 *
 * G291(b) PARITY: choosing a value clears a stale "required" error at once
 * (Territory is required on a Customer). The Accounts relate override that
 * did this for Territory no longer draws Territory, so the rule lives here.
 */
({
    extendsFrom: 'RelateField',

    /**
     * Module every lookup value lives in.
     * @property {string}
     */
    lookupModule: 'ERP_LookupValues',

    /**
     * ERP_LookupValues field carrying the owning ERP company's id.
     * @property {string}
     */
    lookupCompanyFilterField: 'erp_companies_erp_lookupvalueserp_companies_ida',

    /**
     * Show the type-ahead box from this many values up. Territory is the
     * long list (select2 shows the box when the count is >= this number).
     * @property {number}
     */
    defaultSearchThreshold: 16,

    /**
     * Page size of the lookup read and how many pages are followed.
     * @property {number}
     */
    lookupPageSize: 200,
    lookupMaxPages: 10,

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this._super('initialize', [options]);

        // A lookup value is a dropdown choice, not a record to open: no link
        // in read views (RelateField.buildRoute() returns early without it and
        // never re-points this.module at ERP_LookupValues).
        this.viewDefs = _.extend({}, this.viewDefs || {}, {link: false});

        /**
         * Whether the stored value is a lookup the ERP has since deactivated.
         * @property {boolean}
         */
        this.erpLookupInactive = false;

        if (!this.lookupType() && app.logger && _.isFunction(app.logger.error)) {
            app.logger.error('erp-lookup: ' + this.name + ' declares no erp_lookup_type; no values are loaded.');
        }
    },

    // ------------------------------------------------------------------------
    // What this field is and which list it reads
    // ------------------------------------------------------------------------

    /**
     * The ERP_LookupValues `type` this field holds (FOB, ShippingTerms, ...).
     * @return {string}
     */
    lookupType: function() {
        var defs = this.def || {};
        return defs.erp_lookup_type ? String(defs.erp_lookup_type) : '';
    },

    /**
     * The record field that carries its ERP company id.
     * @return {string}
     */
    lookupCompanyField: function() {
        var defs = this.def || {};
        return defs.erp_lookup_company_field ? String(defs.erp_lookup_company_field) : '';
    },

    /**
     * The record's ERP company id; the published single company (G511) when
     * the record has none; '' when neither is known.
     * @return {string}
     */
    lookupCompanyId: function() {
        var field = this.lookupCompanyField();
        var id = field && this.model && _.isFunction(this.model.get) ? this.model.get(field) : '';
        if (!id && app.erpSingleCompany && _.isFunction(app.erpSingleCompany.singleCompany)) {
            var one = app.erpSingleCompany.singleCompany();
            id = one && one.id ? one.id : '';
        }
        return id ? String(id) : '';
    },

    /**
     * The stored lookup id.
     * @return {string}
     */
    currentLookupId: function() {
        var idName = this.def && this.def.id_name;
        var id = idName && this.model && _.isFunction(this.model.get) ? this.model.get(idName) : '';
        return id ? String(id) : '';
    },

    /**
     * The select2 search-box threshold.
     * @return {number}
     */
    searchThreshold: function() {
        var value = parseInt(this.def && this.def.searchBarThreshold, 10);
        return value > 0 ? value : this.defaultSearchThreshold;
    },

    // ------------------------------------------------------------------------
    // Loading: one batched read per view and company
    // ------------------------------------------------------------------------

    /**
     * The view-scoped cache every erp-lookup field of one view shares.
     * @return {Object}
     */
    _lookupStore: function() {
        var host = this.view || this;
        if (!host._erpLookupStore) {
            host._erpLookupStore = {entries: {}, pending: {}};
        }
        return host._erpLookupStore;
    },

    /**
     * The loaded entry for this field's type and company, or null while it
     * is not loaded yet.
     * @return {Object|null}
     */
    _readyLookupEntry: function() {
        var type = this.lookupType();
        if (!type) {
            return null;
        }
        var entry = this._lookupStore().entries[this.lookupCompanyId() + '|' + type];
        return entry && entry.status !== 'loading' ? entry : null;
    },

    /**
     * Calls back with this field's entry once it is loaded (at once when it
     * already is). A field with no type gets a failed, empty entry. A failed
     * read is not cached: the next request (the next open) tries again.
     *
     * @param {Function} callback Receives the entry.
     */
    requestLookupValues: function(callback) {
        var type = this.lookupType();
        if (!type) {
            callback({status: 'error', type: '', company: '', items: []});
            return;
        }
        var company = this.lookupCompanyId();
        var store = this._lookupStore();
        var key = company + '|' + type;
        var entry = store.entries[key];
        if (entry && entry.status === 'error') {
            delete store.entries[key];
            entry = null;
        }
        if (entry && entry.status !== 'loading') {
            callback(entry);
            return;
        }
        if (!entry) {
            entry = store.entries[key] = {status: 'loading', type: type, company: company, items: [], waiters: []};
            var batch = store.pending[company];
            if (!batch) {
                batch = store.pending[company] = [];
                _.defer(_.bind(this._flushLookupBatch, this, store, company));
            }
            batch.push(type);
        }
        entry.waiters.push(callback);
    },

    /**
     * One read for every type asked for this company in the same tick.
     *
     * @param {Object} store The view's store.
     * @param {string} company The company id ('' = no company filter).
     * @private
     */
    _flushLookupBatch: function(store, company) {
        var types = _.uniq(store.pending[company] || []);
        delete store.pending[company];
        if (!types.length) {
            return;
        }

        var filter = [{type: {'$in': types}}];
        if (company) {
            var byCompany = {};
            byCompany[this.lookupCompanyFilterField] = {'$equals': company};
            filter.push(byCompany);
        }

        var self = this;
        var rows = [];
        var settle = function(status) {
            _.each(types, function(type) {
                var entry = store.entries[company + '|' + type];
                if (!entry) {
                    return;
                }
                entry.status = status;
                entry.items = self._lookupItems(rows, type);
                var waiters = entry.waiters || [];
                entry.waiters = [];
                _.each(waiters, function(waiter) {
                    try {
                        waiter(entry);
                    } catch (e) {
                        if (app.logger && _.isFunction(app.logger.error)) {
                            app.logger.error('erp-lookup: a field failed to take its values: ' + e);
                        }
                    }
                });
            });
        };
        var readPage = function(offset, page) {
            var params = {
                filter: filter,
                fields: 'id,name,type,is_active,erp_display_sync_key',
                max_num: self.lookupPageSize,
                order_by: 'name:asc'
            };
            if (offset) {
                params.offset = offset;
            }
            app.api.call('read', app.api.buildURL(self.lookupModule, null, null, params), null, {
                success: function(data) {
                    rows = rows.concat((data && data.records) || []);
                    var next = data && data.next_offset;
                    if (next > 0 && page + 1 < self.lookupMaxPages) {
                        readPage(next, page + 1);
                        return;
                    }
                    settle('ready');
                },
                error: function() {
                    if (app.logger && _.isFunction(app.logger.error)) {
                        app.logger.error('erp-lookup: unable to load ' + types.join(', ') + ' values');
                    }
                    settle('error');
                }
            });
        };
        readPage(0, 0);
    },

    /**
     * Rows of one type as dropdown items, in the server's name order.
     *
     * @param {Array} rows ERP_LookupValues records.
     * @param {string} type The type to keep.
     * @return {Array} [{id, name, code, active}]
     * @private
     */
    _lookupItems: function(rows, type) {
        var items = [];
        _.each(rows, function(row) {
            if (!row || !row.id || String(row.type) !== type) {
                return;
            }
            var active = row.is_active;
            items.push({
                id: String(row.id),
                name: String(row.name || '').trim(),
                code: String(row.erp_display_sync_key || '').trim(),
                active: !(active === false || active === 0 || active === '0')
            });
        });
        return items;
    },

    /**
     * An item's label: its name; the code when it has no name; name plus code
     * when another active value carries the same name.
     *
     * @param {Object} item
     * @param {Array} items All items of the list.
     * @return {string}
     */
    lookupLabel: function(item, items) {
        var name = item.name || item.code || item.id;
        if (!item.name || !item.code) {
            return name;
        }
        var twins = _.filter(items || [], function(other) {
            return other.active && other.name === item.name;
        });
        return twins.length > 1 ? name + ' (' + item.code + ')' : name;
    },

    /**
     * The dropdown's choices: every active value, and the current value when
     * it is not one of them (marked inactive when the ERP says it is), so
     * opening the field never loses what is stored.
     *
     * @param {Object} entry A loaded entry.
     * @return {Array} [{id, text, inactive}]
     */
    lookupOptions: function(entry) {
        var items = (entry && entry.items) || [];
        var options = [];
        _.each(items, function(item) {
            if (item.active) {
                options.push({id: item.id, text: this.lookupLabel(item, items)});
            }
        }, this);

        var currentId = this.currentLookupId();
        if (currentId && !_.findWhere(options, {id: currentId})) {
            var known = _.findWhere(items, {id: currentId});
            var stored = this.model && _.isFunction(this.model.get) ? this.model.get(this.name) : '';
            var text = known ? this.lookupLabel(known, items) : String(stored || currentId);
            var inactive = !!(known && !known.active);
            options.unshift({
                id: currentId,
                text: inactive ? text + ' ' + app.lang.get('LBL_ERP_LOOKUP_INACTIVE', this.module) : text,
                inactive: inactive
            });
        }
        return options;
    },

    /**
     * Is the stored value one the ERP has deactivated? False until known.
     *
     * @param {Object|null} entry
     * @return {boolean}
     */
    isCurrentInactive: function(entry) {
        var currentId = this.currentLookupId();
        if (!entry || entry.status !== 'ready' || !currentId) {
            return false;
        }
        var known = _.findWhere(entry.items || [], {id: currentId});
        return !!(known && !known.active);
    },

    // ------------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------------

    /**
     * @inheritdoc
     *
     * Also works out the inactive marker from what the view already loaded.
     */
    format: function(value) {
        value = this._super('format', [value]);
        this.erpLookupInactive = this.isCurrentInactive(this._readyLookupEntry());
        return value;
    },

    /**
     * RelateField._render() calls this INSTEAD of its own dropdown code when
     * it is defined: no Filters fetch, no search collection, no drawer.
     */
    customDropdownRender: function() {
        switch (this.tplName) {
            case 'edit':
            case 'massupdate':
                this._renderLookupDropdown();
                this.requestLookupValues(_.bind(this._onLookupValues, this));
                break;
            case 'disabled':
                this._renderDisabledDropdown();
                break;
            default:
                // Read views load only to mark an inactive stored value.
                if (this.currentLookupId()) {
                    this.requestLookupValues(_.bind(this._onLookupValues, this));
                }
        }
    },

    /**
     * The select2 dropdown, bound to change only. No `select2-open` handler:
     * that is where RelateField appends "Search and Select...".
     * @private
     */
    _renderLookupDropdown: function() {
        var $dropdown = this.$(this.fieldTag);
        if (!$dropdown || !$dropdown.length || $dropdown.data('select2')) {
            return;
        }
        $dropdown.select2(this._getSelect2Options())
            .on('change', _.bind(this._onSelect2Change, this));

        var plugin = $dropdown.data('select2');
        if (plugin && plugin.focusser && _.isFunction(this.handleFocus)) {
            plugin.focusser.on('select2-focus', _.bind(_.debounce(this.handleFocus, 0), this));
        }
        if (_.isFunction(this.setupSelect2Accessibility)) {
            this.setupSelect2Accessibility(plugin);
        }
    },

    /**
     * @inheritdoc
     *
     * A local list: open on click, filter only when the list is long.
     */
    _getSelect2Options: function() {
        var options = this._super('_getSelect2Options');
        return _.extend(options, {
            minimumInputLength: 0,
            minimumResultsForSearch: this.searchThreshold(),
            query: _.bind(this._queryLookup, this),
            formatNoMatches: _.bind(this._formatNoMatches, this),
            placeholder: this.lookupPlaceholder()
        });
    },

    /**
     * @inheritdoc
     *
     * RelateField's "Search <module>..." reads as a record search; the
     * disabled dropdown (RelateField._renderDisabledDropdown) asks this too.
     */
    getPlaceHolder: function() {
        return this.lookupPlaceholder();
    },

    /**
     * 'Select...' - or, once the view knows the list is empty and nothing is
     * stored, 'No values synced from the ERP'.
     * @return {string}
     */
    lookupPlaceholder: function() {
        var entry = this._readyLookupEntry();
        if (entry && entry.status === 'ready' && !this.lookupOptions(entry).length) {
            return app.lang.get('LBL_ERP_LOOKUP_NONE_SYNCED', this.module);
        }
        return app.lang.get('LBL_SEARCH_SELECT', this.module);
    },

    /**
     * select2 `query`: the loaded list, narrowed by what was typed.
     *
     * @param {Object} query select2's query ({term, callback}).
     * @private
     */
    _queryLookup: function(query) {
        this.requestLookupValues(_.bind(function(entry) {
            if (this.disposed) {
                return;
            }
            this._lastLookupEntry = entry;
            var term = String(query.term || '').toLowerCase();
            var results = _.filter(this.lookupOptions(entry), function(option) {
                return !term || option.text.toLowerCase().indexOf(term) !== -1;
            });
            if (_.isFunction(query.callback)) {
                query.callback({results: results, more: false});
            }
        }, this));
    },

    /**
     * select2 `formatNoMatches`.
     *
     * @param {string} term What was typed.
     * @return {string}
     * @private
     */
    _formatNoMatches: function(term) {
        var entry = this._lastLookupEntry || this._readyLookupEntry();
        if (entry && entry.status === 'error') {
            return app.lang.get('LBL_ERP_LOOKUP_LOAD_FAILED', this.module);
        }
        if (!term && (!entry || !this.lookupOptions(entry).length)) {
            return app.lang.get('LBL_ERP_LOOKUP_NONE_SYNCED', this.module);
        }
        return app.lang.get('LBL_ERP_LOOKUP_NO_MATCHES', this.module);
    },

    /**
     * The view's values arrived: show the empty state in edit, the inactive
     * marker in read views.
     *
     * @param {Object} entry
     * @private
     */
    _onLookupValues: function(entry) {
        if (this.disposed) {
            return;
        }
        if (this.tplName === 'edit' || this.tplName === 'massupdate') {
            var $dropdown = this.$(this.fieldTag);
            var plugin = $dropdown && $dropdown.length ? $dropdown.data('select2') : null;
            if (plugin && plugin.opts && _.isFunction(plugin.setPlaceholder)) {
                plugin.opts.placeholder = this.lookupPlaceholder();
                plugin.setPlaceholder();
            }
            return;
        }
        var inactive = this.isCurrentInactive(entry);
        if (inactive !== this.erpLookupInactive) {
            this.erpLookupInactive = inactive;
            this.render();
        }
    },

    /**
     * @inheritdoc
     *
     * Stores the choice exactly as RelateField does - setValue() with the id
     * and the name - from the loaded list instead of a search collection.
     */
    _onSelect2Change: function(e) {
        var id = e && e.val;
        if (_.isUndefined(id)) {
            return;
        }
        if (!id) {
            this.setValue({id: '', name: '', value: ''});
            return;
        }
        var entry = this._lastLookupEntry || this._readyLookupEntry();
        var item = _.findWhere((entry && entry.items) || [], {id: String(id)});
        var name = item ? item.name || item.code : '';
        if (!item && String(id) === this.currentLookupId()) {
            name = this.model.get(this.name) || '';
        }
        this.setValue({id: String(id), name: name, value: name});
    },

    /**
     * @inheritdoc
     *
     * Only this field's own value and its company matter here; RelateField's
     * re-reading of filter options on every model change does not apply.
     */
    bindDataChange: function() {
        if (!this.model) {
            return;
        }
        this.model.on('change:' + this.name, this._updateField, this);

        var idName = this.def && this.def.id_name;
        var events = ['change:' + this.name];
        if (idName && idName !== this.name) {
            events.push('change:' + idName);
        }
        this.model.on(events.join(' '), this.clearRequiredErrorOnceFilled, this);

        var companyField = this.lookupCompanyField();
        if (companyField) {
            this.model.on('change:' + companyField, this._onCompanyChange, this);
        }
    },

    /**
     * Another company, another list: redraw so the next open reads it.
     * @private
     */
    _onCompanyChange: function() {
        if (this.disposed) {
            return;
        }
        this._lastLookupEntry = null;
        this.render();
    },

    // ------------------------------------------------------------------------
    // A required lookup: no "Required" text, only Sugar's red on a failed Save
    // ------------------------------------------------------------------------

    /**
     * A required lookup carries no "Required" text, before or after a Save. Sugar's field.js draws it from here: the
     * "(Required)" placeholder (or label) on every render of a required field, and again after a failed Save for a
     * field flagged no_required_placeholder. Draws nothing; Sugar's own check still refuses the Save and turns the
     * field red with its own message. Every erp-lookup field is one of the ERP lookups these packages add.
     *
     * @override
     */
    decorateRequired: function() {},

    // ------------------------------------------------------------------------
    // G291(b) parity: a pick clears a stale required error
    // ------------------------------------------------------------------------

    /**
     * Mirrors sidecar's requiredValidator(): a set id satisfies a relate on
     * its own; failing that, a non-empty name does.
     * @return {boolean}
     */
    hasSatisfyingValue: function() {
        if (this.currentLookupId()) {
            return true;
        }
        var value = this.model && _.isFunction(this.model.get) ? this.model.get(this.name) : '';
        return !(value === null || value === undefined || value === '' || value === false);
    },

    /**
     * Drops the stale `required` error once the field holds a value, and
     * repaints whatever other error is left.
     */
    clearRequiredErrorOnceFilled: function() {
        if (!this._errors || !this._errors.required || !this.hasSatisfyingValue()) {
            return;
        }
        delete this._errors.required;
        if (_.isFunction(this.clearErrorDecoration)) {
            this.clearErrorDecoration();
        }
        if (_.keys(this._errors).length && _.isFunction(this.decorateError)) {
            this.decorateError(this._errors);
        }
    }
})

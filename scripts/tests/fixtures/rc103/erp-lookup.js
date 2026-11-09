// NOT SHIPPED. sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js as BenchDogs-Ext 0.9.46-rc103 shipped it (source c6b0e222, sha256 e10cf0aa15d0af9a09438538d9657a76ca207591310456e97075dc40befffdaa of the shipped file), word for word on the lines below. The CONTROL of ship_via_required_on_real_sidecar.cjs: it draws "(Required)" beside Ship Via and never makes it required.
/**
 * @class View.Fields.Base.Quotes.ErpLookupField
 * @alias SUGAR.App.view.fields.BaseQuotesErpLookupField
 * @extends View.Fields.Base.ErpLookupField
 *
 * On a quote's record view and on the create form of a new quote, Ship Via says "(Required)" beside its label while
 * Submit Order would refuse the quote for want of it, so the seller sees it before pressing the button. FOB's marker
 * is ERP-Epicor's own (its quote checks field, from the company's "order requires FOB" switch): this field draws
 * none, so the FOB label never carries a second one, nor one the switch does not ask for.
 *
 * ONLY A MARKER. The field is never made required: def.required is never set, so Sugar's save check never looks at
 * it and Save is never refused for it. Submit Order's own refusal stays the rule. The marker is Sugar's own label
 * marker (setRequiredLabel / clearRequiredLabel, which Sugar draws for fields that have no placeholder), so it reads
 * like every other required field and shows in the record view, in its edit mode and on the create form.
 *
 * The browser cannot ask the refusal, so its conditions are restated here, from what the quote and three standard
 * reads (its billing account, that account's ERP company, one probe for the companies that get ADM's rules) hold.
 * The company is the billing account's, as the server rules read it (ERP-Epicor's ErpQuoteFacts), not the quote's
 * own company field, which a new quote may not have yet.
 * "An ADM company" is one the Bench connector extension publishes as an active BdAdmCompanies row, from the same
 * setting that registers its own send-time refusals, so the marker and those refusals decide from one source.
 *   - Ship Via (this package's refusal, and the extension's): the ERP company is an ADM company, the quote has no
 *     Ship Via, and either the quote is ordered from its ERP quote (an Advanced Quote that has one: the account's
 *     default does not count there) or its billing account has no default Ship Via.
 * A closed quote shows none. Any read that fails, or is refused, shows none: the refusal still stands at Submit
 * Order. Only the record view of a saved quote and the create form of a new one read anything; every other view and
 * every other ERP lookup on Quotes is ERP-Core's field, unchanged.
 *
 * This controller is a module field, which Sugar serves with the module's metadata, so a package install reaches the
 * browser on its next load. It needs no file in a script bundle.
 */
({
    extendsFrom: 'ErpLookupField',

    /**
     * The quote lookups an order is refused without, by the quote's id field, each with the billing account's
     * default of the same lookup and the refusals that ask for it. A refusal applies on an ADM company (admCompany);
     * erpQuoteDecides means an order converted from the quote's ERP quote is refused whatever the account's default.
     * A copy of the server rule's field names (this package's BdAdmOrderRequirements): the browser cannot read them,
     * and test_quote_needed_markers.py holds this copy to it, both ways. FOB is not here: ERP-Epicor draws its marker.
     * @property {Object}
     */
    bdOrderLookups: {
        erp_quotes_ship_viaerp_lookupvalues_idb: {
            accountField: 'erp_ship_viaerp_lookupvalues_idb',
            refusals: [
                {admCompany: true, erpQuoteDecides: true}
            ]
        }
    },

    /**
     * The lookup type whose active rows name the companies that get ADM's rules (BdAdmRules::TYPE_ADM_COMPANIES).
     * @property {string}
     */
    bdAdmCompanyType: 'BdAdmCompanies',

    /**
     * The quote type ordered from its ERP quote (ERP-Epicor's erp_quote_type key).
     * @property {string}
     */
    bdErpQuoteType: 'advanced_quote',

    /**
     * The billing account's ERP company id field: the company the server rules read (ErpQuoteFacts reads the
     * account's, never the quote's own). test_quote_needed_markers.py holds it to ErpQuoteFacts.
     * @property {string}
     */
    bdAccountCompanyField: 'erp_companies_accountserp_companies_ida',

    /**
     * The views this marker is drawn on, each with the quote it draws: the record view of a saved quote, the create
     * form of a new one.
     * @property {Object}
     */
    bdViews: {record: 'saved', create: 'new'},

    /**
     * A closed quote stage starts with this, in any case (ERP-Core's ErpQuoteClosedStages).
     * @property {string}
     */
    bdClosedStagePrefix: 'closed ',

    /**
     * @inheritdoc
     *
     * The marker is judged again when anything it reads changes.
     */
    bindDataChange: function() {
        this._super('bindDataChange');
        if (!this.model || !this.bdOrderLookup()) {
            return;
        }
        var events = [
            'change:' + this.name,
            'change:' + this.def.id_name,
            'change:billing_account_id',
            'change:quote_stage',
            'change:erp_quote_type',
            'change:erp_display_sync_key',
            'sync'
        ];
        this.model.on(events.join(' '), this.bdShowNeeded, this);
    },

    /**
     * @inheritdoc
     *
     * Every render draws the marker afresh: the label sits outside the field's own element.
     */
    _render: function() {
        this._super('_render');
        this.bdShowNeeded();
        return this;
    },

    /**
     * This field's order rule, or null for every other lookup.
     * @return {Object|null}
     */
    bdOrderLookup: function() {
        var idName = this.def && this.def.id_name;
        return idName && this.bdOrderLookups.hasOwnProperty(idName) ? this.bdOrderLookups[idName] : null;
    },

    /**
     * Clears the marker, then draws it again once the reads say Submit Order would refuse for this field.
     */
    bdShowNeeded: function() {
        if (this.disposed || !this.bdOrderLookup() || (this.def && this.def.required)) {
            // A field something else made required carries Sugar's own marker; this one stays out of its way.
            return;
        }
        this.clearRequiredLabel();
        var turn = this.bdTurn = (this.bdTurn || 0) + 1;
        if (!this.bdOnQuoteForm()) {
            return;
        }
        var self = this;
        this.bdJudge(function(needed) {
            if (self.disposed || turn !== self.bdTurn || !needed) {
                return;
            }
            self.clearRequiredLabel();
            self.setRequiredLabel();
        });
    },

    /**
     * True on the record view of a saved quote and on the create form of a new one.
     * @return {boolean}
     */
    bdOnQuoteForm: function() {
        var wanted = this.view && this.model && this.bdViews.hasOwnProperty(this.view.name) ?
            this.bdViews[this.view.name] : '';
        if (!wanted) {
            return false;
        }
        var isNew = typeof this.model.isNew === 'function' ? this.model.isNew() : !this.model.id;
        return wanted === (isNew ? 'new' : 'saved');
    },

    /**
     * Calls back true when Submit Order would refuse this quote for want of this field, false otherwise or when
     * any read fails.
     * @param {Function} done
     */
    bdJudge: function(done) {
        var rule = this.bdOrderLookup();
        var model = this.model;
        if (this.bdIsClosedStage(model.get('quote_stage')) || this.hasSatisfyingValue()) {
            done(false);
            return;
        }
        var accountId = this.bdText(model.get('billing_account_id'));
        if (!accountId) {
            done(false);
            return;
        }
        var self = this;
        this.bdRecord('Accounts', accountId, this.bdAccountFields(), function(account) {
            var companyId = account ? self.bdText(account[self.bdAccountCompanyField]) : '';
            if (!companyId) {
                done(false);
                return;
            }
            self.bdJudgeFor(rule, account, companyId, done);
        });
    },

    /**
     * The decision once the billing account is read: whether its company is an ADM company.
     * @param {Object} rule this field's order rule
     * @param {Object} account the billing account's fields
     * @param {string} companyId the account's ERP company id
     * @param {Function} done
     */
    bdJudgeFor: function(rule, account, companyId, done) {
        var self = this;
        this.bdRecord('ERP_Companies', companyId, ['erp_sync_key'], function(company) {
            if (!company) {
                done(false);
                return;
            }
            var decide = function(applying) {
                if (!applying.length) {
                    done(false);
                    return;
                }
                for (var j = 0; j < applying.length; j++) {
                    if (applying[j].erpQuoteDecides && self.bdOrderedFromErpQuote()) {
                        done(true);
                        return;
                    }
                }
                done(!self.bdText(account[rule.accountField]));
            };
            self.bdIsAdmCompany(self.bdText(company.erp_sync_key), function(isAdm) {
                decide(isAdm ? rule.refusals : []);
            });
        });
    },

    /**
     * Is this a quote ordered from its ERP quote? ERP-Epicor's own test: an Advanced Quote with an ERP quote key.
     * @return {boolean}
     */
    bdOrderedFromErpQuote: function() {
        var model = this.model;
        return model.get('erp_quote_type') === this.bdErpQuoteType &&
            !!(this.bdText(model.get('erp_display_sync_key')) || this.bdText(model.get('erp_sync_key')));
    },

    /**
     * @param {*} stage
     * @return {boolean}
     */
    bdIsClosedStage: function(stage) {
        return this.bdText(stage).toLowerCase().indexOf(this.bdClosedStagePrefix) === 0;
    },

    /**
     * @param {*} value
     * @return {string} the value trimmed, '' for nothing
     */
    bdText: function(value) {
        return value === null || value === undefined ? '' : String(value).replace(/^\s+|\s+$/g, '');
    },

    /** @return {string[]} the account's ERP company and every default the rules read, in one read */
    bdAccountFields: function() {
        var out = [this.bdAccountCompanyField];
        for (var idName in this.bdOrderLookups) {
            if (this.bdOrderLookups.hasOwnProperty(idName)) {
                out.push(this.bdOrderLookups[idName].accountField);
            }
        }
        return out;
    },

    /**
     * Calls back with the record's fields, or null when it cannot be read.
     * @param {string} module
     * @param {string} id
     * @param {string[]} fields
     * @param {Function} callback
     */
    bdRecord: function(module, id, fields, callback) {
        var url = app.api.buildURL(module, null, {id: id}, {fields: fields.join(',')});
        this.bdRead(module + ':' + id, url, function(data) {
            callback(data && typeof data === 'object' ? data : null);
        });
    },

    /**
     * Calls back true when the connector extension publishes the company as one that gets ADM's rules (an active
     * BdAdmCompanies row whose key starts "<company>__", read as BdAdmRules::admCompanies() reads it), false
     * otherwise or when the read fails.
     * @param {string} company
     * @param {Function} callback
     */
    bdIsAdmCompany: function(company, callback) {
        if (!company) {
            callback(false);
            return;
        }
        var url = app.api.buildURL('ERP_LookupValues', null, null, {
            filter: [{type: this.bdAdmCompanyType}, {erp_sync_key: {$starts: company + '__'}}, {is_active: 1}],
            fields: 'id',
            max_num: 1
        });
        this.bdRead('adm-company:' + company, url, function(data) {
            callback(!!(data && data.records && data.records.length));
        });
    },

    /**
     * One read per key for the view's life, shared by every marked field (the same shape as ERP-Core's lookup
     * store). A failed read is not kept, so the next render asks again; its waiters get null.
     * @param {string} key
     * @param {string} url
     * @param {Function} callback
     */
    bdRead: function(key, url, callback) {
        var host = this.view || this;
        var store = host._bdOrderFacts || (host._bdOrderFacts = {});
        var entry = store[key];
        if (entry && entry.done) {
            callback(entry.value);
            return;
        }
        if (!entry) {
            entry = store[key] = {done: false, value: null, waiters: []};
            var finish = function(value, keep) {
                if (keep) {
                    entry.done = true;
                    entry.value = value;
                } else if (store[key] === entry) {
                    delete store[key];
                }
                var waiters = entry.waiters;
                entry.waiters = [];
                for (var i = 0; i < waiters.length; i++) {
                    waiters[i](value);
                }
            };
            app.api.call('read', url, null, {
                success: function(data) {
                    finish(data || null, true);
                },
                error: function() {
                    finish(null, false);
                }
            });
        }
        entry.waiters.push(callback);
    }
})

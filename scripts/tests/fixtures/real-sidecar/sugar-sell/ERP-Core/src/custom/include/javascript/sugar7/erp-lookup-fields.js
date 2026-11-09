/**
 * G527 — THE NINE ERP LOOKUP RELATES ARE DRAWN AS `erp-lookup` DROPDOWNS ON
 * EVERY VIEW, WITHOUT WRITING A VIEWDEF ON THE TENANT.
 *
 * Owner, 2026-09-24: lookup tables should show as simple drop downs, not a
 * record search. The field that draws them is
 * custom/clients/base/fields/erp-lookup (see its docblock for the behaviour).
 * This file decides WHERE it is used: it edits the in-memory metadata the
 * way custom/include/javascript/sugar7/erp-single-company.js (G511) does.
 *
 * WHICH FIELDS. The nine relates to ERP_LookupValues below, each with the
 * ERP_LookupValues `type` it holds - the discriminator core's writers use for
 * the same field (connector_base transformers: accounts.py lookup_id(...,
 * "FOB", ...), quotes, orders) and the sugarfield_erp_*_name.php prefilter vardefs
 * for the picker filter. Any other relate to ERP_LookupValues joins by declaring
 * `erp_lookup_type` on its own vardef; nothing else is touched.
 *
 * ADDRESS COUNTRIES. The billing and shipping country of Accounts and Quotes
 * (where the module has them) are drawn as `erp-country`
 * (custom/clients/base/fields/erp-country: the ERP's active countries, the
 * current value always kept, a text box when the tenant has no countries).
 * They are found from the vardefs, not listed: a varchar whose name is its
 * address group plus `_country` (billing_address_country in group
 * billing_address), the shape Sugar declares them in. Those vardefs stay
 * varchar, so the column, the save, filters, reports and the sync are
 * unchanged; only the views draw a list. An entry typed as anything but
 * the text box Sidecar gives a varchar is left alone, as below.
 *
 * WHAT CHANGES. On every view of the module (record - which the create view
 * reads too - list, preview, selection lists, subpanels, dashlets, and the
 * dropdown-based record views) the field's ENTRY gets
 *     type: 'erp-lookup', erp_lookup_type: <type>,
 *     erp_lookup_company_field: <the record's ERP company id field>,
 *     related_fields: + that company id field (so it is always fetched).
 * Sidecar's _patchFields() keeps an explicit entry type
 * (metadata-manager.js: field.type = field.type || fieldDef.type), so this is
 * what the view builds. An entry already typed as something other than
 * `relate` is someone's deliberate choice and is left alone.
 *
 * 🛑 WHAT DOES NOT CHANGE. The VARDEF stays `relate`, so everything that reads
 * the vardef keeps working exactly as today: the stored value (id_name + name),
 * view.getFieldNames() fetching the id (it tests the VARDEF type), SugarLogic,
 * the filter builder (Filters reads module.filters and vardefs, not views),
 * mass update, reports and the sync. `custom_type` was NOT used for this: the
 * server reads it too (SugarFieldHandler, FilterApi, DBManager), which would
 * have changed how the value is saved and filtered.
 *
 * WHY NOT THE INSTALL SCRIPTS. AccountsLayout/QuotesLayout run in replace
 * mode and would carry a `type` into the record panels they own, but only
 * there: the list, preview, selection-list, subpanel and dashlet views use
 * add-if-absent helpers (a changed entry never reaches a tenant that has it,
 * vault L-0009), and a field an admin moved into another panel or view in
 * Studio is missed. Here the change follows the field wherever it is placed,
 * reaches an upgraded tenant on its next page load, writes no viewdef, and is
 * gone with the package. The price: Studio and the deployed viewdef still
 * read `relate` - the dropdown is decided in the browser, here.
 *
 * WHEN. On `app:sync:complete`, and at once if the app has already synced.
 * Idempotent; a sync that brings fresh metadata replaces the modules and this
 * runs again. Mass update is left as the stock picker (out of G527's scope).
 *
 * NEVER THROWS: it runs inside the sync event, where an exception would stop
 * the listeners after it and the callbacks that start the router.
 *
 * Ships in the SIDECAR bundle (ErpCoreJSGroupings.php) like the G511 file,
 * so the listener exists before the first sync. ES5 only.
 */
(function(app) {
    'use strict';

    if (!app || !app.events || typeof app.events.on !== 'function') {
        return;
    }

    var FIELD_TYPE = 'erp-lookup';
    var LOOKUP_MODULE = 'ERP_LookupValues';
    var COMPANY_MODULE = 'ERP_Companies';
    var COUNTRY_FIELD_TYPE = 'erp-country';

    /**
     * The modules whose address countries are offered as the ERP's country
     * list. Their country fields are read from the vardefs (countryRetypes).
     */
    var COUNTRY_MODULES = ['Accounts', 'Quotes'];

    /**
     * module -> relate field -> ERP_LookupValues.type it holds.
     */
    var LOOKUPS = {
        Accounts: {
            erp_fob_name: 'FOB',
            erp_ship_via_name: 'ShippingTerms',
            erp_billing_terms_name: 'BillingTerms',
            erp_billing_frequency_name: 'BillingFrequency',
            erp_credit_hold_reason_name: 'CreditHoldReason',
            erp_territory_name: 'Territories'
        },
        Quotes: {
            erp_quotes_fob_name: 'FOB',
            erp_quotes_ship_via_name: 'ShippingTerms',
            erp_quotes_billing_terms_name: 'BillingTerms'
        }
    };

    /** A module's lookup relates, as {name: type}. */
    function lookupFieldsOf(module, fields) {
        var known = LOOKUPS[module] || {};
        var out = {};
        _.each(fields || {}, function(def, key) {
            if (!def || typeof def !== 'object' || def.type !== 'relate' || def.module !== LOOKUP_MODULE) {
                return;
            }
            var name = def.name || key;
            var type = def.erp_lookup_type || known[name];
            if (type) {
                out[name] = String(type);
            }
        });
        return out;
    }

    /** The id field of the module's relate to ERP_Companies, or ''. */
    function companyIdField(fields) {
        var found = '';
        _.each(fields || {}, function(def) {
            if (!found && def && typeof def === 'object' && def.type === 'relate' &&
                def.module === COMPANY_MODULE && def.id_name) {
                found = String(def.id_name);
            }
        });
        return found;
    }

    function entryName(entry) {
        if (typeof entry === 'string') {
            return entry;
        }
        return entry && typeof entry === 'object' && typeof entry.name === 'string' ? entry.name : '';
    }

    function union(list, name) {
        var out = _.isArray(list) ? list.slice() : [];
        if (name && out.indexOf(name) === -1) {
            out.push(name);
        }
        return out;
    }

    /**
     * What each lookup relate of a module becomes, as {name: retype}. A
     * retype names the entry type to set, the entry type it may replace, the
     * entry keys to set, and a field the view must also fetch ('' for none).
     */
    function lookupRetypes(module, fields) {
        var lookups = lookupFieldsOf(module, fields);
        var companyField = companyIdField(fields);
        var retypes = {};
        _.each(lookups, function(type, name) {
            var set = {erp_lookup_type: type};
            if (companyField) {
                set.erp_lookup_company_field = companyField;
            }
            retypes[name] = {type: FIELD_TYPE, from: 'relate', set: set, fetch: companyField};
        });
        return retypes;
    }

    /**
     * The address country fields of a module, as {name: retype}: a varchar
     * named after its address group plus `_country`.
     */
    function countryRetypes(module, fields) {
        var retypes = {};
        if (!_.contains(COUNTRY_MODULES, module)) {
            return retypes;
        }
        _.each(fields || {}, function(def, key) {
            if (!def || typeof def !== 'object' || def.type !== 'varchar' || typeof def.group !== 'string') {
                return;
            }
            var name = def.name || key;
            if (/_address$/.test(def.group) && name === def.group + '_country') {
                // Sidecar has typed a varchar entry 'text' when it stored the
                // metadata (metadata-manager.js fieldTypeMap), before this runs.
                retypes[name] = {type: COUNTRY_FIELD_TYPE, from: 'text', set: {}, fetch: ''};
            }
        });
        return retypes;
    }

    /**
     * Retypes the matching entries of one panel (or fieldset), nested
     * `fields`/`subfields` included. `host` is the top-level entry a nested
     * one belongs to: view.getFieldNames() reads related_fields only there.
     */
    function patchEntries(list, retypes, host) {
        if (!_.isArray(list)) {
            return 0;
        }
        var changed = 0;
        _.each(list, function(entry, index) {
            var name = entryName(entry);
            var retype = name !== '' ? retypes[name] : null;
            if (retype) {
                if (typeof entry === 'string') {
                    entry = list[index] = {name: entry};
                }
                if (entry.type && entry.type !== retype.from && entry.type !== retype.type) {
                    return;
                }
                entry.type = retype.type;
                _.each(retype.set, function(value, key) {
                    entry[key] = value;
                });
                if (retype.fetch) {
                    var carrier = host || entry;
                    carrier.related_fields = union(carrier.related_fields, retype.fetch);
                }
                changed++;
                return;
            }
            if (entry && typeof entry === 'object') {
                _.each(['fields', 'subfields'], function(key) {
                    changed += patchEntries(entry[key], retypes, host || entry);
                });
            }
        });
        return changed;
    }

    function patchViewMeta(meta, retypes) {
        var changed = 0;
        if (!meta || typeof meta !== 'object' || !_.isArray(meta.panels)) {
            return changed;
        }
        _.each(meta.panels, function(panel) {
            if (panel && typeof panel === 'object') {
                changed += patchEntries(panel.fields, retypes, null);
            }
        });
        return changed;
    }

    /** Retype one module's lookup and country entries. Returns how many were set. */
    function patchModule(module, moduleMeta) {
        if (!moduleMeta || typeof moduleMeta !== 'object') {
            return 0;
        }
        var retypes = _.extend(lookupRetypes(module, moduleMeta.fields),
            countryRetypes(module, moduleMeta.fields));
        if (_.isEmpty(retypes)) {
            return 0;
        }
        var changed = 0;
        _.each(moduleMeta.views || {}, function(view, viewName) {
            if (viewName === 'massupdate') {
                return;
            }
            changed += patchViewMeta(view && view.meta, retypes);
        });
        _.each(moduleMeta.dropdownViews || {}, function(byField) {
            _.each(byField || {}, function(byValue) {
                _.each(byValue || {}, function(view) {
                    changed += patchViewMeta(view && view.meta, retypes);
                });
            });
        });
        return changed;
    }

    /**
     * Apply to every module in the metadata. Returns {module: entries set}.
     */
    function apply() {
        var touched = {};
        try {
            var modules = app.metadata && typeof app.metadata.getModules === 'function'
                ? app.metadata.getModules()
                : null;
            _.each(modules || {}, function(moduleMeta, module) {
                var changed = patchModule(module, moduleMeta);
                if (changed) {
                    touched[module] = changed;
                }
            });
        } catch (e) {
            if (app.logger && typeof app.logger.error === 'function') {
                app.logger.error('erp-lookup-fields: could not set the ERP lookup dropdowns: ' + e);
            }
        }
        return touched;
    }

    app.erpLookupFields = {
        apply: apply,
        lookups: LOOKUPS,
        countryModules: COUNTRY_MODULES
    };

    app.events.on('app:sync:complete', apply);
    if (app.isSynced) {
        apply();
    }
})(SUGAR.App);

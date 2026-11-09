'use strict';
// AN EPICOR-BORN QUOTE SAVES WITH ITS ERP PICKERS, FOB AND SHIP VIA EMPTY; A NEW QUOTE STILL ASKS FOR THE PICKERS.
// SUGAR'S OWN RECORD VIEW AND CREATE FORM, 26.1.0 AND 26.2.0.
//
// What the seller met (hosted Bench tenant, 2026-10-17, BenchDogs-Ext rc100): a quote created in Epicor and read into
// Sugar (ADM quote 8829, read back as erp_sync_key ADM__8829 with Lead Source ADVERTISE, Lead Type BROKER and the
// campaign 26BRECLN with its event 26BRECLN/1) could not be edited: Save stopped with "Please complete all required
// fields before saving", the seller reading FOB, Ship Via, Project and Ship-To as the fields asked for.
//
// Measured in the source, and reproduced here:
//   * Project is the one of this package's fields that stops the save. Its vardef asked ERP-Core's pick list field to
//     require it on EVERY quote while the tenant offers ADM projects (erp_required_when_options), and an ERP quote never
//     carries a Project (it is the order lines' ProjectID; nothing reads it back), so every Epicor-born quote was
//     refused. Lead Source, Lead Type, Campaign and Event already used the "until the quote is in the ERP" key
//     (erp_required_until_synced), which ERP-Core's field reads as: required on a new quote, and on a saved quote
//     whose ERP keys (erp_sync_key, erp_display_sync_key) are both empty; never on a quote either key names.
//   * rc104 to rc112 made Ship Via required on every quote (owner, 2026-10-19), so such a quote's Save was refused for
//     Ship Via alone. Since rc113 Ship Via is not required on a quote (owner, 2026-10-22: checked only at Submit Order
//     and Order Selected Lines, where Kinetic needs it): the Save goes ahead with Project, the pickers, FOB and Ship
//     Via empty. FOB is ERP-Epicor's (never required on a quote from 1.2.118), and ERP-Epicor's quote checks field is
//     not on this page.
//   * Ship-To is ERP-Epicor's rule, not this package's (its shipping address is required on every quote), and is not
//     on this page.
// Since rc101 Project used the "until the quote is in the ERP" key too; since rc106 Project is optional (owner ruling,
// 2026-10-20): no rule asks for it, and an empty one goes to Epicor as no ProjectID. Since rc108 the server no longer
// fills an empty Project on save; the create form still offers the account's newest one (ERP-Core's prefill), and a
// seller who clears it saves without one (the server half is bd_adm_rules_test.php section W). Since rc110 the server
// gives a NEW quote the account's newest Project only when its create sends no Project at all (the Account's create
// button); the create view's own save sends a cleared one as '', read here, so the form's quote keeps it empty.
// Since rc117 the Project is each quote line's own (owner ruling, 2026-10-26): the quote-level one is on no quote view
// (its vardef has no placement or prefill marker), so the form draws, offers and asks for no Project, and the server
// fills none. The CONTROL still draws rc100's five pickers, Project among them.
//
// The page runs Sugar's own code, as quote_needed_markers_on_real_sidecar.cjs does (its header names what is
// recorded and from where): Sidecar, the stock RecordView, CreateView and Quotes create view, record-decor, the stock
// enum field, ERP-Core's erp-lookup field and its retype of the FOB and Ship Via relates. Over it:
//   * ERP-Core's erp-dependent-enum field (fixtures/shared-sugar, at the commit PINNED.json names; its code is the
//     same on the ERP-Epicor release line), declared as the server declares a controller installed under custom/;
//   * the Quotes vardefs: Sugar's stock ones, ERP-Core's FOB, Ship Via and ERP company relates, and this package's
//     SHIPPED Quote vardef file, included over them by php;
//   * this package's SHIPPED Quotes erp-lookup controller (ERP-Core's field, unchanged, since rc113);
//   * a server that records each request and answers the reads: each picker's options (recorded ADM codes), the
//     quote's ERP company, its billing account, the ADM companies the connector extension publishes, and the
//     account's earlier quotes for the create form's look-up (none, unless a case gives them).
// Save is pressed as the seller presses it: the record view's own saveClicked (edit mode first), whose validation
// runs Sugar's requiredValidator over each drawn field's own definition; the create form's own validation step.
// handleSave is replaced by a recorder, so "the save goes ahead" means Sugar's validation let it through.
//
// CONTROL: the rc100 vardef (fixtures/rc100, the shipped bytes, unchanged since rc92) on the same page refuses the
// Epicor-born quote's save naming Project: what the seller met, less Ship-To, FOB and Ship Via.
//
// IF IT WERE BROKEN: the Epicor-born quote's Save is stopped for Project or any picker (required on a quote the ERP
// holds) or for Ship Via, or a new quote saves with its ERP pickers empty (a picker no longer required
// before the quote is in the ERP), or "Required" text shows, or the rc100 control stops naming Project. Run the main
// tests on another vardef file with BD_VARDEF_SUBJECT=<path>.
//
// NOT PROVEN HERE: Ship-To (ERP-Epicor's rule); the Quotes record view's own controller and the install's panel
// placement (as in the marker test); that Submit Order still refuses without Project (the Bench connector extension's
// refusal at send, in the other repository). On a tenant: edit an Epicor-born quote with no Project and save it.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {execFileSync} = require('node:child_process');

const PINS = path.join(__dirname, 'fixtures/real-sidecar');
const SHARED = path.join(__dirname, 'fixtures/shared-sugar');
const {stock, stockArray, servedTemplate, openSugarPage, sleep}
    = require(path.join(PINS, 'scripts/tests/_real_sugar_page.cjs'));

const CORE = path.join(PINS, 'sugar-sell/ERP-Core/src');
const DEPENDENT_ENUM = path.join(SHARED, 'sugar-sell/ERP-Core/src/custom/clients/base/fields/erp-dependent-enum/'
    + 'erp-dependent-enum.js');
const PACKAGE = path.resolve(__dirname, '../../sugar-sell/BenchDogs-Ext');
const SHIPPED_VARDEF = path.join(PACKAGE, 'custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php');
const SUBJECT = process.env.BD_VARDEF_SUBJECT ? path.resolve(process.env.BD_VARDEF_SUBJECT) : SHIPPED_VARDEF;
const RC100 = path.join(__dirname, 'fixtures/rc100/bd_adm_required_fields.php.txt');
const RC100_SHA256 = 'bcd1a4ee9fafc3660437f81947a010cfab97f26d4f6555237a33cabd192d64e8';
const MARKER = path.join(PACKAGE, 'custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js');
const SUGARS = ['26.1.0', '26.2.0'];
const FROM_26_2 = ['include/javascript/sugar7/utils.js', 'include/javascript/sugar7/field.js'];
const PLUGINS = ['GridBuilder', 'Editable', 'ErrorDecoration', 'ToggleMoreLess', 'FindDuplicates', 'LinkedModel',
    'DropdownEnhancer']
    .map((name) => 'include/javascript/sugar7/plugins/' + name + '.js')
    .concat(['modules/Quotes/clients/base/plugins/QuotesViewSaveHelper.js']);
const FIELD_TEMPLATES = {
    base: ['edit', 'detail', 'nodata'],
    'record-decor': ['record-decor'],
    relate: ['edit', 'detail', 'pill'],
    fieldset: ['detail', 'record-detail'],
    button: ['detail'],
    sidebartoggle: ['detail'],
};
const FIELD_CONTROLLERS = ['base', 'record-decor', 'relate', 'button', 'rowaction', 'enum', 'fieldset',
    'sidebartoggle', 'actiondropdown'];
const FOB = 'erp_quotes_fob_name';
const SHIP_VIA = 'erp_quotes_ship_via_name';
// A vardef file's quote pickers: every field it has the install draw with ERP-Core's pick list field.
const pickersOf = (file) => Object.entries(vardefs(file))
    .filter(([, def]) => def.erp_layout && def.erp_layout.type === 'erp-dependent-enum').map(([name]) => name).sort();
// The package's: since rc117 the four lead and marketing pickers (Project is on the lines).
const PICKERS = pickersOf(SHIPPED_VARDEF);
const ADM_TYPE = 'BdAdmCompanies';
const COMPANY_ID_FIELD = 'erp_companies_quoteserp_companies_ida';

// Each picker's options as the enum endpoint answers them: ADM codes recorded on the hosted tenant (the quote's own
// values, and Project's default 20065 and 17879, both ADM projects, which the rc100 control draws).
const OPTIONS = {
    bd_lead_source: {'': '', ADVERTISE: 'ADVERTISE - Advertisement', REFERRAL: 'REFERRAL - Referral'},
    bd_lead_type: {'': '', BROKER: 'BROKER - Broker', DIRECT: 'DIRECT - Direct'},
    bd_project_id: {'': '', 20065: '20065 - CMI', 17879: '17879 - LGH EXPANSION'},
    bd_marketing_campaign: {'': '', '26BRECLN': '26BRECLN'},
    bd_marketing_event: {'': '', '26BRECLN/1': '26BRECLN/1'},
};

const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');

/** The Quotes vardefs: stock, then ERP-Core's three relates, then the given package vardef file, included by php. */
function vardefs(packageVardef) {
    return JSON.parse(execFileSync('php', ['-r', `
define('sugarEntry', true);
$dictionary = array('Quote' => json_decode(file_get_contents('php://stdin'), true));
foreach (array_slice($argv, 1) as $f) { include $f; }
echo json_encode($dictionary['Quote']['fields']);`,
    ...['erp_quotes_fob_Quotes.php', 'erp_quotes_ship_via_Quotes.php', 'erp_companies_quotes_Quotes.php']
        .map((name) => path.join(CORE, 'SugarModules/relationships/vardefs', name)), packageVardef],
    {input: JSON.stringify(stockArray('modules/Quotes/vardefs.php', 'dictionary').Quote), encoding: 'utf8'}));
}

/** The rc100 vardef, checked: the shipped bytes. php includes a file by path, so it is copied to one. */
function rc100Vardef() {
    const raw = fs.readFileSync(RC100);
    assert.equal(sha256(raw), RC100_SHA256, 'fixtures/rc100 holds the shipped rc100 vardef, unedited');
    const file = path.join(fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'bd-rc100-')), 'vardef.php');
    fs.writeFileSync(file, raw);
    return file;
}

// Sugar's stock Quotes record view with a body panel holding the two marked lookups and the five pickers, each
// picker drawn by ERP-Core's pick list field, as the install places them (erp_layout['type']).
const STOCK_RECORD = stockArray('modules/Quotes/clients/base/views/record/record.php', 'viewdefs').Quotes.base.view.record;
function recordFor(pickers) {
    const record = JSON.parse(JSON.stringify(STOCK_RECORD));
    record.panels.push({name: 'panel_bd_erp', label: 'LBL_RECORD_SHOWMORE', columns: 2, labelsOnTop: true,
        placeholders: true, fields: [FOB, SHIP_VIA].concat(pickers.map((name) => ({name, type: 'erp-dependent-enum'})))});
    return record;
}

const APP_WORDS = stockArray('include/language/en_us.lang.php', 'app_strings');

const script = (stockPath) => ({name: stockPath, source: stock(stockPath)});
const core = (rel) => fs.readFileSync(path.join(CORE, rel), 'utf8');

/**
 * The server: every request is recorded and answered after a turn. The enum endpoint answers each picker's options;
 * the reads of the marker answer the company, the account and the published ADM companies; every other read (the
 * create form's look-up of the account's earlier quotes, the lookup lists) answers an empty list.
 */
function serve(page, world) {
    page.requests = [];
    page.readsOut = 0;
    page.app.api.call = (method, url, data, callbacks) => {
        page.requests.push({method, url: String(url), data: method === 'read' ? undefined : data});
        page.readsOut += 1;
        const parsed = new URL(String(url), 'https://sugar.example/');
        const [module, id, extra] = parsed.pathname.split('/').filter(Boolean).slice(2);
        const q = parsed.searchParams;
        let answer = {next_offset: -1, records: []};
        if (method === 'read' && module === 'Quotes' && id === 'enum' && OPTIONS[extra]) {
            answer = OPTIONS[extra];
        } else if (method === 'read' && module === 'ERP_Companies' && id === world.company.id) {
            answer = world.company;
        } else if (method === 'read' && module === 'Accounts' && id === world.account.id) {
            answer = world.account;
        } else if (method === 'read' && module === 'Quotes' && id === undefined && world.history) {
            // The create form's look-up: this account's quotes holding every field it asks to be not empty.
            const holds = [...q.keys()].map((key) => /^filter\[\d+\]\[(\w+)\]\[\$not_empty\]$/.exec(key))
                .filter(Boolean).map((match) => match[1]);
            answer = {next_offset: -1, records: world.history.filter((row) => holds.length > 0
                && row.billing_account_id === q.get('filter[0][billing_account_id]')
                && holds.every((field) => String(row[field] || '') !== ''))};
        } else if (method === 'read' && module === 'ERP_LookupValues' && q.get('filter[0][type]') === ADM_TYPE) {
            const published = world.published.some((code) => q.get('filter[1][erp_sync_key][$starts]') === code + '__')
                && q.get('filter[2][is_active]') === '1';
            answer = {next_offset: -1, records: published ? [{id: 'adm-row', _module: 'ERP_LookupValues'}] : []};
        }
        const waiting = [];
        setTimeout(() => {
            try {
                if (callbacks && callbacks.success) {
                    callbacks.success(answer);
                }
                if (callbacks && callbacks.complete) {
                    callbacks.complete();
                }
                waiting.forEach((callback) => callback(answer));
            } finally {
                page.readsOut -= 1;
            }
        }, 0);
        // Sugar's enum field waits on a request already out through its xhr.
        const request = {xhr: {done(callback) {
            waiting.push(callback);
            return request.xhr;
        }}};
        return request;
    };
}

/** Waits until every drawn picker and lookup is in `action`, no read is out, and a few turns pass with nothing new. */
async function settled(page, view, action, limit = 5000) {
    const start = Date.now();
    let quiet = 0;
    for (;;) {
        await sleep(10);
        const drawn = [FOB, SHIP_VIA].concat(PICKERS).every((name) => {
            const field = view.getField(name);
            return field && field.action === action;
        });
        quiet = drawn && page.readsOut === 0 ? quiet + 1 : 0;
        if (quiet >= 5) {
            return;
        }
        if (Date.now() - start > limit) {
            throw new Error(`the ${view.name} view was not settled after ${limit} ms: fields in ${action}: ${drawn}, `
                + `reads out: ${page.readsOut}`);
        }
    }
}

/** A page on the given Sugar with the stock record view chain, ERP-Core's fields and the given Quote vardef file. */
async function quotesPage(sugar, packageVardef) {
    const page = await openSugarPage({
        sugar,
        beforeConfig: sugar === '26.1.0' ? ['jssource/src/build/bootstrap.min.js', 'sidecar/lib/sugarlogic/expressions.js',
            'sidecar/lib/sugarlogic/sidecarExpressionContext.js'] : [],
        afterConfig: sugar === '26.1.0' ? ['include/javascript/sugar7/utils.js', 'include/javascript/sugar7/field.js',
            'include/javascript/sugar7/hbs-helpers.js'] : FROM_26_2,
        alsoLoad: (sugar === '26.1.0' ? [] : ['jssource/src/build/bootstrap.min.js',
            'sidecar/lib/sugarlogic/expressions.js', 'sidecar/lib/sugarlogic/sidecarExpressionContext.js',
            'include/javascript/sugar7/hbs-helpers.js'])
            .concat(['include/javascript/mousetrap/mousetrap.min.js', 'include/javascript/sugar7/shortcuts.js',
                'include/javascript/select2/select2.js', 'include/javascript/sugar7/utils-filters.js'], PLUGINS)
            .map(script)
            .concat([{name: 'custom/include/javascript/sugar7/erp-lookup-fields.js',
                source: core('custom/include/javascript/sugar7/erp-lookup-fields.js')}]),
    });
    const {app} = page;
    page.renderFailures = [];
    app.error.handleRenderError = (component, method, field) => page.renderFailures.push(method + ':' + (field && field.name));
    // A controller installed under custom/ is served as 'custom' + Name (MetaDataFiles::getClientFileContents).
    page.declare({
        fields: {base: Object.assign(Object.fromEntries(FIELD_CONTROLLERS.map((type) => [type,
            stock('clients/base/fields/' + type + '/' + type + '.js')])), {
            'customErp-lookup': core('custom/clients/base/fields/erp-lookup/erp-lookup.js'),
            'customErp-dependent-enum': fs.readFileSync(DEPENDENT_ENUM, 'utf8'),
        })},
        views: {base: {record: stock('clients/base/views/record/record.js'),
            create: stock('clients/base/views/create/create.js')}},
        modules: {Quotes: {fieldTemplates: {base: {'customErp-lookup': fs.readFileSync(MARKER, 'utf8')}},
            views: {base: {create: stock('modules/Quotes/clients/base/views/create/create.js')}}}},
    });
    const template = (stockPath) => servedTemplate(stock(stockPath));
    const erpLookupTemplates = Object.fromEntries(['detail', 'edit', 'disabled', 'list'].map((name) => [name,
        servedTemplate(core('custom/clients/base/fields/erp-lookup/' + name + '.hbs'))]));
    app.metadata.set({
        fields: Object.assign(Object.fromEntries(Object.entries(FIELD_TEMPLATES).map(([type, names]) => [type,
            {templates: Object.fromEntries(names.map((name) => [name,
                template('clients/base/fields/' + type + '/' + name + '.hbs')]))}])),
        {'erp-lookup': {templates: erpLookupTemplates}}),
        views: {
            record: {meta: stockArray('clients/base/views/record/record.php', 'viewdefs').base.view.record,
                templates: Object.fromEntries(['record', 'headerpane', 'tabspanels', 'businesscard'].map((name) => [name,
                    template('clients/base/views/record/' + name + '.hbs')]))},
            create: {meta: stockArray('clients/base/views/create/create.php', 'viewdefs').base.view.create},
        },
        modules: {
            Quotes: {fields: vardefs(packageVardef), views: {record: {meta: recordFor(pickersOf(packageVardef))}}},
            ProductBundles: {fields: {}}, Products: {fields: {}}, ProductBundleNotes: {fields: {}},
            ERP_LookupValues: {fields: {}}, ERP_Companies: {fields: {}},
        },
        app_strings: APP_WORDS,
    });
    app.data.declareModels();
    app.acl.hasAccessToModel = () => true;
    app.acl.hasAccess = () => true;
    // The app's sync completes (sidecar/src/app.js): ERP-Core's retype of the lookup relates runs, as on a tenant.
    app.isSynced = true;
    app.trigger('app:sync:complete');
    return page;
}

const COMPANY = 'company-adm';
const ACCOUNT = 'account-1925';
// The Epicor-born quote as the tenant read it back (the values the UAT read named), with its ERP lookups and Project
// empty: an ERP quote carries no Project, and this one no FOB or Ship Via.
const EPICOR_BORN = {id: 'quote-adm-8829', name: 'ADM quote 8829', quote_stage: 'Priced',
    erp_quote_type: 'advanced_quote', erp_sync_key: 'ADM__8829', erp_display_sync_key: '8829',
    billing_account_id: ACCOUNT, billing_account_name: 'SAIQA customer 1925', date_quote_expected_closed: '2099-12-31',
    [COMPANY_ID_FIELD]: COMPANY, erp_quotes_foberp_lookupvalues_idb: '', [FOB]: '',
    erp_quotes_ship_viaerp_lookupvalues_idb: '', [SHIP_VIA]: '',
    bd_lead_source: 'ADVERTISE', bd_lead_type: 'BROKER', bd_project_id: '',
    bd_marketing_campaign: '26BRECLN', bd_marketing_event: '26BRECLN/1'};

const WORLD = {
    company: {id: COMPANY, erp_sync_key: 'ADM', erp_order_requires_fob: false, _module: 'ERP_Companies'},
    account: {id: ACCOUNT, erp_companies_accountserp_companies_ida: COMPANY, erp_foberp_lookupvalues_idb: '',
        erp_ship_viaerp_lookupvalues_idb: '', _module: 'Accounts'},
    published: ['ADM'],
};

/**
 * A saved quote's record view, opened as Sugar opens one, switched to edit, and Save pressed: whether the save went
 * ahead, and the fields Sugar's validation refused (with every "(Required)" label marker FOB and Ship Via showed).
 */
async function editAndSave(page, attrs) {
    const {app, $} = page;
    serve(page, WORLD);
    const model = app.data.createBean('Quotes', Object.assign({}, attrs));
    const context = new app.Context({module: 'Quotes', model});
    context.prepare();
    const view = app.view.createView({name: 'record', module: 'Quotes', context});
    $('#content').empty().append(view.$el);
    view.render();
    await settled(page, view, 'detail');
    const markers = [FOB, SHIP_VIA].filter((name) => view.getField(name).$el.closest('.record-cell')
        .find('.record-label span[data-required]').length === 1);
    view.editClicked();
    await settled(page, view, 'edit');
    let saved = false;
    view.handleSave = () => {
        saved = true;
    };
    let refused = [];
    model.once('error:validation', (errors) => {
        refused = Object.keys(errors || {}).sort();
    });
    const valid = await new Promise((resolve) => {
        view.validationCallback = resolve;
        view.saveClicked();
    });
    // Sugar draws a refused field's error a turn later (handleValidationError); the view goes after that.
    await sleep(30);
    view.dispose();
    return {valid, saved, refused, markers};
}

/**
 * A new quote's create form from the account, as Sugar opens it, and its own validation step: what it refuses, and
 * the Project the form held once settled. `seller` runs on the settled form before the validation step. With `send`,
 * the create view's own saveModel() then runs (the step after a passing validation), and `body` is what it sent.
 */
async function createAndValidate(page, world = WORLD, seller = null, send = false) {
    const {app, $} = page;
    serve(page, world);
    const attrs = Object.assign({}, EPICOR_BORN, {quote_stage: 'Draft', erp_sync_key: '', erp_display_sync_key: '',
        erp_quote_type: 'sales_order', bd_lead_source: '', bd_lead_type: '', bd_marketing_campaign: '',
        bd_marketing_event: ''});
    delete attrs.id;
    const model = app.data.createBean('Quotes', attrs);
    const context = new app.Context({module: 'Quotes', model, create: true});
    context.prepare();
    const view = app.view.createView({name: 'create', module: 'Quotes', context});
    $('#content').empty().append(view.$el);
    view.render();
    await settled(page, view, 'edit');
    const offered = model.get('bd_project_id');
    if (seller) {
        seller(model);
        await settled(page, view, 'edit');
    }
    let refused = [];
    model.once('error:validation', (errors) => {
        refused = Object.keys(errors || {}).sort();
    });
    const blocked = await new Promise((resolve) => view.validateModelWaterfall(resolve));
    const kept = model.get('bd_project_id');
    let body = null;
    if (send) {
        await new Promise((resolve) => view.saveModel(resolve, resolve));
        const create = page.requests.find((r) => r.method === 'create');
        body = create ? create.data : null;
    }
    view.dispose();
    return {blocked, refused, offered, kept, body};
}

test('the pinned pick list field is ERP-Core\'s, and the vardef files are the shipped ones', () => {
    const pins = JSON.parse(fs.readFileSync(path.join(SHARED, 'PINNED.json'), 'utf8'));
    assert.equal(sha256(fs.readFileSync(DEPENDENT_ENUM)), pins.files['erp-dependent-enum.js'].sha256);
    const fields = vardefs(SUBJECT);
    PICKERS.forEach((name) => assert.ok(fields[name], name + ' is defined by the package vardef'));
    assert.deepEqual(PICKERS, ['bd_lead_source', 'bd_lead_type', 'bd_marketing_campaign', 'bd_marketing_event'],
        'the four lead and marketing pickers are read from the shipped vardef');
    assert.deepEqual(Object.keys(OPTIONS).sort(), PICKERS.concat(['bd_project_id']).sort(),
        'the server answers options for those pickers and for the control\'s Project');
    assert.ok(fields.bd_project_id, 'rc117: the quote-level Project field (its column and data) stays');
    assert.equal(fields.bd_project_id.erp_layout, undefined, 'rc117: the install draws the quote-level Project nowhere');
    assert.equal(fields.bd_project_id.erp_prefill_from_account_latest, undefined, 'rc117: and no form prefills it');
    assert.ok(pickersOf(rc100Vardef()).includes('bd_project_id'), 'the control draws rc100\'s Project');
});

const SHIP_VIA_PICKED = {erp_quotes_ship_viaerp_lookupvalues_idb: 'ship-1', [SHIP_VIA]: 'UPS GROUND'};

SUGARS.forEach((sugar) => {
    test(`an Epicor-born quote with no Project, FOB or Ship Via: Save goes ahead, with no "Required" text, every `
        + `picker empty too (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, SUBJECT);
        t.after(() => page.close());
        // As read back, and as most ERP quotes hold these pickers: empty.
        const shapes = {'as read back': EPICOR_BORN, 'every picker empty': Object.assign({}, EPICOR_BORN,
            Object.fromEntries(PICKERS.map((name) => [name, ''])))};
        for (const [shape, attrs] of Object.entries(shapes)) {
            const result = await editAndSave(page, attrs);
            assert.deepEqual(result.refused, [], shape + ': no field is refused, Ship Via and FOB included');
            assert.equal(result.valid, true, shape + ': Sugar\'s validation passes');
            assert.equal(result.saved, true, shape + ': the save goes ahead');
            assert.deepEqual(result.markers, [], shape + ': no "Required" text on FOB or Ship Via');
            const picked = await editAndSave(page, Object.assign({}, attrs, SHIP_VIA_PICKED));
            assert.equal(picked.saved, true, shape + ', Ship Via picked: the save goes ahead as well');
        }
        const project = page.requests.filter((r) => r.url.includes('Quotes/enum/bd_project_id'));
        assert.equal(project.length, 0, 'rc117: the quote view draws no Project, so its options are never asked for');
        assert.deepEqual(page.renderFailures, []);
        assert.deepEqual(page.errors, []);
    });

    test(`the same quote with its ERP keys empty is not in the ERP yet: Save asks for neither Ship Via nor Project `
        + `(Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, SUBJECT);
        t.after(() => page.close());
        const result = await editAndSave(page, Object.assign({}, EPICOR_BORN,
            {erp_sync_key: '', erp_display_sync_key: ''}));
        assert.deepEqual(result.refused, [], 'Project and Ship Via are optional: empty, they never stop the save');
        assert.equal(result.valid, true);
        assert.equal(result.saved, true);
        assert.deepEqual(page.errors, []);
    });

    test(`a new quote from an ADM account: the form asks for the four lead and marketing pickers, not for Project, `
        + `Ship Via or FOB (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, SUBJECT);
        t.after(() => page.close());
        const result = await createAndValidate(page);
        assert.equal(result.blocked, true, 'the create form does not save');
        PICKERS.filter((name) => name !== 'bd_project_id')
            .forEach((name) => assert.ok(result.refused.includes(name), name + ' is asked for'));
        assert.ok(!result.refused.includes('bd_project_id'), 'Project is optional: left empty, it is not asked for');
        assert.ok(!result.refused.includes(SHIP_VIA), 'Ship Via is checked at the order, not on the quote');
        assert.ok(!result.refused.includes(FOB), 'FOB is not this package\'s to ask for');
        assert.deepEqual(page.errors, []);
    });

    test(`rc117: a new quote from an account whose newest quote holds Project 17879: the form draws no Project, `
        + `offers none and asks for none (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, SUBJECT);
        t.after(() => page.close());
        const world = Object.assign({}, WORLD, {history: [{id: 'quote-7151', billing_account_id: ACCOUNT,
            bd_project_id: '17879'}]});
        const result = await createAndValidate(page, world, null, true);
        assert.equal(result.offered, '', 'the form offers no Project (17879 is the old prefill)');
        assert.ok(!page.requests.some((r) => decodeURIComponent(r.url).includes('[bd_project_id][$not_empty]')),
            'it never reads the account\'s quotes for a Project');
        assert.ok(!page.requests.some((r) => r.url.includes('Quotes/enum/bd_project_id')), 'nor Project\'s options');
        assert.ok(!result.refused.includes('bd_project_id'), 'and asks for none');
        assert.deepEqual(page.errors, []);
    });

    test(`CONTROL: the rc100 vardef refuses the Epicor-born quote's save naming Project (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, rc100Vardef());
        t.after(() => page.close());
        const result = await editAndSave(page, EPICOR_BORN);
        assert.equal(result.valid, false);
        assert.equal(result.saved, false);
        assert.deepEqual(result.refused, ['bd_project_id'], 'what the seller met, less Ship-To, FOB and Ship Via');
        assert.deepEqual(page.errors, []);
    });
});

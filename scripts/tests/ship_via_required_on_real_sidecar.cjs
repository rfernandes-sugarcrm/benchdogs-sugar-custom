'use strict';
// SHIP VIA IS NEVER REQUIRED ON A BENCH QUOTE: THE ORDER IS WHERE IT IS CHECKED. SUGAR'S OWN RECORD AND CREATE VIEWS,
// 26.1.0 AND 26.2.0.
//
// The rule (owner, 2026-10-22, superseding 2026-10-19): Bench quotes check Ship Via only when the seller presses
// Submit Order or Order Selected Lines, where Kinetic needs it, instead of making it required from quote creation. On
// a tenant with this package a seller's Save of a quote with no Ship Via goes through, on the create form and on the
// record view, at every stage, for every company, a quote the ERP sent included: Ship Via is not required, never red
// for want of a value, and shows no "Required" text. The order refusal (BdAdmOrderRequirements, which ERP-Epicor
// names beside the FOB in one refusal on both buttons, before anything is sent) is unchanged and is tested in
// bd_order_requirements_test.php; ERP-Epicor's OrderNamesEveryHeaderRequirementTest runs the slot on both routes.
//
// The CONTROLS: rc104 to rc112 (fixtures/rc112, the shipped bytes) made Ship Via required on every quote, so a Save
// with no Ship Via was refused, red, from the quote's creation on; rc96 to rc103 (fixtures/rc103) drew "(Required)"
// beside it without requiring it.
//
// Why the package still ships a controller at this path: Module Loader never deletes a file a later build stops
// shipping, so only a file at the same path takes rc112's rule off an upgraded tenant. This page runs that file.
//
// The page runs Sugar's own code, not a stand-in: the files under fixtures/real-sidecar/ are copied, at one commit
// (PINNED.json), from erp-integration-sugar, where they were recorded from a SugarEnt 26.1.0 tree and from the
// files a 26.2.0 instance serves (each recording is checked against its sha256 when loaded): Sidecar, Sugar's
// additions to its Field (decorateRequired, handleValidationError, decorateError), utils and Handlebars helpers, the
// SugarLogic engine, select2, the stock RecordView and its record template with its partials, the record-decor field
// that draws each body cell, the stock base, relate, fieldset, button, rowaction, enum, sidebartoggle and
// actiondropdown fields, Sugar's words, and the plugins the record view draws with. ERP-Core's own code from the same
// commit: its erp-lookup field (the parent of this package's controller) and erp-lookup-fields.js, which retypes the
// quote's FOB and Ship Via relates as erp-lookup on the app's sync, as on a tenant. Over it: the Quotes vardefs
// (Sugar's with ERP-Core's FOB, Ship Via and ERP company relates), this package's SHIPPED Quotes erp-lookup
// controller, and a server that answers the quote's billing account and ERP company.
//
// A Save is judged by Sugar's own check: Bean.doValidate over the fields the view validates (what the record and
// create views' Save run), whose error reaches the field through Sugar's handleValidationError. Each page that finds
// Ship Via not asked for also has the same check refuse a field Sugar does require (the quote's name, cleared), so
// "not asked" is never read off a check that did not run.
//
// IF IT WERE BROKEN: Ship Via is required, red, or refuses a Save, on any company, stage, quote the ERP sent or the
// create form; any "Required" text shows on it; or a CONTROL stops showing its own behaviour. Run the main tests on
// another controller with BD_MARKER_SUBJECT=<path of a controller file> (fixtures/rc112/erp-lookup.js is red).
//
// NOT PROVEN HERE (each needs a signed-in Sugar): ERP-Epicor's own Quotes record and create views (the stock ones are
// drawn here), FOB (ERP-Epicor's rule: its own page, test_fob_required_on_real_sidecar.cjs, holds that FOB is never
// required on a quote from ERP-Epicor 1.2.118), the 26.2.0 record templates, CSS.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {execFileSync} = require('node:child_process');

const PINS = path.join(__dirname, 'fixtures/real-sidecar');
const {stock, stockArray, servedTemplate, openSugarPage, recordingsThatDifferFromTheTree, sleep}
    = require(path.join(PINS, 'scripts/tests/_real_sugar_page.cjs'));

const CORE = path.join(PINS, 'sugar-sell/ERP-Core/src');
const SHIPPED = path.resolve(__dirname, '../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/'
    + 'erp-lookup/erp-lookup.js');
const SUBJECT = process.env.BD_MARKER_SUBJECT ? path.resolve(process.env.BD_MARKER_SUBJECT) : SHIPPED;
// The controls: rc103's label marker, and rc104 to rc112's Ship Via required on every quote.
const BEFORE = path.join(__dirname, 'fixtures/rc103/erp-lookup.js');
const REQUIRED_BEFORE = path.join(__dirname, 'fixtures/rc112/erp-lookup.js');
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
const ADM_TYPE = 'BdAdmCompanies';

const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');

// The Quotes vardefs: stock, then ERP-Core's FOB, Ship Via and ERP company relates included over them.
const VARDEFS = JSON.parse(execFileSync('php', ['-r', `
define('sugarEntry', true);
$dictionary = array('Quote' => json_decode(file_get_contents('php://stdin'), true));
foreach (array_slice($argv, 1) as $f) { include $f; }
echo json_encode($dictionary['Quote']['fields']);`,
...['erp_quotes_fob_Quotes.php', 'erp_quotes_ship_via_Quotes.php', 'erp_companies_quotes_Quotes.php']
    .map((name) => path.join(CORE, 'SugarModules/relationships/vardefs', name))],
{input: JSON.stringify(stockArray('modules/Quotes/vardefs.php', 'dictionary').Quote), encoding: 'utf8'}));
const COMPANY_ID_FIELD = 'erp_companies_quoteserp_companies_ida';

// Sugar's stock Quotes record view with a body panel holding the two cells (the install places them in its own).
const STOCK_RECORD = stockArray('modules/Quotes/clients/base/views/record/record.php', 'viewdefs').Quotes.base.view.record;
const RECORD = JSON.parse(JSON.stringify(STOCK_RECORD));
RECORD.panels.push({name: 'panel_bd_order', label: 'LBL_RECORD_SHOWMORE', columns: 2, labelsOnTop: true,
    placeholders: true, fields: [FOB, SHIP_VIA]});

const APP_WORDS = stockArray('include/language/en_us.lang.php', 'app_strings');

/** A recorded controller: the shipped bytes after the note, checked against the sha256 the note names. */
function control(file = BEFORE) {
    const raw = fs.readFileSync(file);
    const cut = raw.indexOf(0x0a);
    const named = /sha256 ([0-9a-f]{64})/.exec(raw.subarray(0, cut).toString('utf8'));
    const body = raw.subarray(cut + 1);
    assert.ok(named && sha256(body) === named[1], path.basename(path.dirname(file)) + '/erp-lookup.js holds the '
        + 'shipped file, unedited');
    return body.toString('utf8');
}

const script = (stockPath) => ({name: stockPath, source: stock(stockPath)});
const core = (rel) => fs.readFileSync(path.join(CORE, rel), 'utf8');

/**
 * The server: every request is recorded; reads are answered from `world` (the company record, the account record,
 * and which company has an active BdAdmCompanies row). Any other read answers an empty list.
 */
function serve(page, world) {
    page.requests = [];
    page.readsOut = 0;
    page.app.api.call = (method, url, data, callbacks) => {
        page.requests.push({method, url: String(url)});
        page.readsOut += 1;
        const parsed = new URL(String(url), 'https://sugar.example/');
        const [module, id] = parsed.pathname.split('/').filter(Boolean).slice(2);
        const q = parsed.searchParams;
        let answer = {next_offset: -1, records: []};
        if (method === 'read' && module === 'ERP_Companies' && id === world.company.id) {
            answer = world.company;
        } else if (method === 'read' && module === 'Accounts' && id === world.account.id) {
            answer = world.account;
        } else if (method === 'read' && module === 'ERP_LookupValues' && q.get('filter[0][type]') === ADM_TYPE) {
            const published = world.published.some((code) => q.get('filter[1][erp_sync_key][$starts]') === code + '__')
                && q.get('filter[2][is_active]') === '1';
            answer = {next_offset: -1, records: published ? [{id: 'adm-row', _module: 'ERP_LookupValues'}] : []};
        }
        setTimeout(() => {
            try {
                if (callbacks && callbacks.success) {
                    callbacks.success(answer);
                }
                if (callbacks && callbacks.complete) {
                    callbacks.complete();
                }
            } finally {
                page.readsOut -= 1;
            }
        }, 0);
        return {};
    };
}

/**
 * Waits until the page is done drawing the two marked fields: both drawn in `action` (the create form switches its
 * fields to edit after its first render, on later turns), no read out, and a few more turns with nothing new. How
 * long that takes depends on the machine and the node version (a fixed wait passed on one and not on another), so
 * the page is waited on, never a clock; past `limit` ms it fails, saying what was still pending.
 */
async function settled(page, view, action, limit = 5000) {
    const start = Date.now();
    let quiet = 0;
    for (;;) {
        await sleep(10);
        const drawn = [FOB, SHIP_VIA].every((name) => {
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

/** A page on the given Sugar running the stock record view chain, with the given Quotes erp-lookup controller. */
async function quotesPage(sugar, controller) {
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
            stock('clients/base/fields/' + type + '/' + type + '.js')])),
        {'customErp-lookup': core('custom/clients/base/fields/erp-lookup/erp-lookup.js')})},
        views: {base: {record: stock('clients/base/views/record/record.js'),
            create: stock('clients/base/views/create/create.js')}},
        modules: {Quotes: {fieldTemplates: {base: {'customErp-lookup': controller}},
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
            Quotes: {fields: JSON.parse(JSON.stringify(VARDEFS)), views: {record: {meta: JSON.parse(JSON.stringify(RECORD))}}},
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

const COMPANY = 'company-1';
const ACCOUNT = 'account-1';
const QUOTE = {id: 'quote-1', name: 'Bench quote', quote_num: 7069, quote_stage: 'Confirmed',
    erp_quote_type: 'sales_order', erp_display_sync_key: '', billing_account_id: ACCOUNT,
    billing_account_name: 'Acme Supply', date_quote_expected_closed: '2099-12-31', [COMPANY_ID_FIELD]: COMPANY,
    erp_quotes_foberp_lookupvalues_idb: '', [FOB]: '', erp_quotes_ship_viaerp_lookupvalues_idb: '', [SHIP_VIA]: ''};

/** A saved quote's record view, opened as Sugar opens one, once its reads have answered. */
async function openRecord(page, world, changes = {}) {
    const {app, $} = page;
    serve(page, world);
    const model = app.data.createBean('Quotes', Object.assign({}, QUOTE, changes));
    const context = new app.Context({module: 'Quotes', model});
    context.prepare();
    const view = app.view.createView({name: 'record', module: 'Quotes', context});
    $('#content').empty().append(view.$el);
    view.render();
    await settled(page, view, 'detail');
    return view;
}

/**
 * A new quote's create form, opened as Sugar opens it from an account: the Billing Account filled, the quote's own ERP
 * company field empty. Answers once Sugar has switched the fields to edit and the reads have answered.
 */
async function openCreate(page, world) {
    const {app, $} = page;
    serve(page, world);
    const attrs = Object.assign({}, QUOTE, {quote_stage: 'Draft', [COMPANY_ID_FIELD]: ''});
    delete attrs.id;
    delete attrs.quote_num;
    const model = app.data.createBean('Quotes', attrs);
    const context = new app.Context({module: 'Quotes', model, create: true});
    context.prepare();
    const view = app.view.createView({name: 'create', module: 'Quotes', context});
    $('#content').empty().append(view.$el);
    view.render();
    await settled(page, view, 'edit');
    return view;
}

const world = (code, requiresFob, published) => ({
    company: {id: COMPANY, erp_sync_key: code, erp_order_requires_fob: requiresFob, _module: 'ERP_Companies'},
    account: {id: ACCOUNT, erp_companies_accountserp_companies_ida: COMPANY, erp_foberp_lookupvalues_idb: '',
        erp_ship_viaerp_lookupvalues_idb: '', _module: 'Accounts'},
    published,
});

test('the recordings are Sugar\'s own files, unedited, and the pins are one commit', () => {
    assert.deepEqual(recordingsThatDifferFromTheTree() || [], []);
    FROM_26_2.concat(['sidecar/minified/sidecar.min.js']).forEach((p) => stock(p, '26.2.0'));
    control();
    assert.match(control(REQUIRED_BEFORE), /this\.def\.required = true;/, 'the rc112 field marks Ship Via required');
});

test('Ship Via is served optional: no definition the server reads requires it', () => {
    assert.equal(VARDEFS[SHIP_VIA].type, 'relate');
    assert.equal(VARDEFS[SHIP_VIA].id_name, 'erp_quotes_ship_viaerp_lookupvalues_idb');
    assert.notEqual(VARDEFS[SHIP_VIA].required, true);
    assert.equal(VARDEFS[SHIP_VIA].required_formula, undefined);
});

/** What a field's cell shows: required, every "Required" text in it, red. */
function cell(view, name) {
    const field = view.getField(name);
    assert.ok(field && field.$el, name + ' is drawn');
    const $cell = field.$el.closest('.record-cell');
    const word = APP_WORDS.LBL_REQUIRED_FIELD;
    const placeholders = $cell.find('[placeholder], [data-placeholder]').toArray()
        .map((el) => (el.getAttribute('placeholder') || '') + ' ' + (el.getAttribute('data-placeholder') || ''));
    return {
        required: field.def.required === true,
        texts: [].concat($cell.text().includes(word) ? ['text'] : [],
            placeholders.filter((p) => p.includes(word)).map(() => 'placeholder'),
            $cell.find('span[data-required]').toArray().map(() => 'label marker')),
        red: $cell.hasClass('error'),
    };
}

/** The record view in full edit, as the Edit button opens it. */
async function edit(page, view) {
    view.action = 'edit';
    view.toggleEdit(true);
    await settled(page, view, 'edit');
}

/** Sugar's own check over the fields the view validates, then a few turns for Sugar's error drawing. */
async function validate(view) {
    const fields = view.getFields('Quotes', view.model);
    assert.ok(SHIP_VIA in fields, 'Ship Via is one of the fields the view validates');
    const errors = await new Promise((resolve) => {
        view.model.once('error:validation', resolve);
        view.model.doValidate(fields, (valid) => valid && resolve({}));
    });
    await sleep(30);
    return errors;
}

const SHIP_VIA_PICKED = {erp_quotes_ship_viaerp_lookupvalues_idb: 'ship-1', [SHIP_VIA]: 'UPS GROUND'};

SUGARS.forEach((sugar) => {
    test(`a saved quote, any company: Ship Via is not required and shows no "Required" text; a Save with it empty `
        + `goes through Sugar's own check, which still refuses the quote's cleared name (Sugar ${sugar})`, async (t) => {
        for (const code of ['ADM', 'EPIC06']) {
            const page = await quotesPage(sugar, fs.readFileSync(SUBJECT, 'utf8'));
            t.after(() => page.close());
            const view = await openRecord(page, world(code, true, ['ADM']));
            const field = view.getField(SHIP_VIA);
            assert.ok(field instanceof page.app.view.fields.BaseCustomErpLookupField,
                'the package\'s controller over ERP-Core\'s erp-lookup field');
            assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).texts], [false, []], code);
            assert.deepEqual([cell(view, FOB).required, cell(view, FOB).texts], [false, []],
                code + ': nor FOB, which this field leaves to ERP-Epicor');
            await edit(page, view);
            assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).texts], [false, []],
                code + ': nor in Edit');
            assert.deepEqual(await validate(view), {}, code + ': Sugar\'s check passes with Ship Via empty');
            assert.deepEqual([cell(view, SHIP_VIA).red, cell(view, SHIP_VIA).texts], [false, []], code + ': not red');
            view.model.set('name', '');
            await sleep(30);
            assert.deepEqual(Object.keys(await validate(view)), ['name'],
                code + ': the same check refuses the cleared name, and not Ship Via');
            assert.equal(cell(view, SHIP_VIA).red, false);
            assert.deepEqual(page.renderFailures, []);
        }
    });

    test(`a quote the ERP sent, at every stage, saves with no Ship Via (Sugar ${sugar})`, async (t) => {
        for (const stage of ['Draft', 'In Estimation', 'Priced', 'Negotiation', 'Confirmed', 'Partially Fulfilled',
            'Closed Accepted', 'Closed Lost']) {
            const page = await quotesPage(sugar, fs.readFileSync(SUBJECT, 'utf8'));
            t.after(() => page.close());
            const view = await openRecord(page, world('ADM', true, ['ADM']), {quote_stage: stage,
                erp_display_sync_key: '8847', erp_sync_key: 'ADM__8847', erp_quote_type: 'advanced_quote'});
            await edit(page, view);
            assert.deepEqual(await validate(view), {}, stage + ': Sugar\'s check passes with Ship Via empty');
            assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).red, cell(view, SHIP_VIA).texts],
                [false, false, []], stage);
        }
    });

    test(`a new quote's create form does not ask for Ship Via, and shows no "Required" text, before or after Sugar's `
        + `check (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openCreate(page, world('ADM', true, ['ADM']));
        assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).texts], [false, []]);
        view.model.set('name', '');
        await sleep(30);
        const errors = await validate(view);
        assert.ok('name' in errors, 'the same check refuses the cleared name');
        assert.equal(SHIP_VIA in errors, false, 'and not Ship Via');
        assert.equal(FOB in errors, false, 'nor FOB');
        assert.deepEqual([cell(view, SHIP_VIA).red, cell(view, SHIP_VIA).texts], [false, []]);
    });

    test(`CONTROL: the rc112 field makes Ship Via required on every quote and refuses a Save without it, red, on a `
        + `saved quote and on the create form (Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, control(REQUIRED_BEFORE));
        t.after(() => page.close());
        const view = await openRecord(page, world('ADM', true, ['ADM']), {quote_stage: 'Draft'});
        assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).texts], [true, []]);
        await edit(page, view);
        assert.deepEqual(Object.keys(await validate(view)), [SHIP_VIA], 'refused for Ship Via');
        assert.equal(cell(view, SHIP_VIA).red, true, 'red');
        view.model.set(SHIP_VIA_PICKED);
        await sleep(30);
        assert.deepEqual(await validate(view), {}, 'picked, the check passes');
        const createPage = await quotesPage(sugar, control(REQUIRED_BEFORE));
        t.after(() => createPage.close());
        const create = await openCreate(createPage, world('ADM', true, ['ADM']));
        assert.ok(SHIP_VIA in await validate(create), 'the create form refuses it too');
    });

    test(`CONTROL: the rc103 field draws "(Required)" beside Ship Via and lets a Save without it through `
        + `(Sugar ${sugar})`, async (t) => {
        const page = await quotesPage(sugar, control());
        t.after(() => page.close());
        const view = await openRecord(page, world('ADM', false, ['ADM']));
        assert.deepEqual([cell(view, SHIP_VIA).required, cell(view, SHIP_VIA).texts], [false, ['text', 'label marker']]);
        assert.deepEqual(await validate(view), {}, 'Sugar\'s check never asks for Ship Via');
    });
});

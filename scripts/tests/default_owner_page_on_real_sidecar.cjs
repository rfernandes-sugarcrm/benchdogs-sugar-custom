'use strict';
// THE DEFAULT OWNER FOR ERP-CREATED RECORDS: THE BENCH DOGS ADMIN PAGE, DRAWN BY SUGAR'S OWN CODE, 26.1.0 AND 26.2.0.
//
// The setting is a lookup over Sugar's users, never a text box: the admin types part of a name, picks a user from
// Sugar's own list, and Save stores that user's id. Clearing the field and saving turns it off.
//
// The page runs Sugar's own code, not a stand-in. From fixtures/real-sidecar/ (copied at one commit from
// erp-integration-sugar, where it was recorded from a SugarEnt 26.1.0 tree and from the files a 26.2.0 instance
// serves): Sidecar, Sugar's additions to its Field, utils and Handlebars helpers, select2, the filter utilities, the
// stock base field, and the stock relate field (26.2.0's own on the 26.2.0 page). From fixtures/stock-sugar/
// (recorded here from the same SugarEnt 26.1.0 tree, in the form erp-integration-sugar's
// record_stock_sugar_file.cjs writes, each checked against its sha256 when loaded): Sugar's Filters model and
// collection, which every relate field's search builds its filter with (and which the relate field waits on before
// it draws a searchable box), the relate field's pill template, the Users filter definitions (the quick-search
// fields), and the five Sugar words the page shows. On the 26.2.0 page those are 26.1.0's: no 26.2.0 source tree is at
// hand.
//
// Over it, this package's SHIPPED files: the Administration layout the admin link opens, its view (controller,
// template, field definition), and the words of its language file. The page is opened the way the link's route
// opens it (Sidecar's ':module/layout/:view' route calls app.controller.loadView({module, layout})).
//
// The server: requests to BenchDogs/default-owner go to this package's REAL endpoint, through php
// (bd_default_owner_test.php in its serve mode: only Sugar's BeanFactory and Administration are faked there), so
// what is stored is what the endpoint stores. A Users search is answered with the users of the test's world that
// match the filter the request carries (its status term and its first or last name terms); any other read answers
// an empty list.
//
// What is seen, on each Sugar:
//   * the field is Sugar's relate field over Users, drawn as a searchable box, under the setting's own label and help;
//   * the help sentence is drawn once, by Sugar's relate field from the field's definition, under the box (the page's
//     template draws no copy of its own); its CONTROL is the rc102 template (fixtures/rc102) on the same page, which
//     draws it twice;
//   * a saved owner is shown by name;
//   * typing a name asks Sugar's Users for it, with Sugar's own "not Inactive" term, and the box lists the users the
//     server answers, by name; an Inactive user is not listed;
//   * picking a user and pressing Save stores that user's id; clearing the box and pressing Save stores empty (off);
//   * a saved user who is no longer active is said so on the page;
//   * a user the endpoint refuses (Sugar's own Reserved support users are listed, not being Inactive): the page shows
//     the endpoint's reason and nothing is stored.
//
// IF IT WERE BROKEN: a text box instead of the lookup (no Users search, no list), Save sending the name or nothing,
// a cleared box leaving the old id stored, a refusal the page does not show, or the help sentence printed twice (the
// template and the field each drawing it) or not at all.
//
// NOT PROVEN HERE (each needs a signed-in Sugar): the Admin panel drawing the link (its definition is read below);
// the 26.2.0 Filters classes and templates (26.1.0's run on both pages, the relate field's edit template among them, so
// that 26.2.0's own draws the help is shown by the tenants, not here); the server's own Users filter; CSS. On a
// tenant: open Admin, Bench Dogs, Default owner for ERP-created records, pick a user, Save, reload.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {execFileSync} = require('node:child_process');

const PINS = path.join(__dirname, 'fixtures/real-sidecar');
const {stock, stockArray, servedTemplate, openSugarPage, recordingsThatDifferFromTheTree, sleep}
    = require(path.join(PINS, 'scripts/tests/_real_sugar_page.cjs'));

const EXTRA = path.join(__dirname, 'fixtures/stock-sugar/sugar-26.1');
const STOCK_TREE = process.env.SUGAR_SRC || path.join(os.homedir(), 'Documents/Code/SugarEnt-Full-26.1.0');
const PKG = path.resolve(__dirname, '../../sugar-sell/BenchDogs-Ext/custom');
const PAGE = path.join(PKG, 'modules/Administration/clients/base');
const VIEW = path.join(PAGE, 'views/bd-default-owner');
const SUBJECT = process.env.BD_OWNER_PAGE_SUBJECT ? path.resolve(process.env.BD_OWNER_PAGE_SUBJECT)
    : path.join(VIEW, 'bd-default-owner.js');
const SERVER = path.join(__dirname, 'bd_default_owner_test.php');
const SUGARS = ['26.1.0', '26.2.0'];
// Sugar 26.2.0's relate field: the pin keeps it at this path, unheaded; erp-integration-sugar records the same bytes
// as sugar-26.2/clients/base/fields/relate/relate.js with this sha256.
const RELATE_26_2 = path.join(PINS, 'sugar-sell/ERP-Core/tests/support/sugar-26.2/relate.js');
const RELATE_26_2_SHA = 'c414c7b8351623c00317a11432156e648c1be186c8842f2db31c446e05987b1f';
const FIELD = 'default_owner_user_name';
const TEMPLATE = path.join(VIEW, 'bd-default-owner.hbs');
// The rc102 template, the control: it draws the help sentence itself, beside the field's own.
const RC102_TEMPLATE = path.join(__dirname, 'fixtures/rc102/bd-default-owner.hbs.txt');
const RC102_TEMPLATE_SHA = '30ca464aa63671b9c2f69f03f4fc4205220d2db41b61aee9b02507f2cd7c4396';
const HEADER = /Recorded from SugarEnt (\d+\.\d+\.\d+) \(build (\d+)\): (\S+); sha256 ([0-9a-f]{64}) of the stock file/;

const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');
const php = (code, ...args) => JSON.parse(execFileSync('php', ['-r', code, ...args], {encoding: 'utf8'}));

/** A recording made here: its header names the stock path and SugarEnt 26.1.0, and its body is that file's bytes. */
function extra(stockPath) {
    const raw = fs.readFileSync(path.join(EXTRA, stockPath));
    const cut = raw.indexOf(0x0a);
    const header = HEADER.exec(raw.subarray(0, cut).toString('utf8'));
    const body = raw.subarray(cut + 1);
    assert.ok(header && header[1] === '26.1.0' && header[3] === stockPath, stockPath + ': no recording header');
    assert.equal(sha256(body), header[4], stockPath + ': the recording was edited');
    return body.toString('utf8');
}

/** A stock PHP array recorded here as JSON, whole or only its kept keys. */
function extraArray(stockPath, variable) {
    const data = JSON.parse(fs.readFileSync(path.join(EXTRA, stockPath + '.json'), 'utf8'));
    const header = HEADER.exec(String(data.recorded));
    assert.ok(header && header[1] === '26.1.0' && header[3] === stockPath && variable in data, stockPath);
    return data[variable];
}

const EXTRA_FILES = ['modules/Filters/clients/base/datas/model/model.js',
    'modules/Filters/clients/base/datas/collection/collection.js', 'clients/base/fields/relate/pill.hbs'];
const EXTRA_ARRAYS = ['modules/Users/clients/base/filters/basic/basic.php',
    'modules/Users/clients/base/filters/default/default.php', 'include/language/en_us.lang.php'];

const WORDS = php('$mod_strings = array(); include $argv[1]; echo json_encode($mod_strings);',
    path.join(PKG, 'Extension/modules/Administration/Ext/Language/en_us.bd_default_owner.php'));
const VIEW_META = php('$viewdefs = array(); include $argv[1]; echo json_encode($viewdefs["Administration"]["base"]'
    + '["view"]["bd-default-owner"]);', path.join(VIEW, 'bd-default-owner.php'));
const LAYOUT_META = php('$viewdefs = array(); include $argv[1]; echo json_encode($viewdefs["Administration"]["base"]'
    + '["layout"]["bd-default-owner"]);', path.join(PAGE, 'layouts/bd-default-owner/bd-default-owner.php'));
const ADMIN_LINK = php('$admin_group_header = array(); include $argv[1]; echo json_encode($admin_group_header);',
    path.join(PKG, 'Extension/modules/Administration/Ext/Administration/_override_bd_default_owner.php'));
const APP_WORDS = Object.assign({}, stockArray('include/language/en_us.lang.php', 'app_strings'),
    extraArray('include/language/en_us.lang.php', 'app_strings'));

// The users of the test's world, as the Users table holds them.
const USERS = {
    'u-jane': {full_name: 'Jane Seller', first_name: 'Jane', last_name: 'Seller', status: 'Active', deleted: 0},
    'u-jack': {full_name: 'Jack Seller', first_name: 'Jack', last_name: 'Seller', status: 'Active', deleted: 0},
    'u-jade': {full_name: 'Jade Gone', first_name: 'Jade', last_name: 'Gone', status: 'Inactive', deleted: 0},
    'u-support': {full_name: 'Jarvis Support', first_name: 'Jarvis', last_name: 'Support', status: 'Reserved',
        deleted: 0},
};

/** The Users rows a search asks for: the request's status term and name terms, as the filter API reads them. */
function usersMatching(url) {
    const q = new URL(url, 'https://sugar.example/').searchParams;
    const notStatus = q.get('filter[0][$and][0][status][$not_equals]');
    const starts = [];
    q.forEach((value, key) => {
        if (/\[(first_name|last_name)\]\[\$starts\]$/.test(key)) {
            starts.push([/first_name/.test(key) ? 'first_name' : 'last_name', value.toLowerCase()]);
        }
    });
    return Object.keys(USERS).filter((id) => USERS[id].status !== notStatus
        && starts.some(([field, term]) => USERS[id][field].toLowerCase().startsWith(term)))
        .map((id) => ({id, full_name: USERS[id].full_name, _module: 'Users'}));
}

/** One request to the shipped endpoint, through php; the config rows it leaves are kept for the next one. */
function endpoint(world, method, body) {
    const out = JSON.parse(execFileSync('php', [SERVER], {
        input: JSON.stringify({users: world.users, rows: world.rows, method, body}),
        env: Object.assign({}, process.env, {BD_DEFAULT_OWNER_SERVE: '1'}), encoding: 'utf8',
    }));
    world.rows = out.rows;
    return out;
}

/** The server: every request is recorded and answered on a later turn, as a browser's would be. */
function serve(page, world) {
    page.requests = [];
    page.readsOut = 0;
    page.app.api.call = (method, url, data, callbacks) => {
        const sent = data ? JSON.parse(JSON.stringify(data)) : data;
        page.requests.push({method, url: String(url), data: sent});
        page.readsOut += 1;
        const route = new URL(String(url), 'https://sugar.example/').pathname;
        let reply = {status: 200, answer: {next_offset: -1, records: []}};
        if (route.endsWith('/BenchDogs/default-owner')) {
            reply = endpoint(world, method, sent);
        } else if (method === 'read' && /\/Users$/.test(route)) {
            reply = {status: 200, answer: {next_offset: -1, records: usersMatching(String(url))}};
        }
        // Sidecar's own api.call answers with the request, whose xhr a caller may wait on (the Filters collection does).
        const xhr = page.$.Deferred();
        setTimeout(() => {
            try {
                if (reply.status === 200 && callbacks && callbacks.success) {
                    callbacks.success(reply.answer);
                } else if (reply.status !== 200 && callbacks && callbacks.error) {
                    callbacks.error({status: reply.status, code: reply.error.code, message: reply.error.message});
                }
                if (callbacks && callbacks.complete) {
                    callbacks.complete();
                }
            } finally {
                page.readsOut -= 1;
                xhr.resolve();
            }
        }, 0);
        return {xhr: xhr.promise()};
    };
}

/** Waits for a condition on the page, never a clock; past `limit` ms it fails saying what it waited for. */
async function until(what, condition, limit = 5000) {
    const start = Date.now();
    for (;;) {
        if (condition()) {
            return;
        }
        if (Date.now() - start > limit) {
            throw new Error('waited ' + limit + ' ms for ' + what);
        }
        await sleep(10);
    }
}

const script = (stockPath, sugar) => ({name: stockPath, source: stock(stockPath, sugar)});

/** A page on the given Sugar with the admin page's layout and view declared as the server declares them. */
async function ownerPage(sugar, world, controller, pageTemplate = TEMPLATE) {
    const page = await openSugarPage({
        sugar,
        beforeConfig: sugar === '26.1.0' ? ['jssource/src/build/bootstrap.min.js'] : [],
        afterConfig: ['include/javascript/sugar7/utils.js', 'include/javascript/sugar7/field.js']
            .concat(sugar === '26.1.0' ? ['include/javascript/sugar7/hbs-helpers.js'] : []),
        alsoLoad: (sugar === '26.1.0' ? [] : ['jssource/src/build/bootstrap.min.js',
            'include/javascript/sugar7/hbs-helpers.js']).map((p) => script(p))
            .concat(['include/javascript/select2/select2.js', 'include/javascript/sugar7/utils-filters.js']
                .map((p) => script(p))),
    });
    const {app} = page;
    page.renderFailures = [];
    app.error.handleRenderError = (component, method, field) => page.renderFailures.push(method + ':'
        + (field && field.name));
    page.alerts = [];
    const show = app.alert.show.bind(app.alert);
    app.alert.show = (id, options) => {
        page.alerts.push({id, level: options && options.level, messages: options && options.messages});
        return show(id, options);
    };
    page.declare({
        fields: {base: {
            base: stock('clients/base/fields/base/base.js', sugar === '26.2.0' ? '26.2.0' : undefined),
            relate: sugar === '26.2.0' ? fs.readFileSync(RELATE_26_2, 'utf8') : stock('clients/base/fields/relate/relate.js'),
        }},
        modules: {
            Filters: {datas: {base: {model: extra(EXTRA_FILES[0]), collection: extra(EXTRA_FILES[1])}}},
            // A view the package ships under custom/ is declared as 'custom' + its capitalised name.
            Administration: {views: {base: {'customBd-default-owner': controller}}},
        },
    });
    const template = (stockPath) => servedTemplate(stock(stockPath));
    const users = (name) => extraArray('modules/Users/clients/base/filters/' + name + '/' + name + '.php', 'viewdefs')
        .Users.base.filter[name];
    app.metadata.set({
        fields: {
            base: {templates: {edit: template('clients/base/fields/base/edit.hbs'),
                detail: template('clients/base/fields/base/detail.hbs')}},
            relate: {templates: {edit: template('clients/base/fields/relate/edit.hbs'),
                detail: template('clients/base/fields/relate/detail.hbs'),
                pill: servedTemplate(extra(EXTRA_FILES[2]))}},
        },
        modules: {
            Administration: {fields: {},
                views: {'bd-default-owner': {meta: VIEW_META,
                    templates: {'bd-default-owner': fs.readFileSync(pageTemplate, 'utf8')}}},
                layouts: {'bd-default-owner': {meta: LAYOUT_META}}},
            Users: {fields: {id: {name: 'id', type: 'id'}, full_name: {name: 'full_name', type: 'fullname'},
                status: {name: 'status', type: 'enum'}}, filters: {basic: {meta: users('basic')},
                default: {meta: users('default')}}},
            Filters: {fields: {}},
        },
        // The server lists every module the user may reach in modules_info, Users among them.
        modules_info: {Administration: {enabled: true, visible: true}, Users: {enabled: true, visible: true},
            Filters: {enabled: true, visible: false}},
        app_strings: APP_WORDS,
        mod_strings: {Administration: WORDS},
    });
    app.data.declareModels();
    // An administrator: the server sends the user's own ACL; nothing is restricted here. Their name format is
    // Sugar's install default ('s f l', install/install_defaults.php), as /me hands every signed-in user one.
    app.user.set('preferences', {default_locale_name_format: 's f l'});
    app.acl.hasAccess = () => true;
    app.acl.hasAccessToModel = () => true;
    serve(page, world);
    return page;
}

/** Opens the page as the admin link's route does, and waits until the saved setting is drawn. */
async function openPage(page) {
    const {app} = page;
    app.controller.loadView({module: 'Administration', layout: 'bd-default-owner'});
    const view = app.controller.layout.getComponent('main-pane').getComponent('bd-default-owner');
    await until('the setting to be read and drawn', () => page.readsOut === 0 && view.getField(FIELD)
        && view.$('.select2-container').length === 1 && page.requests.length > 0);
    return view;
}

/** How many times the help sentence is on the page, how many help lines there are, and which the field drew. */
function helpShown(view) {
    const sentence = WORDS.LBL_BD_DEFAULT_OWNER_HELP;
    const field = view.getField(FIELD);
    return {
        sentences: view.$el.text().split(sentence).length - 1,
        lines: view.$('.help-block').length,
        byTheField: field.$('.help-block').filter((i, el) => field.$(el).text().trim() === sentence).length,
        underTheBox: field.$('.select2-container ~ .help-block').length,
    };
}

const picker = (view) => view.getField(FIELD).$(view.getField(FIELD).fieldTag).data('select2');
const shownInBox = (view) => view.$('.select2-chosen').first().text().replace(/\s+/g, ' ').trim();

/** Types into the picker as the admin does, and answers the names the box lists. */
async function type(page, view, term) {
    const plugin = picker(view);
    const before = page.requests.length;
    plugin.open();
    plugin.search.val(term).trigger('keyup-change');
    await until('the search for "' + term + '" to be answered and listed', () => page.readsOut === 0
        && page.requests.length > before && plugin.results.find('.select2-result-label').length > 0);
    return Array.from(plugin.results.find('.select2-result-label'), (el) => page.$(el).text().trim());
}

/** Picks the listed name, as a click on the row does. */
async function pick(page, view, name) {
    const plugin = picker(view);
    const row = plugin.results.find('.select2-result-selectable').filter((i, el) => page.$(el).text().trim() === name);
    assert.equal(row.length, 1, name + ' is listed once');
    row.trigger('mouseup');
    await sleep(0);
}

/** Presses Save and waits for the endpoint's answer to be drawn. */
async function save(page, view) {
    const before = page.requests.length;
    view.$('[data-action=bd-save]').trigger('click');
    await until('the save to be answered', () => page.readsOut === 0 && page.requests.length > before);
    return page.requests.slice(before).filter((r) => r.method === 'update');
}

const stored = (world) => (((world.rows || {}).benchdogs_ext || {}).base || {}).default_owner_user_id;
const worldWith = (saved, users) => ({users: Object.assign({}, USERS, users || {}),
    rows: saved === undefined ? {} : {benchdogs_ext: {base: {default_owner_user_id: saved}}}});

test('the recordings are Sugar\'s own files, unedited', () => {
    assert.deepEqual(recordingsThatDifferFromTheTree() || [], []);
    ['sidecar/minified/sidecar.min.js', 'include/javascript/sugar7/utils.js', 'include/javascript/sugar7/field.js',
        'clients/base/fields/base/base.js'].forEach((p) => stock(p, '26.2.0'));
    assert.equal(sha256(fs.readFileSync(RELATE_26_2)), RELATE_26_2_SHA, 'the 26.2.0 relate field, unedited');
    EXTRA_FILES.forEach((p) => extra(p));
    EXTRA_ARRAYS.forEach((p) => assert.ok(fs.existsSync(path.join(EXTRA, p + '.json')), p));
    // Where a SugarEnt 26.1.0 tree is at hand (the owner's machine), each recording made here is that tree's file.
    if (fs.existsSync(path.join(STOCK_TREE, 'sugar_version.php'))
        && fs.readFileSync(path.join(STOCK_TREE, 'sugar_version.php'), 'utf8').includes("'26.1.0'")) {
        EXTRA_FILES.forEach((p) => assert.equal(extra(p), fs.readFileSync(path.join(STOCK_TREE, p), 'utf8'), p));
        EXTRA_ARRAYS.forEach((p) => {
            const header = HEADER.exec(JSON.parse(fs.readFileSync(path.join(EXTRA, p + '.json'), 'utf8')).recorded);
            assert.equal(sha256(fs.readFileSync(path.join(STOCK_TREE, p))), header[4], p);
        });
    }
});

test('the Admin link opens this page by the route Sidecar maps to the layout', () => {
    const options = ADMIN_LINK.flatMap((group) => Object.values(group[3].Administration || {}));
    assert.equal(options.length, 1, 'one link');
    assert.equal(options[0][1], 'LBL_BD_DEFAULT_OWNER', 'named as the setting');
    assert.match(options[0][3], /SUGAR\.App\.router\.navigate\("Administration\/layout\/bd-default-owner"/);
    assert.equal(WORDS[ADMIN_LINK[0][0]], 'Bench Dogs', 'under a Bench Dogs section');
    assert.equal(WORDS.LBL_BD_DEFAULT_OWNER, 'Default owner for ERP-created records');
});

SUGARS.forEach((sugar) => {
    test(`the setting is Sugar's own lookup over Users, drawn searchable under its label and help (Sugar ${sugar})`,
        async (t) => {
            const world = worldWith();
            const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
            t.after(() => page.close());
            const view = await openPage(page);
            assert.equal(view.constructor, page.app.view.views.BaseAdministrationCustomBdDefaultOwnerView,
                'the package\'s view');
            const field = view.getField(FIELD);
            assert.equal(field.type, 'relate', 'Sugar\'s relate field');
            assert.equal(field.getSearchModule(), 'Users', 'over Users');
            assert.equal(view.$('.select2-container-disabled').length, 0, 'searchable, not Sugar\'s disabled box');
            assert.equal(view.$('h5').first().text().trim(), WORDS.LBL_BD_DEFAULT_OWNER);
            assert.equal(view.$('.help-block').first().text().trim(), WORDS.LBL_BD_DEFAULT_OWNER_HELP);
            assert.deepEqual(page.requests.map((r) => r.method + ' ' + new URL(r.url, 'https://sugar.example/').pathname),
                ['read /rest/v11_27/BenchDogs/default-owner', 'read /rest/v11_27/Filters'],
                'the page reads its setting from the endpoint, and Sugar\'s relate field its filters; nothing else');
            assert.equal(shownInBox(view), String(field.getPlaceHolder()), 'nothing saved: Sugar\'s empty box');
            assert.deepEqual(page.renderFailures, []);
            assert.deepEqual(page.errors, []);
        });

    test(`the help sentence is drawn once, by Sugar's relate field, under the box (Sugar ${sugar})`, async (t) => {
        const page = await ownerPage(sugar, worldWith('u-jane'), fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openPage(page);
        assert.equal(view.getField(FIELD).def.help, 'LBL_BD_DEFAULT_OWNER_HELP', 'the field\'s definition holds the help');
        assert.deepEqual(helpShown(view), {sentences: 1, lines: 1, byTheField: 1, underTheBox: 1},
            'one help sentence on the page, the one Sugar\'s field draws from its definition');
        assert.deepEqual(page.renderFailures, []);
        assert.deepEqual(page.errors, []);
    });

    test(`CONTROL: the rc102 template draws the help sentence twice, its own line and the field's (Sugar ${sugar})`,
        async (t) => {
            assert.equal(sha256(fs.readFileSync(RC102_TEMPLATE)), RC102_TEMPLATE_SHA, 'the rc102 template, unedited');
            const page = await ownerPage(sugar, worldWith('u-jane'), fs.readFileSync(SUBJECT, 'utf8'), RC102_TEMPLATE);
            t.after(() => page.close());
            const view = await openPage(page);
            assert.deepEqual(helpShown(view), {sentences: 2, lines: 2, byTheField: 1, underTheBox: 1},
                'twice, as both tenants showed: Sugar\'s field drew one, the template the other');
            assert.deepEqual(page.errors, []);
        });

    test(`a saved owner is shown by name (Sugar ${sugar})`, async (t) => {
        const world = worldWith('u-jane');
        const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openPage(page);
        assert.equal(shownInBox(view), 'Jane Seller');
        assert.equal(view.model.get('default_owner_user_id'), 'u-jane');
        assert.equal(view.$('[data-name=bd-default-owner-inactive]').length, 0, 'no warning for an active user');
        assert.deepEqual(page.errors, []);
    });

    test(`typing a name searches Sugar's Users, lists them by name, and Save stores the picked user's id `
        + `(Sugar ${sugar})`, async (t) => {
        const world = worldWith();
        const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openPage(page);
        const listed = await type(page, view, 'Ja');
        const search = page.requests.filter((r) => /\/Users\?/.test(r.url)).pop();
        assert.ok(search, 'the box asked Sugar\'s Users');
        const q = new URL(search.url, 'https://sugar.example/').searchParams;
        assert.equal(q.get('filter[0][$and][0][status][$not_equals]'), 'Inactive', 'with Sugar\'s own "not Inactive"');
        assert.ok([...q.keys()].some((k) => /\[first_name\]\[\$starts\]$/.test(k) && q.get(k) === 'Ja'),
            'and the typed name: ' + search.url);
        assert.deepEqual(listed.sort(), ['Jack Seller', 'Jane Seller', 'Jarvis Support'],
            'the users the server answered, by name; the Inactive one is not among them');
        await pick(page, view, 'Jack Seller');
        assert.equal(view.model.get('default_owner_user_id'), 'u-jack');
        const sent = await save(page, view);
        assert.deepEqual(sent.map((r) => r.data), [{default_owner_user_id: 'u-jack'}], 'Save sends the id alone');
        assert.equal(stored(world), 'u-jack', 'the endpoint stored the picked user\'s id');
        assert.equal(shownInBox(view), 'Jack Seller', 'and the page shows the saved user');
        assert.ok(page.alerts.some((a) => a.level === 'success' && a.messages === WORDS.LBL_BD_DEFAULT_OWNER_SAVED));
        assert.deepEqual(page.errors, []);
    });

    test(`clearing the box and pressing Save stores empty, which turns it off (Sugar ${sugar})`, async (t) => {
        const world = worldWith('u-jane');
        const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openPage(page);
        assert.equal(stored(world), 'u-jane');
        view.$('.select2-search-choice-close').trigger('mousedown');
        await sleep(0);
        assert.equal(view.model.get('default_owner_user_id'), '', 'the box is cleared');
        const sent = await save(page, view);
        assert.deepEqual(sent.map((r) => r.data), [{default_owner_user_id: ''}]);
        assert.equal(stored(world), '', 'stored empty: off');
        assert.equal(shownInBox(view), String(view.getField(FIELD).getPlaceHolder()), 'Sugar\'s empty box');
        assert.deepEqual(page.errors, []);
    });

    test(`a saved user who is no longer active is said so (Sugar ${sugar})`, async (t) => {
        const world = worldWith('u-jade');
        const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
        t.after(() => page.close());
        const view = await openPage(page);
        assert.equal(shownInBox(view), 'Jade Gone', 'the name is still shown');
        assert.equal(view.$('[data-name=bd-default-owner-inactive]').text().trim(), WORDS.LBL_BD_DEFAULT_OWNER_INACTIVE);
        assert.deepEqual(page.errors, []);
    });

    test(`a user the endpoint refuses: the page shows its reason and nothing is stored (Sugar ${sugar})`,
        async (t) => {
            const world = worldWith('u-jane');
            const page = await ownerPage(sugar, world, fs.readFileSync(SUBJECT, 'utf8'));
            t.after(() => page.close());
            const view = await openPage(page);
            assert.ok((await type(page, view, 'Jar')).includes('Jarvis Support'),
                'Sugar lists a Reserved support user: its filter leaves out only Inactive ones');
            await pick(page, view, 'Jarvis Support');
            const sent = await save(page, view);
            assert.deepEqual(sent.map((r) => r.data), [{default_owner_user_id: 'u-support'}]);
            assert.equal(stored(world), 'u-jane', 'nothing stored: the saved owner is unchanged');
            const refusal = page.alerts.filter((a) => a.level === 'error');
            assert.equal(refusal.length, 1);
            assert.match(String(refusal[0].messages), /must be an active Sugar user\. Nothing was saved\./,
                'the endpoint\'s own reason');
            assert.deepEqual(page.errors, []);
        });
});

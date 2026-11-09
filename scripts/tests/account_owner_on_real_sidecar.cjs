'use strict';
// A NEW OPPORTUNITY OR QUOTE'S ASSIGNED TO FOLLOWS ITS ACCOUNT'S OWNER, ON SUGAR'S OWN SIDECAR (26.1.0).
//
// The create form's half of rc121's rule: this package's bd-account-owner view, which its create layout overlays put
// right after Sugar's create view (bd_account_owner_test.php), shares the form's model. When the account is set, on
// the form or by the subpanel the form opens from, it reads the account's owner and whether that user is Active, and
// puts the owner in Assigned To in place of the creator Sugar's create view put there. A person's own pick stands:
// the view stops, and the form's save tells the server so (?bd_owner_chosen=1), which the before_save rule obeys.
//
// What runs: Sidecar 26.1.0 (its view manager, data manager, bean and API client, recorded under fixtures/real-sidecar/)
// and this package's SHIPPED controller, declared the way the server's components file declares a module's custom view.
// The server is this file: an account read answers the test world's owner, a Users read the users matching the
// request's filter terms, a create is recorded. Sugar's create view is not drawn; the form's model starts as its
// initialize leaves it (Assigned To the signed-in user, as a default: clients/base/views/create/create.js 26.1).
//
// IF IT WERE BROKEN: Assigned To stays the creator (no read, or the answer never applied); an Inactive owner is put
// there; a person's pick is overwritten by a late answer or a later account; a stale answer for an earlier account
// wins; Cancel asks about a change the person did not make; the save carries no pick, or always one.
//
// NOT PROVEN HERE (each needs a signed-in Sugar): the drawn form and its Assigned To pill; the server's own ACL on the
// two reads (a seller may read Accounts and the Users list, as Sugar's own user picker does).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const PINS = path.join(__dirname, 'fixtures/real-sidecar');
const {openSugarPage, sleep} = require(path.join(PINS, 'scripts/tests/_real_sugar_page.cjs'));

const PKG = path.resolve(__dirname, '../../sugar-sell/BenchDogs-Ext/custom');
// One controller, shipped in each module (a module's client files ride its metadata; no Quick Repair).
const SHIPPED = ['Quotes', 'Opportunities'].map((m) => path.join(PKG, 'modules', m,
    'clients/base/views/bd-account-owner/bd-account-owner.js'));
const SUBJECT = process.env.BD_ACCOUNT_OWNER_SUBJECT ? path.resolve(process.env.BD_ACCOUNT_OWNER_SUBJECT) : SHIPPED[0];

const ME = {id: 'u-samie', full_name: 'Samie Assistant'};
const USERS = {
    'u-samie': {full_name: 'Samie Assistant', status: 'Active'},
    'u-lynn': {full_name: 'Lynn Seller', status: 'Active'},
    'u-rich': {full_name: 'Rich Seller', status: 'Active'},
    'u-gone': {full_name: 'Gone Seller', status: 'Inactive'},
};
const ACCOUNTS = {
    'a-lynn': 'u-lynn', 'a-rich': 'u-rich', 'a-gone': 'u-gone', 'a-none': '', 'a-samie': 'u-samie',
};
const ACCOUNT_FIELD = {Quotes: 'billing_account_id', Opportunities: 'account_id'};

/** The server: every request is recorded and answered on a later turn; an account's read can be held back. */
function serve(page) {
    page.requests = [];
    page.out = 0;
    page.hold = {};
    page.refuse = null;
    // What Sugar does with a failed request whose caller gave no error callback: its default handler, which alerts.
    page.defaultErrors = [];
    page.app.api.defaultErrorHandler = (error) => page.defaultErrors.push(error);
    page.app.api.call = (method, url, data, callbacks) => {
        const u = new URL(String(url), 'https://sugar.example/');
        page.requests.push({method, path: u.pathname, query: u.searchParams, data});
        page.out += 1;
        let answer = {};
        let delay = 0;
        const account = /\/Accounts\/([^/]+)$/.exec(u.pathname);
        if (method === 'read' && account) {
            const owner = ACCOUNTS[account[1]];
            answer = {id: account[1], assigned_user_id: owner || '',
                assigned_user_name: owner ? USERS[owner].full_name : ''};
            delay = page.hold[account[1]] || 0;
        } else if (method === 'read' && /\/Users$/.test(u.pathname)) {
            const id = u.searchParams.get('filter[0][id][$equals]');
            const status = u.searchParams.get('filter[0][status][$equals]');
            answer = {next_offset: -1, records: USERS[id] && USERS[id].status === status
                ? [{id, full_name: USERS[id].full_name, _module: 'Users'}] : []};
        } else if (method === 'create') {
            answer = Object.assign({id: 'new-1'}, data);
        }
        const refused = page.refuse && page.refuse.test(u.pathname);
        const xhr = page.$.Deferred();
        setTimeout(() => {
            try {
                if (refused) {
                    // As Sidecar's API client does (ventana client.js 26.1): the caller's error callback, else the default.
                    const error = {status: 403, code: 'not_authorized', message: 'No access'};
                    if (callbacks && callbacks.error) {
                        callbacks.error(error);
                    } else if (typeof page.app.api.defaultErrorHandler === 'function') {
                        page.app.api.defaultErrorHandler(error);
                    }
                } else if (callbacks && callbacks.success) {
                    callbacks.success(answer);
                }
                if (callbacks && callbacks.complete) {
                    callbacks.complete();
                }
            } finally {
                page.out -= 1;
                xhr.resolve();
            }
        }, delay);
        return {xhr: xhr.promise()};
    };
}

async function settled(page) {
    for (let i = 0; i < 500; i++) {
        await sleep(5);
        if (page.out === 0) {
            await sleep(5);
            if (page.out === 0) {
                return;
            }
        }
    }
    throw new Error('the requests never settled');
}

async function sugarPage(t) {
    const page = await openSugarPage({});
    t.after(() => page.close());
    const {app} = page;
    const controller = fs.readFileSync(SUBJECT, 'utf8');
    // The server's components file always has its top sections; this page needs none of them.
    page.declare({fields: {base: {}}, modules: {Quotes: {views: {base: {'customBd-account-owner': controller}}},
        Opportunities: {views: {base: {'customBd-account-owner': controller}}}}});
    const relate = (name, idName, module) => ({name, id_name: idName, type: 'relate', module, rname: 'name'});
    app.metadata.set({
        modules: {
            Quotes: {fields: {id: {name: 'id', type: 'id'}, name: {name: 'name', type: 'name'},
                assigned_user_id: {name: 'assigned_user_id', type: 'id'},
                assigned_user_name: relate('assigned_user_name', 'assigned_user_id', 'Users'),
                billing_account_id: {name: 'billing_account_id', type: 'id'},
                billing_account_name: relate('billing_account_name', 'billing_account_id', 'Accounts'),
                account_id: {name: 'account_id', type: 'id'},
                account_name: relate('account_name', 'account_id', 'Accounts')}},
            Opportunities: {fields: {id: {name: 'id', type: 'id'}, name: {name: 'name', type: 'name'},
                assigned_user_id: {name: 'assigned_user_id', type: 'id'},
                assigned_user_name: relate('assigned_user_name', 'assigned_user_id', 'Users'),
                account_id: {name: 'account_id', type: 'id'},
                account_name: relate('account_name', 'account_id', 'Accounts')}},
            Accounts: {fields: {id: {name: 'id', type: 'id'}, name: {name: 'name', type: 'name'}}},
            Users: {fields: {id: {name: 'id', type: 'id'}}},
        },
        modules_info: {Quotes: {enabled: true}, Opportunities: {enabled: true}, Accounts: {enabled: true},
            Users: {enabled: true}},
    });
    app.data.declareModels();
    app.user.set(ME);
    serve(page);
    return page;
}

/** A create form's model as Sugar's create view leaves it (the creator a default), and this package's view on it. */
function openForm(page, module, attrs, link) {
    const {app} = page;
    const model = app.data.createBean(module);
    model.setDefault({assigned_user_id: ME.id, assigned_user_name: ME.full_name});
    if (link) {
        model.link = link;
    }
    model.set(attrs || {});
    const context = app.context.getContext({module, model, create: true});
    const view = app.view.createView({type: 'bd-account-owner', context, module, model});
    view.render();
    return {model, view};
}

const owner = (model) => [model.get('assigned_user_id'), model.get('assigned_user_name')];
const unsaved = (model) => _keys(model.changedAttributes(model.getDefault()) || {});
const _keys = (o) => Object.keys(o).filter((k) => /assigned_user/.test(k)).sort();

async function saveOf(page, model) {
    const before = page.requests.length;
    model.save(null, {});
    await settled(page);
    return page.requests.slice(before).filter((r) => r.method === 'create');
}

test('the shipped controller is the view Sidecar builds for the create layout\'s bd-account-owner, and draws nothing',
    async (t) => {
        const page = await sugarPage(t);
        const {view} = openForm(page, 'Quotes');
        assert.equal(view.constructor, page.app.view.views.BaseQuotesCustomBdAccountOwnerView);
        assert.equal(openForm(page, 'Opportunities').view.constructor,
            page.app.view.views.BaseOpportunitiesCustomBdAccountOwnerView);
        assert.equal(fs.readFileSync(SHIPPED[0], 'utf8'), fs.readFileSync(SHIPPED[1], 'utf8'),
            'the two modules ship the same controller');
        assert.equal(view.$el.text().trim(), '');
        await settled(page);
        assert.deepEqual(page.requests, [], 'no account yet: nothing read');
        assert.deepEqual(page.errors, []);
    });

['Quotes', 'Opportunities'].forEach((module) => {
    test(`${module}: picking an account owned by another active user puts that owner in Assigned To`, async (t) => {
        const page = await sugarPage(t);
        const {model} = openForm(page, module);
        assert.deepEqual(owner(model), [ME.id, ME.full_name], 'control: the creator, as Sugar\'s create view sets it');
        model.set(ACCOUNT_FIELD[module], 'a-lynn');
        await settled(page);
        assert.deepEqual(owner(model), ['u-lynn', 'Lynn Seller']);
        assert.deepEqual(page.requests.map((r) => r.method + ' ' + r.path), ['read /rest/v11_27/Accounts/a-lynn',
            'read /rest/v11_27/Users'], 'the account\'s owner, then whether that user is Active');
        assert.deepEqual(unsaved(model), [], 'not the person\'s change: Cancel does not ask about it');
        const [create] = await saveOf(page, model);
        assert.equal(create.data.assigned_user_id, 'u-lynn', 'saved as the owner');
        assert.equal(create.query.get('bd_owner_chosen'), null, 'no pick to report');
        assert.deepEqual(page.errors, []);
    });
});

// rc123: on a quote the top Account Name (account_id) counts too; with both set, the Billing Account decides.
test('Quotes: picking only the top Account Name (account_id) puts its owner in Assigned To', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes');
    model.set({account_id: 'a-lynn', account_name: 'Lynn\'s account'});
    await settled(page);
    assert.deepEqual(owner(model), ['u-lynn', 'Lynn Seller']);
    assert.deepEqual(page.requests.map((r) => r.method + ' ' + r.path), ['read /rest/v11_27/Accounts/a-lynn',
        'read /rest/v11_27/Users'], 'the account\'s owner, then whether that user is Active');
    assert.deepEqual(unsaved(model), [], 'not the person\'s change: Cancel does not ask about it');
    const [create] = await saveOf(page, model);
    assert.equal(create.data.assigned_user_id, 'u-lynn', 'saved as the owner');
    assert.equal(create.query.get('bd_owner_chosen'), null, 'no pick to report');
    assert.deepEqual(page.errors, []);
});

test('Quotes: a Billing Account picked after the top Account Name decides, and keeps deciding', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes', {account_id: 'a-lynn'});
    await settled(page);
    assert.deepEqual(owner(model), ['u-lynn', 'Lynn Seller'], 'the top Account Name\'s owner');
    model.set('billing_account_id', 'a-rich');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller'], 'the Billing Account\'s owner');
    model.set('account_id', 'a-gone');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller'], 'a later top Account Name does not override it');
    model.set('billing_account_id', '');
    await settled(page);
    assert.deepEqual(owner(model), [ME.id, ME.full_name], 'Billing Account cleared: the top one, owner Inactive');
    const [create] = await saveOf(page, model);
    assert.equal(create.query.get('bd_owner_chosen'), null, 'the view\'s own changes are not a pick');
});

test('Quotes: the person\'s pick stands over a later top Account Name', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes');
    model.set({assigned_user_id: 'u-rich', assigned_user_name: 'Rich Seller'});
    model.set('account_id', 'a-lynn');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
    assert.deepEqual(page.requests, [], 'nothing read once the person picked');
    const [create] = await saveOf(page, model);
    assert.equal(create.query.get('bd_owner_chosen'), '1');
});

test('a form opened from the account\'s subpanel (the account is the link\'s) is filled at once', async (t) => {
    const page = await sugarPage(t);
    const account = page.app.data.createBean('Accounts', {id: 'a-lynn', name: 'Lynn\'s account'});
    const {model} = openForm(page, 'Quotes', {}, {name: 'quotes', bean: account, isNew: true});
    await settled(page);
    assert.deepEqual(owner(model), ['u-lynn', 'Lynn Seller']);
    const other = openForm(page, 'Quotes', {}, {name: 'quotes_shipto', bean: account, isNew: true});
    await settled(page);
    assert.deepEqual(owner(other.model), [ME.id, ME.full_name], 'control: from the ship-to subpanel, the creator');
});

test('the person\'s pick stands: no later account changes it, and the save says so', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes');
    model.set({assigned_user_id: 'u-rich', assigned_user_name: 'Rich Seller'});
    model.set('billing_account_id', 'a-lynn');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
    assert.deepEqual(page.requests, [], 'nothing read once the person picked');
    const [create] = await saveOf(page, model);
    assert.equal(create.query.get('bd_owner_chosen'), '1');
});

test('a pick made while the account\'s owner is still being read stands when the answer comes', async (t) => {
    const page = await sugarPage(t);
    page.hold['a-lynn'] = 40;
    const {model} = openForm(page, 'Quotes', {billing_account_id: 'a-lynn'});
    await sleep(5);
    model.set({assigned_user_id: 'u-rich', assigned_user_name: 'Rich Seller'});
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
    // Picked back to themselves while the read is out: still their pick.
    const again = openForm(page, 'Quotes', {billing_account_id: 'a-lynn'});
    await sleep(5);
    again.model.set({assigned_user_id: 'u-rich', assigned_user_name: 'Rich Seller'});
    again.model.set({assigned_user_id: ME.id, assigned_user_name: ME.full_name});
    await settled(page);
    assert.deepEqual(owner(again.model), [ME.id, ME.full_name]);
});

test('the person picks themselves after the owner was filled: it stands, and the save says so', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Opportunities', {account_id: 'a-lynn'});
    await settled(page);
    assert.deepEqual(owner(model), ['u-lynn', 'Lynn Seller'], 'control: filled');
    model.set({assigned_user_id: ME.id, assigned_user_name: ME.full_name});
    model.set('account_id', 'a-rich');
    await settled(page);
    assert.deepEqual(owner(model), [ME.id, ME.full_name]);
    const [create] = await saveOf(page, model);
    assert.equal(create.query.get('bd_owner_chosen'), '1', 'the server keeps the creator (BdAccountOwner)');
});

test('an Inactive owner, no owner, or the creator\'s own account: the creator stays', async (t) => {
    const page = await sugarPage(t);
    for (const id of ['a-gone', 'a-none', 'a-samie']) {
        const {model} = openForm(page, 'Quotes', {billing_account_id: id});
        await settled(page);
        assert.deepEqual(owner(model), [ME.id, ME.full_name], id);
    }
});

test('another account: its owner; then one with no eligible owner: the creator again', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes', {billing_account_id: 'a-lynn'});
    await settled(page);
    model.set('billing_account_id', 'a-rich');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
    model.set('billing_account_id', 'a-gone');
    await settled(page);
    assert.deepEqual(owner(model), [ME.id, ME.full_name]);
    const [create] = await saveOf(page, model);
    assert.equal(create.query.get('bd_owner_chosen'), null, 'the view\'s own changes are not a pick');
});

test('a late answer for an earlier account never wins', async (t) => {
    const page = await sugarPage(t);
    page.hold['a-lynn'] = 60;
    const {model} = openForm(page, 'Quotes', {billing_account_id: 'a-lynn'});
    model.set('billing_account_id', 'a-rich');
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
});

test('a read the server refuses (the account, or the Users list) leaves the creator, with no alert', async (t) => {
    const page = await sugarPage(t);
    for (const refused of [/\/Users$/, /\/Accounts\//]) {
        page.refuse = refused;
        const {model} = openForm(page, 'Quotes', {billing_account_id: 'a-lynn'});
        await settled(page);
        assert.deepEqual(owner(model), [ME.id, ME.full_name], String(refused));
    }
    assert.ok(page.requests.some((r) => /\/Users$/.test(r.path)), 'control: the Users read was made, and refused');
    assert.deepEqual(page.defaultErrors, [], 'no failure reached Sugar\'s default handler (its alert)');
});

test('a form that came with another owner (a copy) keeps it', async (t) => {
    const page = await sugarPage(t);
    const {model} = openForm(page, 'Quotes', {assigned_user_id: 'u-rich', assigned_user_name: 'Rich Seller',
        billing_account_id: 'a-lynn'});
    await settled(page);
    assert.deepEqual(owner(model), ['u-rich', 'Rich Seller']);
});

test('CONTROL: on a record that exists (not a create) the view does nothing', async (t) => {
    const page = await sugarPage(t);
    const model = page.app.data.createBean('Quotes', {id: 'q-1', assigned_user_id: ME.id, billing_account_id: 'a-lynn'});
    const context = page.app.context.getContext({module: 'Quotes', model});
    page.app.view.createView({type: 'bd-account-owner', context, module: 'Quotes', model});
    model.set('billing_account_id', 'a-rich');
    await settled(page);
    assert.deepEqual(page.requests, []);
    assert.equal(model.get('assigned_user_id'), ME.id);
});

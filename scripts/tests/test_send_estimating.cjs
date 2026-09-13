/* Exercise the shipped Sidecar estimating handler without a Sugar session. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname,
    '../../sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/' +
    'bd-send-estimating/bd-send-estimating.js'), 'utf8');

function harness() {
    const requests = [];
    const alerts = [];
    const attributes = {};
    const control = {
        attr(key, value) { attributes[key] = value; return this; },
        prop(key, value) { attributes[key] = value; return this; },
    };
    const model = {
        values: {id: 'quote-1', erp_display_sync_key: ''},
        get(key) { return this.values[key]; },
        on() {},
        fetch(options) { this.fetchOptions = options; },
    };
    const app = {
        api: {
            buildURL: value => value,
            call: (method, url, body, callbacks) => requests.push({method, url, body, callbacks}),
        },
        alert: {
            show: (key, value) => alerts.push({kind: 'show', key, value}),
            dismiss: key => alerts.push({kind: 'dismiss', key}),
        },
        lang: {get: key => key},
    };
    const field = vm.runInNewContext(source, {app});
    Object.assign(field, {
        model,
        context: {on() {}},
        $el: {find: () => control, show() {}, hide() {}},
        _super() {},
    });
    return {field, app, model, requests, alerts, attributes};
}

test('two immediate clicks issue exactly one estimating request', () => {
    const {field, requests, attributes} = harness();
    field._onClicked();
    field._onClicked();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'create');
    assert.equal(requests[0].url, 'Quotes/quote-1/bd-send-to-estimating');
    assert.equal(attributes['aria-disabled'], true);
    assert.equal(attributes['aria-busy'], true);
    assert.equal(attributes.disabled, true);
});

test('successful handoff stays guarded through the Quote refresh', () => {
    const {field, model, requests, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({
        status: 'success', notification_status: 'created', message: 'Created'
    });
    field._onClicked();
    assert.equal(requests.length, 1);
    assert.ok(model.fetchOptions);
    assert.equal(attributes.disabled, true);
    model.values.erp_display_sync_key = '1201';
    model.fetchOptions.success();
    assert.equal(attributes['aria-disabled'], false);
    assert.equal(attributes['aria-busy'], false);
    assert.equal(attributes.disabled, false);
});

test('successful response stays guarded when refreshed ERP identity is absent', () => {
    const {field, model, requests, alerts, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({
        status: 'success', estimating_timestamp_status: 'pending_exact_mirror',
        notification_status: 'created', message: 'Created',
    });
    model.fetchOptions.success();
    assert.equal(attributes.disabled, true);
    field._onClicked();
    assert.equal(requests.length, 1);
    const identityWarning = alerts.find(entry =>
        entry.kind === 'show' && entry.key === 'bd-send-estimating-identity-pending');
    assert.equal(identityWarning.value.level, 'warning');
    assert.equal(identityWarning.value.autoClose, false);
    assert.equal(identityWarning.value.messages,
        'Kinetic accepted the Quote, but its ERP identity is not visible yet. ' +
        'Refresh to check the identity; do not retry unless an administrator verifies ' +
        'that no Kinetic quote exists.');
    assert.doesNotMatch(identityWarning.value.messages, /before retrying/i);
});

test('pending or ambiguous timestamp is never presented as all-green success', () => {
    for (const status of ['pending_exact_mirror', 'pending_timestamp_persistence', 'ambiguous_exact_mirror']) {
        const {field, requests, alerts} = harness();
        field._onClicked();
        requests[0].callbacks.success({
            status: 'success', estimating_timestamp_status: status,
            notification_status: 'created', message: 'Created',
        });
        const result = alerts.find(entry => entry.key === 'bd-send-estimating-done');
        assert.equal(result.value.level, 'warning');
        assert.match(result.value.messages, /pending exact ERP mirror verification/);
    }
});

test('notification failure is amber but never exposes an ERP retry', () => {
    const {field, model, requests, alerts, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({
        status: 'success',
        erp_handoff_status: 'completed',
        estimating_timestamp_status: 'stamped',
        notification_status: 'save_failed',
        notification_message: 'Use the In Estimating view.',
        message: 'Kinetic quote 1201 created.',
    });
    const result = alerts.find(entry => entry.key === 'bd-send-estimating-done');
    assert.equal(result.value.level, 'warning');
    assert.equal(result.value.autoClose, false);
    assert.match(result.value.messages, /Kinetic quote 1201 created/);
    assert.match(result.value.messages, /Use the In Estimating view/);
    assert.equal(attributes.disabled, true);
    field._onClicked();
    assert.equal(requests.length, 1);

    model.values.erp_display_sync_key = '1201';
    model.fetchOptions.success();
    assert.equal(requests.length, 1);
});

test('missing notification outcome is never shown as all-green success', () => {
    const {field, requests, alerts} = harness();
    field._onClicked();
    requests[0].callbacks.success({status: 'success', message: 'Created'});
    const result = alerts.find(entry => entry.key === 'bd-send-estimating-done');
    assert.equal(result.value.level, 'warning');
    assert.equal(result.value.autoClose, false);
    assert.match(result.value.messages, /could not confirm the in-app notification/);
});

test('application error unlocks for an explicit retry and never retries itself', () => {
    const {field, requests, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({status: 'error', message: 'Rejected'});
    assert.equal(requests.length, 1);
    assert.equal(attributes.disabled, false);
    field._onClicked();
    assert.equal(requests.length, 2);
});

test('partial ERP success refreshes identity and does not expose a blind retry', () => {
    const {field, model, requests, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({
        status: 'error', partial_success: true, retry_safe: false,
        erp_id: '1201', message: 'Refresh before recovery',
    });
    assert.equal(requests.length, 1);
    assert.ok(model.fetchOptions);
    assert.equal(attributes.disabled, true);
    field._onClicked();
    assert.equal(requests.length, 1);

    model.values.erp_display_sync_key = '1201';
    model.fetchOptions.success();
    assert.equal(attributes.disabled, false);
});

test('partial ERP success stays guarded when refreshed identity is absent', () => {
    const {field, model, requests, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.success({status: 'error', partial_success: true});
    model.fetchOptions.success();
    assert.equal(attributes.disabled, true);
    field._onClicked();
    assert.equal(requests.length, 1);
});

test('transport error unlocks for an explicit retry', () => {
    const {field, requests, attributes} = harness();
    field._onClicked();
    requests[0].callbacks.error({message: 'Unavailable'});
    assert.equal(requests.length, 1);
    assert.equal(attributes.disabled, false);
    field._onClicked();
    assert.equal(requests.length, 2);
});

test('synchronous transport failure releases the guard and remains observable', () => {
    const {field, app, attributes} = harness();
    const failure = new Error('transport unavailable');
    app.api.call = () => { throw failure; };
    assert.throws(() => field._onClicked(), error => error === failure);
    assert.equal(attributes['aria-disabled'], false);
    assert.equal(attributes['aria-busy'], false);
    assert.equal(attributes.disabled, false);
});

test('render preserves an in-flight guard', () => {
    const {field, attributes} = harness();
    field._sendPending = true;
    field._render();
    assert.equal(attributes['aria-disabled'], true);
    assert.equal(attributes.disabled, true);
});

'use strict';

/**
 * G574 — drives SugarCRM's OWN EnumField (clients/base/fields/enum/enum.js) the
 * way a new quote's create form does, with the Project picker's real field def
 * (this package's vardef) and the option list exactly as the browser receives it
 * (BdAdmRules::optionsFromRows() -> json_encode -> JSON.parse).
 *
 * Run by scripts/tests/test_g574_g578_bench_quote_pickers.py, which builds the
 * input with PHP and pipes it here as JSON on stdin:
 *
 *   {"tree": "<SugarEnt-Full-x.y.z>", "field": "bd_project_id",
 *    "def": {...the vardef...}, "options_json": "{\"\":\"\",\"17879\":...}"}
 *
 * Prints one JSON line: the keys in the order the browser lists them, and every
 * model.setDefault() the stock field made on the create form.
 *
 * Nothing here is a copy of Sugar's logic: the controller object is Sugar's file,
 * evaluated as Sugar evaluates it (a parenthesised object literal), and the
 * underscore it runs on is the tree's own, with sidecar's own mixins.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const tree = input.tree;

const _ = require(path.join(tree, 'sidecar/node_modules/underscore/underscore-umd.js'));
global._ = _;
_.mixin(require(path.join(tree, 'sidecar/src/utils/underscore-mixins.js')));

const source = fs.readFileSync(path.join(tree, 'clients/base/fields/enum/enum.js'), 'utf8');
const app = {
    acl: {hasAccessToModel: () => true},
    lang: {getAppListKeys: () => []},
    metadata: {getEditableDropdownFilter: () => ({})},
};
const EnumField = vm.runInNewContext(source, {app: app, _: _});

// The browser's view of the REST enum answer: JSON.parse, so the key order is
// JavaScript's (integer-like keys first), not PHP's.
const items = JSON.parse(input.options_json);

const defaults = [];
const model = {
    attributes: {},
    has(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); },
    get(name) { return this.attributes[name]; },
    setDefault(name, value) { defaults.push([name, value]); this.attributes[name] = value; },
};

const field = Object.assign(Object.create(null), EnumField, {
    name: input.field,
    def: input.def,
    items: items,
    model: model,
    action: 'create',
    view: {action: 'create'},
    _keysOrder: null,
});

field._checkForDefaultValue(model.get(input.field), _.keys(items));

process.stdout.write(JSON.stringify({keys: Object.keys(items), defaults: defaults}) + '\n');

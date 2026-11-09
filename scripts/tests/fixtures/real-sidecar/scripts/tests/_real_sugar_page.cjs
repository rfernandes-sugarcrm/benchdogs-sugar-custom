'use strict';
// A PAGE RUNNING SUGAR'S OWN FRONT-END CODE, FOR TESTS THAT MUST NOT RESTATE IT.
//
// A test that draws a shipped controller over a hand-written copy of Sidecar tests the author's reading of
// Sidecar. This opens a DOM (jsdom) and loads into it the files Sugar itself serves, recorded from a SugarEnt
// 26.1.0 source tree into sugar-sell/ERP-Core/tests/support/sugar-26.1/ (each recording opens with one line
// naming its stock path and the stock file's sha256, and is refused here if its body no longer hashes to it):
//
//   * sidecar/minified/sidecar.min.js: jQuery, underscore, Backbone, Handlebars and all of Sidecar (the app,
//     the metadata manager, the view manager, Component, View, Field, the plugin manager, Bean, Context);
//   * the files of Sugar's two script bundles a test asks for, loaded in the order Sugar's own bundle list
//     (jssource/JSGroupings.php) loads them;
//   * controllers and templates, handed to Sidecar the way the server hands them over: one components file
//     shaped as MetaDataManager::buildJavascriptComponentFile() writes it, declared by Sidecar's own
//     metadata manager, and templates inside the metadata, license comment trimmed as the server trims it.
//
// What is NOT Sugar's here, and why:
//   * the DOM is jsdom: no layout (every width is 0), no real pointer, no CSS;
//   * `Modernizr` is one flag, `touch: false` (a desktop browser);
//   * the app's configuration is a short object in the shape the server writes cache/config.js (that file is
//     generated per instance and holds nothing a recording could carry);
//   * nothing is fetched: no sign-in, no metadata sync, no record read. Metadata and labels are given by the
//     test, which says where each came from.
//
// The Sugar instances these packages run on are 26.2; the recorded tree is 26.1.0. A test that depends on
// something 26.2 changed says so. A page can also run on Sugar 26.2.0's own files (options.sugar '26.2.0'):
// sugar-26.2/ holds the few a test asks for, recorded from the static files a 26.2.0 build 292 instance serves
// (no 26.2 source tree is at hand), in the same form and checked the same way.
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {createRequire} = require('node:module');

const REPO = path.resolve(__dirname, '../..');
const SUPPORT = path.join(REPO, 'sugar-sell/ERP-Core/tests/support');
const STOCK = path.join(SUPPORT, 'sugar-26.1');
const STOCK_VERSION = '26.1.0';
const HEADER = /Recorded from SugarEnt (\d+\.\d+\.\d+) \(build (\d+)\): (\S+); sha256 ([0-9a-f]{64}) of the stock file/;
// Where a SugarEnt tree may sit on a developer's machine; CI has none.
const STOCK_TREE = process.env.SUGAR_SRC || path.join(os.homedir(), 'Documents/Code/SugarEnt-Full-26.1.0');

/** jsdom, from the pinned folder beside this file. A missing install fails the test: it never skips. */
function jsdom() {
    try {
        return createRequire(path.join(__dirname, 'dom', 'package.json'))('jsdom');
    } catch (e) {
        throw new Error('jsdom is not installed, so no test that needs a page can run. Install the pinned copy with: '
            + 'npm ci --ignore-scripts --prefix scripts/tests/dom   (' + e.message.split('\n')[0] + ')');
    }
}

function sha256(buffer) {
    return crypto.createHash('sha256').update(buffer).digest('hex');
}

/** A recorded stock file, checked: its header names this path and version, and its body is the stock bytes. */
function stock(stockPath, version) {
    const want = version || STOCK_VERSION;
    const file = path.join(SUPPORT, 'sugar-' + want.split('.').slice(0, 2).join('.'), stockPath);
    const raw = fs.readFileSync(file);
    const cut = raw.indexOf(0x0a);
    const header = HEADER.exec(raw.subarray(0, cut).toString('utf8'));
    if (!header) {
        throw new Error(stockPath + ': the recording has no header line');
    }
    const body = raw.subarray(cut + 1);
    if (header[1] !== want || header[3] !== stockPath) {
        throw new Error(stockPath + ': the header names ' + header[3] + ' of SugarEnt ' + header[1]);
    }
    if (sha256(body) !== header[4]) {
        throw new Error(stockPath + ': the recording was edited; its body no longer hashes to the stock file\'s sha256');
    }
    return body.toString('utf8');
}

/** A stock PHP array recorded as JSON (a view definition, labels): {recorded, kept, <variable>: ...}. */
function stockArray(stockPath, variable) {
    const data = JSON.parse(fs.readFileSync(path.join(STOCK, stockPath + '.json'), 'utf8'));
    const header = HEADER.exec(String(data.recorded));
    if (!header || header[1] !== STOCK_VERSION || header[3] !== stockPath || !(variable in data)) {
        throw new Error(stockPath + '.json: not a recording of $' + variable + ' from that stock file');
    }
    return data[variable];
}

/**
 * Where a SugarEnt 26.1.0 tree is present, every recorded file is compared with it; the list of differences
 * (none expected), or null when there is no tree to compare with.
 */
function recordingsThatDifferFromTheTree() {
    if (!fs.existsSync(path.join(STOCK_TREE, 'sugar_version.php'))
        || !fs.readFileSync(path.join(STOCK_TREE, 'sugar_version.php'), 'utf8').includes("'" + STOCK_VERSION + "'")) {
        return null;
    }
    const differ = [];
    (function walk(dir) {
        fs.readdirSync(dir, {withFileTypes: true}).forEach((entry) => {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                return walk(full);
            }
            const rel = path.relative(STOCK, full);
            if (rel.endsWith('.php.json')) {
                const data = JSON.parse(fs.readFileSync(full, 'utf8'));
                const header = HEADER.exec(String(data.recorded)) || [];
                if (sha256(fs.readFileSync(path.join(STOCK_TREE, rel.slice(0, -5)))) !== header[4]) {
                    differ.push(rel);
                }
                return undefined;
            }
            stock(rel);
            const raw = fs.readFileSync(full);
            if (!raw.subarray(raw.indexOf(0x0a) + 1).equals(fs.readFileSync(path.join(STOCK_TREE, rel)))) {
                differ.push(rel);
            }
            return undefined;
        });
    })(STOCK);
    return differ;
}

/** A template as the server puts it in the metadata (MetaDataFiles::trimLicense, 26.1 lines 1481-1491). */
function servedTemplate(text) {
    if (text.slice(0, 3) === '{{!') {
        return text.slice(text.indexOf('}}') + 2);
    }
    return text;
}

/**
 * The components file as the server writes it (MetaDataManager::buildJavascriptComponentFile and
 * buildJavascriptComponentSection, 26.1 lines 3158-3270): every controller's source, as written in its file,
 * inside one function that is called with the app.
 *
 * @param {Object} data {fields: {<platform>: {<name>: <source>}}, modules: {<module>: {fieldTemplates|views|
 *   layouts: {<platform>: {<name>: <source>}}}}}. A controller a module ships under custom/ is named
 *   'custom' + its capitalised name, as MetaDataFiles::getClientFileContents names it (26.1 lines 1180-1182).
 */
function componentsFile(data) {
    const section = (types) => Object.keys(types).map((type) => JSON.stringify(type) + ': {\n'
        + Object.keys(types[type]).map((platform) => JSON.stringify(platform) + ': {\n'
            + Object.keys(types[type][platform]).map((name) => JSON.stringify(name) + ': {"controller": '
                + types[type][platform][name].trim().replace(/^[,;]+|[,;]+$/g, '') + ' }').join(',\n')
            + '\n}').join(',\n') + '}\n').join(',\n') + '\n';
    const {modules, ...top} = data;
    let js = '(function(app) {\n SUGAR.jssource = {' + section(top);
    if (modules && Object.keys(modules).length) {
        js += ',\n\t"modules":{' + Object.keys(modules).map((module) => '\n\t\t' + JSON.stringify(module) + ':{'
            + section(modules[module]) + '}').join(',') + '\n\t}';
    }
    return js + '}})(SUGAR.App);\n';
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Opens a page, loads Sidecar and the stock scripts asked for, and starts the app as Sugar's index page does.
 *
 * @param {Object} options
 * @param {string} [options.sugar] The Sugar version whose recorded files run: '26.1.0' (the default) or '26.2.0'.
 * @param {string[]} options.beforeConfig Stock paths loaded after Sidecar and before the configuration (the
 *   files of Sugar's sugar_sidecar bundle the test needs).
 * @param {string[]} options.afterConfig Stock paths loaded after the configuration (files of sugar_grp7).
 * @param {Array<{name: string, source: string}>} [options.alsoLoad] Other scripts loaded before the app
 *   starts (a recorded plugin of another Sugar version, a shipped bundle file), in order.
 * @return {Promise<Object>} {window, app, $, _, errors, declare(components), close()}.
 */
async function openSugarPage(options) {
    const {JSDOM, VirtualConsole} = jsdom();
    const errors = [];
    const firstLines = (e) => String((e && (e.stack || e.message)) || e).split('\n').slice(0, 3).join(' | ');
    const virtualConsole = new VirtualConsole();
    // An exception inside an event handler does not reach the code that fired the event; a browser prints it
    // to the console. jsdom reports it here, and the app's own logger writes its errors here too.
    virtualConsole.on('jsdomError', (e) => errors.push(firstLines(e.detail || e)));
    virtualConsole.on('error', (...args) => errors.push('console.error: ' + args.map(String).join(' ').slice(0, 400)));
    const dom = new JSDOM('<!doctype html><html><body><div id="sugarcrm"><div id="sidecar">'
        + '<div id="alerts" class="alert-top"></div><main><div id="content"></div></main>'
        + '<div id="drawers"></div></div></div></body></html>',
        {runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole, url: 'https://sugar.example/'});
    const window = dom.window;
    const load = (name, source) => {
        try {
            window.eval(source + '\n//# sourceURL=' + name);
        } catch (e) {
            throw new Error('loading ' + name + ' failed: ' + firstLines(e));
        }
    };
    window.Modernizr = {touch: false};
    load('sidecar/minified/sidecar.min.js', stock('sidecar/minified/sidecar.min.js', options.sugar));
    (options.beforeConfig || []).forEach((stockPath) => load(stockPath, stock(stockPath, options.sugar)));
    // cache/config.js, as ModuleInstaller::getJSConfig writes it (26.1 lines 3559-3565), with the keys of
    // getBaseConfig the loaded code reads. syncConfig false: nothing is fetched.
    load('cache/config.js', '(function(app) {app.augment("config", ' + JSON.stringify({
        appId: 'SugarCRM', env: 'prod', platform: 'base', alertsEl: '#alerts', alertAutoCloseDelay: 2500,
        serverUrl: 'rest/v11_27', siteUrl: '', unsecureRoutes: ['login', 'logout', 'error'], loadCss: false,
        themeName: 'default', clientID: 'sugar', additionalComponents: {}, syncConfig: false,
        logger: {level: 'WARN'}, uniqueKey: 'recorded-page', metadataTypes: [],
    }) + ', false);})(SUGAR.App);');
    (options.afterConfig || []).forEach((stockPath) => load(stockPath, stock(stockPath, options.sugar)));
    (options.alsoLoad || []).forEach((script) => load(script.name, script.source));
    // Bootstrap gives jQuery its plugins once the document is ready.
    if (window.document.readyState === 'loading') {
        await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve));
    }
    await sleep(0);
    // The index page's own start (a Sugar 26.2 instance's served index page): App.init, whose callback calls
    // app.start(). Every module's init runs and 'app:init' fires, which is when plugins register; start makes the
    // router. Nothing signs in, so nothing is fetched.
    let app;
    try {
        app = window.SUGAR.App.init({el: '#sidecar', contentEl: '#content', callback: (started) => started.start()});
    } catch (e) {
        window.close();
        throw new Error('the app did not start: ' + firstLines(e));
    }
    return {
        window,
        app,
        $: window.jQuery,
        _: window._,
        errors,
        /** Hands controllers to Sidecar as the server does, and lets its metadata manager declare them. */
        declare(components) {
            load('cache/javascript/base/components.js', componentsFile(components));
            app.metadata._declareClasses(window.SUGAR.jssource);
        },
        close() {
            window.close();
        },
    };
}

module.exports = {REPO, SUPPORT, stock, stockArray, servedTemplate, componentsFile, openSugarPage,
    recordingsThatDifferFromTheTree, sleep};

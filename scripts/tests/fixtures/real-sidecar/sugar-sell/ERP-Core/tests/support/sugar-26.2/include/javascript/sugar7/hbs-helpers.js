// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/hbs-helpers.js; sha256 f7bb7253eee5ce90e983c6ec3ed8a46fab2fad6ed6e329662b0e20a031d55d63 of the stock file, which follows unchanged.
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */

/**
 * Handlebars helpers.
 *
 * These functions are to be used in handlebars templates.
 * @class View.Handlebars.helpers
 */
(function(app) {
    app.events.on('app:init', function() {
        /**
         * Gets the letters used for the icons shown in various headers for
         * each module, based on the translated singular module name.
         *
         * This does not always match the name of the module in the model,
         * e.g. "Product" maps to "Quoted Line Item".
         *
         * If the module has an icon string defined, use it, otherwise
         * fallback to the module's translated name.
         *
         * If there are spaces in the name, (e.g. Revenue Line Items or
         * Product Catalog), it takes the initials from the first two words,
         * instead of the first two letters (e.g. RL and PC, instead of Re
         * and Pr).
         *
         * @param {string} module Module to which the icon belongs.
         */
        Handlebars.registerHelper('moduleIconLabel', function(module) {
            return app.lang.getModuleIconLabel(module);
        });

        /**
         * Handlebar helper to get the Tooltip used for the icons shown in various headers for each module, based on the
         * translated singular module name.  This does not always match the name of the module in the model,
         * i. e. Product == Revenue Line Item
         * @param {string} module to which the icon belongs
         */
        Handlebars.registerHelper('moduleIconToolTip', function(module) {
            return app.lang.getModuleName(module);
        });

        /**
         * Handlebar helper to translate any dropdown values to have the appropriate labels
         * @param {string} value The value to be translated.
         * @param {string} key The dropdown list name.
         */
        Handlebars.registerHelper('getDDLabel', function(value, key) {
            return app.lang.getAppListStrings(key)[value] || value;
        });

        /**
         * Handlebar helper to retrieve a view template as a sub template
         * @param {string} key Key for the template to retrieve.
         * @param {object} data Data to pass into the compiled template
         * @param {object} options (optional) Optional parameters
         * @returns {string} String Template
         */
        Handlebars.registerHelper('subViewTemplate', function(key, data, options) {
            var frame; var template;

            template = app.template.getView(key, options.hash.module);

            // merge the hash variables into the frame so they can be added as
            // private @variables via the data option below
            frame = _.extend(Handlebars.createFrame(options.data || {}), options.hash);

            return template ? template(data, {data: frame}) : '';
        });

        /**
         * Handlebar helper to retrieve a field template as a sub template
         * @param {string} fieldName determines which field to use.
         * @param {string} view determines which template within the field to use.
         * @param {object} data Data to pass into the compiled template
         * @param {object} options (optional) Optional parameters
         * @returns {string} String Template
         */
        Handlebars.registerHelper('subFieldTemplate', function(fieldName, view, data, options) {
            var frame; var template;

            template = app.template.getField(fieldName, view, options.hash.module);

            // merge the hash variables into the frame so they can be added as
            // private @variables via the data option below
            frame = _.extend(Handlebars.createFrame(options.data || {}), options.hash);

            return template ? template(data, {data: frame}) : '';
        });

        /**
         * Handlebar helper to retrieve a layout template as a sub template
         * @param {string} key Key for the template to retrieve.
         * @param {object} data Data to pass into the compiled template
         * @param {object} options (optional) Optional parameters
         * @returns {string} String Template
         */
        Handlebars.registerHelper('subLayoutTemplate', function(key, data, options) {
            var frame; var template;

            template = app.template.getLayout(key, options.hash.module);

            // merge the hash variables into the frame so they can be added as
            // private @variables via the data option below
            frame = _.extend(Handlebars.createFrame(options.data || {}), options.hash);

            return template ? template(data, {data: frame}) : '';
        });

        /**
         * @function buildUrl
         * Builds an URL based on hashes sent on handlebars helper.
         *
         * Example:
         * <pre><code>
         * {{buildUrl url="path/to/my-static-file.svg"}}
         * </code></pre>
         *
         * @see Utils.Utils#buildUrl to know how we are building the url.
         *
         * @param {object} options
         *   The hashes being sent by handlebars helper. Currently requires
         *   `options.hash.url` until we extend this to be used for image
         *   fields.
         * @returns {string}
         *   The safely built url.
         */
        Handlebars.registerHelper('buildUrl', function(options) {
            return new Handlebars.SafeString(app.utils.buildUrl(options.hash.url));
        });

        /**
         * @function loading
         * Display animated loading message.
         *
         * To display loading message with default markup:
         *
         *     {{loading 'LBL_ALERT_TITLE_LOADING' }}
         *
         * You can also apply specific css classes:
         *
         *     // this will add the class `someCssClass` to `div.loading`.
         *     {{loading 'LBL_ALERT_TITLE_LOADING' cssClass='someCssClass'}}
         *
         * @param {object} [options] Optional params.
         * @param {object} [options.hash] The hash of the optional params.
         * @param {string} [options.hash.cssClass] A space-separated list of
         *   classes to apply to `div.loading`.
         */
        Handlebars.registerHelper('loading', function(str, options) {
            str = app.lang.get(str);
            var cssClass = ['loading'];
            if (_.isString(options.hash.cssClass)) {
                cssClass = _.unique(cssClass.concat(
                    Handlebars.Utils.escapeExpression(options.hash.cssClass).split(' '),
                ));
            }
            return new Handlebars.SafeString(
                '<div class="' + cssClass.join(' ') + '">' +
                `<span role="alert" aria-live="polite">${Handlebars.Utils.escapeExpression(str)}</span>` +
                '<i class="l1">&#46;</i><i class="l2">&#46;</i><i class="l3">&#46;</i>' +
                '</div>',
            );
        });

        Handlebars.registerHelper('decoratedField', function(type, view, options) {
            var def = {
                type: type,
                field: this,
            };

            var field = app.view.createField({
                type: type,
                def: def,
                viewDefs: def,
                view: view,
                model: options.hash.model,
                viewName: options.hash.template,
            });

            if (options.hash.parent && _.isArray(options.hash.parent.fields)) {
                options.hash.parent.fields.push(field);
            }

            return field.getPlaceholder();
        });

        Handlebars.registerHelper('timeAgo', function(str, options) {
            return moment ? moment.utc(str).fromNow() : str;
        });

        /**
         * Helper implements a boolean operation "OR"
         * @param Variable number of parameters
         * @returns {boolean}
         */
        Handlebars.registerHelper('or', function(...args) {
            for (let i = 0; i < args.length - 1; i++) {
                if (!!args[i]) {
                    return true;
                }
            }

            return false;
        });

        /**
         * Helper implements a utility that allows a block of template to be run a given number of times
         * @param n number of times the block should run
         * Usage:
         *
         *   {{#times <number>}}
         *      ...
         *   {{/times}}
         *
         * Example:
         *   {{#times count>}}
         *      ...
         *   {{/times}}
         * @returns {string} Result of the `block` being executed n times
         */
        Handlebars.registerHelper('times', function(n, block) {
            let accum = '';
            for (let i = 0; i < n; i++) {
                accum += block.fn(i);
            }
            return accum;
        });

        /**
         * Helpers implements a utility that allows different versions of icons to be rendered.
         * @param iconClass defined icon class passed in through metadata
         * @returns {string} iconClass with its based font class
         * Usage:
         *
         *   {{buildIcon <icon-class>}}
         *
         * Example:
         *
         *   {{buildIcon 'sicon-plus'}} returns 'sicon sicon-plus'
         */
        Handlebars.registerHelper('buildIcon', function(iconClass) {
            return iconClass.includes('sicon') ?
                `sicon ${iconClass}` :
                `fa ${iconClass}`;
        });

        /**
         * Helper that returns the HTML to display a module label. Takes in the
         * module name and the size of the label to create, but also supports
         * any number of optional HTML attributes to apply to the element.
         * Label color and contents (either text or a SugarIcon) are
         * automatically added based on the stored module settings in metadata.
         *
         * Usage:
         *
         * {{moduleLabel <module> <size> <attr>="<value>"...}}
         *
         * Example:
         *
         * {{moduleLabel 'Accounts' 'lg' rel="tooltip" data-bs-placement="right" title="tooltip"}}
         *
         * When the Accounts color is set to "green" and is set to show by
         * the abbreviation "Ac", the above returns:
         *
         * <span class="label-module label-module-size-lg
         *     label-module-color-green" rel="tooltip"
         *     data-bs-placement="right" title="tooltip">
         *     Ac
         * </span>
         *
         * @param {string} module The name of the module
         * @param {string} size The size of the label to create ('sm' or 'lg'), default is 'sm'
         * @param {object} options The optional params, supporting any key/value HTML attribute pairs
         */
        Handlebars.registerHelper('moduleLabel', function(module, size, options) {
            let contents = '';
            let attributes = _.clone(options.hash) || {};
            attributes.class = attributes.class ? `${attributes.class} label label-module` : 'label label-module';

            // Determine the size based on the passed in option
            size = _.contains(['sm', 'lg'], size) ? size : 'sm';
            attributes.class += ` label-module-size-${size}`;

            // Determine the color and contents based on module metadata
            const moduleMeta = app.metadata.getModule(module) || {};

            let color = _.has(attributes, 'color') ? attributes.color : moduleMeta.color || 'ocean';
            attributes.class += ` label-module-color-${color}`;

            if (moduleMeta.display_type === 'abbreviation') {
                contents = app.lang.getModuleIconLabel(module);
            } else {
                const icon = moduleMeta.icon || 'sicon-default-module-lg';
                attributes.class += ` sicon ${icon}`;
            }

            // Build the attributes string and return the element HTML
            attributes = _.reduce(attributes, function(string, value, key) {
                const escaped = _.escape(value);
                return `${string} ${key}="${escaped}"`;
            }, '');

            return new Handlebars.SafeString(
                `<span ${attributes.trim()}>${contents.trim()}</span>`,
            );
        });

        /**
         * Helper that returns the HTML to display a tooltip.
         * Bootstrap v5 doesn't support different instances for one element.
         * For example, it's impossible to use dropdown and tooltip for the same <a> tag.
         *
         * Usage:
         *
         * {{tooltipTemplate <title> <placement>}}
         *
         * Examples:
         *
         * {{tooltipTemplate (str 'LBL_TEST_STRING' 'Accounts') 'bottom'}}
         * Placement is 'top' by default in Bootstrap:
         * {{tooltipTemplate (str 'LBL_TEST_STRING')}}
         *
         * Example of generated template:
         *
         * <span class="absolute h-full w-full top-0 left-0" rel="tooltip"
         *        data-bs-placement="bottom" title="Test String"></span>
         *
         * @param {string} title The title
         * @param {string} placement The placement
         */
        Handlebars.registerHelper('tooltipTemplate', function(title, placement) {
            let html = `<span class="absolute h-full w-full top-0 left-0" rel="tooltip" `;

            if (placement && _.isString(placement)) {
                html += `data-bs-placement="${placement}" `;
            }

            html += `data-original-title="${title}"></span>`;
            return new Handlebars.SafeString(html);
        });

        /**
         * Helper that checks if the current language direction is RTL
         * Usage:
         *
         * {{#isRTL}}
         *    ... content for RTL languages ...
         * {{else}}
         *    ... content for LTR languages ...
         * {{/isRTL}}
         */
        Handlebars.registerHelper('isRTLLang', function(options) {
            return (app.lang.direction === 'rtl') ?
                options.fn(this) :
                options.inverse(this);
        });

        /**
         * Helper that checks if a string starts with a specific prefix.
         * @param {string} str The string to check.
         * @param {string} prefix The prefix to match against.
         * @returns {boolean} True if string starts with prefix, false otherwise.
         */
        Handlebars.registerHelper('startsWith', function(str, prefix) {
            return str && typeof str === 'string' && str.startsWith(prefix);
        });

        /**
         * Helper that returns the class list needed to build the container for
         * a module label. The classes will create the container with the given
         * size, and the color scheme of the given module.
         *
         * Usage:
         *
         * {{moduleLabelContainer 'Accounts' 'lg'}}
         *
         * When the Accounts color is set to "green", the above returns:
         *
         * label-module label-module-size-lg label-module-color-green
         *
         * @param {string} module The name of the module
         * @param {string} size The size of the label to create ('sm' or 'lg'), default is 'sm'
         */
        Handlebars.registerHelper('moduleLabelContainer', function(module, size) {
            let moduleMeta = app.metadata.getModule(module) || {};
            let color = moduleMeta.color || 'ocean';
            size = _.contains(['sm', 'lg'], size) ? size : 'sm';
            return `label label-module label-module-size-${size} label-module-color-${color}`;
        });

        /**
         * Creates a field widget.
         *
         * Example:
         * ```
         * {{reportField view model=mymodel index=1 template=edit parent=fieldset}}
         * ```
         *
         * @param {View} view Parent view
         * @param {object} [options] Optional params to pass to the field.
         * @param {Backbone.Model} [options.model] The model associated with the field.
         * @param {string} [options.template] The name of the template to be used.
         * @param {Field} [options.parent] The parent field of this field.
         * @returns {Handlebars.SafeString} HTML placeholder for the widget.
         */
        Handlebars.registerHelper('reportField', function(view, options) {
            const parentModel = options.hash.model;
            const index = options.hash.index;
            const html = options.hash.html;
            const model = parentModel.get(index);

            // we need to dereference this, because we might change its type
            let self = this;

            // do try-catch because JSON.parse might throw error on some poorly formatted strings
            try {
                // eslint-disable-next-line consistent-this
                self = app.utils.deepCopy(this);
            } catch {
                // we don't need to do anything on error catch
                // it will use the first init of currentContext
            }

            // change type to text when we encounter a relate field without id
            if (_.isEmpty(model.get('id')) && self.link) {
                self.type = 'text';
                self.link = false;
            }

            if (self.type === 'currency') {
                const hasModel = !!model;
                const isUsDollar = hasModel && model.has('is_us_dollar') && model.get('is_us_dollar') === true;
                const showPreffered = app.user.getPreference('currency_show_preferred');

                if (hasModel && model.has('showTransactionalAmount')) {
                    self.showTransactionalAmount = model.get('showTransactionalAmount');
                } else {
                    self.showTransactionalAmount = showPreffered;
                }

                if (hasModel && model.has('convertToBase')) {
                    self.convertToBase = model.get('convertToBase');
                } else {
                    self.convertToBase = showPreffered;
                }

                if (isUsDollar) {
                    self.is_base_currency = true;
                    self.ignoreUserPrefCurrency = true;
                } else {
                    self.currency_field = 'currency_id';
                    self.base_rate_field = 'base_rate';
                }
            }

            const field = SUGAR.App.view.createField({
                def: self,
                viewDefs: self,
                view: view,
                model: model,
                viewName: options.hash.template,
            });

            if (options.hash.parent && _.isArray(options.hash.parent.fields)) {
                options.hash.parent.fields.push(field);
            }

            if (field.type === 'htmleditable_tinymce') {
                field.render();

                return new Handlebars.SafeString(field.$el.html());
            }

            if (html) {
                if (field.type === 'enum') {
                    if (_.has(self, 'options')) {
                        field.items = app.lang.getAppListStrings(self.options);
                    } else if (_.has(self, 'function') && !_.isUndefined(view.data.functionOptions) &&
                        !_.isUndefined(view.data.functionOptions[self.function])) {
                        field.items = view.data.functionOptions[self.function];
                    }
                }
                field.render();

                if (field.type === 'image' && field.value) {
                    field.resizeWidget();
                }

                return field.$el.html();
            }

            return field.getPlaceholder();
        });

        /**
         * Helper that returns true if a value is found inside an object false otherwise
         *
         * Usage:
         *
         * {{includes myObject 'myValue'}}
         *
         * @param {object} haystack The object which we search in
         * @param {string} needle The value we're searching for
         */
        Handlebars.registerHelper('includes', function(haystack, needle, options) {
            if (haystack && (_.has(haystack, needle) ||
                (_.isString(haystack) && _.isString(needle) &&
                    haystack.toLowerCase().includes(needle.toLowerCase())) ||
                (_.isArray(haystack) && _.isString(needle) && haystack.includes(needle))
            )) {
                return options.fn(this); // Key exists, execute the content within the block
            } else {
                return options.inverse(this); // Key does not exist, execute the else block
            }
        });

        /**
         * Sanitize user-controlled HTML
         *
         * Usage:
         * {{#sanitize}}
         *     {{{value}}}
         * {{/sanitize}}
         *
         * @returns {Handlebars.SafeString} sanitized HTML.
         */
        Handlebars.registerHelper('sanitize', function(options) {
            return new Handlebars.SafeString(DOMPurify.sanitize(options.fn(this), {
                ADD_ATTR: ['target'],
                FORBID_ATTR: ['style'],
                FORBID_TAGS: ['style'],
            }));
        });

        /**
         * Trim helper to remove extra spaces and new lines from a block of text.
         * That is useful when you want to format your handlebars templates nicely
         * but don't want the extra spaces and new lines to appear in the final output.
         *
         * Usage:
         * {{#trim}}
         *     ... text block ...
         * {{/trim}}
         *
         * Example:
         * <div class="{{#trim}}px-4 py-3 text-sm record-label align-top
         *             max-w-[23.75rem]{{/trim}}"> ... </div>
         * Will generate:
         * <div class="px-4 py-3 text-sm record-label align-top max-w-[23.75rem]"> ... </div>
         *
         * @returns {Handlebars.SafeString} trimmed text.
         */
        Handlebars.registerHelper('trim', function(options) {
            let str = options.fn(this);
            if (!_.isString(str)) {
                return str;
            }

            str = str.replace(/\s+/g, ' ').trim();
            return new Handlebars.SafeString(str);
        });

        /**
         * Handlebar helper to format a number to fixed decimal
         * @param {(number|string)} value The value of the number we want to format.
         * @param {number} decimals How many decimals we want the number to have.
         * @returns {number} The formatted value
         */
        Handlebars.registerHelper('formatDecimal', function(value, decimals) {
            return Number(value).toFixed(decimals);
        });

        /**
         * Check whether a parameter is an array and execute the appropriate Handlebars block.
         *
         * @param {*} target - The value to check.
         * @param {object} options - Handlebars options object containing fn and inverse.
         */
        Handlebars.registerHelper('isArray', function(target, options) {
            if (_.isArray(target)) {
                return options.fn(target); // target is array, execute the content within the block
            } else {
                return options.inverse(target); // target is not array, execute the else block
            }
        });

        /**
         * Check whether a parameter is an object (excluding string objects)
         * and execute the appropriate Handlebars block.
         *
         * @param {*} target - The value to check.
         * @param {object} options - Handlebars options object containing fn and inverse.
         */
        Handlebars.registerHelper('isObject', function(target, options) {
            if (
                typeof target === 'object' &&
                !(target instanceof String)
            ) {
                return options.fn(target); // target is object, execute the content within the block
            } else {
                return options.inverse(target); // target is not object, execute the else block
            }
        });

        /**
         * Check whether a parameter is a string and execute the appropriate Handlebars block.
         *
         * @param {*} target - The value to check.
         * @param {object} options - Handlebars options object containing fn and inverse.
         */
        Handlebars.registerHelper('isString', function(target, options) {
            if (_.isString(target)) {
                return options.fn(target); // target is string, execute the content within the block
            } else {
                return options.inverse(target); // target is not string, execute the else block
            }
        });

        /**
         * Check whether a record-view row has more than one label/value pair —
         * e.g. a paired "Open Deals"/"Expected Close" row meant to render as
         * two columns on one visual line instead of one column per row — and
         * execute the appropriate Handlebars block.
         *
         * @param {*} target - The row object to check.
         * @param {object} options - Handlebars options object containing fn and inverse.
         * @returns {Handlebars.SafeString} Rendered content of the `fn` block if the row has
         *   2+ keys, otherwise the rendered `inverse` block.
         */
        Handlebars.registerHelper('isMultiKeyRow', function(target, options) {
            if (target && typeof target === 'object' && Object.keys(target).length > 1) {
                return options.fn(target); // row has 2+ keys, execute the content within the block
            } else {
                return options.inverse(target); // row has 0-1 keys, execute the else block
            }
        });

        /**
         * Retrieves a property value from an object dynamically.
         *
         * Usage:
         * {{getValue someObject "propertyName"}}
         *
         * @param {object} obj The object to retrieve the property from.
         * @param {string} prop The property name to access on the object.
         * @returns {string} The value of the property, or an empty string if not found.
         */
        Handlebars.registerHelper('getValue', function(obj, prop) {
            return obj && obj[prop] ? obj[prop] : '';
        });

        /**
         * Allow only http(s) URLs for helper-generated hrefs. HTML-escaping is
         * not URL sanitization: javascript: and data: schemes must not render.
         *
         * @param {*} url
         * @returns {?string} Normalized http(s) href, or null
         */
        const toAllowedHttpUrl = function(url) {
            if (typeof url !== 'string') {
                return null;
            }

            try {
                const parsed = new URL(url.trim());
                if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                    return parsed.href;
                }
            } catch {
                return null;
            }

            return null;
        };

        /**
         * Restore a conservative allowlist of markdown/LLM body tags after
         * HTML-escaping. Attributes are not restored, so `<p onclick=...>`
         * stays escaped. Record titles and hrefs are escaped separately.
         *
         * @param {string} escaped
         * @returns {string}
         */
        const unescapeAllowedBodyTags = function(escaped) {
            return escaped.replace(
                /&lt;(\/?)(p|br|strong|em|b|i|u|ul|ol|li|h[1-6]|blockquote|code|pre|hr|div|span)\s*\/?&gt;/gi,
                '<$1$2>',
            );
        };

        const mailtoAddressPattern = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/;
        const recordRoutePattern = /^#([A-Za-z][A-Za-z0-9_]*)\/([A-Za-z0-9_-]+)$/;

        /**
         * Decide whether an LLM anchor may be restored.
         *
         * @param {string} href
         * @param {string} label
         * @returns {?{href: string, label: string}}
         */
        const toSafeAnchor = function(href, label) {
            const visible = label.trim();

            if (!visible || /[<>]/.test(visible)) {
                return null;
            }

            if (/^mailto:/i.test(href)) {
                const email = href.replace(/^mailto:/i, '');

                if (!mailtoAddressPattern.test(email) || visible.toLowerCase() !== email.toLowerCase()) {
                    return null;
                }

                return {href: 'mailto:' + email, label: email};
            }

            if (!recordRoutePattern.test(href) ||
                mailtoAddressPattern.test(visible) ||
                /^(?:https?:|#|mailto:)/i.test(visible)) {
                return null;
            }

            return {href: href, label: visible};
        };

        /**
         * Lift a plain mailto or in-app record anchor out of text before it
         * is HTML-escaped.
         *
         * @param {string} text Raw helper text
         * @param {Function} escape HTML-escape function
         * @returns {{text: string, anchors: string[]}}
         */
        const extractMailtoAnchors = function(text, escape) {
            const anchors = [];
            const safeAnchor = /<a\s+href=(["'])([^"'\s>]+)\1\s*>([\s\S]*?)<\/a>/gi;
            const rewritten = text.replace(safeAnchor, function(match, quote, href, label) {
                const safe = toSafeAnchor(href, label);

                if (!safe) {
                    return match;
                }

                anchors.push('<a href="' + escape(safe.href) + '">' + escape(safe.label) + '</a>');

                return '%%ANCHOR_' + (anchors.length - 1) + '%%';
            });

            return {text: rewritten, anchors: anchors};
        };

        /**
         * Put lifted mailto anchors back after the surrounding text is escaped.
         *
         * @param {string} escaped
         * @param {string[]} anchors
         * @returns {string}
         */
        const restoreMailtoAnchors = function(escaped, anchors) {
            return anchors.reduce(function(result, anchor, index) {
                return result.split('%%ANCHOR_' + index + '%%').join(anchor);
            }, escaped);
        };

        /**
         * Replaces placeholders in a text string with links to records.
         * Placeholders are in the format {0}, {1}, etc., and correspond to the `meta` array or single object passed in.
         *
         * Usage:
         * {{replaceWithLinks "Click {0} or {1}" metaArray}}
         *
         * @param {string} text The text containing placeholders.
         * @param {object | object[]} meta A record object or array of records. Each record can have:
         * @returns {Handlebars.SafeString} The text with placeholders replaced by HTML links.
         */
        Handlebars.registerHelper('replaceWithLinks', function(text, meta) {
            const escape = Handlebars.Utils.escapeExpression;

            if (!text) {
                return text;
            }

            const extracted = extractMailtoAnchors(String(text).replace(/&amp;/gi, '&'), escape);
            const safeBody = restoreMailtoAnchors(
                unescapeAllowedBodyTags(escape(extracted.text)),
                extracted.anchors,
            );

            const titleSpan = function(title) {
                return '<span class="font-semibold" ' +
                    'data-bs-placement="bottom" ' +
                    'data-bs-original-title="' + title + '">' + title + '</span>';
            };

            const buildLink = function(record) {
                const title = escape(record.title);
                let link = '';
                const safeHref = record.isInternal === false ?
                    toAllowedHttpUrl(record.url) : null;

                if (safeHref) {
                    link = '<a href="' + escape(safeHref) + '" target="_blank" ' +
                        'rel="noopener noreferrer" class="font-semibold" ' +
                        'data-bs-placement="bottom" ' +
                        'data-bs-original-title="' + title + '">' + title + '</a>';
                } else if (record.module && record.id) {
                    link = '<a href="#' + escape(record.module) + '/' +
                        escape(record.id) + '" ' +
                        'data-bs-placement="bottom" ' +
                        'data-bs-original-title="' + title + '">' + title + '</a>';
                } else {
                    link = titleSpan(title);
                }

                if (record.suffix) {
                    link += ' (' + escape(record.suffix) + ')';
                }

                return link;
            };

            // Handle both array and single object meta
            const metaArray = meta ? (Array.isArray(meta) ? meta : [meta]) : [];

            // Single pass so a record title that itself contains "{1}" is never re-substituted.
            // A {N} with no meta entry has nothing to resolve to: drop it (and its leading space)
            // rather than show the raw token. An entry that exists but has no title is left as is.
            const result = safeBody.replace(/( ?)\{(\d+)\}/g, function(match, space, index) {
                const record = metaArray[Number(index)];

                if (!record) {
                    return '';
                }

                return record.title ? space + buildLink(record) : match;
            });

            return new Handlebars.SafeString(result);
        });

        /**
         * Renders a table cell for a component table.
         * If the cell value is an object with `text` and `meta`, it uses the `replaceWithLinks` helper.
         * Otherwise, it returns the plain string value.
         *
         * Usage:
         * {{renderComponentTableCell rowData columnName}}
         *
         * @param {object} rowData The data for the current row.
         * @param {string} columnName The name of the column to render.
         * @returns {Handlebars.SafeString|string} The rendered cell content.
         */
        Handlebars.registerHelper('renderComponentTableCell', function(rowData, columnName) {
            const cellValue = rowData && rowData[columnName] ? rowData[columnName] : '';

            // Check if it's an object with text and meta properties
            if (typeof cellValue === 'object' && cellValue !== null && cellValue.text && cellValue.meta) {
                return Handlebars.helpers.replaceWithLinks(cellValue.text, cellValue.meta);
            } else {
                return cellValue;
            }
        });

        /**
         * Returns the appropriate asset path based on current theme (light/dark mode).
         *
         * This helper automatically detects the current theme and returns the correct
         * asset path with theme variant. In dark mode, it returns the dark variant,
         * otherwise returns the standard (light) variant.
         *
         * Usage:
         * {{themedAssetPath basePath='gai/loading/ai-loader' ext='svg'}}
         * {{themedAssetPath basePath='gai/loading/ai-loader' ext='svg' size='md'}}
         * {{themedAssetPath basePath='gai/loading/ai-loader' ext='svg' size='lg'}}
         *
         * @param {object} [options] Optional parameters.
         * @param {object} [options.hash] The hash of the optional params.
         * @param {string} [options.hash.basePath] Base path of the asset (relative to styleguide/assets/img/).
         * @param {string} [options.hash.ext] File extension (e.g., 'svg', 'png').
         * @param {string} [options.hash.size] Size variant ('md', 'lg'). Default is no suffix.
         * @returns {string} The asset file path with appropriate theme variant.å
         */
        Handlebars.registerHelper('themedAssetPath', function(options) {
            const isDark = (app.utils && app.utils.isDarkMode) ? app.utils.isDarkMode() : false;

            const hash = options.hash || {};
            const path = hash.basePath || '';
            const ext = hash.ext || 'svg';
            const size = hash.size ? `-${hash.size}` : '';
            const themeVariant = isDark ? '-dark' : '';

            const basePath = 'styleguide/assets/img/';

            return `${basePath}${path}${themeVariant}${size}.${ext}`;
        });
    });
})(SUGAR.App);

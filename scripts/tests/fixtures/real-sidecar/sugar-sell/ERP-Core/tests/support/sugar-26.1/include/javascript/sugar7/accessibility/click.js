// Recorded from SugarEnt 26.1.0 (build 319): include/javascript/sugar7/accessibility/click.js; sha256 5358f5eaf22b1dfb812458f3e919aaf690e0013150a1eec3f428af79b1e761b9 of the stock file, which follows unchanged.
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
(function(app) {
    // make sure the accessibility module is set up
    app.accessibility = app.accessibility || {};
    app.accessibility.helpers = app.accessibility.helpers || {};

    /**
     * The Click Accessibility Helper is responsible for making an element
     * compliant with accessibility standards when click events are bound to
     * said element.
     *
     * Inherently focusable elements are those elements that require no
     * intervention. These elements include:
     *
     * - button
     * - input
     * - select
     * - textarea
     *
     * Conditionally focusable elements are those elements that require
     * intervention under certain circumstances. In the case of &lt;a&gt; and
     * &lt;area&gt; tags, these elements are compliant as long as they contain
     * an `href` attribute. These elements include:
     *
     * - a
     * - area
     *
     * All other elements are not inherently focusable and require a `tabindex`
     * attribute of `-1` if a `tabindex` attribute does not already exist.
     */
    app.accessibility.helpers.click = {
        /**
         * Detects click events bound to an element and applies accessibility
         * rules to the targets of each click event.
         *
         * @param {Array/Component/jQuery} component
         * The element(s) to test for accessibility compliance.
         */
        run: function(component) {
            var self = this;
            var $el;
            var events;

            if (component instanceof app.view.Component) {
                // component is a Sugar view
                $el = component.$el;
            } else if (_.isArray(component)) {
                // component may be an array of components
                // so each should be compliant
                component.each(function() {
                    self.run(this);
                });
                return;
            } else if (component.length > 1) {
                // component may be a jQuery collection of elements
                // so each should be compliant
                component.each(function() {
                    self.run($(this));
                });
                return;
            } else {
                // the component is only a single element
                $el = component;
            }

            events = $._data($el[0] || $el, 'events');

            // only bother with elements that have click events attached to them
            if (!events || !events.click) {
                app.logger.debug('Found no events on ' + app.accessibility.getElementTag($el));
                return;
            }

            _.each(events.click, function(clickEvent) {
                if (clickEvent.selector) {
                    // all elements targeted by the click event's selector
                    // should be compliant
                    $el.find(clickEvent.selector).each(function() {
                        self._makeElementCompliant($(this));
                    });
                } else {
                    // the element is the target
                    self._makeElementCompliant($el);
                }
            });
        },

        /**
         * Determines whether or not an element requires any additional
         * accessibility compliance for click events.
         *
         * @param {jQuery} $el
         * The element to test for accessibility compliance.
         * @returns {boolean}
         * @private
         */
        _isElementCompliant: function($el) {
            var tag = $el.prop('tagName');

            if (!tag) {
                // the rest of the function won't work
                app.logger.debug('The element does not have a tag name');
                return true;
            }

            switch (tag.toLowerCase()) {
            case 'button':
            case 'input':
            case 'select':
            case 'textarea':
                // inherently focusable
                return true;
            case 'a':
                // inherently focusable when an href attribute is present
                // or role is button
                return !!($el.attr('href')) || $el.attr('role') === 'button';
            case 'area':
                // inherently focusable when an href attribute is present
                return !!($el.attr('href'));
            case 'th':
                return $el.hasClass('sorting') || $el.hasClass('sorting_asc') || $el.hasClass('sorting_desc');
            default:
                // not inherently focusable
                return $el[0].hasAttribute('tabindex');
            }
        },

        /**
         * Adds a `tabindex` attribute to an element to make it compliant.
         *
         * @param {jQuery} $el
         * The element to make compliant.
         *
         * @private
         */
        _makeElementCompliant: function($el) {
            var role;
            var tagName;
            var tagPretty;

            role = $el.attr('role');
            tagName = $el.prop('tagName').toLowerCase();
            tagPretty = app.accessibility.getElementTag($el);
            const isHidden = $el.attr('aria-hidden') === 'true';

            if (this._isElementCompliant($el)) {
                app.logger.debug('Found ' + tagPretty + ' to be compliant');

                if (tagName === 'a' && role === 'button' && !$el[0].hasAttribute('tabindex')) {
                    $el.attr('tabindex', 0);
                }
            } else if (!isHidden) {
                app.logger.debug('Made ' + tagPretty + ' compliant');
                $el.attr('tabindex', -1);
            }
        },
    };
})(SUGAR.App);

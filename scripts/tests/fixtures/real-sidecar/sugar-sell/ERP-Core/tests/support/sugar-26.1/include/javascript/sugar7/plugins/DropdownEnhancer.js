// Recorded from SugarEnt 26.1.0 (build 319): include/javascript/sugar7/plugins/DropdownEnhancer.js; sha256 bab0cbeda2df13c48339fe6a5498ba091cea13c9a51d760db55f2a01fefad6b1 of the stock file, which follows unchanged.
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
    app.events.on('app:init', function() {
        app.plugins.register('DropdownEnhancer', ['field'], {
            /**
             * Sets the maximum height of the dropdown menu
             */
            _setDropdownMaxHeight: function() {
                if (!this.$el) {
                    return;
                }

                const bottomOffset = 24;
                const dropdown = this.$(this.dropdownTag);
                const dropdownEl = _.first(dropdown);
                const dashletContainer = _.first(dropdown.closest('.dashlet'));
                let availableHeight = window.innerHeight;

                if (!dropdown.hasClass('show') || !dropdownEl) {
                    return;
                }

                if (this.view.type === 'dashlet-toolbar' && dashletContainer) {
                    availableHeight = dashletContainer.getBoundingClientRect().bottom;
                }

                const topOffset = dropdownEl.getBoundingClientRect().top;
                const borderTop = parseInt(dropdown.css('border-top-width'), 10) || 0;
                const borderBottom = parseInt(dropdown.css('border-bottom-width'), 10) || 0;
                const maxHeight = availableHeight - topOffset - bottomOffset - borderTop - borderBottom;
                dropdown.css({
                    'max-height': `${maxHeight}px`,
                    'overflow-y': 'auto'
                });
            },

            /**
             * Reset the styles of the dropdown menu
             */
            _resetDropdownMaxHeight: function() {
                if (!this.$el) {
                    return;
                }

                const dropdown = this.$(this.dropdownTag);

                if (!dropdown || !dropdown.length) {
                    return;
                }

                dropdown.css({
                    'max-height': '',
                    'overflow-y': ''
                });
            },
        });
    });
})(SUGAR.App);

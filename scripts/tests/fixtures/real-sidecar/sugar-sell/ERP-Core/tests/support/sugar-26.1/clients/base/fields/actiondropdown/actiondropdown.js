// Recorded from SugarEnt 26.1.0 (build 319): clients/base/fields/actiondropdown/actiondropdown.js; sha256 01d53f60921a6ced9ebfd0de6c3cc2857471cdd861993274bd92544f3650af10 of the stock file, which follows unchanged.
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
 * Create a dropdown button that contains multiple
 * {@link View.Fields.Base.RowactionField} fields.
 *
 * Supported Properties:
 *
 * - {Boolean} primary True if the entire dropdown group shows as primary.
 * - {String} icon Css icon that places on dropdown caret.
 * - {Boolean} switch_on_click True if the selected action needs to switch
 *   against the default action.
 * - {Boolean} no_default_action True if the default action should be empty and
 *   all buttons place under the dropdown action.
 * - {Array} buttons List of actions.
 *   First action goes to the default action (unless no_default_action set as `true`)
 *
 * Example usage:
 *
 *      array(
 *          'type' => 'actiondropdown',
 *          'primary' => true,
 *          'switch_on_click' => true,
 *          'no_default_action' => false,
 *          'icon' => 'sicon-settings',
 *          'buttons' => array(
 *              ...
 *          )
 *      )
 *
 * @class View.Fields.Base.ActiondropdownField
 * @alias SUGAR.App.view.fields.BaseActiondropdownField
 * @augments View.Fields.Base.FieldsetField
 */
({
    extendsFrom: 'FieldsetField',

    plugins: ['DropdownEnhancer'],

    events: {
        'click [data-bs-toggle=dropdown]': 'renderDropdown',
        'shown.bs.dropdown': '_onDropdownShown',
        'hidden.bs.dropdown': '_onDropdownHidden',
    },

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        /**
         * A subset of {@link #fields}.
         * Contains ACL accessible fields that are part of the dropdown.
         *
         * @property {Array}
         */
        this.dropdownFields = [];

        /**
         * Dom element selector for dropdown action.
         *
         * @property {string}
         */
        this.actionDropDownTag = '[data-bs-toggle=dropdown]';

        /**
         * Dom element selector for mobile dropdown selector.
         *
         * @property {string}
         */
        this.selectDropdownTag = '[data-toggle=dropdownmenu]';

        /**
         * The dropdown tag to append the dropdown list to.
         *
         * @property {string}
         */
        this.dropdownTag = '[data-menu=dropdown]';

        /**
         * The default action button. An item in {@link #fields}.
         * This is a readonly property. Use {@link #_setDefaultBtn} to modify.
         *
         * @property {object}
         */
        this.defaultActionBtn = {};

        /**
         * @inheritdoc
         *
         * This field doesn't support `showNoData`.
         */
        this.showNoData = false;

        /**
         * @inheritdoc
         *
         * This field's user action is enabled.
         */
        this.tabIndex = 0;

        this._super('initialize', [options]);
        this._bindEvents();

        /**
         * The caret icon class.
         *
         * @property {string}
         */
        this.caretIcon = this.def.icon || 'sicon-chevron-down';

        /**
         * The tooltip text.
         *
         * @property {string}
         */
        this.tooltip = this.def.tooltip || 'LBL_MORE';

        this.def.css_class = this.def.css_class ?
            this.def.css_class + ' actions' : 'actions';

        if (this.def.no_default_action) {
            this.def.switch_on_click = false;
        }

        // shortcut keys
        app.shortcuts.register({
            id: 'Dropdown:More',
            keys: 'm',
            component: this,
            description: 'LBL_SHORTCUT_OPEN_MORE_ACTION',
            handler: function() {
                var $primaryDropdown = this.$('.btn-primary[data-bs-toggle=dropdown]');
                if ($primaryDropdown.is(':visible') && !$primaryDropdown.hasClass('disabled')) {
                    $primaryDropdown.click();
                }
            },
        });
        this.model.on('acl:change', function() {
            if (this.disposed) {
                return;
            }
            this._orderButtons();
            this.render();
        }, this);
    },

    /**
     * Binds the events that this field uses
     */
    _bindEvents: function() {
        // Handle the height recalculation on window resize
        $(window).on('resize.' + this.cid, _.debounce(_.bind(this._setDropdownMaxHeight, this), 100));
    },

    /**
     * @inheritdoc
     */
    _getChildFieldsMeta: function() {
        return app.utils.deepCopy(this.def.buttons);
    },

    /**
     * @inheritdoc
     *
     * Calls {@link #_reorganizeButtons} if creating fields for the first time.
     * @returns {Array} Array of accessible fields, a subset of {@link #fields}.
     */
    _getChildFields: function() {
        if (_.isEmpty(this.fields)) {
            var fields = this._super('_getChildFields');
            this._orderButtons(fields);
        }
        return !_.isEmpty(this.defaultActionBtn) ?
            [this.defaultActionBtn].concat(this.dropdownFields) :
            this.dropdownFields;
    },

    /**
     * Orders the fields according to order given and places the ACL accessible
     * fields in {@link #defaultActionBtn} and {@link #dropdownFields}.
     *
     * @param {Array} [fields=this.fields] Buttons in a specific order.
     * @private
     */
    _orderButtons: function(fields) {
        // Set to `true` to avoid starting the list with a divider.
        var prevIsDivider = true;
        var orderedFields = fields || this.fields;
        this.dropdownFields = _.filter(orderedFields, function(field) {
            var actionHidden = (_.isFunction(field.hasAccess) && !field.hasAccess()) ||
                (_.isFunction(field.isVisible) && !field.isVisible()) ||
                (_.isFunction(field.isOnForbiddenLayout) && field.isOnForbiddenLayout()) ||
                (_.isFunction(field.enableFocusDrawer) && field.type !== 'divider');

            if (actionHidden || (field.type === 'divider' && prevIsDivider)) {
                return false;
            }
            prevIsDivider = field.type === 'divider';
            // If we have a predefined button filter function, apply it
            if (field.def.filterForRecordDashlet && _.isFunction(field.isAllowedDropdownButton)) {
                return field.isAllowedDropdownButton();
            }
            return true;
        });

        if (!this.def.no_default_action && !_.isEmpty(this.dropdownFields)) {
            this._setDefaultBtn(_.first(this.dropdownFields));
            this.dropdownFields = _.rest(this.dropdownFields);
        }
    },

    /**
     * Gets the dropdown template and caches it to `this.dropdownTpl`.
     *
     * @returns {Function} The handlebars dropdown template.
     * @protected
     */
    _getDropdownTpl: function() {
        this.dropdownTpl = this.dropdownTpl ||
            app.template.getField('actiondropdown', 'dropdown', this.module);
        return this.dropdownTpl;
    },

    /**
     * Appends the dropdown from `dropdown.hbs` and binds the
     * {@link #switchButton} method to the dropdown buttons if necessary.
     *
     * @param {Event} evt The `click` event.
     */
    renderDropdown: function(evt) {
        var $dropdown = this.$(this.dropdownTag);

        this._setDropdownMaxHeight();

        if (_.isEmpty(this.dropdownFields) || this.isDisabled() || !$dropdown.is(':empty')) {
            return;
        }
        var dropdownTpl = this._getDropdownTpl();

        $dropdown.append(dropdownTpl(this));
        $dropdown.find('li[role="menuitem"]').on('click', function(e) {
            if ($(e.target).attr('role') === 'menuitem') {
                $(e.target).find('a').click();
            }
        });

        _.each(this.dropdownFields, function(field) {
            field.setElement(this.$('span[sfuuid="' + field.sfId + '"]'));
            if (this.def['switch_on_click'] && !this.def['no_default_action']) {
                field.$el.on('click.' + this.cid, _.bind(this.switchButton, this));
            }
            field.render();
        }, this);
    },

    /**
     * Sets a button accessibility class 'aria-expanded' to true or false
     * depending on if the dropdown menu is open or closed.
     *
     * @private
     */
    _toggleAria: function() {
        var $button = this.$(this.actionDropDownTag);
        $button.attr('aria-expanded', this.$el.find('.dropdown-menu').hasClass('show'));
    },

    /**
     * Sets a button to {@link #defaultActionBtn} and to have default-button
     * properties. Unsets the previous {@link #defaultActionBtn}.
     *
     * @param {object} button The button of interest.
     * @private
     */
    _setDefaultBtn: function(button) {
        if (!button || button.disposed) {
            return;
        }
        if (!_.isEmpty(this.defaultActionBtn)) {
            this.defaultActionBtn.def.primary = this.defaultActionBtn.def.button = false;
        }
        this.defaultActionBtn = button;
        this.defaultActionBtn.def.primary = this.def.primary;
        this.defaultActionBtn.def.button = true;
    },

    /**
     * Switch the default button against one that is clicked.
     *
     * @param {Event} evt The `click` event
     */
    switchButton: function(evt) {
        var sfId = parseInt(this.$(evt.currentTarget).attr('sfuuid'), 10);
        var index = -1;

        if (sfId === this.defaultActionBtn.sfId) {
            return;
        }
        var selectedField = _.find(this.dropdownFields, function(field, idx) {
            if (field.sfId === sfId) {
                index = idx;
                return true;
            }
            return false;
        });

        if (!selectedField) {
            return;
        }

        // rebuild `dropdownFields` with the new ordering
        this.dropdownFields.splice(index, 1, this.defaultActionBtn);
        this._setDefaultBtn(selectedField);
        this.render();
    },

    /**
     * @inheritdoc
     *
     * Rendering an `ActiondropdownField` will always force the dropdown to be
     * re-rendered.
     */
    _render: function() {
        this.$(this.dropdownTag).empty();

        this._super('_render');
        this._updateCaret();
        this._renderDefaultActionBtn();
        this.$el.toggleClass('btn-group', !_.isEmpty(this.dropdownFields) || !!this.def.is_group);

        return this;
    },

    /**
     * Renders the default action button only.
     * The fields in the dropdown will be rendered on click on the dropdown
     * button with {@link #renderDropdown}.
     *
     * @override
     * @protected
     */
    _renderFields: function() {
        if (!_.isEmpty(this.defaultActionBtn)) {
            this.defaultActionBtn.setElement(this.$('span[sfuuid="' + this.defaultActionBtn.sfId + '"]'));
            this.defaultActionBtn.render();
        }
    },

    /**
     * Formats the default action button if it exists.
     * Sets the mode of the button to `small` if it is in a subpanel.
     *
     * A button is in `small` mode when it contains only the icon, with the label
     * shown as a tooltip.
     *
     * @protected
     */
    _renderDefaultActionBtn: function() {
        if (_.isEmpty(this.defaultActionBtn)) {
            return;
        }
        // FIXME: SC-3366 Should not explicitly look for `closestComponent`
        if (this.defaultActionBtn.def.icon &&
            this.defaultActionBtn.closestComponent('subpanel')) {
            this.defaultActionBtn.setMode('small');
        }

        if (!this.def['switch_on_click'] || this.def['no_default_action']) {
            return;
        }

        this.defaultActionBtn.$el.on('click.' + this.cid, _.bind(this.switchButton, this));
        app.accessibility.run(this.defaultActionBtn.$el, 'click');
    },

    /**
     * Enable or disable caret depending on if there are any enabled actions in the dropdown list
     * @private
     */
    _updateCaret: function() {
        if (_.isEmpty(this.dropdownFields)) {
            return;
        }
        // FIXME: SC-3365 Should not need to check for 'disabled' in css_class
        var caretEnabled = _.some(this.dropdownFields, function(field) {
            if (!_.isFunction(field.hasAccess) || field.hasAccess()) {
                if (field.def.css_class && field.def.css_class.indexOf('disabled') > -1) {
                    // If action disabled in metadata
                    return false;
                } else if (field.isDisabled()) { // Or disabled via field controller
                    return false;
                } else {
                    return true;
                }
            }
            return false;
        });
        this.$('.' + this.caretIcon)
            .closest('a')
            .toggleClass('disabled', !caretEnabled)
            .attr('aria-haspopup', caretEnabled)
            .attr('tabindex', caretEnabled ? 0 : -1);
    },

    /**
     * @inheritdoc
     */
    setDisabled: function(disable) {
        this._super('setDisabled', [disable]);
        disable = _.isUndefined(disable) ? true : disable;
        this.tabIndex = disable ? -1 : 0;
        if (this.$el) {
            this.$(this.actionDropDownTag)
                .toggleClass('disabled', disable)
                .attr('aria-haspopup', !disable)
                .attr('tabindex', this.tabIndex);
        }
    },

    /**
     * Handles the dropdown being shown
     */
    _onDropdownShown: function() {
        this.renderDropdown();
        this._toggleAria();
    },

    /**
     * Handles the dropdown being hidden
     */
    _onDropdownHidden: function() {
        this._resetDropdownMaxHeight();
        this._toggleAria();
    },

    /**
     * @inheritdoc
     */
    _dispose: function() {
        _.each(this.fields, function(field) {
            if (!field.disposed) {
                field.$el.off('click.' + this.cid);
            }
        }, this);
        this.defaultActionBtn = null;
        this.dropdownFields = null;
        $(window).off('resize.' + this.cid);
        this._super('_dispose');
    },
})

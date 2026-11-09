// Recorded from SugarEnt 26.1.0 (build 319): clients/base/views/dashlet-toolbar/dashlet-toolbar.js; sha256 5a4bb4f1a5c7d28228653bd0b80bd4715a457712faba616b921d43b6ed90a8ad of the stock file, which follows unchanged.
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
 * @class View.Views.Base.DashletToolbarView
 * @alias SUGAR.App.view.views.BaseDashletToolbarView
 * @augments View.View
 */
({
    className: 'dashlet-header border-b border-[--border-base] dark:border-none flex flex-row items-center h-11 px-4',
    cssIconDefault: 'sicon sicon-settings',
    cssIconRefresh: 'sicon sicon-refresh sicon-is-spinning',
    defaultActions: {
        'dashlet:edit:clicked': 'editClicked',
        'dashlet:viewReport:clicked': 'viewReportClicked',
        'dashlet:refresh:clicked': 'refreshClicked',
        'dashlet:delete:clicked': 'removeClicked',
        'dashlet:toggle:clicked': 'toggleMinify',
    },

    plugins: [
        'SugarLogic',
    ],

    /**
     * Button states.
     */
    _STATE: {
        EDIT: 'edit',
        VIEW: 'view',
    },

    /**
     * List of fields to display in the header.
     *
     * @property {object[]|null}
     */
    headerFields: null,

    /**
     * The total number of search results for all modules.
     *
     * @property {number|null}
     */
    modulesNumber: null,

    initialize: function(options) {
        _.extend(options.meta, app.metadata.getView(null, 'dashlet-toolbar'), options.meta.toolbar);
        app.view.View.prototype.initialize.call(this, options);
        var model = this.closestComponent('dashboard') ?
            this.closestComponent('dashboard').model : this.model;

        /**
         * A flag to indicate if the dashlet is editable.
         *
         * @type {boolean}
         */
        this.canEdit = app.acl.hasAccessToModel('edit', model) || false;

        this.buttons = this.meta.buttons;

        // filter buttons depending on if the dashboard is a template or not
        const templateRestrictedActions = ['editClicked', 'removeClicked'];
        _.each(this.buttons, (buttons, buttonsIdx) => {
            if (buttons.dropdown_buttons) {
                const dButtons = buttons.dropdown_buttons;

                for (let idx = dButtons.length - 1; idx >= 0; idx--) {
                    const dButton = dButtons[idx];

                    if (dButton &&
                        this.layout &&
                        this.layout.model &&
                        templateRestrictedActions.indexOf(dButton.action) > -1 &&
                        this.layout.model.get('is_template')) {
                        this.buttons[buttonsIdx].dropdown_buttons.splice(idx, 1);
                    }
                }
            }
        });

        $(window).on('resize.' + this.cid, this.adjustHeaderPaneTitle);
        $(window).on('resize.' + this.cid, this.adjustCustomDashletTitle);
    },

    /**
     * @inheritdoc
     */
    bindDataChange: function() {
        this._super('bindDataChange');
        this.listenTo(this.context, 'dashlet:toolbar:change', this._handleToolbarChange);
        this.listenTo(this.context.parent, 'search:modules:number:change', (modulesNumber) => {
            this.modulesNumber = modulesNumber;
            this.render();
        });
    },

    /**
     * Handles when the toolbar needs to change (new fields, buttons, model, etc.)
     *
     * @param {Array} headerFields the new header field definitions
     * @param {Array} headerButtons the new header button definitions
     * @param {Bean} dashletModel the model used in the dashlet if applicable
     * @param {object} dashlet the dashlet view
     * @private
     */
    _handleToolbarChange: function(headerFields, headerButtons, dashletModel, dashlet) {
        this.headerFields = headerFields;
        this.buttons = _.union(headerButtons, this.meta.buttons);
        if (dashletModel) {
            this.dashletModel = dashletModel;
        }
        if (dashlet) {
            this.dashlet = dashlet;
        }
        this.render();

        // Restart SugarLogic to initialize dependencies for any changed module context
        this.context.set('module', dashletModel ? dashletModel.module : 'Home');
        this.collection.reset(dashletModel);
        this.stopSugarLogic();
        this.startSugarLogic();
    },

    /**
     * Adjust header pane dashlet title such that the field is ellipsified.
     */
    adjustHeaderPaneTitle: function() {
        const fullNameField = _.findWhere(this.headerFields, {type: 'fullname'});
        if (_.isEmpty(fullNameField)) {
            return;
        }

        // Calculate the width of the headerpane
        const headerPaneWidth = this.$el.closest('.dashlet-header').width();

        // Calculate the width of the record-cells other than the fullname one
        let recordCellsWidth = 0;
        _.each(this.$('.dashlet-title > .record-cell'), function(recordCell) {
            const $recordCell = $(recordCell);
            if ($recordCell.data('name') !== fullNameField.name) {
                recordCellsWidth += $recordCell.outerWidth(true);
            } else {
                recordCellsWidth += $recordCell.outerWidth(true) - $recordCell.width();
            }
        }, this);

        // Calculate the width of the toolbar buttons
        const btnGroupWidth = this.$('.btn-toolbar').outerWidth(true);

        // Dashlet record title is positioned as the child element of the second record-cell.
        // Calculate title width by subtracting the record-cell and btn-group width from parent headerpane width.
        const titleWidth = headerPaneWidth - btnGroupWidth - recordCellsWidth;

        this.$('.dashlet-open-container').css({'max-width': titleWidth + 'px'});
    },

    /**
     * @inheritdoc
     *
     * Handle the record state if this is a toolbar for a dashablerecord.
     */
    _render: function() {
        this._super('_render');

        this._setupTitleLinkFields();
        this.adjustHeaderPaneTitle();
        if (this.dashlet) {
            this._handleRecordState(this.dashlet && this.dashlet.action);
        }
    },

    /**
     * Sets up enableTitleLink and related properties on name/fullname fields,
     * then re-renders them using the dashlet-header template.
     *
     * @private
     */
    _setupTitleLinkFields: function() {
        _.each(this.fields, (field) => {
            if (!_.contains(['name', 'fullname'], field.type)) {
                return;
            }

            field.enableTitleLink = !!this.closestComponent('side-drawer');
            if (field.enableTitleLink && this.dashlet && this.dashlet.model) {
                field.module = this.dashlet.model.module || this.context.get('module');
                field.modelId = this.dashlet.model.get('id');
                field.linkTarget = 'focus';
            }
            field.setMode('dashlet-header');
        });
    },

    /**
     * Handle changes between edit/detail mode (for record view dashlets).
     *
     * @param {string} action Action name.
     * @private
     */
    _handleRecordState: function(action) {
        if (action === 'edit' && _.isFunction(this.toggleEdit)) {
            this.setButtonStates(this._STATE.EDIT);
            this.toggleEdit(true);
        } else {
            this.setButtonStates(this._STATE.VIEW);
        }
    },

    /**
     * Show/hide buttons depending on the state defined for each buttons in the
     * metadata.
     *
     * @param {string} state The {@link #_STATE} of the current view.
     */
    setButtonStates: function(state) {
        this.currentState = state;

        _.each(this.buttons, function(field) {
            field = this.getField(field.name);
            if (!field) {
                return;
            }
            var showOn = field.def && field.def.showOn;
            if (_.isUndefined(showOn) || (showOn === state)) {
                field.show();
            } else {
                field.hide();
            }
        }, this);

        this.toggleButtons(true);
    },

    /**
     * Enables or disables the action buttons that are currently shown on the
     * page. Toggles the `.disabled` class by default.
     *
     * @param {boolean} [enable=false] Whether to enable or disable the action
     *   buttons. Defaults to `false`.
     */
    toggleButtons: function(enable) {
        var state = !_.isUndefined(enable) ? !enable : false;

        _.each(this.buttons, function(button) {
            const buttonMeta = button;
            button = this.getField(button.name);
            if (!button) {
                return;
            }

            var showOn = button.def && button.def.showOn;
            if (_.isUndefined(showOn) || this.currentState === showOn) {
                button.setDisabled(state);
            }

            // disable edit button for system currency only
            const dropdownButtonsKey = 'dropdown_buttons';
            if (buttonMeta[dropdownButtonsKey]) {
                _.each(buttonMeta[dropdownButtonsKey], function(dropdownButton) {
                    if (dropdownButton.name && dropdownButton.name === 'edit_button' && this.dashletModel &&
                        this.dashletModel.id === app.currency.getBaseCurrencyId()) {
                        const $dropdownButton = this.getField(dropdownButton.name);
                        $dropdownButton.setDisabled(true);
                        this.dashletModel.set('name', app.lang.get('LBL_CURRENCY_DEFAULT', 'Currencies'));
                    }
                }, this);
            }
        }, this);
        this.adjustHeaderPaneTitle();
    },

    /**
     * Change to the spinning icon to indicate that loading process is triggered
     */
    refreshClicked: function() {
        var $el = this.$('[data-action=loading]');
        var options = {};
        if ($el.length > 0) {
            $el.removeClass(this.cssIconDefault).addClass(this.cssIconRefresh);
            options.complete = _.bind(function() {
                if (this.disposed) {
                    return;
                }
                $el.removeClass(this.cssIconRefresh).addClass(this.cssIconDefault);

                // If the user refreshes a collapsed dashlet, set the right toggle icon
                if (this.layout.isDashletCollapsed()) {
                    this.$('.dashlet-toggle > i').toggleClass('sicon-chevron-down', true);
                    this.$('.dashlet-toggle > i').toggleClass('sicon-chevron-up', false);
                }
            }, this);
        }
        this.layout.reloadDashlet(options);
    },

    /**
     * Remove a dashlet.
     */
    removeClicked: function() {
        app.alert.show('delete_confirmation', {
            level: 'confirmation',
            messages: app.lang.get('LBL_REMOVE_DASHLET_CONFIRM', this.module),
            onConfirm: _.bind(function() {
                this.layout.removeDashlet();
            }, this),
        });
    },

    /**
     * View report.
     */
    viewReportClicked: function() {
        this.layout.viewReport();
    },

    /**
     * Edit the dashlet.
     */
    editClicked: function() {
        this.layout.editDashlet();
    },

    /**
     * Toggle current dashlet frame when user clicks the toolbar action
     *
     * @param {Event} evt The mouse event
     */
    toggleClicked: function(evt) {
        var $btn = $(evt.currentTarget);
        var expanded = _.isUndefined($btn.data('expanded')) ? true : $btn.data('expanded');
        var label = expanded ? 'LBL_DASHLET_MAXIMIZE' : 'LBL_DASHLET_MINIMIZE';

        $btn.html(app.lang.get(label, this.module));
        this.layout.collapse(expanded);
        $btn.data('expanded', !expanded);
    },

    /**
     * Toggle current dashlet frame when user clicks chevron icon
     */
    toggleMinify: function() {
        var $el = this.$('.dashlet-toggle > i');
        var collapsed = $el.is('.sicon-chevron-up');
        this.layout.collapse(collapsed);
        // firing an event to notify dashlet expand / collapse
        this.layout.trigger('dashlet:collapse', collapsed);
    },

    /**
     * Handles custom toolbar changes like adding icon to header
     * @param {Array} headerFields the new header field definitions
     * @param {Array} headerButtons the new header button definitions
     * @param {Bean} dashletModel the model used in the dashlet if applicable
     * @param {object} dashlet the dashlet view
     */
    _handleCustomToolbarChange: function(headerFields, headerButtons, dashletModel, dashlet) {
        this.headerFields = headerFields;
        this.buttons = _.union(headerButtons, this.meta.buttons);

        if (dashletModel) {
            this.dashletModel = dashletModel;
        }

        if (dashlet) {
            this.dashlet = dashlet;
        }

        this.render();

        if (this.layout && this.layout._applyCustomStyle) {
            this.layout._applyCustomStyle();
        }

        // Restart SugarLogic to initialize dependencies for any changed module context
        this.context.set('module', dashletModel ? dashletModel.module : 'Home');
    },

    /**
     * Adjust the dashlet title width to fit the toolbar taking into account the custom icon added to the title
     */
    adjustCustomDashletTitle: function() {
        const dashletTitle = this.$('[data-toggle="dashlet"]').filter(function() {
            return $(this).closest('.dashlet-container').hasClass('custom-toolbar');
        });

        if (!dashletTitle.length) {
            return;
        }

        const $dashletContainer = dashletTitle.closest('.dashlet-container');
        const gap = 40;

        const containerWidth = $dashletContainer.width();
        if (containerWidth <= 0) {
            return;
        }

        const iconEl = dashletTitle.find('[data-type="icon"]');
        const labelEl = dashletTitle.find('[data-type="label"]');
        const titleWrapper = labelEl.length ? labelEl.find('.table-cell-wrapper') : $();
        const btnToolbarEl = $dashletContainer.find('.btn-toolbar');

        const iconWidth = iconEl.length ? iconEl.outerWidth(true) : 0;
        const btnWidth = btnToolbarEl.length ? btnToolbarEl.outerWidth(true) : 0;

        const finalTitleWidth = containerWidth - iconWidth - btnWidth - gap;

        titleWrapper.width(finalTitleWidth);
    },

    /**
     * Remove event listeners on dispose
     * @private
     */
    _dispose: function() {
        $(window).off('resize.' + this.cid);
        this.stopListening();
        this._super('_dispose');
    },
})

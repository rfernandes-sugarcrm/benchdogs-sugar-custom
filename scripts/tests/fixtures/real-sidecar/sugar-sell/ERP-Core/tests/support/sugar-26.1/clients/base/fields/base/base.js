// Recorded from SugarEnt 26.1.0 (build 319): clients/base/fields/base/base.js; sha256 a780a5dbe986a3a01b65174c9ddbbd2b63145b10a1d00a453f1afebcdca1cb27 of the stock file, which follows unchanged.
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
 * This is the base field and all of other fields extend from it.
 *
 * @class View.Fields.Base.BaseField
 * @alias SUGAR.App.view.fields.BaseBaseField
 * @augments View.Field
 */
({
    plugins: ['MetadataEventDriven'],

    /**
     * Should we initialize the default field value?
     */
    shouldInitDefaultValue: false,

    /**
     * @inheritdoc
     *
     * Some plugins use events which prevents {@link View.Field#delegateEvents}
     * to fallback to metadata defined events.
     * This will make sure we merge metadata events with the ones provided by
     * the plugins.
     *
     * The Base Field will always clear any tooltips after `render`.
     */
    initialize: function(options) {
        this.events = _.extend({}, this.events, options.def.events);

        this._super('initialize', [options]);

        /**
         * Property to add or not the `ellipsis_inline` class when rendering the
         * field in the `list` template. `true` to add the class, `false`
         * otherwise.
         *
         * Defaults to `true`.
         *
         * @property {boolean}
         */
        this.ellipsis = _.isUndefined(this.def.ellipsis) || this.def.ellipsis;

        if (app.tooltip) {
            this.on('render', app.tooltip.clear);
        }

        if (this.shouldInitDefaultValue) {
            this._initDefaultValue();
        }
    },

    /**
     * @inheritdoc
     */
    _render: function() {
        var action = 'view';
        if (this.def.link && this.def.route) {
            action = this.def.route.action;
        }
        if (this.def.link && app.acl.hasAccessToModel(action, this.model)) {
            this.module = this.model.module || this.context.get('module');
            this.modelId = this.model.get('id');
            this.linkTarget = 'focus';
            this.href = this.buildHref();
        }
        app.view.Field.prototype._render.call(this);
    },

    /**
     * @inheritdoc
     */
    render: function() {
        this._super('render');

        this.addElListeners();
    },

    /**
     * Trigger click by edit block if user clicked on field
     */
    addElListeners: function() {
        if (!this.$el) {
            return;
        }

        this.$el.on('click', (e) => {
            if (this.isClickToEditField(e.target) || this.isClickToEditField(e.currentTarget)) {
                $(e.target).closest('.record-cell:not(.edit)')
                    .find('.record-edit-link-wrapper:not(.hide) > a').click();
            }
        });
    },

    /**
     * Checks if click to edit should be triggered.
     * @param {HTMLElement} target
     * @returns {boolean}
     */
    isClickToEditField: function(target) {
        return $(target).hasClass('fieldset-inline') ||
            $(target).hasClass('ellipsis_inline') ||
            $(target).hasClass('record-label-wrapper') ||
            $(target).closest('.record-label').length;
    },

    /**
     * Takes care of building href for when there's a def.link and also if is
     * bwc enabled.
     *
     * Deprecated functionality:
     * If `this.def.bwcLink` is set to `true` on metadata, we force the href
     * to be in BWC.
     *
     * @returns {string}
     *
     * TODO remove this from the base field
     */
    buildHref: function() {
        var defRoute = this.def.route ? this.def.route : {};
        var module = this.model.module || this.context.get('module');
        // This is a workaround until bug 61478 is resolved to keep parity with 6.7
        if (module === 'Users' && this.context.get('module') !== 'Users') {
            module = 'Employees';
        }
        // FIXME remove this.def.bwcLink functionality (not yet removed due to Portal need for Documents)
        return '#' + app.router.buildRoute(module, this.model.get('id'), defRoute.action, this.def.bwcLink);
    },

    /**
     * @inheritdoc
     *
     * Trim whitespace from value if it is a String.
     */
    unformat: function(value) {
        return _.isString(value) ? value.trim() : value;
    },

    /**
     * Set the default field value from metadata for a new model
     *  [
     *      ...
     *      'default' => '...',
     *      ...
     *  ]
     *
     * This function makes 2 assumptions:
     *      1. the default key is named 'default'
     *      2. the default value requires no parsing
     *
     * @private
     */
    _initDefaultValue: function() {
        if (!this.model.isNew() || this.model.get(this.name) || !this.def.default) {
            return;
        }

        if (_.isFunction(this.model.setDefault)) {
            this.model.setDefault(this.name, this.def.default);
        }
    },

    /**
     * Add aria roles for accessibility.
     *
     * @param {object} plugin The select2 plugin instance
     */
    setupSelect2Accessibility: function(plugin) {
        if (!plugin) {
            return;
        }

        const $cont = plugin.container;

        if ($cont) {
            $cont.find('a').attr({
                'role': 'combobox',
                'aria-haspopup': true,
            });

            $cont.find('.select2-arrow').attr('aria-hidden', true);
            $cont.find('input').attr('aria-hidden', true);

            $('.select2-search-choice-close', $cont).closest('a', $cont)
                .attr('role', 'group')
                .removeAttr('aria-haspopup');
        }
    },

    /**
     * @inheritdoc
     */
    _dispose: function() {
        this.stopListening();
        this.$el.off('click');
        this._super('_dispose');
    },
})

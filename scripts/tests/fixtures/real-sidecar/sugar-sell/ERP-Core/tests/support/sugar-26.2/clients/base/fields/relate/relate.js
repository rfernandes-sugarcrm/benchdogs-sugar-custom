// Recorded from SugarEnt 26.2.0 (build 292): clients/base/fields/relate/relate.js; sha256 c414c7b8351623c00317a11432156e648c1be186c8842f2db31c446e05987b1f of the stock file, which follows unchanged.
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

/* global app */

/**
 * Relate field provides a link to a module that is set in the definition of
 * this field metadata.
 *
 * This field requires at least the follow definitions to be exist in the
 * field:
 *
 * ```
 * array(
 *     'name' => 'account_name',
 *     'rname' => 'name',
 *     'id_name' => 'account_id',
 *     'module' => 'Accounts',
 *     'link' => true,
 *     //...
 * ),
 * ```
 *
 * The field also support a `populate_list` to update other fields in the
 * current model from other fields of the selected model.
 *
 * ```
 * array(
 *     //...
 *     'populate_list' => array(
 *         'populate_list' => array(
 *         'billing_address_street' => 'primary_address_street',
 *         'billing_address_city' => 'primary_address_city',
 *         'billing_address_state' => 'primary_address_state',
 *         'billing_address_postalcode' => 'primary_address_postalcode',
 *         'billing_address_country' => 'primary_address_country',
 *         'phone_office' => 'phone_work',
 *         //...
 *
 *     ),
 * )
 * ```
 *
 * This field allows you to configure the minimum chars that trigger a search
 * when using the typeahead feature.
 *
 * ```
 * array(
 *     //...
 *     'minChars' => 3,
 * )
 * ```
 *
 * TODO: we have a mix of properties here with camelCase and underscore.
 * Needs to be addressed.
 *
 * @class View.Fields.Base.RelateField
 * @alias SUGAR.App.view.fields.BaseRelateField
 * @augments View.Fields.Base.BaseField
 */
({
    plugins: ['FocusDrawer'],

    fieldTag: 'input.select2',

    events: {
        'click a': 'linkClickHandler',
    },

    /**
     * Initializes field and binds all function calls to this
     * @param {object} options
     */
    initialize: function(options) {
        /**
         * Boolean used for the 'allowClear' select2 option.
         *
         * @property {boolean}
         * @protected
         */
        this._allow_single_deselect = true;
        /**
         * Minimum input characters to trigger the search. Used for
         * `minimumInputLength` select2 option.
         *
         * @property {number}
         * @protected
         */
        this._minChars = options.def.minChars || 1;
        /**
         * Separator used by select2 to separate values. Used for `separator`
         * select2 option.
         *
         * @property {string}
         * @protected
         */
        this._separator = '|';
        /**
         * Maximum number of records the user can select.
         *
         * @property {number}
         * @protected
         */
        this._maxSelectedRecords = 20;

        if (_.property('link')(options.def) && !_.isBoolean(options.def.link)) {
            app.logger.warn('The `link` property passed in the viewDefs must be a boolean. Hence, `link`' +
                ' will be set to `true` by default.');
        }

        this._super('initialize', [options]);

        // A relate field displays a link by default.
        this.viewDefs = _.defaults(this.viewDefs || {}, {link: true});

        this.showIcon = this.def && this.def.show_avatar;

        /**
         * The template used for a pill in case of multiselect field.
         *
         * @property {Function}
         * @private
         */
        this._select2formatSelectionTemplate = app.template.getField('relate', 'pill', this.module);

        var populateMetadata = this._getPopulateMetadata();

        if (_.isEmpty(populateMetadata)) {
            return;
        }
        _.each(this.def.populate_list, function(target, source) {
            if (_.isUndefined(populateMetadata.fields[source])) {
                app.logger.error('Fail to populate the related attributes: attempt to access undefined key - ' +
                    this.getSearchModule() + '::' + source);
            }
        }, this);

        this._createSearchCollection();
    },

    /**
     * Function-handler for link click event
     *
     * @param e
     */
    linkClickHandler: function(e) {
        e.stopPropagation();
    },

    /**
     * Gets the proper module's metadata to use during auto populating fields
     *
     * @returns {*|object}
     * @protected
     */
    _getPopulateMetadata: function() {
        return app.metadata.getModule(this.getSearchModule());
    },

    /**
     * Creates a Filters BeanCollection to easily apply filters.
     * The user must have `list` access to the search module to create a
     * {@link Data.Base.FiltersBeanCollection}.
     *
     * @protected
     */
    _createFiltersCollection: function(options) {
        var searchModule = this.getSearchModule();

        if (!app.acl.hasAccess('list', searchModule)) {
            app.logger.debug('No "list" access to ' + searchModule + ' so skipping the creation of filter.');
            return;
        }

        if (app.metadata.getModule('Filters') && searchModule) {
            this.filters = app.data.createBeanCollection('Filters');
            this.filters.setModuleName(searchModule);
            this.filters.setFilterOptions(this.getFilterOptions());
            this.filters.load(options);
        }
    },
    /**
     * Creates a {@link Data.BeanCollection} for the search results pertaining
     * to the search module.
     *
     * @protected
     */
    _createSearchCollection: function() {
        var searchModule = this.getSearchModule();
        if (searchModule && app.metadata.getModule(searchModule)) {
            this.searchCollection = app.data.createBeanCollection(searchModule);
        } else {
            this.searchCollection = null;
        }
    },

    /**
     * Bind the additional keydown handler on select2
     * search element (affected by version 3.4.3).
     *
     * Invoked from {@link app.plugins.Editable}.
     * @param {Function} callback Callback function for keydown.
     */
    bindKeyDown: function(callback) {
        var $dropdown = this.$(this.fieldTag);
        $dropdown.on('keydown.record', {field: this}, callback);
        var plugin = $dropdown.data('select2');
        if (plugin) {
            plugin.focusser.on('keydown.record', {field: this}, callback);
            plugin.search.on('keydown.record', {field: this}, callback);
        }
    },

    /**
     * Unbind the additional keydown handler.
     *
     * Invoked from {@link app.plugins.Editable}.
     * @param {Function} callback Callback function for keydown.
     */
    unbindKeyDown: function(callback) {
        var $dropdown = this.$(this.fieldTag);
        $dropdown.off('keydown.record', callback);
        var plugin = $dropdown.data('select2');
        if (plugin) {
            plugin.search.off('keydown.record', callback);
        }
    },

    focus: function() {
        if (this.action !== 'disabled') {
            // Need to defer to ensure that all the related elements have finished
            // rendering before attempting to open the dropdown.
            _.defer(_.bind(function() {
                this.$(this.fieldTag).first().select2('open');
            }, this));
        }
    },

    /**
     * //FIXME: We shouldn't have this method. SC-4121 will address this.
     * Creates the css classes to set to the select2 plugin.
     *
     * @returns {string}
     * @private
     */
    _buildCssClasses: function() {
        var cssClasses = [];
        if (this.view.name === 'recordlist') {
            cssClasses.push('select2-narrow');
        }
        if (this.type === 'parent') {
            cssClasses.push('select2-parent');
        }
        if (this.def.isMultiSelect) {
            cssClasses.push('select2-choices-pills-close same-size-pills');
        }
        return cssClasses.join(' ');
    },

    /**
     * Renders relate field
     */
    _render: function() {
        var searchModule = this.getSearchModule();
        this._updateErasedPlaceholder();

        this._super('_render');

        if (this.customDropdownRender) {
            this.customDropdownRender();
        } else {
            // FIXME remove check for tplName SC-2608
            switch (this.tplName) {
            case 'edit':
            case 'massupdate':
                // `searchModule` can be undefined for a parent field when there
                // is no value set (ie in create mode). In that case, we don't
                // want to render the dropdown disabled.
                if (!_.isUndefined(searchModule)) {
                    if (!app.acl.hasAccess('list', searchModule) ||
                        !_.contains(app.metadata.getModuleNames(), searchModule)) {
                        this._renderDisabledDropdown();
                        break;
                    }
                }
                if (_.isUndefined(this.filters) ||
                    (!_.isEmpty(this.filters.moduleName) && this.filters.moduleName !== searchModule)
                ) {
                    this._createFiltersCollection({
                        success: _.bind(function() {
                            if (!this.disposed) {
                                this._renderEditableDropdown();
                            }
                        }, this),
                    });
                } else {
                    this._renderEditableDropdown();
                }
                break;
            case 'disabled':
                this._renderDisabledDropdown();
                break;
            }
        }
        return this;
    },

    /**
     * Renders the editable dropdown using the `select2` plugin.
     *
     * Since a filter may have to be applied on the field, we need to fetch
     * the list of filters for the current module before rendering the dropdown
     * (and enabling the searchahead feature that requires the filter
     * definition).
     *
     * @private
     */
    _renderEditableDropdown: function() {
        var self = this;
        var $dropdown = this.$(this.fieldTag);

        if ($dropdown.data('select2')) {
            return;
        }
        $dropdown.select2(this._getSelect2Options())
            .on('select2-open', _.bind(this._onSelect2Open, this))
            .off('searchmore')
            .on('searchmore', function() {
                $(this).select2('close');
                self.openSelectDrawer();
            })
            .on('change', _.bind(this._onSelect2Change, this));

        var plugin = $dropdown.data('select2');
        if (plugin && plugin.focusser) {
            plugin.focusser.on('select2-focus', _.bind(_.debounce(this.handleFocus, 0), this));
        }

        if (_.isFunction(this.setupSelect2Accessibility)) {
            this.setupSelect2Accessibility(plugin);
        }
    },

    /**
     * Renders the dropdown in disabled mode.
     *
     * @private
     */
    _renderDisabledDropdown: function() {
        var loadingLabel = app.lang.get('LBL_LOADING', this.module);
        var $dropdown = this.$(this.fieldTag);

        $dropdown.select2({
            width: '100%',
            initSelection: function(el, callback) {
                var $el = $(el);
                var id = $el.val();
                var text = String($el.data('rname'));
                callback({id: id, text: text});
            },
            formatInputTooShort: function() {
                return '';
            },
            formatSearching: function() {
                return loadingLabel;
            },
            placeholder: this.getPlaceHolder(),
            allowClear: this._allow_single_deselect,
            minimumInputLength: this._minChars,
            query: _.bind(this.search, this),
        });
        $dropdown.select2('disable');
    },

    /**
     * Handler for when the select2 dropdown change event
     *
     * @param e
     * @private
     */
    _onSelect2Change: function(e) {
        var $el = $(e.target);
        var plugin = $el.data('select2');
        var id = e.val;

        if (_.isUndefined(id)) {
            return;
        }

        // For multiselect fields, we update the data-rname attributes
        // so it stays in sync with the id list, and allows us to use
        // 'setValue' method. The use of 'setValue' method is required
        // to re-render the field.
        if (this.def.isMultiSelect) {
            var dataRname = String(plugin.opts.element.data('rname'));
            dataRname = dataRname ? dataRname.split(this._separator) : [];
            var ids = $el.select2('val');

            if (e.added) {
                dataRname.push(e.added.text);
            } else if (e.removed) {
                dataRname = _.without(dataRname, e.removed.text);
            } else {
                return;
            }
            var models = _.map(ids, function(id, index) {
                return {id: id, value: dataRname[index]};
            });

            this.setValue(models);
            return;
        }

        var value = (id) ? plugin.selection.find('span').text() : String($el.data('rname'));
        var collection = plugin.context;
        var attributes = {};
        if (collection && !_.isEmpty(id)) {
            // if we have search results use that to set new values
            var model = collection.get(id);
            attributes.id = model.id;
            attributes.value = model.get(this.getRelatedModuleField());
            _.each(model.attributes, function(value, field) {
                if (app.acl.hasAccessToModel('view', model, field)) {
                    attributes[field] = attributes[field] || model.get(field);
                }
            });
        } else if (e.currentTarget.value && value) {
            // if we have previous values keep them
            attributes.id = value;
            attributes.value = e.currentTarget.value;
        } else {
            // default to empty
            attributes.id = '';
            attributes.value = '';
        }

        this.setValue(attributes);
    },

    /**
     * Entry point for child classes to hook in and add/update to the base select2 options
     *
     * @returns {{}}
     * @protected
     */
    _getSelect2Options: function() {
        return _.extend({}, {
            width: this.view.name === 'recordlist' ? 'off' : '100%',
            dropdownCssClass: _.bind(this._buildCssClasses, this),
            multiple: !!this.def.isMultiSelect,
            containerCssClass: _.bind(this._buildCssClasses, this),
            separator: this._separator,
            initSelection: _.bind(this._onInitSelect, this),
            formatInputTooShort: function() {
                return '';
            },
            formatSelection: _.bind(this._onFormatSelection, this),
            formatSearching: app.lang.get('LBL_LOADING', this.module),
            placeholder: this.getPlaceHolder(),
            allowClear: this._allow_single_deselect,
            minimumInputLength: this._minChars,
            maximumSelectionSize: 20,
            query: _.bind(this.search, this),
        });
    },

    /**
     * Callback for select2 `initSelection` property.
     *
     * @param {HTMLElement} el The select2 element that stores values.
     * @param {Function} callback select2 callback to initialize the plugin.
     * @private
     */
    _onInitSelect: function(el, callback) {
        var $el = $(el);
        var id = $el.val();
        var text = String($el.data('rname'));

        if (!this.def.isMultiSelect) {
            return callback({id: id, text: text, label: this.label});
        }
        var ids = id.split(this._separator);
        text = text.split(this._separator);
        callback(_.map(ids, function(value, index) {
            return {id: value, text: text[index]};
        }));
    },

    /**
     * Callback for select2 `formatSelection` property.
     *
     * @param {object} obj object containing the item name.
     * @returns {string} A string containing template for a pill.
     *
     * @private
     */
    _onFormatSelection: function(obj) {
        var ctx = {};
        // TODO We should investigate why it's sometimes `text` and
        // sometimes `id` and make it always same if possible.
        ctx.text = obj.text || obj.id;
        return this._select2formatSelectionTemplate(ctx);
    },

    /**
     * Callback when select2 plugin opens.
     * @private
     * @param {Event} e The `click` event.
     */
    _onSelect2Open: function(e) {
        var plugin = this.$(e.currentTarget).data('select2');
        if (plugin.searchmore) {
            return;
        }
        var label = app.lang.get('LBL_SEARCH_AND_SELECT_ELLIPSIS', this.module);
        var $tpl = $('<div/>').addClass('select2-result-label').html(label);
        var onMouseDown = function() {
            // eslint-disable-next-line new-cap
            plugin.opts.element.trigger($.Event('searchmore'));
            plugin.close();
        };
        var $content = $('<li class="select2-result">').append($tpl).mousedown(onMouseDown);
        plugin.searchmore = $('<ul class="select2-results">').append($content);
        plugin.dropdown.append(plugin.searchmore);
    },

    /**
     * Builds the route for the relate module's record.
     * @param {string} module The related module.
     * @param {string} id The record id to link to.
     *
     * TODO since base.js has a build href, we should try to reuse code or
     * extend this one from other "link" field
     */
    buildRoute: function(module, id) {
        if (_.isUndefined(id) || !this.viewDefs.link) {
            return;
        }

        var oldModule = module;
        // This is a workaround until bug 61478 is resolved to keep parity with 6.7
        if (module === 'Users' && this.context.get('module') !== 'Users') {
            module = 'Employees';
        }

        if (_.isEmpty(module)) {
            return;
        }

        var relatedRecord = this.model.get(this.fieldDefs.link);
        var action = this.viewDefs.route ? this.viewDefs.route.action : 'view';

        if (app.acl.hasAccess(action, oldModule, {acls: relatedRecord?._acl})) {
            this.href = '#' + app.router.buildRoute(module, id);
        } else {
            this.href = undefined;
        }

        this.module = module;
        this.modelId = id;
        this.linkTarget = 'focus';
    },

    // Derived controllers can override these if related module and id in another
    // place.
    _buildRoute: function() {
        this.buildRoute(this.getSearchModule(), this._getRelateId());
    },
    _getRelateId: function() {
        return this.model.get(this.def.id_name);
    },

    /**
     * @inheritdoc
     *
     * When there is no value set and we are in a create view, we try to check
     * if the parent context module matches this relate field. If it matches,
     * we pre-populate with that data.
     *
     * FIXME: the relate field should use this method to pre-populate the
     * values without touching the model or else we need to use silent to
     * prevent the warning of unsaved changes, consequently we can't bind
     * events like `change` to it.
     *
     * TODO: the model might not have the field that we are relating to. On
     * those corner cases, we need to fetch from the server that information.
     *
     * @returns {string} This field's value. Need to change to object with all
     *   data that we need to render the field.
     */
    format: function(value) {
        var parentCtx = this.context && this.context.parent;
        var setFromCtx;

        if (value) {
            /**
             * Flag to indicate that the value has been set from the context
             * once, so if later the value is unset, we don't set it again on
             * {@link #format}.
             *
             * @type {boolean}
             * @protected
             */
            this._valueSetOnce = true;
        }

        // This check sees if we should populate the field from the context.
        // Note that this is a different condition from if we should populate
        // the field from a parent model.
        //
        // Also note that readonly fields are not automatically populated from
        // the context.
        setFromCtx = value === null && !this.fieldDefs.readonly &&
            !this._valueSetOnce && parentCtx && _.isEmpty(this.context.get('model').link) &&
            this.view instanceof app.view.views.BaseCreateView &&
            parentCtx.get('module') === this.def.module &&
            this.module !== this.def.module;

        if (setFromCtx) {
            this._valueSetOnce = true;
            var model = parentCtx.get('model');
            // FIXME we need a method to prevent us from doing this
            this.def.auto_populate = true;
            // FIXME the setValue receives a model but not a backbone model...
            this.setValue(model.toJSON());
            // FIXME we need to iterate over the populated_ that is causing
            // unsaved warnings when doing the auto populate.
        }

        if (!this.def.isMultiSelect && this.action !== 'edit' && !this.context.get('create')) {
            this._buildRoute();
        }

        // If the field is on the audit log, remove the link. We need to explicitly
        // clear the href here as some fields will have an href created in the base
        // field controller.
        if (this.view.name === 'audit') {
            this.href = null;
        }

        var idList = this.model.get(this.def.id_name);
        if (_.isArray(value)) {
            this.formattedRname = value.join(this._separator);
            this.formattedIds = idList.join(this._separator);
        } else {
            this.formattedRname = value;
            this.formattedIds = idList;
        }

        return value;
    },

    /**
     * Sets the value in the field.
     *
     * @param {object|Array} models The source models attributes.
     */
    setValue: function(models) {
        if (!models) {
            return;
        }
        var isErased = false;
        var updateRelatedFields = true;
        var values = {};
        if (_.isArray(models)) {
            // Does not make sense to update related fields if we selected
            // multiple models
            updateRelatedFields = false;
        } else {
            models = [models];
        }

        values[this.def.id_name] = [];
        values[this.def.name] = [];
        if (this.fieldDefs.link) {
            values[this.fieldDefs.link] = [];
        }

        _.each(models, _.bind(function(model) {
            values[this.def.id_name].push(model.id);
            // FIXME SC-4196 will fix the fallback to `formatNameLocale` for person type models.
            values[this.def.name].push(model[this.getRelatedModuleField()] ||
                app.utils.formatNameLocale(model) || model.value);
            if (this.fieldDefs.link) {
                values[this.fieldDefs.link].push(model);
            } else {
                isErased = app.utils.isNameErased(app.data.createBean(model._module, model));
            }
        }, this));

        // If it's not a multiselect relate, we get rid of the array.
        if (!this.def.isMultiSelect) {
            values[this.def.id_name] = values[this.def.id_name][0];
            values[this.def.name] = values[this.def.name][0];
            if (this.fieldDefs.link) {
                values[this.fieldDefs.link] = values[this.fieldDefs.link][0];
            } else {
                this._nameIsErased = isErased;
            }
        }

        // In case of selecting an erased value twice, we need to force a re-render to show the erased placeolder.
        var forceUpdate = _.isEmpty(this.model.get(this.def.name)) && _.isEmpty(values[this.def.name]);

        this.model.set(values);

        if (updateRelatedFields) {
            // Force an update to the link field as well so that SugarLogic and other listeners can react
            if (this.fieldDefs.link && _.isEmpty(values[this.fieldDefs.link]) && forceUpdate) {
                this.model.trigger('change:' + this.fieldDefs.link);
            }
            this.updateRelatedFields(models[0]);
        }

        if (forceUpdate) {
            this._updateField();
        }
    },

    _updateErasedPlaceholder: function() {
        // Handle erased field placehilders
        // Show a custom placeholder if the field's content has been erased
        if (this._isErasedField()) {
            this.hasErasedPlaceholder = true;
        } else {
            this.hasErasedPlaceholder = false;
        }
    },

    /**
     * Handles update of related fields.
     *
     * @param {object} model The source model attributes.
     */
    updateRelatedFields: function(model) {
        var newData = {};
        var self = this;
        _.each(this.def.populate_list, function(target, source) {
            target = !_.isArray(target) ? [target] : target;
            _.each(target, function(targetName) {
                source = _.isNumber(source) ? targetName : source;
                if (!_.isUndefined(model[source]) && app.acl.hasAccessToModel('edit', this.model, targetName)) {
                    var before = this.model.get(targetName);
                    var after = model[source];
                    if (before !== after) {
                        newData[targetName] = model[source];
                    }
                }
            }, this);
        }, this);

        if (_.isEmpty(newData)) {
            return;
        }

        // if this.def.auto_populate is true set new data and doesn't show alert message
        if (!_.isUndefined(this.def.auto_populate) && this.def.auto_populate == true) {
            // if we have a currency_id, set it first to trigger the currency conversion before setting
            // the values to the model, this prevents double conversion from happening
            if (!_.isUndefined(newData.currency_id)) {
                this.model.set({currency_id: newData.currency_id});
                delete newData.currency_id;
            }
            this._setRelated(newData);
            return;
        }

        // load template key for confirmation message from defs or use default
        var messageTplKey = this.def.populate_confirm_label || 'TPL_OVERWRITE_POPULATED_DATA_CONFIRM';
        var messageTpl = Handlebars.compile(app.lang.get(messageTplKey, this.getSearchModule()));
        var fieldMessageTpl = app.template.getField(this.type, 'overwrite-confirmation', this.model.module);
        var messages = [];
        var relatedModuleSingular = app.lang.getModuleName(this.def.module);

        _.each(newData, function(value, field) {
            var before = this.model.get(field);
            var after = value;

            if (before !== after) {
                var def = this.model.fields[field];
                messages.push(fieldMessageTpl({
                    before: before,
                    after: after,
                    field_label: app.lang.get(def.label || def.vname || field, this.module),
                }));
            }
        }, this);

        app.alert.show('overwrite_confirmation', {
            level: 'confirmation',
            messages: messageTpl({
                values: new Handlebars.SafeString(messages.join(', ')),
                moduleSingularLower: relatedModuleSingular.toLowerCase(),
            }),
            onConfirm: function() {
                // if we have a currency_id, set it first to trigger the currency conversion before setting
                // the values to the model, this prevents double conversion from happening
                if (!_.isUndefined(newData.currency_id)) {
                    self.model.set({currency_id: newData.currency_id});
                    delete newData.currency_id;
                }
                self._setRelated(newData);
            },
        });
    },

    /**
     * @override
     */
    _isErasedField: function() {
        if (!this.model) {
            return false;
        }

        var def = this.fieldDefs;
        var link = this.model.get(def.link);

        if (link) {
            var recordField = app.metadata.getField({
                module: def.module,
                name: def.rname,
            });

            if (recordField && recordField.type === 'fullname') {
                return app.utils.isNameErased(app.data.createBean(def.module, link));
            } else {
                return _.contains(link._erased_fields, def.rname);
            }
        } else {
            return this._nameIsErased || this._super('_isErasedField');
        }
    },

    /**
     * Opens the selection drawer.
     *
     * Note that if the field definitions have a `filter_relate` property, it
     * will open the drawer and filter by this relate field.
     *
     *     @example a Revenue Line Item is associated to an account and to an
     *      opportunity. If I want to open a drawer to select an opportunity
     *      with an initial filter that filters opportunities by the account
     *      associated to the revenue line item, in the field definitions I can
     *      specify:
     *      ```
     *      'filter_relate' => array(
     *          'account_id' => 'account_id',
     *      ),
     *      ```
     *      The key is the field name in the Revenue Line Items record,
     *      the value is the field name in the Opportunities record.
     */
    openSelectDrawer: function() {
        var layout = 'selection-list';

        // Merge pre-filter options (if configured) with existing filter options
        const filterOptions = _.extend({}, this.getFilterOptions(), this.getPrefilterOptions());

        var context = {
            module: this.getSearchModule(),
            fields: this.getSearchFields(),
            filterOptions: filterOptions,
        };

        if (!!this.def.isMultiSelect) {
            layout = 'multi-selection-list';
            _.extend(context, {
                preselectedModelIds: _.clone(this.model.get(this.def.id_name)),
                maxSelectedRecords: this._maxSelectedRecords,
                isMultiSelect: true,
            });
        }

        app.drawer.open({
            layout: layout,
            context: context,
        }, _.bind(this.setValue, this));
    },

    /**
     * Gets the list of fields to search by in the related module.
     *
     * @returns {Array} The list of fields.
     */
    getSearchFields: function() {
        return _.union(['id', this.getRelatedModuleField()], _.keys(this.def.populate_list || {}));
    },

    /**
     * Gets the related field name in the related module record.
     *
     * Falls back to `name` if not defined.
     *
     * @returns {string} The field name.
     */
    getRelatedModuleField: function() {
        return this.def.rname || 'name';
    },

    /**
     * @inheritdoc
     *
     * We need this empty so it won't affect refresh the select2 plugin
     */
    bindDomChange: function() {
    },

    /**
     * Gets the correct module to search based on field/link defs.
     *
     * If both `this.def.module` and `link.module` are empty, fall back onto the
     * metadata manager to get the proper module as a last resort.
     *
     * @returns {string} The module to search on.
     */
    getSearchModule: function() {
        // If we have a module property on this field, use it
        if (this.def.module) {
            return this.def.module;
        }

        // No module in the field def, so check if there is a module in the def
        // for the link field
        var link = this.fieldDefs.link && this.model.fields && this.model.fields[this.fieldDefs.link] || {};
        if (link.module) {
            return link.module;
        }

        // At this point neither the def nor link field def have a module... let
        // metadata manager try find it
        return app.data.getRelatedModule(this.model.module, this.fieldDefs.link);
    },
    getPlaceHolder: function() {
        var searchModule = this.getSearchModule();
        var searchModuleLower = searchModule.toLocaleLowerCase();
        var module = app.lang.getModuleName(searchModule, {defaultValue: searchModuleLower});

        return app.lang.get('LBL_SEARCH_SELECT_MODULE', this.module, {
            module: new Handlebars.SafeString(module),
        });
    },

    /**
     * Gets the default relate filter metadata for the module, if one exists
     * @returns {null|object}
     * @private
     */
    _getModuleDefaultFilterMetadata: function() {
        const moduleMetadata = app.metadata.getModule(this.getSearchModule());
        if (_.isEmpty(moduleMetadata) ||
            _.isEmpty(moduleMetadata.defaultRelateFilter) ||
            this.def.default_relate_filter === false
        ) {
            return null;
        }
        return moduleMetadata.defaultRelateFilter;
    },

    /**
     * Gets the default relate filter for the module, if one exists
     * @returns {null|object}
     * @private
     */
    _getModuleDefaultFilter: function() {
        if (!app.metadata.getModule('Filters') || !this.filters || !this.filters.collection) {
            return null;
        }

        const filterMetadata = this._getModuleDefaultFilterMetadata();
        if (_.isEmpty(filterMetadata)) {
            return null;
        }

        const filterBeanClass = app.data.getBeanClass('Filters').prototype;
        const filterOptions = new app.utils.FilterOptions().config(filterMetadata).format();
        const filter = this.filters.collection.get(filterOptions.initial_filter);
        let filterDef = null;

        if (filter) {
            const populate = filter.get('is_template') && filterOptions.filter_populate;
            filterDef = filterBeanClass.populateFilterDefinition(filter.get('filter_definition') || {}, populate);
        }

        return filterDef;
    },

    /**
     * Formats the filter options.
     *
     * @param {boolean} force `true` to force retrieving the filter options
     *   whether or not it is available in memory.
     * @returns {object} The filter options.
     */
    getFilterOptions: function(force) {
        if (this._filterOptions && !force) {
            return this._filterOptions;
        }

        // If the related module has a default filter, apply that only if there is no field-specific default filter
        const moduleDefaultFilter = this._getModuleDefaultFilterMetadata();
        if (!_.isEmpty(moduleDefaultFilter) && _.isEmpty(this.def.initial_filter)) {
            this._filterOptions = new app.utils.FilterOptions().config(moduleDefaultFilter).format();
        } else {
            this._filterOptions = new app.utils.FilterOptions()
                .config(this.def)
                .setInitialFilter(this.def.initial_filter || '$relate')
                .populateRelate(this.model)
                .format();
        }

        return this._filterOptions;
    },

    /**
     * Drops pre-filter conditions naming a field the target module no longer has.
     *
     * Studio stores the condition field names as they were when the pre-filter was
     * configured, and nothing revalidates them on read, so a field deleted since then would
     * fail the FilterApi request. Dropping the clause matches what the filter panel does
     * with a saved list view filter that names a deleted field, except that a pre-filter is
     * invisible to the user, so a stale one is worth reporting to whoever can fix it.
     *
     * @param {Array|object} conditions The stored pre-filter conditions.
     * @returns {Array} The conditions the target module can still be filtered by.
     * @private
     */
    _removeStaleConditions: function(conditions) {
        // Nothing to clean, and nothing worth passing on: the stored value was empty or
        // failed to parse
        if (_.isEmpty(conditions)) {
            return [];
        }

        // The cleanup lives on the Filters bean, which is only there when the module is
        // available - the same dependency `buildFilterDefinition` guards before using it
        if (!app.metadata.getModule('Filters')) {
            return _.isArray(conditions) ? conditions : [conditions];
        }

        const targetModule = this.getSearchModule();
        const cleaned = app.data.getBeanClass('Filters').prototype
            .removeUnknownFields(conditions, targetModule);

        if (cleaned.length !== _.size(conditions)) {
            app.logger.warn('Relate field "' + this.name + '" has pre-filter conditions naming fields ' +
                'that module "' + targetModule + '" no longer has. Those conditions were dropped.');
        }

        return cleaned;
    },

    /**
     * Expands checkbox "is false" pre-filter conditions to also match records for which no value has ever been stored.
     *
     * A boolean condition is serialized as {field: {$equals: '0'}}, which the server translates to `field = 0`.
     * A custom checkbox that has never been touched has no stored value (`NULL` in the custom-field table). Because
     * `field = 0` does not match `NULL`, records that appear unchecked in the UI would otherwise be omitted.
     *
     * Only the false case needs special handling: a `NULL` value should not match an "is true" condition, so true
     * conditions are left unchanged. A matching clause is rewritten as `(field = 0 OR field IS NULL)`. The explicit
     * `$is_null` operator is used instead of `$empty` because comparing a numeric column with `''` is not portable
     * across the supported databases.
     *
     * Conditions that do not represent a checkbox "is false" comparison are returned unchanged. This includes
     * non-boolean fields, operators other than `$equals`, and logical groups, so clauses that require no modification
     * retain their exact shape.
     *
     * @param {Array} conditions The parsed pre-filter conditions.
     * @returns {Array} The conditions with false-boolean clauses expanded.
     * @private
     */
    _expandFalseBooleanConditions: function(conditions) {
        if (!_.isArray(conditions)) {
            return conditions;
        }

        // A missing module leaves this an empty map, so nothing is seen as boolean and every
        // clause is returned unchanged - the safe fallback when metadata has not loaded.
        const targetFields = (app.metadata.getModule(this.getSearchModule()) || {}).fields || {};

        // Keep this in sync with SugarFieldBool::unformatField's definition of false values.
        const falseValues = ['', '0', 0, false, 'off', 'false', 'no'];

        const isFalseBooleanCondition = function(fieldName, spec) {
            return targetFields[fieldName] &&
                targetFields[fieldName].type === 'bool' &&
                _.isObject(spec) &&
                !_.isArray(spec) &&
                _.keys(spec).length === 1 &&
                _.has(spec, '$equals') &&
                _.contains(falseValues, spec.$equals);
        };

        const nullAwareClause = function(fieldName) {
            const equalsFalse = {};
            const isNull = {};

            // Normalize every false representation to '0' - the value the server stores for an unchecked box - and
            // add a NULL match for boxes that were never set ($is_null carries no value, hence the empty string).
            equalsFalse[fieldName] = {$equals: '0'};
            isNull[fieldName] = {$is_null: ''};

            return {$or: [equalsFalse, isNull]};
        };

        return _.map(conditions, function(clause) {
            if (!_.isObject(clause) || _.isArray(clause)) {
                return clause;
            }

            const fieldNames = _.keys(clause);
            const falseBooleanFields = _.filter(fieldNames, function(fieldName) {
                return isFalseBooleanCondition(fieldName, clause[fieldName]);
            });

            // No checkbox "is false" condition: leave the clause exactly as it is.
            if (_.isEmpty(falseBooleanFields)) {
                return clause;
            }

            // Single-key clause (what the conditions builder produces per row): the whole clause
            // becomes the null-aware $or.
            if (fieldNames.length === 1) {
                return nullAwareClause(fieldNames[0]);
            }

            // Defensive: a clause carrying other keys alongside the checkbox one. Keep the others
            // as-is and AND them with the expanded boolean.
            const parts = _.map(fieldNames, function(fieldName) {
                if (_.contains(falseBooleanFields, fieldName)) {
                    return nullAwareClause(fieldName);
                }

                const other = {};
                other[fieldName] = clause[fieldName];
                return other;
            });

            return {$and: parts};
        });
    },

    /**
     * Builds the locked, non-removable pre-filter constraint(s) from Studio configuration.
     * Supports two independent pre-filter sources:
     * 1. Anchor-field pre-filter: When prefilter_field is configured, filters by the related
     *    record in that anchor field (immutable to the user).
     * 2. Condition pre-filter: When prefilter_conditions is configured, applies its
     *    filter conditions directly (immutable to the user).
     *
     * Both sources are combined with AND logic when both are present. If neither is
     * configured or the anchor field has no value, returns empty object.
     *
     * @returns {object} Object with prefilter_filter_def key when filters are configured,
     *                   or empty object if none. Also includes prefilter_label and
     *                   prefilter_value_label when anchor-field pre-filter is active.
     */
    getPrefilterOptions: function() {
        const prefilterField = this.def.prefilter_field;
        const prefilterConditions = this.def.prefilter_conditions;

        // If neither source is configured, nothing to filter
        if (!prefilterField && !prefilterConditions) {
            return {};
        }

        let conditionFilterClause = {};

        // Parse filter conditions if present
        if (prefilterConditions) {
            try {
                conditionFilterClause = JSON.parse(prefilterConditions);
            } catch (e) {
                app.logger.error('Invalid JSON in prefilter_conditions:', e);
            }

            conditionFilterClause = this._removeStaleConditions(conditionFilterClause);
            conditionFilterClause = this._expandFalseBooleanConditions(conditionFilterClause);
        }

        // If only condition pre-filter is configured (no anchor field), return it
        if (!prefilterField) {
            if (_.isEmpty(conditionFilterClause)) {
                return {};
            }
            return {
                prefilter_filter_def: conditionFilterClause,
            };
        }

        const moduleMeta = app.metadata.getModule(this.model.module) || {};
        const moduleFields = moduleMeta.fields || {};
        const anchorFieldDef = moduleFields[prefilterField];
        if (!anchorFieldDef || !anchorFieldDef.id_name) {
            // Defensive case: configured anchor field doesn't exist or is not a relate field
            app.logger.warn('Relate field "' + this.name + '" has an invalid prefilter_field: "' +
                prefilterField + '" is not a relate field on module "' + this.model.module + '".');
            return {};
        }

        const anchorRecordId = this.model.get(anchorFieldDef.id_name);
        if (_.isEmpty(anchorRecordId)) {
            // No value in anchor field: skip anchor filter, but condition filter may still apply
            if (_.isEmpty(conditionFilterClause)) {
                return {};
            }
            return {
                prefilter_filter_def: conditionFilterClause,
            };
        }

        const targetModule = this.getSearchModule();
        const targetModuleMeta = app.metadata.getModule(targetModule) || {};
        const targetModuleFields = targetModuleMeta.fields || {};
        const targetFieldDefs = _.filter(targetModuleFields, function(field) {
            return field.type === 'relate' && field.module === anchorFieldDef.module && field.id_name;
        });
        if (targetFieldDefs.length > 1) {
            // Fail-safe: skip filter on ambiguous matches
            app.logger.warn('Relate field "' + this.name + '" has an ambiguous prefilter target: ' +
                'module "' + targetModule + '" has more than one relate field pointing at "' +
                anchorFieldDef.module + '".');
            return {};
        }

        let filterFieldName;
        if (targetFieldDefs.length === 1) {
            // Direct: the target module stores the anchor record id in a relate field
            // (e.g. Contacts.account_name → Accounts): filter by that id field.
            filterFieldName = targetFieldDefs[0].id_name;
        } else {
            // No relate field: the modules are connected through a link only
            // (e.g. Contacts.opportunities, M:M). Filter through the link with
            // dot notation, which FilterApi resolves into a join.
            const linkFieldDefs = _.filter(targetModuleFields, function(field) {
                return field.type === 'link' &&
                    app.data.getRelatedModule(targetModule, field.name) === anchorFieldDef.module;
            });
            if (linkFieldDefs.length !== 1) {
                // Fail-safe: skip filter if no path to the anchor module or ambiguous links
                if (linkFieldDefs.length > 1) {
                    app.logger.warn('Relate field "' + this.name + '" has an ambiguous prefilter target: ' +
                        'module "' + targetModule + '" has more than one link pointing at "' +
                        anchorFieldDef.module + '".');
                }
                return {};
            }
            filterFieldName = linkFieldDefs[0].name + '.id';
        }

        // Build anchor constraint to filter
        const anchorFilterClause = {};
        anchorFilterClause[filterFieldName] = {$equals: anchorRecordId};

        // Merge condition filter with anchor filter using AND logic
        let finalFilterClause = [anchorFilterClause];
        if (!_.isEmpty(conditionFilterClause)) {
            finalFilterClause = finalFilterClause.concat(conditionFilterClause);
        }

        const anchorDisplayField = anchorFieldDef.name || prefilterField;
        const anchorLabel = anchorFieldDef.vname ?
            app.lang.get(anchorFieldDef.vname, this.model.module) : prefilterField;

        return {
            prefilter_filter_def: finalFilterClause,
            prefilter_label: anchorLabel,
            prefilter_value_label: this.model.get(anchorDisplayField),
        };
    },

    /**
     * Builds the filter definition to pass to the request when doing a quick search.
     *
     * Combines: search term filter, initial filter, module default filter, and any
     * Studio pre-filters (anchor-field and/or condition-based). All are combined
     * with AND logic. Any may be empty, so this method may return an empty array.
     *
     * The pre-filters from {@link getPrefilterOptions} are immutable to the user —
     * the quick search cannot bypass the pre-filter relationship or conditions.
     *
     * @param {string} searchTerm The term typed in the quick search field.
     * @returns {Array} The filter definition.
     */
    buildFilterDefinition: function(searchTerm) {
        if (!app.metadata.getModule('Filters') || !this.filters) {
            return [];
        }
        var filterBeanClass = app.data.getBeanClass('Filters').prototype;
        var filterOptions = this.getFilterOptions() || {};
        var filter = (this.filters.collection) ? this.filters.collection.get(filterOptions.initial_filter) : null;
        var filterDef;
        var populate;
        var searchTermFilter;
        var searchModule;

        if (filter) {
            populate = filter.get('is_template') && filterOptions.filter_populate;
            filterDef = filterBeanClass.populateFilterDefinition(filter.get('filter_definition') || {}, populate);
            searchModule = filter.moduleName;
        }

        searchTermFilter = filterBeanClass.buildSearchTermFilter(searchModule || this.getSearchModule(), searchTerm);

        // If the related module has a default filter, apply that as well
        const moduleDefaultFilter = this._getModuleDefaultFilter();
        if (!_.isEmpty(moduleDefaultFilter)) {
            searchTermFilter = filterBeanClass.combineFilterDefinitions(moduleDefaultFilter, searchTermFilter);
        }

        const combinedFilterDef = filterBeanClass.combineFilterDefinitions(filterDef, searchTermFilter);
        const prefilterDef = this.getPrefilterOptions().prefilter_filter_def;
        if (_.isEmpty(prefilterDef)) {
            return combinedFilterDef;
        }

        // Pre-filters may contain multiple elements (anchor filter + condition filters).
        // When there are multiple elements, wrap them in an $and clause as a single filter
        // so that combineFilterDefinitions doesn't discard them via [0] indexing.
        let prefilterToApply = prefilterDef;
        if (_.isArray(prefilterDef) && prefilterDef.length > 1) {
            prefilterToApply = [{$and: prefilterDef}];
        }

        return filterBeanClass.combineFilterDefinitions(combinedFilterDef, prefilterToApply);
    },

    /**
     * Searches for related field.
     * @param event
     */
    search: _.debounce(function(query) {
        var term = query.term || '';
        var self = this;
        var params = {};
        var limit = self.def.limit || 5;
        var relatedModuleField = this.getRelatedModuleField();

        if (query.context) {
            // `searchCollection` is shared by every typeahead query on this field, so
            // `next_offset` may already hold the -1 "no more records" sentinel of a
            // newer query by the time a stale `searchmore` event is handled. Paging
            // from that sentinel produces `offset=-1`, which the API rejects with a
            // 500. There is nothing left to page through, so stop here instead.
            if (parseInt(this.searchCollection.next_offset, 10) < 0) {
                if (query.callback && _.isFunction(query.callback)) {
                    query.callback({results: [], more: false, context: query.context});
                }
                return;
            }
            params.offset = this.searchCollection.next_offset;
        }
        params.filter = this.buildFilterDefinition(term);

        this.searchCollection.fetch({
            // Don't show alerts for this request
            showAlerts: false,
            update: true,
            remove: _.isUndefined(params.offset),
            reset: _.isUndefined(params.offset),
            fields: this.getSearchFields(),
            context: self,
            params: params,
            limit: limit,
            success: function(data) {
                var fetch = {results: [], more: data.next_offset > 0, context: data};
                if (fetch.more) {
                    var fieldEl = self.$(self.fieldTag);
                    // For teamset widget, we should specify which index element to be filled in
                    var plugin = (fieldEl.length > 1) ? $(fieldEl.get(self._currentIndex)).data('select2') :
                        fieldEl.data('select2');
                    var height = plugin.searchmore.children('li:first').children(':first').outerHeight();
                    // 0.2 makes scroll not to touch the bottom line which avoid fetching next record set
                    var maxHeight = height * (limit - .2);
                    plugin.results.css('max-height', maxHeight);
                }
                _.each(data.models, function(model, index) {
                    if (params.offset && index < params.offset) {
                        return;
                    }
                    fetch.results.push({
                        id: model.id,
                        text: model.get(relatedModuleField) + '',
                        label: this.label,
                    });
                }, this);
                if (query.callback && _.isFunction(query.callback)) {
                    query.callback(fetch);
                }
            },
            error: function() {
                if (query.callback && _.isFunction(query.callback)) {
                    query.callback({results: []});
                }
                app.logger.error('Unable to fetch the bean collection.');
            },
        });
    }, app.config.requiredElapsed || 500),

    /**
     * @inheritdoc
     * Avoid rendering process on select2 change in order to keep focus.
     */
    bindDataChange: function() {
        if (this.model) {
            this.model.on('change', function() {
                this.getFilterOptions(true);
            }, this);

            this.model.on('change:' + this.name, this._updateField, this);
        }
    },

    _updateField: function() {
        if (this.disposed) {
            return;
        }
        var $dropdown = this.$(this.fieldTag);
        if (!_.isEmpty($dropdown.data('select2'))) {
            var value = this.model.get(this.def.name);
            value = _.isArray(value) ? value.join(this._separator) : value;
            value = value ? value.trim() : value;
            if (this._isErasedField()) {
                value = app.lang.getAppString('LBL_VALUE_ERASED');
            }

            $dropdown.data('rname', value);

            // `id` can be an array of ids if the field is a multiselect.
            var id = this.model.get(this.def.id_name);
            if (_.isEqual($dropdown.select2('val'), id)) {
                return;
            }

            $dropdown.select2('val', id);
        } else {
            this.render();
        }
    },


    unbindDom: function() {
        this.$(this.fieldTag).select2('destroy');
        app.view.Field.prototype.unbindDom.call(this);
    },

    /**
     * Set new values, first checking for any parent/child dependencies, setting
     * parent values first.
     *
     * @param {object} attrs new values to set on model
     * @private
     */
    _setRelated: function(attrs) {
        var dependentAttrs = this._getDependentAttributes(attrs);
        attrs = _.omit(attrs, _.keys(dependentAttrs));

        this.model.set(attrs);
        if (!_.isEmpty(dependentAttrs)) {
            this._setRelated(dependentAttrs);
        }
    },

    /**
     * Gets attributes that depend on other attributes also being set.
     *
     * If a field has a visibility grid, it is a dropdown whose contents  depend
     * on another dropdown. If its parent is also in the attrs about to be set,
     * we need to ensure children are set after their parents.
     *
     * @param {object} attrs Bean attributes
     * @returns {object} Attributes with dependencies
     * @private
     */
    _getDependentAttributes: function(attrs) {
        var fields = this.model.fields || {};
        var dependentAttrs = {};
        _.each(attrs, function(val, key) {
            var field = fields[key];
            if (field &&
                field.visibility_grid &&
                field.visibility_grid.trigger in attrs) {
                dependentAttrs[key] = val;
            }
        }, this);

        return dependentAttrs;
    },

    /**
     * Used by the FocusDrawer plugin to get the ID of the record this
     * field links to
     *
     * @returns {string} the ID of the related record
     */
    getFocusContextModelId: function() {
        return this._getRelateId();
    },

    /**
     * Used by the FocusDrawer plugin to get the name of the module this
     * field links to
     *
     * @returns {string} the name of the related module
     */
    getFocusContextModule: function() {
        return this.getSearchModule();
    },
})

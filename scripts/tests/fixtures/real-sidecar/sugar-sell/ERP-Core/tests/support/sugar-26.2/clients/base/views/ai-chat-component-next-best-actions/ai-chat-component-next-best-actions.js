// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-chat-component-next-best-actions/ai-chat-component-next-best-actions.js; sha256 3a7678add8857e8dcbf1705b0c2c3e742184b8f4dcf1066bc9a9c38c332d5ba5 of the stock file, which follows unchanged.
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
 * @class View.Views.Base.AiChatComponentNextBestActionsView
 * @alias SUGAR.App.view.views.BaseAiChatComponentNextBestActionsView
 * @augments View.View
 */
({
    extendsFrom: 'AiChatComponentView',

    plugins: ['AiChatComponentHandler', 'AiChatComponentActions'],
    events: {
        'click [data-action="next-best-action"]': 'handleItemClick',
    },

    /**
     * @inheritdoc
     */
    _initProperties: function() {
        this._super('_initProperties');

        const data = this.options.data || {};

        this.items = data.rows || [];

        this.receiversModules = ['Calls', 'Meetings'];
        this.emailModule = 'Emails';
    },

    /**
     * @inheritdoc
     */
    _registerEvents: function() {
        this._super('_registerEvents');

        this.listenTo(app.events, 'ai-chat-NBA:success', this.completedNBA);
    },

    /**
     * Handle next best action clicked
     *
     * @param {jQuery} e Event object
     */
    handleItemClick: function(e) {
        e.preventDefault();

        const recordInfo = this._getItemInformation(e);

        const {model, layout} = this.createActionRecord(recordInfo);

        const {$chatContainer, originalChatZIndex} = this._overrideChatContainerLook();

        this.setOpenDrawerLook({
            module: recordInfo.module,
            model: model,
            $chatContainer: $chatContainer,
            originalChatZIndex: originalChatZIndex,
            layout: layout,
            actionDescription: recordInfo.description,
        });
    },

    /**
     * Get item information from the clicked element
     * @param {jQuery} e Event object
     *
     * @returns {object} Item information
     */
    _getItemInformation: function(e) {
        const $item = $(e.currentTarget);
        const description = $item.data('description');
        const text = $item.data('text');
        const longDescription = $item.data('longDescription');
        const module = $item.data('module');

        const item = this.items.find((i) => i.description === description) || {};

        return {
            text,
            description,
            longDescription,
            module,
            relatedRecord: item.relatedRecord,
            receivers: item.receivers,
        };
    },

    /**
     * Get email 'to' and 'cc' data for the given description of the item
     * @param {string} description
     * @returns {object}
     */
    _getEmailsData: function(description) {
        const item = this.items.find(function(i) {
            return i.description === description;
        });

        const to = item ? item.to : undefined;
        const cc = item ? item.cc : undefined;

        return {to: to, cc: cc};
    },

    /**
     * Create the action record model and determine the layout to use
     * @param {object} param - The parameters for creating the action record
     * @returns {object}
     */
    createActionRecord: function({relatedRecord, module, text, description, longDescription, receivers}) {
        if (!module) {
            return;
        }

        let layout = 'create';

        // Prepare pre-populate data for the new record
        const prepopulateData = {
            description: description,
            name: text,
        };

        // Create a new bean with pre-populated data
        let model = app.data.createBean(module, prepopulateData);

        model.set('parent_type', relatedRecord.recordType);
        model.set('parent_name', relatedRecord.recordName);
        model.set('parent_id', relatedRecord.recordId);

        // Special handling for Emails
        if (module === this.emailModule) {
            layout = 'compose-email';

            model = this._prepareEmailModel(model, description, longDescription, receivers);
        }

        if (receivers && this.receiversModules.includes(module)) {
            const receiverModule = _.first(receivers).recordType;
            const invitees = app.data.createBeanCollection(receiverModule);
            receivers.forEach((receiver) => {
                const record = app.data.createBean(receiver.recordType, {
                    id: receiver.recordId,
                    name: receiver.recordName,
                    _module: receiver.recordType,
                });
                record.module = receiverModule;
                invitees.add(record);
            });

            model.set('invitees', invitees);
        }

        return {
            model,
            layout,
        };
    },

    /**
     * Prepares and populates the email model with description, recipients, and related records.
     * @param {Data.Bean} model
     * @param {string} description
     * @param {string} longDescription
     * @returns {Data.Bean} The updated email model ready for compose view.
     */
    _prepareEmailModel: function(model, description, longDescription) {
        model.set('description_html', description);

        const {to = [], cc =[]} = this._getEmailsData(description);

        // NOT IMPLEMENTED ON THE BACKEND YET
        const setCollection = (type, recipients) => {
            if (!Array.isArray(recipients) || recipients.length === 0) {
                return;
            }
            const key = `${type}_collection`;
            if (!model.get(key)) {
                model.set(key, app.data.createBeanCollection('EmailParticipants'));
            }

            recipients.forEach((receiver) => {
                const data = {
                    _link: type,
                };

                if (receiver.relatedRecord) {
                    const {recordType, recordId, recordName} = receiver.relatedRecord;
                    Object.assign(data, {
                        parent_type: recordType,
                        parent_id: recordId,
                        parent_name: recordName,
                        parent: {_acl: {}, _erased_fields: [], type: recordType, id: recordId, name: recordName},
                    });
                } else if (receiver.email_address) {
                    data.email_address_id = receiver.email_address;
                    data.email_address = receiver.email_address;
                }

                model.get(key).add(app.data.createBean('EmailParticipants', data));
            });
        };

        setCollection('to', to);
        setCollection('cc', cc);

        return model;
    },

    /**
     * Overrides the chat container's appearance for the record compose view.
     * @returns {object} The chat container and its original z-index.
     */
    _overrideChatContainerLook: function() {
        const $chatContainer = $('.ai-chat-layout');

        const originalChatZIndex = $chatContainer.length > 0 ? $chatContainer.css('z-index') : null;

        if ($chatContainer.length > 0) {
            $chatContainer.css({
                'z-index': '900',
                'opacity': '0',
            });
        }

        return {
            $chatContainer,
            originalChatZIndex,
        };
    },

    /**
     * Opens the Sugar drawer for record creation and temporarily adjusts chat container styling.
     * Restores the chat container state after the drawer closes.
     *
     * @param {object} options - Configuration object for opening the drawer.
     */
    setOpenDrawerLook: function(options) {
        const {module, model, $chatContainer, originalChatZIndex, layout, actionDescription} = options;

        app.drawer.open({
            layout,
            context: {
                create: true,
                module: module,
                model: model,
            },
        }, _.bind(function(refresh, createdModel) {
            // Restore original z-indexes after drawer closes
            if ($chatContainer.length > 0 && originalChatZIndex) {
                $chatContainer.css({
                    'z-index': originalChatZIndex,
                    'opacity': '',
                });
            }

            if (refresh && createdModel) {
                app.alert.show('nba-create-success', {
                    level: 'success',
                    messages: app.lang.get('LBL_RECORD_SAVED', module),
                    autoClose: true,
                    autoCloseDelay: 3000,
                });

                app.events.trigger('ai-chat-NBA:success', actionDescription);
            } else {
                this.render();
            }
        }, this));
    },

    /**
     * Marks the specified action as completed.
     * @param {string} actionDescription
     */
    completedNBA: function(actionDescription) {
        // Find the item that was just completed and mark it as disabled
        if (actionDescription) {
            const item = this.items.find((item) => item.description === actionDescription);
            if (item) {
                item.disabled = true;
                // Re-render to show the disabled state
                this.render();
            }
        }
    },

    /**
     * @inheritdoc
     */
    serializeText: function() {
        return '';
    },
})

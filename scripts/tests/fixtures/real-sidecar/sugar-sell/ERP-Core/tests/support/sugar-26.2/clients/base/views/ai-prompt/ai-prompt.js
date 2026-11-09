// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-prompt/ai-prompt.js; sha256 84bcf67a7ba53c7b64eafb0916b83c993826dcb2e06f4ea8b49880f8ddab5b5c of the stock file, which follows unchanged.
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
 * @class View.Layouts.Base.AiPromptView
 * @alias SUGAR.App.view.views.BaseAiPromptView
 * @augments View.View
 */
({
    className: 'ai-prompt group/prompt relative flex-1 h-full min-w-[191px] p-4 box-border ' +
        'bg-[--foreground-base] cursor-pointer',

    /**
     * @inheritdoc
     */
    events: {
        'click [data-action="show-new-prompt"]': '_showNewPromptClicked',
        'click': 'openChat',
    },

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this._super('initialize', [options]);

        this._bindEvents();
        this.prompt = options.data;

        this._initProperties();
    },

    /**
     * Initialize properties
     */
    _initProperties: function() {
        this.isNewPromptAvailable = this.layout ? !!this.layout.findNextAvailablePromptId() : false;

        this.isDarkMode = app.utils.isDarkMode();
    },

    /**
     * Bind events for component communication
     */
    _bindEvents: function() {
        this.listenTo(app.events, {
            'ai-chat:event': this.onChatClose,
            'ai-chat:new-chat': this.clearActive,
        });
    },

    /**
     * Show full-card skeleton; hide real card body.
     *
     * @param {string} [message]
     */
    showComponentLoading: function(message) {
        this.$el.addClass('is-loading');

        // Re-create overlay if a prior render wiped it from the DOM.
        if (this._loadingComponentOverlay &&
            (!this.$el.length || !$.contains(this.$el[0], this._loadingComponentOverlay[0]))) {
            this._loadingComponentOverlay = null;
        }

        this._super('showComponentLoading', [message]);
    },

    /**
     * Hide skeleton and restore card body.
     */
    hideComponentLoading: function() {
        this._super('hideComponentLoading');
        this.$el.removeClass('is-loading');
    },

    /**
     * Handles when the user clicks to show a new prompt
     *
     * @param {object} evt
     * @private
     */
    _showNewPromptClicked: function(evt) {
        // Prevent the click from bubbling up to the openChat handler
        evt.stopPropagation();
        this.showNewPromptWithLoader();
    },

    /**
     * Show new prompt with loading using toggleComponentLoading
     */
    showNewPromptWithLoader: function() {
        if (!this.isNewPromptAvailable) {
            return;
        }

        this.toggleComponentLoading(true);

        const delayTime = 300;
        _.delay(() => {
            if (this.disposed) {
                return;
            }

            this.showNewPrompt();

            this.toggleComponentLoading(false);
        }, delayTime);
    },

    /**
     * Show new prompt
     */
    showNewPrompt: function() {
        const prompt = this.layout.setNewPrompt(this.prompt.smartPromptId);
        if (prompt) {
            this.prompt = prompt;
        }
        this.render();
    },

    /**
     * Open AI chat
     *
     * @param {Event} e
     */
    openChat: function(e) {
        const prompt = this.prompt;

        if (!prompt || !prompt.smartPromptId) {
            return;
        }

        const executionResponse = app.smartPrompts.getCardExecutionResponse(prompt.smartPromptId);

        if (executionResponse) {
            this._openCachedChat(prompt, executionResponse);
            return;
        }

        app.smartPrompts.fetchOnePrompt(prompt.smartPromptId).then((card) => {
            if (this.disposed || !card) {
                return;
            }

            const executionResponse = app.smartPrompts.getCardExecutionResponse(prompt.smartPromptId);

            if (!executionResponse) {
                return;
            }

            this.prompt = _.omit(card, 'executionResponse');
            this._openCachedChat(this.prompt, executionResponse);
        });
    },

    /**
     * Open chat using a cached smart prompt execution payload.
     *
     * @param {object} prompt
     * @param {object} executionResponse
     * @private
     */
    _openCachedChat: function(prompt, executionResponse) {
        app.events.trigger('ai-chat:action', {
            type: 'prompt-submit',
            target: this,
            payload: {
                cachedExecutionResponse: executionResponse,
                chatId: executionResponse.chatId || null,
                chatTitle: prompt.smartPromptText,
                ignoreUser: true,
            },
        });

        this.$el
            .toggleClass('active', true)
            .siblings()
            .toggleClass('active', false);
    },

    /**
     * Remove the active card highlight.
     */
    clearActive: function() {
        this.$el.toggleClass('active', false);
    },

    /**
     * Handle chat close event
     */
    onChatClose: function(action) {
        if (action.type === 'chat-open-change' && !action.isOpen) {
            this.clearActive();
        }
    },
})

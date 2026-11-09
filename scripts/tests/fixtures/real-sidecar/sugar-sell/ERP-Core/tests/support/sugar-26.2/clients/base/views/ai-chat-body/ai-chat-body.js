// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-chat-body/ai-chat-body.js; sha256 4e0a28762de1c4831eba36d9929ec42fec93ec5973d1f3ca274d2c03702d574f of the stock file, which follows unchanged.
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
 * @class View.Views.Base.AiChatBodyView
 * @alias SUGAR.App.view.views.BaseAiChatBodyView
 * @augments View.View
 */
({
    plugins: [
        'AiChatStateManager',
        'AiChatComponentHandler',
        'AiChatPaginationManager',
        'AiChatStreaming',
    ],

    events: {
        'click [data-type="prompt"]': 'handlePromptClick',
        'click [data-action="detailed-analysis"]': 'handleDetailedAnalysisClick',
        'click [data-action="load-more"]': 'handleLoadMoreClick',
    },

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this._super('initialize', [options]);

        this._initProperties();
    },


    /**
     * Initialize properties
     */
    _initProperties: function() {
        this.quickPrompts = [
            {
                prompt: app.lang.get('LBL_AI_CHAT_PROMPT_THIS_WEEK_WORK_PROMPT'),
                text: app.lang.get('LBL_AI_CHAT_PROMPT_THIS_WEEK_WORK'),
            },
            {
                prompt: app.lang.get('LBL_AI_CHAT_PROMPT_PREPARE_MEETINGS_PROMPT'),
                text: app.lang.get('LBL_AI_CHAT_PROMPT_PREPARE_MEETINGS'),
            },
            {
                prompt: app.lang.get('LBL_AI_CHAT_PROMPT_SUMMARIZE_ACCOMPLISHMENTS_PROMPT'),
                text: app.lang.get('LBL_AI_CHAT_PROMPT_SUMMARIZE_ACCOMPLISHMENTS'),
            },
        ];

        this.isMounted = false;
        this.isDarkMode = app.utils.isDarkMode();
        this.chatId = undefined;
        this._scrollPosition = null;

        this.useLoadingSkeleton = false;

        this.TABLE_BODY_SELECTOR = '[data-target="table-body"]';
        this.LOADING_SELECTOR = '[data-identifier="loading"]';
        this.LOADING_MORE_SELECTOR = '[data-identifier="loading-more"]';
        this.LOAD_MORE_SELECTOR = '[data-action="load-more"]';
        this.PROMPT_SELECTOR = '[data-type="prompt"]';
        this.DETAILED_ANALYSIS_SELECTOR = '[data-action="detailed-analysis"]';
        this.AUTH_RETRY_MAX_ATTEMPTS = 2;

        /**
         *  Error types for Pendo
         *  @type {string|null}
         */
        this._pendingErrorType = null;
        this._pendingPromptText = null;
    },

    /**
     * @inheritdoc
     */
    bindDataChange: function() {
        this.listenTo(app.user, 'change:appearance', this.switchAppearance);
        this.listenTo(app.events, 'ai-chat:body:expand', this.toggleFullScreen);
        this.listenTo(this.context, {
            'change:dialog_info': this.updateChat,
            'change:loading': this.handleLoadingChange,
            'change:opened': this.onChatOpenChange,
            'prompt-stop': this.handleStopRequest,
            'before:state_change': this.handleBeforeStateChange,
            'change:show_details': this.handleShowDetailsPanel,
        });
    },

    /**
     * Handle before state change event to save scroll position before showing details panel
     * @param {string} type - Type of state change
     * @param {object} newState - New state values that will be applied to context
     * @param {boolean} newState.show_details - Whether the details panel will be shown
     */
    handleBeforeStateChange: function(type, newState) {
        if (newState.show_details && this._scrollPosition === null) {
            this._scrollPosition = this.$el.scrollTop();
        }
    },

    /**
     * Handle change in show_details to restore scroll position when details panel is hidden
     */
    handleShowDetailsPanel: function() {
        // restore scroll when it has value and details panel is now hidden
        if (this._scrollPosition !== null && !this.context.get('show_details')) {
            this.$el.scrollTop(this._scrollPosition);
            this._scrollPosition = null;
        }
    },

    /**
     * It will show chat as new chat or previous chat
     * @returns {void}
     */
    updateChat: function() {
        if (!this.isMounted) {
            // prevent updates when component is not mounted to avoid multiple fetches of history
            return;
        }

        const dialogInfo = this.context.get('dialog_info');
        const isHistoryDialog = Boolean(dialogInfo?.chatId && !dialogInfo?.message);
        const isLoadingHistory = isHistoryDialog && this.context.get('loading');

        if (isLoadingHistory) {
            return;
        }
        const shouldClear = !dialogInfo?.chatId || this.chatId !== dialogInfo.chatId || isHistoryDialog;

        if (shouldClear) {
            this._clearChat();
        }

        if (!dialogInfo) {
            this.newChat();
            return;
        }

        this.toggleFullScreen(true);

        if (dialogInfo.message || dialogInfo.filterSelections) {
            this.sendUserPrompt(dialogInfo.message || '', shouldClear, dialogInfo.fromSmartPrompt || false, {
                selectedKey: dialogInfo.selectedKey || null,
                filterSelections: dialogInfo.filterSelections || null,
                clarifierId: dialogInfo.clarifierId || null,
            });
        } else if (dialogInfo.cachedExecutionResponse) {
            this._openCachedSmartPrompt(dialogInfo.cachedExecutionResponse);
        } else if (dialogInfo.chatId) {
            this.getHistory(dialogInfo);
        }
    },

    /**
     * Render a smart prompt from a cached execution payload.
     *
     * @param {object} executionResponse
     * @private
     */
    _openCachedSmartPrompt: function(executionResponse) {
        const groupId = app.utils.generateUUID();
        const response = _.extend({}, executionResponse, {
            promptId: app.utils.generateUUID(),
            groupId: groupId,
        });

        if (response.chatTitle) {
            this.insertUserPrompt(response.chatTitle, true, groupId);
        }

        if (response.chatId) {
            this._updateChatState({lastChatId: response.chatId});
            this.context.set({
                dialog_info: {
                    chatId: response.chatId,
                    message: response.chatTitle || '',
                },
            }, {silent: true});
        }

        this.successResponse(response);
    },

    /**
     * Handle detailed analysis click
     * @param {Event} e
     */
    handleDetailedAnalysisClick: function(e) {
        e.preventDefault();
        e.stopPropagation();

        const components = [];
        this.showDetailsPanel(components);
    },

    /**
     * Start a new chat - clear state and start over
     */
    newChat: function() {
        this.toggleFullScreen(false);
        this.showWelcomeComponent();
        this.toggleComponentLoading(false);
    },

    /**
     * Handle quick prompt click
     *
     * @param {Event} e - Click event
     */
    handlePromptClick: function(e) {
        e.preventDefault();
        const promptText = $(e.currentTarget).data('text').trim();
        this.goToDialog({message: promptText});
    },

    /**
     * Resolve the active chat ID for the current conversation.
     *
     * Prefers the in-memory chat ID (e.g. from a cached Smart Prompt
     * executionResponse) over persisted lastChatId, which may belong to a
     * different prior session.
     *
     * @returns {string|null}
     */
    getActiveChatId: function() {
        if (this.chatId) {
            return this.chatId;
        }

        const state = this._getChatState();

        return state?.lastChatId || null;
    },

    /**
     * Send user prompt and if replacement of content is needed
     * @param {string} promptText
     * @param {boolean} replaceContent
     * @param {boolean} [fromSmartPrompt=false]
     * @param {object} [clarifierAnswer] Structured clarifier answer
     * @param {string} [clarifierAnswer.selectedKey] Stable option key chosen by the user
     * @param {string} [clarifierAnswer.clarifierId] Id of the clarifier question being answered
     */
    sendUserPrompt: function(promptText, replaceContent, fromSmartPrompt, clarifierAnswer) {
        const groupId = app.utils.generateUUID();
        const promptId = app.utils.generateUUID();
        this._pendingErrorType = 'prompt_response_error';
        this._pendingPromptText = promptText;
        this.insertUserPrompt(promptText, replaceContent, groupId);

        const chatId = this.getActiveChatId();

        this.getAiResponse(promptText, chatId, promptId, fromSmartPrompt || false, clarifierAnswer).then((response) => {
            response.groupId = groupId;
            response.promptId = promptId;
            this._updateChatState({lastChatId: response.chatId});
            this.trackUserAiChatInteraction(response, promptText, promptId, response.chatId);
            // it's silent update to not trigger chat state change
            this.context.set({dialog_info: {chatId: response.chatId, message: promptText}}, {silent: true});
            return response;
        })
            .then(_.bind(this.successResponse, this))
            .catch((error) => {
                this.trackUserAiChatInteraction(null, promptText, promptId, chatId);
                this.failureResponse(error);
            });
    },

    /**
     * Track user AI chat interaction analytics event.
     * Fires only when the response contains a single ai-chat-markdown content item.
     *
     * @param {object} response Full API response
     * @param {string} promptText The user's prompt text
     * @param {string} promptId The prompt UUID
     */
    trackUserAiChatInteraction: function(response, promptText, promptId, chatId) {
        const content = response?.data?.content || [];
        const isMarkdownResponse = content.length === 1 && _.first(content).view === 'ai-chat-markdown';

        if (!response || isMarkdownResponse) {
            app.analytics.trackEvent('ai_chat_interaction', 'ai_chat', null, {
                chatId: chatId,
                promptId: promptId,
                promptText: promptText,
                responseText: !response ? 'error' : _.first(content).data?.prompt,
            });
        }
    },

    /**
     * Show or remove loading component
     * @param {object} context Chat context
     * @param {boolean} isLoading
     */
    handleLoadingChange: function(_context, isLoading) {
        this.toggleLoadingState(isLoading);

        if (isLoading) {
            const el = this.$(this.TABLE_BODY_SELECTOR);
            const elP = el.parent();
            const data = {
                isDarkMode: this.isDarkMode,
            };

            if (this._isFetchingHistory()) {
                this.prependComponent('component-load', el, data);
            } else {
                this.appendComponent('component-load', el, data);
                elP.scrollTop(elP.prop('scrollHeight'));
            }
        } else {
            this.$(this.LOADING_SELECTOR).remove();
        }
    },

    /**
     * Scroll to latest message when chat is reopened.
     * Reopen does not always re-fetch history, so we force bottom alignment.
     *
     * @param {object} _context
     * @param {boolean} isOpen
     */
    onChatOpenChange: function(_context, isOpen) {
        if (!isOpen || !this.isMounted || this.context.get('active_component') !== 'ai-chat-panel') {
            return;
        }

        this._scheduleScrollToBottom();
    },

    /**
     * Insert user prompt component and check if replacement of content is needed
     * @param {string} promptText
     * @param {boolean} replaceContent
     */
    insertUserPrompt: function(promptText, replaceContent, groupId) {
        const targetEl = this.$(this.TABLE_BODY_SELECTOR);
        const options = {groupId: groupId};

        if (replaceContent) {
            this.replaceWithComponent('ai-chat-component-prompt', targetEl, {prompt: promptText}, options);
        } else {
            this.appendComponent('ai-chat-component-prompt', targetEl, {prompt: promptText}, options);
        }
    },

    /**
     * @inheritdoc
     */
    render: function() {
        this._super('render');
        this.isMounted = true;

        this.bindScrollListener(
            this._getScrollContainer(),
            _.bind(this.loadMoreHistory, this),
        );

        this.updateChat();
    },

    /**
     * Handle click on "Load More" button
     * @param {Event} e - Click event
     */
    handleLoadMoreClick: function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.loadMoreHistory();
    },

    /**
     * Show or remove full screen mode
     * @param {boolean} show
     */
    toggleFullScreen: function(show) {
        const fullScreenClass = 'h-[calc(100vh-6rem)]';
        this.$el.parent().toggleClass(fullScreenClass, show);
    },

    /**
     * Show welcome component
     */
    showWelcomeComponent: function() {
        this._clearChat();

        this.replaceWithComponent(
            'welcome-prompts',
            this.$(this.TABLE_BODY_SELECTOR),
            {quickPrompts: this.quickPrompts},
        );
    },

    /**
     * Clear chat content and destroy child components
     *
     * @private
     */
    _clearChat: function() {
        this.chatId = undefined;
        this.destroyChildComponents();
        this.$(this.TABLE_BODY_SELECTOR).empty();
        this.resetPaginationState();
    },

    /**
     * Handle successful response from the server for chat history or prompt response.
     *
     * Both endpoints return the same base format with data.content[]:
     * {
     *   chatId: 'xxx',
     *   chatTitle: 'xxx',
     *   pinned: true,
     *   canPin: true,
     *   data: { content: [...] },
     *   pagination: { nextOffset, total, ... }  // Only present in history responses
     * }
     *
     * @param {object} apiResponse - API response
     * @param {object} [options] - Additional options
     * @param {boolean} [options.prepend] - If true, prepend content (for loading older messages)
     * @param {boolean} [options.isInitialHistoryLoad] - If true, scroll to bottom after rendering
     */
    successResponse: function(apiResponse, options = {}) {
        if (!apiResponse) {
            return;
        }

        // Both history and prompt responses use data.content
        const target = this.$(this.TABLE_BODY_SELECTOR);
        const response = apiResponse.data;
        const promptId = apiResponse.promptId;
        // messageId is the stable LLM response identifier returned by POST /chat.
        // It is forwarded to the system-actions component so that thumbs-up/down
        // feedback can reference the exact AI generation in DynamoDB and Langfuse.
        // A history response has no top-level messageId — groupMessageId (tracked
        // below, per content item) supplies it per group instead.
        const messageId = apiResponse.messageId || null;
        // A history response flattens every prompt/answer group into one
        // content array with a single response.recordMeta — the newest
        // group's value only. Track the metadata of the group currently being
        // built from each content item's own recordMeta/messageId (stamped
        // per-group by DataAdapter), falling back to the flat response value
        // so the live/streaming path — which has no per-item recordMeta — is
        // unaffected.
        let groupRecordMeta = response?.recordMeta;
        let groupMessageId = messageId;
        let groupId = apiResponse.groupId;
        let hasComponent = false;
        const chatTitle = apiResponse.chatTitle;
        // Smart prompt cards should land the seller on the top (rank #1)
        // card rather than the bottom of the conversation — tracked here so
        // the scroll can happen once, after every card in this response has
        // rendered, rather than per-card.
        let isSmartPromptCardResponse = false;
        let firstRecordViewEl = null;

        const content = response?.content || [];

        this.chatId = apiResponse.chatId;

        // Update pagination state (pagination is at root level, only present in history responses)
        this.updatePaginationState(apiResponse.pagination);

        // Wipe streaming artifacts before rendering the authoritative result
        this.clearStreamingArtifacts();

        // Dive-deeper is deferred per group so it renders after that group's system actions
        this.tempFlowActionHolder = null;

        _.each(content, function(contentItem) {
            const componentName = this._getComponentName(contentItem);
            if (!componentName) {
                return;
            }

            hasComponent = true;

            if (componentName === 'ai-chat-component-smart-prompt-card') {
                isSmartPromptCardResponse = true;
            }

            // Carry this item's own recordMeta/messageId (stamped per-group by
            // DataAdapter on the history path) as the current group's value.
            if (contentItem.recordMeta) {
                groupRecordMeta = contentItem.recordMeta;
            }
            if (contentItem.messageId) {
                groupMessageId = contentItem.messageId;
            }

            // Defer flow-action components (dive-deeper pills, the filter
            // multiselect) to render after their group's system actions.
            if (componentName === 'ai-chat-component-dive-deeper' ||
                componentName === 'ai-chat-component-filter-multiselect') {
                this.tempFlowActionHolder = contentItem;
                return;
            }

            const isNewGroup = componentName === 'ai-chat-component-prompt';
            if (isNewGroup && groupId && this.chatId) {
                this.appendChatSystemActions(promptId, groupId, groupRecordMeta, chatTitle, groupMessageId);

                if (this.tempFlowActionHolder) {
                    this.setLastFlowActions(response, groupId);
                }
                // Open the new group — its own recordMeta/messageId arrive on
                // its content items below.
                groupId = app.utils.generateUUID();
                groupRecordMeta = null;
                groupMessageId = null;
            } else if (!groupId) {
                groupId = app.utils.generateUUID();
            }

            // A component (e.g. the Save to Notes pill) reads recordMeta off
            // fullApiResponse === options.response. History flattens every
            // group into one response, so override with this item's own
            // recordMeta (per-group on the history path) rather than handing
            // every component the whole multi-group response.
            const componentOptions = {
                response: contentItem.recordMeta ?
                    _.extend({}, response, {recordMeta: contentItem.recordMeta}) : response,
                groupId: groupId,
            };

            let insertedView;
            if (options.prepend) {
                insertedView = this.prependComponent(componentName, target, contentItem.data, componentOptions);
            } else {
                insertedView = this.appendComponent(componentName, target, contentItem.data, componentOptions);
            }

            // Capture the first (rank #1) card of a fresh smart-prompt-card
            // response so it can be scrolled into view once every card in
            // this response has rendered. Not meaningful for options.prepend
            // (loading older history at the top) — leave that path alone.
            if (!options.prepend && !firstRecordViewEl &&
                componentName === 'ai-chat-component-record-view' && insertedView) {
                firstRecordViewEl = insertedView.$el;
            }
        }, this);

        if (this.chatId && hasComponent) {
            this.appendChatSystemActions(promptId, groupId, groupRecordMeta, chatTitle, groupMessageId);
            if (this.tempFlowActionHolder) {
                this.setLastFlowActions(response, groupId);
            }
        }

        // Show load more button if there's more history and button mode is enabled
        this._updateLoadMoreButton();

        // Scroll to bottom only on initial history load (not for new prompts or load more).
        // A fresh smart-prompt-card response scrolls to the top of its first (highest-ranked)
        // card instead, so the seller lands on rank #1 rather than the bottom of the chat.
        if (options.isInitialHistoryLoad) {
            this._scheduleScrollToBottom();
        } else if (isSmartPromptCardResponse && firstRecordViewEl) {
            this._scheduleScrollElementToTop(firstRecordViewEl);
        }

        if (chatTitle) {
            app.events.trigger(
                'ai-chat:set-title',
                chatTitle,
                !!apiResponse.pinned,
                !!apiResponse.canPin,
            );
        }
    },

    /**
     * Update the load more button visibility based on pagination state
     * @private
     */
    _updateLoadMoreButton: function() {
        // Remove existing button first
        this.$('[data-identifier="load-more-container"]').remove();

        if (this.shouldShowLoadMoreButton()) {
            const target = this.$(this.TABLE_BODY_SELECTOR);
            this.prependComponent('component-load-more', target, {});
        }
    },

    /**
     * Handle failure response from the server for the chat history retrieval
     * @param {Error} error
     */
    failureResponse: function(error) {
        // SX-758 Firefox returns status 0 for aborted requests
        if (!error || error.status > 0) {
            this.appendComponent('component-error', this.$(this.TABLE_BODY_SELECTOR), {
                messageLabel: this._getErrorMessageLabel(error),
            });
            this._trackAiChatError(this._pendingErrorType, error, this._pendingPromptText);
        }
        this._pendingPromptText = null;
        this._pendingErrorType = null;
    },

    /**
     * Return a localized message key for a request failure.
     *
     * @param {object} error
     * @returns {string}
     *
     * @private
     */
    _getErrorMessageLabel: function(error) {
        if (error?.status === 503) {
            return 'LBL_AI_CHAT_TOKEN_VALIDATION_UNAVAILABLE';
        }

        if (error?.status === 404) {
            return 'LBL_AI_CHAT_NOT_FOUND';
        }

        if (error?.status === 401 || error?.chatAuthFailed) {
            return 'LBL_AI_CHAT_SESSION_EXPIRED';
        }

        return 'LBL_AI_CHAT_ERROR_MESSAGE';
    },

    /**
     * Append chat system actions component to the target element
     *
     * @param {string} promptId - Prompt ID associated with the actions
     * @param {string} groupId - Group ID associated with the actions
     * @param {object} recordMeta - Additional metadata
     * @param {string} chatTitle - Chat title
     * @param {string|null} [messageId] - Stable LLM response identifier for feedback correlation
     */
    appendChatSystemActions: function(promptId, groupId, recordMeta, chatTitle, messageId) {
        this.appendComponent('ai-chat-system-actions', this.$(this.TABLE_BODY_SELECTOR), {
            chatId: this.chatId,
            promptId: promptId,
            groupId: groupId,
            recordMeta: recordMeta,
            chatTitle: chatTitle,
            messageId: messageId || null,
        }, {});
    },

    /**
     * Set the last flow actions for the chat
     *
     * @param {object} response - API response
     * @param {string} groupId - Group ID associated with the actions
     */
    setLastFlowActions: function(response, groupId) {
        // Render the held component under its own type (dive-deeper pills or the
        // filter multiselect), not a hardcoded one.
        const componentName = this._getComponentName(this.tempFlowActionHolder) ||
            'ai-chat-component-dive-deeper';
        this.appendComponent(componentName, this.$(this.TABLE_BODY_SELECTOR),
            this.tempFlowActionHolder.data, {response: response, groupId: groupId});

        this.tempFlowActionHolder = null;
    },

    /**
     * Makes an API call to fetch chat history.
     *
     * @param {object} meta - Request parameters (chatId, userId, ignoreUser).
     * @param {object} [paginationParams] - Pagination parameters
     * @param {number} [paginationParams.limit] - Number of items to fetch
     * @param {number} [paginationParams.offset] - Offset to start from
     * @param {number} [paginationParams.direction] - Direction (-1 for newest first)
     * @returns {Promise} Promise resolving to API response
     */
    getHistory: function(meta, paginationParams = {}) {
        const params = {
            chatId: meta.chatId || null,
        };

        if (!_.isEmptyValue(meta.ignoreUser)) {
            params.ignoreUser = meta.ignoreUser;
        }

        if (!meta.ignoreUser) {
            params.userId = app.user.id;
        }

        // Add pagination parameters using plugin method
        const pagination = this.buildPaginationParams(paginationParams);
        params.limit = pagination.limit;
        params.offset = paginationParams.offset || 0;
        params.direction = pagination.direction;

        const apiParams = {
            method: 'read',
            url: app.api.buildURL('chatbot/history', null, null, params),
        };

        this._pendingErrorType = 'history_load_error';
        this._pendingPromptText = null;

        return this.callApi(apiParams).then((response) => {
            // Pass isInitialHistoryLoad flag to scroll to bottom only on first load
            this.successResponse(response, {isInitialHistoryLoad: true});
        }, _.bind(this.failureResponse, this));
    },

    /**
     * Load more (older) chat history messages.
     * Called when user scrolls to top or clicks "Load More" button.
     *
     * @returns {Promise|void} Promise resolving when load completes, or void if cannot load more
     */
    loadMoreHistory: function() {
        return this.executeLoadMore({
            chatId: this.chatId,
            scrollContainer: this._getScrollContainer(),
            onLoadStart: _.bind(this._onLoadMoreStart, this),
            onSuccess: _.bind(this._onLoadMoreSuccess, this),
            onError: _.bind(this._onLoadMoreError, this),
            onComplete: _.bind(this._onLoadMoreComplete, this),
        });
    },

    /**
     * Callback when load more starts - update UI and set loading state
     * @private
     */
    _onLoadMoreStart: function() {
        this._pendingErrorType = 'load_more_error';
        this.toggleLoadingState(true);
        this.$('[data-identifier="load-more-container"]').remove();
    },

    /**
     * Callback when load more completes (success or error) - clear loading state and update button
     * @private
     */
    _onLoadMoreComplete: function() {
        this.toggleLoadingState(false);
        this._updateLoadMoreButton();
    },

    /**
     * Callback when load more succeeds - render content
     * @param {object} response - API response
     * @param {object} scrollInfo - Saved scroll position
     * @private
     */
    _onLoadMoreSuccess: function(response, scrollInfo) {
        this._removeLoadingMoreIndicator();
        this._handleLoadMoreSuccess(response, scrollInfo);
    },

    /**
     * Callback when load more fails - show error
     * @param {Error} error - Error object
     * @private
     */
    _onLoadMoreError: function(error) {
        this._removeLoadingMoreIndicator();
        this._handleLoadMoreError(error);
    },

    /**
     * Handle successful load more response
     * @param {object} apiResponse - API response with data.content and pagination
     * @param {object} scrollInfo - Saved scroll position info
     * @private
     */
    _handleLoadMoreSuccess: function(apiResponse, scrollInfo) {
        if (!apiResponse) {
            return;
        }

        const content = apiResponse.data?.content || [];

        // Update pagination state (pagination is at root level)
        this.updatePaginationState(apiResponse.pagination);

        // Prepend older content (in reverse order to maintain chronological order)
        // Content comes in chronological order (oldest to newest within page)
        // We prepend each item, so we iterate in reverse to put oldest at top
        const reversedContent = content.toReversed();

        let groupId = null;
        _.each(reversedContent, function(contentItem) {
            const componentName = this._getComponentName(contentItem);
            if (!componentName) {
                return;
            }

            if (!groupId || componentName === 'ai-chat-component-prompt') {
                groupId = app.utils.generateUUID();
            }

            const target = this.$(this.TABLE_BODY_SELECTOR);
            const componentOptions = {
                response: apiResponse.data,
                groupId: groupId,
            };

            this.prependComponent(componentName, target, contentItem.data, componentOptions);
        }, this);

        // Restore scroll position after prepending
        this.restoreScrollPosition(scrollInfo, this._getScrollContainer());
    },

    /**
     * Handle load more error - render error UI
     * @param {Error} error - Error object
     * @private
     */

    _handleLoadMoreError: function(error) {
        // Show error message at top
        const target = this.$(this.TABLE_BODY_SELECTOR);
        this.prependComponent('component-load-more-error', target, {});
        this._trackAiChatError(this._pendingErrorType, error, null);
    },

    /**
     * Remove loading more indicator
     * @private
     */
    _removeLoadingMoreIndicator: function() {
        this.$(this.LOADING_MORE_SELECTOR).remove();
    },

    /**
     * Get the scroll container element
     * @returns {jQuery} Scroll container jQuery element
     * @private
     */
    _getScrollContainer: function() {
        return this.$el.parent();
    },

    /**
     * Scroll the chat container to the bottom
     * @private
     */
    _scrollToBottom: function() {
        let container = this.$(this.TABLE_BODY_SELECTOR);

        container = container.parent();
        container.scrollTop(container.prop('scrollHeight'));
    },

    /**
     * Schedule scroll to bottom after UI updates complete.
     * @private
     */
    _scheduleScrollToBottom: function() {
        _.defer(_.bind(function() {
            if (this.disposed) {
                return;
            }

            this._scrollToBottom();
        }, this));
    },

    /**
     * Scroll *el* to the top of the chat body's own scroll container.
     *
     * Used to land the seller on the highest-ranked smart-prompt card
     * rather than the bottom of the conversation. Deliberately scoped to
     * the same container `_scrollToBottom` uses (the chat body's own
     * `overflow-y-auto` element) instead of the native `scrollIntoView`,
     * which walks up *every* ancestor with non-`visible` overflow —
     * `ai-chat-panel`'s `overflow-hidden` wrapper around the header and
     * input included, since `overflow: hidden` still permits programmatic
     * scrolling — and can drag the whole panel chrome out of view along
     * with the card.
     *
     * @param {jQuery} el - Element to scroll to the top of the container.
     * @private
     */
    _scrollElementToTop: function(el) {
        if (!el || !el.length) {
            return;
        }

        const container = this.$(this.TABLE_BODY_SELECTOR).parent();
        if (!container.length) {
            return;
        }

        const offset = el.offset().top - container.offset().top;
        container.scrollTop(container.scrollTop() + offset);
    },

    /**
     * Schedule scrolling *el* to the top after UI updates complete.
     *
     * Deferred (same pattern as {@link #_scheduleScrollToBottom}) so this
     * runs after any trailing streaming auto-scroll still in flight (see
     * AiChatStreaming's `_scrollToBottomIfNear`, scheduled via
     * `requestAnimationFrame`) rather than losing a race with it.
     *
     * @param {jQuery} el - Element to scroll into view.
     * @private
     */
    _scheduleScrollElementToTop: function(el) {
        _.defer(_.bind(function() {
            if (this.disposed) {
                return;
            }

            this._scrollElementToTop(el);
        }, this));
    },

    /**
     * Returns AI response for user prompt
     *
     * @param {string} text - The prompt text
     * @param {string|null} chatId - Optional chat ID to continue conversation
     * @param {string} promptId - Prompt tracking ID
     * @param {boolean} [fromSmartPrompt=false] - Whether the prompt was triggered from a Smart Prompt
     * @param {object} [clarifierAnswer] - Structured clarifier answer (selectedKey, clarifierId)
     * @returns {Promise} Promise resolving with the API response
     */
    getAiResponse: function(text, chatId, promptId, fromSmartPrompt, clarifierAnswer) {
        return this.streamAiResponse(text, chatId, promptId, fromSmartPrompt, clarifierAnswer);
    },

    /**
     * Handle stop request — abort the active request (streaming or blocking),
     * clear loading + streaming UI, and append a stopped-by-user note.
     */
    handleStopRequest() {
        if (!this.activeRequest) {
            return;
        }

        if (this.activeRequest.promptId) {
            this.callApi({
                method: 'create',
                url: app.api.buildURL('chatbot', 'cancelPrompt'),
                data: {
                    promptId: this.activeRequest.promptId,
                },
            });
        }

        // abortRequest alone doesn't reliably stop the stream — tear it down directly.
        this.abortActiveStream();
        app.api.abortRequest(this.activeRequest.requestId);

        this.activeRequest = null;
        this.toggleLoadingState(false);
        this.toggleComponentLoading(false);
        this.$(this.LOADING_SELECTOR).remove();
        this.removeStreamingPreview();

        const targetEl = this.$(this.TABLE_BODY_SELECTOR);
        const stopWords = app.lang.get('LBL_AI_CHAT_RESPONSE_STOPPED', 'AIExperience');
        this.appendComponent('ai-chat-component-simple-text', targetEl, {prompt: stopWords});
    },

    /**
     * @inheritdoc
     */
    _dispose: function() {
        this.isMounted = false;
        this.destroyChildComponents();
        this._super('_dispose');
    },

    /**
     * Handle theme change
     */
    switchAppearance: function() {
        this.isDarkMode = app.user.get('appearance') === 'dark';
        this.render();
    },

    /**
     * Promise-based API call.
     * @param {object} params - API call parameters
     * @returns {Promise} Promise resolving with the API response
     */
    callApi: function(params) {
        const retryCount = params.retryCount || 0;

        return this._sendApiRequest(params).catch((error) => {
            const isAuthRetryable = this._isAuthRetryableError(error, retryCount);
            const isAuthRetryExhausted = error && error.status === 401 && retryCount >= this.AUTH_RETRY_MAX_ATTEMPTS;
            if (!isAuthRetryable) {
                if (isAuthRetryExhausted) {
                    throw _.extend({}, error, {chatAuthFailed: true});
                }
                throw error;
            }

            return this._refreshAccessToken().then(() => {
                return this.callApi(_.extend({}, params, {
                    retryCount: retryCount + 1,
                }));
            }).catch(() => {
                throw _.extend({}, error, {chatAuthFailed: true});
            });
        });
    },

    /**
     * Execute API request.
     *
     * @param {object} params
     * @returns {Promise}
     * @private
     */
    _sendApiRequest: function(params) {
        return new Promise((resolve, reject) => {
            this.toggleLoadingState(true);

            if (!params.appendLoading) {
                this.toggleComponentLoading(true);
            }
            const request = app.api.call(params.method || 'read', params.url, params.data, {
                success: resolve,
                error: reject,
                complete: () => {
                    this.activeRequest = null;
                    this.toggleLoadingState(false);
                    this.toggleComponentLoading(false);
                },
            });

            this.activeRequest = {
                requestId: request.uid,
                promptId: params.promptId,
            };
        });
    },

    /**
     * Determine if request should be retried after auth failure.
     *
     * @param {object} error
     * @param {number} retryCount
     * @returns {boolean}
     * @private
     */
    _isAuthRetryableError: function(error, retryCount) {
        return error?.status === 401 && retryCount < this.AUTH_RETRY_MAX_ATTEMPTS;
    },

    /**
     * Refresh API access token using cached auth tokens.
     *
     * @returns {Promise}
     * @private
     */
    _refreshAccessToken: function() {
        const authStore = app.config.authStore || 'cache';
        const authCache = app[authStore];
        const accessToken = authCache?.get('AuthAccessToken');
        const refreshToken = authCache?.get('AuthRefreshToken');

        if (!refreshToken) {
            return Promise.reject(new Error('Missing refresh token'));
        }

        return new Promise((resolve, reject) => {
            app.api.login(null, {
                refresh: true,
                access_token: accessToken,
                refresh_token: refreshToken,
            }, {
                success: resolve,
                error: reject,
            });
        });
    },

    /**
     * Get child components rendered in chat body, optionally filtered by group.
     * @param {string|undefined} groupId Group identifier to filter components by
     * @returns {Array} Child components matching the filter (or all when undefined)
     */
    getComponents(groupId) {
        if (!groupId) {
            return this._childComponents;
        }

        return this._childComponents.filter((component) => component.options.groupId === groupId);
    },
})

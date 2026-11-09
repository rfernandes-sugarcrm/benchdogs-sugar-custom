// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/plugins/AiChatPaginationManager.js; sha256 03a6ce370d8addfa33891905ccda424d09205430ac523b818d66461b7030d802 of the stock file, which follows unchanged.
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
 * AI Chat Pagination Manager Plugin
 *
 * Provides pagination functionality for AI chat views including:
 * - Pagination state management
 * - Scroll-based infinite loading
 * - Scroll position preservation when prepending content
 * - Response format adaptation (legacy to paginated)
 */
(function(app) {
    /**
     * Pagination mode enum
     * @enum {string}
     */
    const PaginationMode = {
        /** Load more via infinite scroll only */
        SCROLL: 'scroll',
        /** Load more via button click only */
        BUTTON: 'button',
        /** Load more via both scroll and button */
        BOTH: 'both',
    };

    app.events.on('app:init', function() {
        /**
         * AI Chat Pagination Manager Plugin
         * @class Plugins.AiChatPaginationManager
         */
        app.plugins.register('AiChatPaginationManager', ['view', 'layout'], {
            /**
             * Pagination mode constants
             * @type {object}
             */
            PaginationMode: PaginationMode,
            /**
             * Default page size for pagination
             * @type {number}
             */
            DEFAULT_PAGE_SIZE: 20,

            /**
             * Scroll threshold in pixels from top to trigger load more
             * @type {number}
             */
            SCROLL_THRESHOLD: 100,

            /**
             * Throttle delay in milliseconds for scroll event handler
             * @type {number}
             */
            SCROLL_THROTTLE: 200,

            /**
             * Plugin lifecycle hook - called when plugin is attached.
             * Initializes pagination state.
             */
            onAttach: function() {
                this._paginationState = this._getDefaultPaginationState();
                this._scrollHandler = null;
                this._scrollContainer = null;
                this._fetchingHistory = false;
            },

            /**
             * Plugin lifecycle hook - called when plugin is detached.
             * Cleans up scroll listeners.
             */
            onDetach: function() {
                this._unbindScrollListener();
                this._paginationState = null;
                this._fetchingHistory = false;
            },

            /**
             * Get default pagination state object
             * @returns {object} Default pagination state
             * @private
             */
            _getDefaultPaginationState: function() {
                return {
                    nextOffset: -1,
                    total: 0,
                    direction: -1, // -1 = newest first (load older on demand)
                };
            },

            /**
             * Reset pagination state to initial values
             */
            resetPaginationState: function() {
                this._paginationState = this._getDefaultPaginationState();
            },

            /**
             * Get current pagination state
             * @returns {object} Current pagination state
             */
            getPaginationState: function() {
                return this._paginationState;
            },

            /**
             * Update pagination state from API response
             * @param {object} pagination - Pagination metadata from API response
             */
            updatePaginationState: function(pagination) {
                if (!pagination) {
                    return;
                }

                this._paginationState = {
                    nextOffset: pagination.nextOffset ?? -1,
                    total: pagination.total || 0,
                    direction: pagination.direction || -1,
                };
            },

            /**
             * Check if currently loading history.
             * @returns {boolean} True if currently loading
             */
            _isFetchingHistory: function() {
                return this._fetchingHistory;
            },

            /**
             * Check if currently loading more content.
             * Uses the shared loading state from AiChatStateManager context.
             * @returns {boolean} True if currently loading
             */
            isLoadingMore: function() {
                return Boolean(this.context?.get('loading'));
            },

            /**
             * Check if more history can be loaded
             * @returns {boolean}
             */
            canLoadMore: function() {
                return this._paginationState.nextOffset > 0 &&
                       !this.isLoadingMore();
            },

            /**
             * Get pagination mode from layout/view metadata
             * @returns {string} PaginationMode.SCROLL, PaginationMode.BUTTON, or PaginationMode.BOTH
             */
            getPaginationMode: function() {
                const meta = this.meta || {};
                return meta.paginationMode || PaginationMode.BOTH;
            },

            /**
             * Get page size from metadata or use default
             * @returns {number}
             */
            getPageSize: function() {
                const meta = this.meta || {};
                return meta.pageSize || this.DEFAULT_PAGE_SIZE;
            },

            /**
             * Bind scroll listener for infinite scroll pagination.
             * Should only be called once after initial render.
             *
             * @param {jQuery} scrollContainer - The scroll container element
             * @param {Function} loadMoreCallback - Callback to execute when scroll threshold reached
             */
            bindScrollListener: function(scrollContainer, loadMoreCallback) {
                const paginationMode = this.getPaginationMode();
                if (paginationMode !== PaginationMode.SCROLL && paginationMode !== PaginationMode.BOTH) {
                    return;
                }

                if (!scrollContainer || !loadMoreCallback) {
                    return;
                }

                // Create throttled scroll handler to avoid performance issues
                this._loadMoreCallback = loadMoreCallback;
                this._scrollHandler = _.throttle(_.bind(this._handleScroll, this), this.SCROLL_THROTTLE);

                // Cache the scroll container reference
                this._scrollContainer = scrollContainer;
                this._scrollContainer.off('scroll.aiChatPagination');
                this._scrollContainer.on('scroll.aiChatPagination', this._scrollHandler);
            },

            /**
             * Unbind scroll listener
             */
            _unbindScrollListener: function() {
                if (this._scrollHandler && this._scrollContainer) {
                    this._scrollContainer.off('scroll.aiChatPagination');
                    this._scrollHandler = null;
                    this._scrollContainer = null;
                    this._loadMoreCallback = null;
                }
            },

            /**
             * Handle scroll event for infinite scroll pagination
             * @private
             */
            _handleScroll: function() {
                if (!this._scrollContainer) {
                    return;
                }

                const scrollTop = this._scrollContainer.scrollTop();

                // Check if scrolled near the top
                if (scrollTop <= this.SCROLL_THRESHOLD && this.canLoadMore()) {
                    if (this._loadMoreCallback) {
                        this._loadMoreCallback();
                    }
                }
            },

            /**
             * Save current scroll position before prepending content.
             * This allows restoring the user's viewport position after new content is added.
             *
             * @param {jQuery} scrollContainer - The scroll container element
             * @returns {object} Scroll position info with scrollHeight and scrollTop
             */
            saveScrollPosition: function(scrollContainer) {
                const container = scrollContainer || this._scrollContainer;
                if (!container) {
                    return null;
                }

                return {
                    scrollHeight: container.prop('scrollHeight'),
                    scrollTop: container.scrollTop(),
                };
            },

            /**
             * Restore scroll position after prepending content.
             * Adjusts scroll position to account for newly added content height,
             * keeping the user's viewport on the same content they were viewing.
             *
             * @param {object} scrollInfo - Previously saved scroll position info
             * @param {jQuery} scrollContainer - The scroll container element
             */
            restoreScrollPosition: function(scrollInfo, scrollContainer) {
                if (!scrollInfo) {
                    return;
                }

                const container = scrollContainer || this._scrollContainer;
                if (!container) {
                    return;
                }

                const newScrollHeight = container.prop('scrollHeight');
                const heightDifference = newScrollHeight - scrollInfo.scrollHeight;

                // Adjust scroll position by the height of newly prepended content
                container.scrollTop(scrollInfo.scrollTop + heightDifference);
            },

            /**
             * Build pagination parameters for API request
             * @param {object} [overrides] - Optional overrides for pagination params
             * @returns {object} Pagination parameters
             */
            buildPaginationParams: function(overrides = {}) {
                return {
                    limit: overrides.limit || this.getPageSize(),
                    offset: overrides.offset || 0,
                    direction: overrides.direction || this._paginationState.direction,
                };
            },

            /**
             * Get next page pagination parameters
             * @returns {object|null} Pagination params for next page, or null if no more pages
             */
            getNextPageParams: function() {
                if (!this.canLoadMore()) {
                    return null;
                }

                return this.buildPaginationParams({
                    offset: this._paginationState.nextOffset,
                });
            },

            /**
             * Check if the load more button should be visible based on pagination mode and state.
             * @returns {boolean} True if load more button should be shown
             */
            shouldShowLoadMoreButton: function() {
                const paginationMode = this.getPaginationMode();
                const showButton = paginationMode === PaginationMode.BUTTON ||
                    paginationMode === PaginationMode.BOTH;
                return showButton && this.canLoadMore();
            },

            /**
             * Fetch more history from the API.
             * This is a generic method that can be used by views to load more paginated content.
             *
             * @param {object} params - Request parameters
             * @param {string} params.chatId - Chat ID to fetch history for
             * @param {string} [params.userId] - User ID for filtering
             * @param {object} [paginationParams] - Optional pagination params override
             * @returns {Promise} Promise resolving to API response
             */
            fetchMoreHistory: function(params, paginationParams) {
                const pagination = paginationParams || this.getNextPageParams();
                if (!pagination) {
                    return Promise.resolve(null);
                }

                const requestParams = {
                    chatId: params.chatId,
                    userId: params.userId || app.user.id,
                    limit: pagination.limit,
                    offset: pagination.offset,
                    direction: pagination.direction,
                };

                return new Promise((resolve, reject) => {
                    app.api.call('read', app.api.buildURL('chatbot/history', null, null, requestParams), null, {
                        success: resolve,
                        error: reject,
                    });
                });
            },

            /**
             * Execute the load more flow with proper state management.
             * Handles loading state, scroll position preservation, and error handling.
             *
             * @param {object} options - Load more options
             * @param {string} options.chatId - Chat ID to load history for
             * @param {jQuery} options.scrollContainer - Scroll container element
             * @param {Function} options.onLoadStart - Callback when loading starts
             * @param {Function} options.onSuccess - Callback on success with (response, scrollInfo)
             * @param {Function} options.onError - Callback on error with (error)
             * @param {Function} options.onComplete - Callback when loading completes
             * @returns {Promise|void} Promise resolving when load completes
             */
            executeLoadMore: function(options) {
                if (!this.canLoadMore() || !options.chatId) {
                    return;
                }

                this._fetchingHistory = true;

                // Notify view to update UI (set loading state, remove button, show spinner)
                if (options.onLoadStart) {
                    options.onLoadStart();
                }

                // Save scroll position before prepending
                const scrollInfo = this.saveScrollPosition(options.scrollContainer);
                const finalizeLoadMore = () => {
                    this._fetchingHistory = false;
                    if (options.onComplete) {
                        options.onComplete();
                    }
                };
                const paginationParams = this.getNextPageParams();

                return this.fetchMoreHistory({chatId: options.chatId}, paginationParams)
                    .then((response) => {
                        if (options.onSuccess) {
                            options.onSuccess(response, scrollInfo);
                        }
                    })
                    .catch((error) => {
                        app.logger.error('Failed to load more chat history:', error);
                        if (options.onError) {
                            options.onError(error);
                        }
                    })
                    .finally(finalizeLoadMore);
            },
        });
    });
})(SUGAR.App);

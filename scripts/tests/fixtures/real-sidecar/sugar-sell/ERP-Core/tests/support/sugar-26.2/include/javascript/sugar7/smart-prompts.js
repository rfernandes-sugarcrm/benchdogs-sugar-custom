// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/smart-prompts.js; sha256 dc14e218349081156dd4f507bc06625b8dba5b18d8cdb2b90bf812db1ec2e2cb of the stock file, which follows unchanged.
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
    app.augment('smartPrompts', {
        cacheKeyPrefix: 'smartPrompts',

        syncInProgress: false,

        pendingCallbacks: [],

        catalogSyncInProgress: false,

        pendingCatalogCallbacks: [],

        /**
         * Timestamp (ms) before which a fresh catalog fetch is skipped after an
         * unsuccessful attempt (error, or empty/missing names). In-memory only —
         * a page reload should retry immediately; this only guards against a
         * sustained stream of requests from repeated renders (e.g. window resize)
         * while the catalog stays unavailable.
         */
        catalogRetryAfter: 0,

        /**
         * Whether this dashboard visit has already started (or finished)
         * fetching missing Smart Prompt cards. In-memory only. A full page
         * reload or leaving the dashboard (layout dispose, e.g. #Home →
         * #Leads → #Home) retries; in-place relayouts (resize, filters, add
         * dashlet, edit) must not start another missing-card fetch.
         */
        pageLoadFetchStarted: false,

        /**
         * Get Smart Prompts cache TTL in milliseconds from config (default 24 hours).
         *
         * @returns {number}
         */
        getCacheTtlMs: function() {
            const defaultTtlSeconds = 24 * 60 * 60;
            const ttlSeconds = parseInt(app.config.smartPromptsCacheTtl, 10);

            if (_.isNaN(ttlSeconds) || ttlSeconds <= 0) {
                return defaultTtlSeconds * 1000;
            }

            return ttlSeconds * 1000;
        },

        /**
         * Get the cooldown (ms) to wait before retrying a catalog fetch after an
         * unsuccessful attempt, from config (default 60 seconds).
         *
         * @returns {number}
         */
        getCatalogRetryCooldownMs: function() {
            const defaultCooldownSeconds = 60;
            const cooldownSeconds = parseInt(app.config.smartPromptsCatalogRetryCooldown, 10);

            if (_.isNaN(cooldownSeconds) || cooldownSeconds <= 0) {
                return defaultCooldownSeconds * 1000;
            }

            return cooldownSeconds * 1000;
        },

        /**
         * Get the last known Smart Prompt catalog names from local cache.
         * Synchronous by design — every caller (getPromptIdsToFetch, getCachedCards,
         * the ai-prompts layout) expects an immediate answer. Use ensureCatalog() to
         * (re)fetch the catalog from the server when it's missing or stale.
         *
         * @param {string} [userId]
         * @returns {Array<string>}
         */
        getPromptNames: function(userId) {
            const cache = this._getCache(userId);
            const names = cache && cache.names && cache.names.list;

            return _.isArray(names) && !_.isEmpty(names) ? names : [];
        },

        /**
         * Whether the cached catalog names are within TTL.
         *
         * @param {object} [cache]
         * @returns {boolean}
         * @private
         */
        _isCatalogFresh: function(cache) {
            return !!(
                cache &&
                cache.names &&
                _.isNumber(cache.names.fetchedAt) &&
                (Date.now() - cache.names.fetchedAt) <= this.getCacheTtlMs()
            );
        },

        /**
         * Ensure the Smart Prompt catalog names are available, fetching from the
         * server when missing or stale. Never blanks a previously-known catalog on
         * failure — a transient outage keeps serving yesterday's prompt list.
         *
         * @param {Function} [callback] Invoked (possibly synchronously) once the
         *     catalog is as fresh as it's going to get for this call.
         * @param {string} [userId]
         */
        ensureCatalog: function(callback, userId) {
            callback = callback || _.noop;
            const cache = this._getCache(userId);

            if (this._isCatalogFresh(cache)) {
                callback();
                return;
            }

            if (Date.now() < this.catalogRetryAfter) {
                callback();
                return;
            }

            if (this.catalogSyncInProgress) {
                this.pendingCatalogCallbacks.push(callback);
                return;
            }

            this.catalogSyncInProgress = true;
            this.pendingCatalogCallbacks = [callback];

            const url = app.api.buildURL('chatbot/smartPrompts/catalog');

            app.api.call('read', url, null, {
                success: (data) => {
                    const names = _.isObject(data) && _.isArray(data.names) ? data.names : null;

                    // Only cache a non-empty catalog as "fresh". An empty list is
                    // indistinguishable from a transient upstream hiccup (the server
                    // itself only serves [] when even its last-good fallback is
                    // exhausted) — caching it for the full TTL would silently block
                    // every retry for the rest of the day. Leave the cache (and any
                    // previously known names) alone so the next render tries again,
                    // but rate-limit that retry via catalogRetryAfter below rather
                    // than re-requesting on every render while the outage lasts.
                    if (names && names.length) {
                        this._saveCatalogNames(names, userId);
                        this.catalogRetryAfter = 0;
                    } else {
                        this.catalogRetryAfter = Date.now() + this.getCatalogRetryCooldownMs();
                    }

                    this._runPendingCatalogCallbacks();
                },
                error: () => {
                    // Keep the stale cached names (if any) — better than blanking the
                    // panel on a transient catalog outage. Rate-limit the next retry.
                    this.catalogRetryAfter = Date.now() + this.getCatalogRetryCooldownMs();
                    this._runPendingCatalogCallbacks();
                },
            });
        },

        /**
         * Persist catalog names and broadcast to other tabs.
         *
         * @param {Array<string>} names
         * @param {string} [userId]
         * @private
         */
        _saveCatalogNames: function(names, userId) {
            const cache = this._getCache(userId) || {prompts: {}};
            cache.names = {
                list: names,
                fetchedAt: Date.now(),
            };

            this._persistCache(cache, userId);
        },

        /**
         * Notify and clear pending ensureCatalog() callers.
         *
         * @private
         */
        _runPendingCatalogCallbacks: function() {
            const callbacks = this.pendingCatalogCallbacks;
            this.catalogSyncInProgress = false;
            this.pendingCatalogCallbacks = [];
            _.each(callbacks, (callback) => callback());
        },

        /**
         * Build the per-user Smart Prompts cache key.
         *
         * @param {string} [userId]
         * @returns {string|undefined}
         */
        getCacheKey: function(userId) {
            userId = userId || app.user.get('id');

            if (!userId) {
                return;
            }

            const tenantStr = app.config.tenant ? `${app.config.tenant}:` : '';

            return `${this.cacheKeyPrefix}:${tenantStr}${userId}`;
        },

        /**
         * Whether an execution response has the shape produced by /chatbot/prompt.
         *
         * @param {object} executionResponse
         * @returns {boolean}
         */
        isExecutionResponseUsable: function(executionResponse) {
            return !!(
                executionResponse &&
                _.isObject(executionResponse) &&
                _.isObject(executionResponse.data)
            );
        },

        /**
         * Whether a single cached card entry is within TTL.
         *
         * @param {object} entry
         * @returns {boolean}
         */
        isCardEntryFresh: function(entry) {
            return !!(
                entry &&
                _.isNumber(entry.created) &&
                (Date.now() - entry.created) <= this.getCacheTtlMs() &&
                this.isExecutionResponseUsable(entry.executionResponse)
            );
        },

        /**
         * Catalog prompt IDs that are missing or past TTL.
         *
         * @param {string} [userId]
         * @returns {Array<string>}
         */
        getPromptIdsToFetch: function(userId) {
            const names = this.getPromptNames(userId);
            const cache = this._getCache(userId);

            if (_.isEmpty(names)) {
                return [];
            }

            if (!cache) {
                return names.slice();
            }

            return _.filter(names, (name) => !this.isCardEntryFresh(cache.prompts[name]));
        },

        /**
         * Read cached card display payloads, including stale entries, ordered
         * by descending total dollar value of each card's affected records
         * (`totalPipeline` — the same figure surfaced in the card subtext).
         * Cards without a value sort last; catalog order breaks ties, since
         * `_.sortBy` is stable.
         *
         * @param {string} [userId]
         * @returns {Array}
         */
        getCachedCards: function(userId) {
            const cache = this._getCache(userId);

            if (!cache) {
                return [];
            }

            return _.chain(this.getPromptNames(userId))
                .map((name) => cache.prompts[name]?.card)
                .compact()
                .sortBy((card) => -(parseFloat(card.totalPipeline) || 0))
                .value();
        },

        /**
         * Get cached execution response for a prompt, including stale entries.
         *
         * @param {string} promptId
         * @returns {object|null}
         */
        getCardExecutionResponse: function(promptId) {
            const cache = this._getCache();

            if (!cache) {
                return null;
            }

            const entry = cache.prompts[promptId];

            if (!entry || !this.isExecutionResponseUsable(entry.executionResponse)) {
                return null;
            }

            return entry.executionResponse;
        },

        /**
         * Persist a single card entry and broadcast to other tabs.
         *
         * @param {string} promptId
         * @param {object} card
         * @param {object} executionResponse
         * @param {string} [userId]
         */
        saveCardCacheState: function(promptId, card, executionResponse, userId) {
            if (!promptId) {
                return;
            }

            const cache = this._getCache(userId) || {prompts: {}};
            cache.prompts[promptId] = {
                card: card,
                executionResponse: executionResponse,
                created: Date.now(),
            };

            this._persistCache(cache, userId);
        },

        /**
         * Remove a single prompt from the cache.
         *
         * @param {string} promptId
         * @param {string} [userId]
         */
        removeCardCacheState: function(promptId, userId) {
            const cache = this._getCache(userId);

            if (!cache || !cache.prompts[promptId]) {
                return;
            }

            delete cache.prompts[promptId];
            this._persistCache(cache, userId);
        },

        /**
         * Clear Smart Prompts from the per-user cache.
         *
         * @param {string} [userId]
         */
        clearCacheState: function(userId) {
            const storage = this._getStorage();
            const cacheKey = this.getCacheKey(userId);

            if (!storage || !cacheKey) {
                return;
            }

            storage.removeItem(cacheKey);
        },

        /**
         * Fetch a single Smart Prompt card from the server.
         *
         * @param {string} promptId
         * @returns {Promise<object|null>}
         */
        fetchOnePrompt: function(promptId) {
            return new Promise((resolve) => {
                const url = app.api.buildURL('chatbot/smartPrompts', null, null, {promptId: promptId});

                app.api.call('read', url, null, {
                    success: (data) => {
                        const card = _.isObject(data) && data.smartPromptId ? data : null;

                        if (!card) {
                            resolve(null);
                            return;
                        }

                        const displayCard = _.omit(card, 'executionResponse');
                        const usableResponse = this.isExecutionResponseUsable(card.executionResponse) ?
                            card.executionResponse :
                            null;

                        this.saveCardCacheState(promptId, displayCard, usableResponse);

                        if (!usableResponse) {
                            resolve(null);
                            return;
                        }

                        resolve(_.extend({}, displayCard, {executionResponse: usableResponse}));
                    },
                    error: (error) => {
                        // >= 500 covers both a raw upstream 500 and the 503 the server
                        // now returns when the Smart Prompt catalog itself is unavailable
                        // (see ChatBotApi::assertValidPromptId).
                        if (error && error.status >= 500) {
                            const cache = this._getCache();
                            const entry = cache && cache.prompts[promptId];

                            if (entry && this.isExecutionResponseUsable(entry.executionResponse)) {
                                resolve(_.extend({}, entry.card, {executionResponse: entry.executionResponse}));
                                return;
                            }
                        }

                        resolve(null);
                    },
                });
            });
        },

        /**
         * Fetch missing or expired Smart Prompts in parallel and update local cache.
         *
         * @param {object} [callbacks]
         * @returns {void}
         */
        fetchPrompts: function(callbacks) {
            callbacks = callbacks || {};

            if (this.syncInProgress) {
                this.pendingCallbacks.push(callbacks);
                return;
            }

            const promptIdsToFetch = this.getPromptIdsToFetch();

            if (!this.getCacheKey() || _.isEmpty(promptIdsToFetch)) {
                this._notifyFetchComplete(callbacks);
                return;
            }

            this.syncInProgress = true;
            this.pendingCallbacks = [callbacks];

            const requests = _.map(promptIdsToFetch, (promptId) => {
                return this.fetchOnePrompt(promptId).then((result) => {
                    if (result) {
                        this._runPendingCardCallback('onCardLoaded', result);
                    }

                    return result;
                });
            });

            Promise.allSettled(requests)
                .then(() => this._runCallbacks('success', [this.getCachedCards()]))
                .catch(() => this._runCallbacks('error', []))
                .finally(() => {
                    this.syncInProgress = false;
                    this._runCallbacks('complete');
                    this.pendingCallbacks = [];
                });
        },

        /**
         * Read and validate cache from storage.
         *
         * @param {string} [userId]
         * @returns {object|null}
         * @private
         */
        _getCache: function(userId) {
            const storage = this._getStorage();
            const cacheKey = this.getCacheKey(userId);

            if (!storage || !cacheKey) {
                return null;
            }

            let cache;

            try {
                cache = JSON.parse(storage.getItem(cacheKey));
            } catch {
                this.clearCacheState(userId);
                return null;
            }

            if (!cache) {
                return null;
            }

            if (!_.isObject(cache.prompts) || _.isArray(cache.prompts)) {
                this.clearCacheState(userId);
                return null;
            }

            return cache;
        },

        /**
         * Write cache to storage and broadcast to other tabs.
         *
         * @param {object} cache
         * @param {string} [userId]
         * @private
         */
        _persistCache: function(cache, userId) {
            const storage = this._getStorage();
            const cacheKey = this.getCacheKey(userId);

            if (!storage || !cacheKey) {
                return;
            }

            if (_.isEmpty(cache.prompts) && !cache.names) {
                storage.removeItem(cacheKey);
                return;
            }

            try {
                storage.setItem(cacheKey, JSON.stringify(cache));
                this._broadcastSync();
            } catch {
                app.logger.warn('Unable to cache Smart Prompts in local storage.');
            }
        },

        /**
         * Invoke fetch callbacks when there is nothing to load.
         *
         * @param {object} callbacks
         * @private
         */
        _notifyFetchComplete: function(callbacks) {
            const cards = this.getCachedCards();

            if (callbacks.success) {
                callbacks.success(cards);
            }

            if (callbacks.complete) {
                callbacks.complete();
            }
        },

        /**
         * Safely get browser local storage.
         *
         * @returns {Storage|null}
         * @private
         */
        _getStorage: function() {
            try {
                return window.localStorage;
            } catch {
                return null;
            }
        },

        /**
         * Notify waiting callers about per-card fetch progress.
         *
         * @param {string} callbackName
         * @param {*} arg
         * @private
         */
        _runPendingCardCallback: function(callbackName, arg) {
            _.each(this.pendingCallbacks, function(callbacks) {
                if (callbacks && callbacks[callbackName]) {
                    callbacks[callbackName](arg);
                }
            });
        },

        /**
         * Notify waiting callers about the current fetch.
         *
         * @param {string} callbackName
         * @param {Array} [args]
         * @private
         */
        _runCallbacks: function(callbackName, args) {
            _.each(this.pendingCallbacks, function(callbacks) {
                if (callbacks && callbacks[callbackName]) {
                    callbacks[callbackName].apply(null, args || []);
                }
            });
        },

        /**
         * Notify other tabs that fresh prompts are available.
         *
         * @private
         */
        _broadcastSync: function() {
            if (!_.isUndefined(window.crosstab) && window.crosstab.supported) {
                window.crosstab.broadcast('sync-ai-prompts');
            }
        },
    });
})(SUGAR.App);

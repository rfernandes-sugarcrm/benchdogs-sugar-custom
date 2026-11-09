// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/plugins/AiChatStreaming.js; sha256 5ca13961a410ec6a66a4b6626c7947e289d674d05cdd483110dd25ede8f6f570 of the stock file, which follows unchanged.
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
 * AI Chat Streaming Plugin
 *
 * Owns SSE transport for the AI chat:
 *  - opens the streaming request to /chatbot/promptStream
 *  - parses event/data lines off XHR onprogress
 *  - dispatches `status`, `progress`, `token`, `partial_result`, `result`,
 *    `error`, and `done` SSE events
 *  - manages the streaming markdown preview (incremental token render)
 *  - manages the partial-results container (additive component append)
 *  - throttles status text updates so labels are readable
 *
 * The host view keeps orchestration responsibility — when to start, when to
 * clean up, what to do with the resolved final payload.
 */
(function(app) {
    app.events.on('app:init', function() {
        /**
         * @class Plugins.AiChatStreaming
         */
        app.plugins.register('AiChatStreaming', ['view', 'layout'], {
            /**
             * Module the language strings live in.
             */
            STREAM_LANG_MODULE: 'AIExperience',

            /**
             * Component used to render the streaming markdown preview.
             * Must accept `{prompt}` data and expose `markdownContent` +
             * `_renderMarkdown()`.
             */
            STREAM_PREVIEW_COMPONENT: 'ai-chat-markdown',

            /**
             * Selector + Tailwind classes for the partial-results container.
             */
            STREAM_PARTIAL_SELECTOR: '[data-identifier="partial-results"]',
            STREAM_PARTIAL_CLASSES: 'flex flex-col gap-5',

            /**
             * Selector for the inline streaming loader shown below partial results.
             */
            STREAM_LOADER_SELECTOR: '[data-identifier="stream-loader"]',

            /**
             * Selector for skeleton placeholders rendered from a layout event.
             */
            STREAM_SKELETON_SELECTOR: '[data-identifier="skeleton-placeholder"]',

            /**
             * Minimum interval between visible status-text changes, ms.
             * Prevents stage labels from flickering past unreadably fast.
             */
            STREAM_STATUS_THROTTLE_MS: 2000,

            /**
             * Auto-scroll guard: keep pinned to bottom only if the user is
             * within this many pixels of it. Above the threshold we assume
             * the user scrolled up to read and don't yank them back down.
             */
            STREAM_SCROLL_THRESHOLD_PX: 80,

            /**
             * Pipeline stage → label key shown on start. Hidden stages
             * (security_check, routing) are excluded so the user never
             * sees internal system terminology.
             */
            _streamStageLabelKeys: {
                context_loading: 'LBL_AI_CHAT_STREAM_CONTEXT_LOADING_START',
                agent_execution: 'LBL_AI_CHAT_STREAM_AGENT_EXECUTION_START',
            },

            /**
             * Stages that are internal and should never surface in the UI.
             */
            _hiddenStages: {
                security_check: true,
                routing: true,
            },

            /**
             * Plugin lifecycle hook — initialize per-instance state.
             */
            onAttach: function() {
                this._streamingMarkdownView = null;
                this._hasPartialContent = false;
                this._statusTextTimer = null;
                this._pendingStatusText = null;
                this._lastStatusChangeTime = 0;
                this._renderedPartialComponents = [];
                this._layoutComponentTypes = [];
                this._streamRenderScheduled = false;
                this._activeStreamState = null;
            },

            /**
             * Plugin lifecycle hook — release timers held by the plugin.
             */
            onDetach: function() {
                this._cancelStatusThrottle();
            },

            /**
             * Open an SSE streaming request and resolve with the final
             * `result` payload (same shape as the blocking /chatbot/prompt
             * response). Token text is rendered into a streaming markdown
             * preview view as it arrives; partial_result components are
             * rendered into a dedicated container.
             *
             * @param {string} text
             * @param {string|null} chatId
             * @param {string} promptId
             * @param {boolean} fromSmartPrompt
             * @param {object} [clarifierAnswer] - Structured clarifier answer (selectedKey, clarifierId)
             * @returns {Promise} resolves with the final result payload
             */
            streamAiResponse: function(text, chatId, promptId, fromSmartPrompt, clarifierAnswer) {
                const body = {
                    prompt: text,
                    promptId: promptId || app.utils.generateUUID(),
                };
                if (chatId) {
                    body.chatId = chatId;
                }
                if (fromSmartPrompt) {
                    body.fromSmartPrompt = true;
                }
                try {
                    if (app.aiContext && app.aiContext.buildDynamic) {
                        body.pageContext = app.aiContext.buildDynamic();
                    }
                } catch {
                    // if something went wrong with page context, don't block the request, just send what we have
                }

                // Carry the structured clarifier answer so the backend resolves on the
                // stable option key instead of free-text matching the displayed label.
                if (clarifierAnswer && clarifierAnswer.selectedKey) {
                    body.selected_key = clarifierAnswer.selectedKey;
                }
                // The multiselect filter clarifier answers with a {field: [values]}
                // map rather than a single key.
                if (clarifierAnswer && clarifierAnswer.filterSelections) {
                    body.filter_selections = clarifierAnswer.filterSelections;
                }
                if (clarifierAnswer && clarifierAnswer.clarifierId) {
                    body.clarifier_id = clarifierAnswer.clarifierId;
                }

                return new Promise((resolve, reject) => {
                    this.toggleLoadingState(true);

                    const state = this._createStreamState();
                    this._activeStreamState = state;
                    const processLine = this._buildLineProcessor(state);

                    const url = app.api.buildURL('chatbot', 'promptStream');
                    const request = app.api.call('create', url, body, {
                        success: () => {
                            this._cancelStatusThrottle();
                            // Stopped by the user — don't render the result.
                            if (state.aborted) {
                                this.activeRequest = null;
                                this._activeStreamState = null;
                                return;
                            }
                            this.activeRequest = null;
                            this._activeStreamState = null;
                            this.toggleLoadingState(false);
                            this.toggleComponentLoading(false);
                            if (!state.hasError && state.finalResult) {
                                resolve(state.finalResult);
                            } else {
                                // Drop pending skeletons, keep what streamed in.
                                this.removeStreamingPreview();
                                this._removeRemainingSkeletons();
                                reject(new Error('Stream completed without result'));
                            }
                        },
                        error: (err) => {
                            this._cancelStatusThrottle();
                            this._activeStreamState = null;
                            // status 0 = aborted; already cleaned up.
                            if (state.aborted || !err || err.status === 0) {
                                this._removeRemainingSkeletons();
                                return;
                            }
                            this.activeRequest = null;
                            this.toggleLoadingState(false);
                            this.toggleComponentLoading(false);
                            this.removeStreamingPreview();
                            // Drop pending skeletons; keep already-rendered widgets.
                            this._removeRemainingSkeletons();
                            reject(err);
                        },
                    }, {
                        // Prevent jQuery from JSON.parsing the SSE response
                        dataType: 'text',
                        // Hook onprogress so SSE lines are parsed as they arrive
                        xhr: () => {
                            const xhr = $.ajaxSettings.xhr();
                            state.xhr = xhr;
                            xhr.addEventListener('progress', () => {
                                // Once stopped, ignore any further buffered chunks.
                                if (state.aborted) {
                                    return;
                                }
                                const newChunk = xhr.responseText.substring(state.processedLength);
                                state.processedLength = xhr.responseText.length;
                                state.eventBuffer += newChunk;
                                const lines = state.eventBuffer.split('\n');
                                state.eventBuffer = lines.pop();
                                lines.forEach(processLine);
                            });
                            return xhr;
                        },
                    });

                    this.activeRequest = {
                        requestId: request.uid,
                        promptId: body.promptId,
                    };
                });
            },

            /**
             * Abort the in-flight SSE stream: flag it aborted and tear down the
             * XHR. Safe to call when no stream is active.
             */
            abortActiveStream: function() {
                const state = this._activeStreamState;
                this._activeStreamState = null;
                if (!state) {
                    return;
                }
                state.aborted = true;
                if (state.xhr) {
                    try {
                        state.xhr.abort();
                    } catch {
                        // XHR already completed/closed — nothing to abort.
                    }
                }
            },

            /**
             * Wipe all streaming artifacts (preview view + partial-results
             * container). Safe to call multiple times.
             */
            clearStreamingArtifacts: function() {
                this._cancelStatusThrottle();
                this.removeStreamingPreview();
                this._removeStreamLoader();
                if (this._hasPartialContent) {
                    this.$(this.STREAM_PARTIAL_SELECTOR).remove();
                    this._hasPartialContent = false;
                }
                this._renderedPartialComponents = [];
                this._layoutComponentTypes = [];
            },

            /**
             * Remove the streaming markdown preview view from the DOM.
             */
            removeStreamingPreview: function() {
                if (this._streamingMarkdownView) {
                    this._streamingMarkdownView.$el.remove();
                    this._streamingMarkdownView = null;
                }
            },

            /**
             * Build the per-stream parsing state object.
             *
             * @returns {object}
             * @private
             */
            _createStreamState: function() {
                return {
                    eventBuffer: '',
                    currentEvent: null,
                    accumulatedTokens: '',
                    finalResult: null,
                    hasError: false,
                    processedLength: 0,
                    // Set on stop; suppresses further chunks and the final result.
                    aborted: false,
                    // Captured so a stop can abort the connection directly.
                    xhr: null,
                    // JSON-prefix suppression: some agents emit
                    // {"is_detected":false} blobs as plain-format tokens
                    // before the real text starts. Track brace depth so
                    // interleaved blobs are all dropped, then start
                    // rendering once we see real (non-brace) content.
                    jsonPrefixDone: false,
                    jsonBraceDepth: 0,
                    jsonSeenOpenBrace: false,
                };
            },

            /**
             * Build a closure that parses one SSE line at a time.
             *
             * @param {object} state - parsing state from _createStreamState
             * @returns {Function} (line: string) => void
             * @private
             */
            _buildLineProcessor: function(state) {
                return (line) => {
                    if (line.startsWith('event: ')) {
                        state.currentEvent = line.slice(7).trim();
                        return;
                    }
                    if (!line.startsWith('data: ') || !state.currentEvent) {
                        return;
                    }
                    let data;
                    try {
                        data = JSON.parse(line.slice(6));
                    } catch {
                        return;
                    }
                    this._dispatchStreamEvent(state, state.currentEvent, data);
                };
            },

            /**
             * Dispatch a parsed SSE event.
             *
             * @param {object} state
             * @param {string} eventName
             * @param {object} data
             * @private
             */
            _dispatchStreamEvent: function(state, eventName, data) {
                switch (eventName) {
                    case 'status':
                        this._handleStreamStatus(data);
                        break;
                    case 'progress':
                        this._handleStreamProgress(data);
                        break;
                    case 'token':
                        this._handleStreamTokenEvent(state, data);
                        break;
                    case 'partial_result':
                        this._handlePartialResult(data);
                        break;
                    case 'layout':
                        this._handleLayout(data);
                        break;
                    case 'result':
                        if (!data.status?.success && !data.data?.content?.length) {
                            state.hasError = true;
                        } else {
                            state.finalResult = data;
                        }
                        break;
                    case 'error':
                        state.hasError = true;
                        break;
                    case 'routing':
                    case 'done':
                    default:
                        break;
                }
            },

            /**
             * Handle a single token SSE event, including JSON-prefix
             * suppression and brace-depth tracking for plain-format tokens.
             *
             * @param {object} state
             * @param {object} data - {text, format?}
             * @private
             */
            _handleStreamTokenEvent: function(state, data) {
                const tokenText = data.text || '';

                // Markdown-format tokens are real content; strip any leading
                // JSON blobs and render whatever remains.
                if (data.format === 'markdown') {
                    // Only clean the FIRST delta: strip any leading supervisor JSON
                    // blob and leading whitespace before content begins. Later
                    // deltas are appended verbatim — the backend already filtered
                    // JSON, block separators arrive as their leading newlines, and
                    // a mid-stream delta may legitimately start with '{' (code) or
                    // indentation that must be preserved on cumulative render.
                    let clean = tokenText;
                    if (!state.accumulatedTokens) {
                        clean = this._stripJsonPrefix(tokenText).replace(/^\s+/, '');
                    }
                    if (clean) {
                        const firstToken = !this._streamingMarkdownView;
                        this.$(this.LOADING_SELECTOR).remove();
                        state.accumulatedTokens += clean;
                        this._renderStreamingPreview(state.accumulatedTokens);
                        // Keep an activity indicator (with thinking labels) below
                        // the streaming preview until the final result renders.
                        if (firstToken) {
                            this._appendStreamLoader();
                        }
                    }
                    return;
                }

                // Plain-format tokens may include leading supervisor JSON
                // blobs interleaved with each other before any real text.
                if (!state.jsonPrefixDone) {
                    const hasBrace = tokenText.includes('{') || tokenText.includes('}');
                    for (const ch of tokenText) {
                        if (ch === '{') {
                            state.jsonBraceDepth++;
                            state.jsonSeenOpenBrace = true;
                        } else if (ch === '}') {
                            state.jsonBraceDepth--;
                        }
                    }
                    if (!state.jsonSeenOpenBrace && !hasBrace) {
                        state.jsonPrefixDone = true;
                    } else if (state.jsonSeenOpenBrace && state.jsonBraceDepth <= 0 && !hasBrace) {
                        state.jsonPrefixDone = true;
                    } else {
                        return;
                    }
                }
                // Real content is starting — swap the main loading indicator for
                // the trailing stream loader (parity with the markdown branch) so
                // thinking labels keep showing until the final result renders.
                const firstToken = !this._streamingMarkdownView;
                this.$(this.LOADING_SELECTOR).remove();
                state.accumulatedTokens += tokenText;
                this._renderStreamingPreview(state.accumulatedTokens);
                if (firstToken) {
                    this._appendStreamLoader();
                }
            },

            /**
             * Handle a `layout` SSE event. Renders one slot wrapper per expected
             * component, keyed by `data-slot-index` so partial_result events fill
             * the right slot in place. Gated on `useLoadingSkeleton`; when off,
             * layout/partial events are ignored and only the final `result` renders.
             *
             * @param {object} data - {components: [{index: number, type: string}, ...]}
             * @private
             */
            _handleLayout: function(data) {
                if (!this.useLoadingSkeleton) {
                    return;
                }
                const components = data.data?.components || data.components;
                if (!components?.length) {
                    return;
                }
                this._layoutComponentTypes = components.map((c) => c.type);
                this._hasPartialContent = true;

                this.$(this.LOADING_SELECTOR).remove();

                const container = this._getOrCreatePartialContainer();
                const defaultTemplate = app.template.getView('ai-chat-body.skeleton-placeholder');

                _.each(components, function(component, i) {
                    const type = component.type;
                    const index = (typeof component.index === 'number') ? component.index : i;
                    const $slot = $(
                        '<div data-slot-index="' + index +
                        '" data-slot-type="' + type + '"></div>',
                    );
                    const specificTemplate = app.template.getView('ai-chat-body.skeleton-' + type);
                    const tmpl = specificTemplate || defaultTemplate;
                    $slot.html(tmpl({componentType: type}));
                    container.append($slot);
                }, this);

                this._appendStreamLoader();
                this._scrollToBottomIfNear();
            },

            /**
             * Handle a partial_result SSE event. With a layout, fills the slot
             * named by `data.index` in place; without one, appends (cumulative
             * payloads replace). Gated on `useLoadingSkeleton`.
             *
             * @param {object} data - {index?: number, data: {content: [...]}}
             * @private
             */
            _handlePartialResult: function(data) {
                if (!this.useLoadingSkeleton) {
                    return;
                }
                const components = data.data?.content;
                if (!components?.length) {
                    return;
                }
                this._hasPartialContent = true;
                this.$(this.LOADING_SELECTOR).remove();
                this._removeStreamLoader();

                // With a layout, place each component in its slot by index.
                if (this._layoutComponentTypes.length) {
                    const index = (typeof data.index === 'number') ? data.index : null;
                    this._fillSlot(index, components);
                    this._appendStreamLoader();
                    return;
                }

                const isCumulative = this._isCumulativePartial(components);

                if (isCumulative) {
                    // Full replace — backend sent all components again
                    this.$(this.STREAM_PARTIAL_SELECTOR).remove();
                    this._renderedPartialComponents = [];
                }

                const container = this._getOrCreatePartialContainer();

                _.each(components, function(contentItem) {
                    const componentName = this._getComponentName(contentItem);
                    if (componentName) {
                        this.appendComponent(componentName, container, contentItem.data, {});
                        this._renderedPartialComponents.push(contentItem);
                        this._scrollToBottomIfNear();
                    }
                }, this);

                this._appendStreamLoader();
            },

            /**
             * Determine if an incoming partial_result is cumulative (contains
             * previously rendered components) or additive (only new ones).
             * Compares the type of the first incoming component against the
             * first already-rendered one.
             *
             * @param {Array} components - Incoming content array
             * @returns {boolean} True if cumulative (should replace)
             * @private
             */
            _isCumulativePartial: function(components) {
                if (!this._renderedPartialComponents.length) {
                    return false;
                }
                const firstRendered = this._renderedPartialComponents[0];
                const firstIncoming = components[0];
                return firstIncoming.type === firstRendered.type &&
                    JSON.stringify(firstIncoming.data?.title) === JSON.stringify(firstRendered.data?.title);
            },

            /**
             * Fill the slot at `index` in place (idempotent). If the index is
             * unknown or the slot is gone, fall back to the next skeleton, then
             * to appending.
             *
             * @param {number|null} index
             * @param {Array} components
             * @private
             */
            _fillSlot: function(index, components) {
                const container = this.$(this.STREAM_PARTIAL_SELECTOR);

                _.each(components, function(contentItem) {
                    const componentName = this._getComponentName(contentItem);
                    if (!componentName) {
                        return;
                    }
                    let $slot = null;
                    if (index !== null) {
                        $slot = container.find('[data-slot-index="' + index + '"]').first();
                    }
                    if (!$slot || !$slot.length) {
                        $slot = this._firstUnfilledSlot();
                    }
                    if ($slot && $slot.length) {
                        $slot.empty();
                        this.appendComponent(componentName, $slot, contentItem.data, {});
                    } else {
                        const $appended = $('<div></div>');
                        container.append($appended);
                        this.appendComponent(componentName, $appended, contentItem.data, {});
                    }
                    this._renderedPartialComponents.push(contentItem);
                    this._scrollToBottomIfNear();
                }, this);
            },

            /**
             * Return the first slot wrapper still showing a skeleton placeholder,
             * or an empty jQuery set when every slot is filled.
             *
             * @returns {jQuery}
             * @private
             */
            _firstUnfilledSlot: function() {
                const skeletonSelector = this.STREAM_SKELETON_SELECTOR;
                return this.$(this.STREAM_PARTIAL_SELECTOR)
                    .find('[data-slot-index]')
                    .filter(function() {
                        return $(this).find(skeletonSelector).length > 0;
                    })
                    .first();
            },

            /**
             * Drop unfilled skeleton slots and the loader, keeping rendered
             * widgets. Used when a stream ends without a result (error/interrupt).
             *
             * @private
             */
            _removeRemainingSkeletons: function() {
                const skeletonSelector = this.STREAM_SKELETON_SELECTOR;
                const container = this.$(this.STREAM_PARTIAL_SELECTOR);
                container.find('[data-slot-index]')
                    .filter(function() {
                        return $(this).find(skeletonSelector).length > 0;
                    })
                    .remove();
                // Defensive: drop any bare skeleton not wrapped in a slot.
                container.find(skeletonSelector).remove();
                this._removeStreamLoader();
            },

            /**
             * Append a small pulsing dot loader after the partial-results
             * container. Removed automatically on next partial or final result.
             *
             * @private
             */
            _appendStreamLoader: function() {
                this.$(this.STREAM_LOADER_SELECTOR).remove();
                const template = app.template.getView('ai-chat-body.stream-loader');
                const $loader = $(template());
                // Always show a default label so the dot never appears alone
                const defaultText = app.lang.get('LBL_AI_CHAT_STREAM_WORKING', this.STREAM_LANG_MODULE);
                $loader.find('[data-identifier="stream-loader-text"]').text(defaultText);

                const container = this.$(this.STREAM_PARTIAL_SELECTOR);
                if (container.length) {
                    // Insert before the first unfilled slot so the loader sits
                    // between the last real component and the placeholders.
                    const $firstUnfilled = this._firstUnfilledSlot();
                    if ($firstUnfilled.length) {
                        $firstUnfilled.before($loader);
                    } else {
                        container.append($loader);
                    }
                } else {
                    this.$(this.TABLE_BODY_SELECTOR).append($loader);
                }
            },

            /**
             * Remove the trailing stream loader.
             *
             * @private
             */
            _removeStreamLoader: function() {
                this.$(this.STREAM_LOADER_SELECTOR).remove();
            },

            /**
             * Get the partial-results container, creating it if it doesn't exist.
             *
             * @returns {jQuery} The partial-results container element
             * @private
             */
            _getOrCreatePartialContainer: function() {
                let container = this.$(this.STREAM_PARTIAL_SELECTOR);
                if (!container.length) {
                    this.$(this.TABLE_BODY_SELECTOR).append(
                        '<div data-identifier="partial-results" class="' +
                        this.STREAM_PARTIAL_CLASSES + '"></div>',
                    );
                    container = this.$(this.STREAM_PARTIAL_SELECTOR);
                }
                return container;
            },

            /**
             * Handle a `status` SSE event. Hidden stages are silently
             * ignored; visible stages update the loading text on start.
             *
             * @param {object} data - {stage, state, elapsed_ms?}
             * @private
             */
            _handleStreamStatus: function(data) {
                if (this._hiddenStages[data.stage]) {
                    return;
                }
                if (data.state !== 'started') {
                    return;
                }
                const key = this._streamStageLabelKeys[data.stage];
                if (!key) {
                    return;
                }
                this._setLoadingText(app.lang.get(key, this.STREAM_LANG_MODULE));
            },

            /**
             * Handle a `progress` SSE event — detail strings come from the
             * agent and are surfaced inside the loading indicator. The backend
             * sends a language key (e.g. `LBL_PGAI_GREETING_ON_IT`) that is
             * resolved client-side; if the key cannot be resolved we fall
             * back to whatever raw text was provided.
             *
             * @param {object} data - {detail, tool?}
             * @private
             */
            _handleStreamProgress: function(data) {
                if (!data.detail) {
                    return;
                }
                this._setLoadingText(this._resolveProgressLabel(data.detail));
            },

            /**
             * Render accumulated token text into the streaming markdown
             * preview view, creating the view lazily on first token.
             *
             * Batches via RAF so rapid-fire tokens don't thrash the DOM.
             *
             * @param {string} accumulatedText
             * @private
             */
            _renderStreamingPreview: function(accumulatedText) {
                if (!this._streamingMarkdownView) {
                    this._streamingMarkdownView = this.appendComponent(
                        this.STREAM_PREVIEW_COMPONENT,
                        this.$(this.TABLE_BODY_SELECTOR),
                        {prompt: ''},
                        {},
                    );
                }

                this._streamingMarkdownView.markdownContent = accumulatedText;

                // Debounce to one render per frame
                if (!this._streamRenderScheduled) {
                    this._streamRenderScheduled = true;
                    requestAnimationFrame(() => {
                        this._streamRenderScheduled = false;
                        if (this.disposed || !this._streamingMarkdownView) {
                            return;
                        }
                        this._streamingMarkdownView._renderMarkdown();
                        this._scrollToBottomIfNear();
                    });
                }
            },

            /**
             * Scroll to bottom if the user is near it. Defers to
             * requestAnimationFrame so the browser has reflowed first.
             *
             * @private
             */
            _scrollToBottomIfNear: function() {
                requestAnimationFrame(() => {
                    if (this.disposed) {
                        return;
                    }
                    const container = this.$el;
                    const distanceFromBottom = container.prop('scrollHeight') -
                        container.scrollTop() -
                        container.outerHeight();
                    if (distanceFromBottom < this.STREAM_SCROLL_THRESHOLD_PX) {
                        container.scrollTop(container.prop('scrollHeight'));
                    }
                });
            },

            /**
             * Resolve a progress detail value into displayable text. The
             * backend sends a language key (e.g. `LBL_PGAI_*`); we look it
             * up in app_strings first, then in the AIExperience module, and
             * fall back to the raw value if neither resolves.
             *
             * @param {string} detail
             * @returns {string}
             * @private
             */
            _resolveProgressLabel: function(detail) {
                if (typeof detail !== 'string' || !detail.startsWith('LBL_')) {
                    return detail;
                }
                const appResolved = app.lang.get(detail);
                if (appResolved && appResolved !== detail) {
                    return appResolved;
                }
                const modResolved = app.lang.get(detail, this.STREAM_LANG_MODULE);
                if (modResolved && modResolved !== detail) {
                    return modResolved;
                }
                return detail;
            },

            /**
             * Strip leading JSON blobs (e.g. `{"is_detected":false}`) from a
             * markdown-format token, returning the remainder. Uses
             * brace-depth tracking so interleaved blobs are all removed.
             *
             * @param {string} text
             * @returns {string}
             * @private
             */
            _stripJsonPrefix: function(text) {
                let depth = 0;
                let seenBrace = false;
                for (let i = 0; i < text.length; i++) {
                    const ch = text[i];
                    if (ch === '{') {
                        depth++;
                        seenBrace = true;
                    } else if (ch === '}') {
                        depth = Math.max(0, depth - 1);
                    } else if (seenBrace && depth === 0) {
                        return text.slice(i);
                    }
                }
                return seenBrace ? '' : text;
            },

            /**
             * The span the current progress label should write to: the trailing
             * stream loader once partial content is visible, otherwise the main
             * loading indicator. Resolved at write time so deferred (throttled)
             * writes land on whichever is active then.
             *
             * @returns {jQuery} the active loading text element
             * @private
             */
            _activeLoadingTextEl: function() {
                const $streamLoaderText = this.$(this.STREAM_LOADER_SELECTOR)
                    .find('[data-identifier="stream-loader-text"]');
                if ($streamLoaderText.length) {
                    return $streamLoaderText;
                }
                return this.$(this.LOADING_SELECTOR)
                    .find('[data-identifier="loading-text"]');
            },

            /**
             * Update the active loading text span, throttled to one visible
             * change per STREAM_STATUS_THROTTLE_MS. New values arriving inside
             * the window are deferred until it elapses. Applies both before and
             * after partial content appears, so the label never flickers.
             *
             * @param {string} text
             * @private
             */
            _setLoadingText: function(text) {
                const now = Date.now();
                const elapsed = now - this._lastStatusChangeTime;
                const throttleMs = this.STREAM_STATUS_THROTTLE_MS;

                if (elapsed >= throttleMs) {
                    if (this._statusTextTimer) {
                        clearTimeout(this._statusTextTimer);
                        this._statusTextTimer = null;
                    }
                    this._lastStatusChangeTime = now;
                    this._activeLoadingTextEl().text(text);
                    return;
                }

                this._pendingStatusText = text;
                if (this._statusTextTimer) {
                    return;
                }
                this._statusTextTimer = setTimeout(() => {
                    this._statusTextTimer = null;
                    this._lastStatusChangeTime = Date.now();
                    const pending = this._pendingStatusText;
                    this._pendingStatusText = null;
                    if (this.disposed) {
                        return;
                    }
                    this._activeLoadingTextEl().text(pending);
                }, throttleMs - elapsed);
            },

            /**
             * Cancel any pending throttled label write. Call on error/interrupt
             * and teardown so a deferred label can't paint after cleanup.
             *
             * @private
             */
            _cancelStatusThrottle: function() {
                if (this._statusTextTimer) {
                    clearTimeout(this._statusTextTimer);
                    this._statusTextTimer = null;
                }
                this._pendingStatusText = null;
            },
        });
    });
})(SUGAR.App);

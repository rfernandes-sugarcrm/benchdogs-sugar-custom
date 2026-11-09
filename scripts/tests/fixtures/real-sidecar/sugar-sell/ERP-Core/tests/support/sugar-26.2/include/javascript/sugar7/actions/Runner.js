// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/actions/Runner.js; sha256 49928278761f71c71e9daca47e3c497028272c8a3d50e9a8ffbdeb0a5efce99e of the stock file, which follows unchanged.
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
(function register(app) {
    app.events.on('app:init', function init() {
        /**
         * Action Runner
         *
         * @class Core.Actions.Runner
         * @alias SUGAR.App.Actions.Runner
         */
        const Runner = function(actions, ops) {
            this.actions = actions;
            this.ops = ops || {};
            this.queue = [];
        };

        /**
         * Creates a new Runner instance
         *
         * @param {Array} actions
         * @param {object} ops
         *
         * @returns {Core.Actions.Runner}
         */
        Runner.create = function(actions, ops) {
            return new Runner(actions, ops);
        };

        /**
         * Consumes/executes queued actions
         *
         */
        Runner.prototype.consume = function() {
            if (this.queue.length === 0) {
                return;
            }

            const ops = this.ops;
            const nextAction = this.queue.shift();

            try {
                nextAction.run(ops, {
                    nextAction: _.bind(function nextExecution() {
                        this.consume();
                    }, this),
                });
            } catch {
                let failedActions = [nextAction.constructor.name];
                let nActions = failedActions.join(', ');

                const message = app.lang.get('LBL_ACTIONBUTTON_TASK_SEQUENCE_FAILED');
                const bTagS = '<b>';
                const bTagE = '</b> ';

                if (ops.stopOnError) {
                    failedActions = failedActions.concat(this.queue.map(function getActionType(action) {
                        return action.constructor.name;
                    }));
                    nActions = failedActions.join(', ');

                    app.alert.show('actionbutton-action-stop', {
                        level: 'warning',
                        messages: message + bTagS + nActions + bTagE,
                        autoClose: true,
                        autoCloseDelay: 10000,
                    });
                } else {
                    app.alert.show('actionbutton-action-continue', {
                        level: 'warning',
                        messages: message + bTagS + nActions + bTagE,
                        autoClose: true,
                        autoCloseDelay: 10000,
                    });
                    this.consume();
                }
            }
        };

        /**
         * Adds actions to the execution queue
         *
         */
        Runner.prototype.execute = function() {
            _.each(this.actions, function pushAction(val, key) {
                this.queue.push(val);
            }, this);

            this.consume();
        };

        /**
         * Generic edit-mode redirect for a recordView.
         *
         * @param {object} recordView The recordView to redirect.
         * @returns {object} `recordView`.
         */
        function triggerGenericEditMode(recordView) {
            if (recordView && recordView.context) {
                recordView.context.trigger('button:edit_button:click');
            }

            app.controller.context.trigger('button:edit_button:click');

            return recordView;
        }

        /**
         * Redirects a record view into edit mode, without saving, so that field updates
         * already applied to the model (e.g. by an update-record or assign-record action)
         * are visible for the user to review and correct.
         *
         * Shared by any action that needs to defer to edit mode instead of saving when
         * pre-execution validation has failed (SUS-856).
         *
         * The component that actually enters edit mode - and will emit
         * editable:toggle_fields - isn't always opts.recordView itself (e.g.
         * dashlet-toolbar delegates to a separately-fetched dashablerecord component), so
         * the return value must be tracked per branch rather than assumed to be
         * opts.recordView.
         *
         * A button's actions share one opts object across the whole queue (see
         * runButtonActions() in the actionbutton field), so when a button has more than
         * one validation-aware action, each would otherwise re-trigger this redirect for
         * the same click. Guard on opts itself so only the first call takes effect.
         *
         * @param {object} opts Action options; recordModel/recordView/buttonField as
         *        passed to an action's run()
         * @returns {object} The component that enters edit mode and will emit
         *          editable:toggle_fields (consumed by showErrorsAfterRender)
         */
        function redirectRecordViewToEditMode(opts) {
            if (opts._editModeRedirectDone) {
                return opts._editModeView;
            }

            let editModeView;

            if (opts.recordView && opts.recordView.name === 'dashablerecord') {
                opts.recordView.editRecord();
                editModeView = opts.recordView;
            } else if (opts.recordView && opts.recordView.name === 'preview') {
                const previewHeader = opts.recordView.layout.getComponent('preview-header');

                if (previewHeader) {
                    previewHeader.triggerEdit();
                    editModeView = opts.recordView;
                }
            } else if (
                opts.recordView &&
                (opts.recordView.type === 'subpanel-list' ||
                opts.recordView.type === 'recordlist' ||
                opts.recordView.type === 'dashablelist')
            ) {
                opts.recordView.editClickedRow(opts.recordModel, opts.buttonField);
                editModeView = opts.recordView;
            } else if (
                opts.recordView &&
                (opts.recordView.name === 'dashlet-toolbar' || opts.recordView.name === 'side-drawer-headerpane')
            ) {
                const candidates = app.utils.findComponentsInLayout(opts.recordView.layout, 'dashablerecord');
                const dashablerecordView = (candidates.length === 1 && candidates[0].model === opts.recordModel) ?
                    candidates[0] :
                    _.find(candidates, (c) => c.model === opts.recordModel);

                if (dashablerecordView) {
                    dashablerecordView.editRecord();
                    editModeView = dashablerecordView;
                } else {
                    editModeView = triggerGenericEditMode(opts.recordView);
                }
            } else {
                editModeView = triggerGenericEditMode(opts.recordView);
            }

            opts._editModeRedirectDone = true;
            opts._editModeView = editModeView;

            return editModeView;
        }

        /**
         * Display validation errors after record enters edit mode.
         *
         * Called after a button action has applied field updates and entered edit mode,
         * but validation failed pre-execution. This displays validation errors to the user
         * so they can see what fields need to be corrected before saving.
         *
         * Field highlighting is handled automatically by Sidecar when model validation
         * events are triggered (via the model's change events).
         *
         * Listens for `editable:toggle_fields` (fired by the Editable plugin's
         * toggleFields() once all fields finish switching to edit mode) rather than
         * `render`, since entering edit mode does not re-render the record view itself.
         *
         * Guarded on opts itself (shared across a button's whole action queue - see
         * redirectRecordViewToEditMode()) so that a button with more than one
         * validation-aware action only schedules the error display once per click.
         *
         * @param {object} opts Action options containing validationFailed and buttonField
         * @param {object} [editModeView] The component that actually enters edit mode and
         *        will emit `editable:toggle_fields` - not always the same as opts.recordView
         *        (e.g. dashlet-toolbar delegates to a separately-fetched dashablerecord
         *        component). Falls back to the 100ms delay when omitted or lacking `.once`.
         */
        function showErrorsAfterRender(opts, editModeView) {
            if (opts._errorsScheduled) {
                return;
            }

            if (opts.validationFailed && opts.buttonField &&
                _.isFunction(opts.buttonField.showValidationErrorsAfterEdit)) {
                opts._errorsScheduled = true;

                if (editModeView && _.isFunction(editModeView.once)) {
                    editModeView.once('editable:toggle_fields', function() {
                        opts.buttonField.showValidationErrorsAfterEdit();
                    });
                } else {
                    _.delay(function() {
                        opts.buttonField.showValidationErrorsAfterEdit();
                    }, 100);
                }
            }
        }

        app.actions = app.actions || {};

        app.actions = _.extend(app.actions, {
            Runner: Runner,
            redirectRecordViewToEditMode: redirectRecordViewToEditMode,
            showErrorsAfterRender: showErrorsAfterRender,
        });
    });
})(SUGAR.App);

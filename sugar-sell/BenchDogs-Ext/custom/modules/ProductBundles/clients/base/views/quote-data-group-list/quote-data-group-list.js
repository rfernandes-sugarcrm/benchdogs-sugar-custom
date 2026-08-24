/**
 * @class View.Views.Base.ProductBundles.QuoteDataGroupListView
 *
 * Ordering lines on a Bench Dogs quote is a SELECTION, not a stored flag.
 *
 * The grid used to carry two extra checkbox columns - "To Order" (a stored
 * bd_to_order tick) and "Ordered" (a stored bd_ordered tick) - alongside the
 * stock multi-select checkbox the grid already puts in its first column.
 * Three checkbox columns on one row is what the flow actually looked like,
 * and it read as three unrelated questions: two of them looked answerable
 * and only one of them was. The stored "To Order" tick also had to be saved
 * before the header button could see it, so a tick that looked set was not
 * necessarily persisted yet.
 *
 * Both custom columns are gone. What is left is the checkbox the grid was
 * always going to draw:
 *
 *   - An UNORDERED line has a live checkbox. Ticking it selects the line for
 *     the next release; "Order Selected Lines" orders exactly the ticked
 *     rows. Nothing is written to the record to express the intent, so there
 *     is no save step between ticking and ordering and no stale tick left
 *     behind afterwards.
 *   - An ORDERED line is greyed, its checkbox is replaced by a padlock, and
 *     it cannot be selected - including by the header's select-all. You
 *     cannot re-order something you have already ordered, so the UI does not
 *     offer it. The row stays on the quote as history (REQ-2: nothing is
 *     deleted when a partial release is placed).
 *
 * bd_ordered is still fetched with the row - BdQliColumnsLayout keeps it in
 * the Quotes product_bundle_items allowlist - it just is not drawn as a
 * column any more. bd_to_order remains on the vardefs for records written
 * before this version; nothing sets it from the UI now.
 */
({
    extendsFrom: 'ProductBundlesQuoteDataGroupListView',

    /** Marker class on a row whose line has already been ordered. */
    bdOrderedRowClass: 'bd-ordered-row',

    initialize: function(options) {
        this._super('initialize', [options]);

        this.bdInjectStyles();

        // Re-decorate whenever the rows or the ordered flag can have moved.
        if (this.collection) {
            this.collection.on(
                'reset sync add remove change:bd_ordered',
                this.bdScheduleDecorate,
                this
            );
        }
        this.on('render', this.bdScheduleDecorate, this);
    },

    /**
     * The package ships no stylesheet: a theme-compiled CSS file is a build
     * step the MLP does not otherwise need, and a <style> written once per
     * page is enough for two rules. Guarded by id so repeated grid renders
     * (one per bundle group) do not stack copies.
     */
    bdInjectStyles: function() {
        if (document.getElementById('bd-ordered-row-styles')) {
            return;
        }
        var css = [
            '.bd-ordered-row > td {',
            '  background-color: var(--neutral-extra-light, #eef1f3) !important;',
            '  color: var(--text-color-secondary, #71818f) !important;',
            '}',
            '.bd-ordered-row a, .bd-ordered-row .ellipsis_inline {',
            '  color: var(--text-color-secondary, #71818f) !important;',
            '}',
            '.bd-ordered-lock {',
            '  display: inline-flex; align-items: center; justify-content: center;',
            '  width: 16px; height: 16px;',
            '  color: var(--text-color-secondary, #71818f);',
            '}',
            '.bd-ordered-lock svg { width: 13px; height: 13px; fill: currentColor; }'
        ].join('\n');

        var style = document.createElement('style');
        style.id = 'bd-ordered-row-styles';
        style.type = 'text/css';
        style.appendChild(document.createTextNode(css));
        document.getElementsByTagName('head')[0].appendChild(style);
    },

    /**
     * Rows are written by the parent's own render pass; decorating inside it
     * would run against the previous DOM. _.defer puts this after the frame
     * the parent is drawing.
     */
    bdScheduleDecorate: function() {
        if (this.disposed) {
            return;
        }
        _.defer(_.bind(this.bdDecorateRows, this));
    },

    _render: function() {
        this._super('_render');
        this.bdScheduleDecorate();
        return this;
    },

    /**
     * Grey the ordered rows, take away their checkbox, and make sure none of
     * them is sitting in the multi-select collection.
     */
    bdDecorateRows: function() {
        if (this.disposed || !this.$el) {
            return;
        }
        var self = this;
        var massCollection = this.context ? this.context.get('mass_collection') : null;

        this.$('tr.product-row').each(function() {
            var $row = $(this);
            var recordId = $row.attr('record-id');
            if (!recordId) {
                return;
            }
            var model = self.collection ? self.collection.get(recordId) : null;
            if (!model) {
                return;
            }

            var ordered = !!model.get('bd_ordered');
            $row.toggleClass(self.bdOrderedRowClass, ordered);

            var $checkbox = $row.find('input[name=check]');
            if (!ordered) {
                // A row can come BACK from ordered only by a data repair, but
                // if it does the control has to work again.
                $checkbox.prop('disabled', false).show();
                $row.find('.bd-ordered-lock').remove();
                return;
            }

            $checkbox.prop('checked', false).prop('disabled', true).hide();
            if (massCollection) {
                massCollection.remove(model);
            }
            if (!$checkbox.siblings('.bd-ordered-lock').length) {
                $checkbox.after(self.bdLockMarkup());
            }
        });
    },

    /**
     * Inline SVG rather than a sicon class: the icon set available to a
     * grid cell varies by Sugar theme build, and a padlock that silently
     * renders as an empty box would read as "this column is broken".
     */
    bdLockMarkup: function() {
        return '<span class="bd-ordered-lock" title="' +
            app.lang.get('LBL_BD_LINE_ALREADY_ORDERED', 'Quotes') +
            '">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true">' +
            '<path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v3H9V7a3 3 0 0 1 3-3z"/>' +
            '</svg></span>';
    },

    /**
     * Select-all must not select what cannot be ordered. The parent adds
     * every model on the page; the ordered ones come straight back out.
     */
    onAllChecked: function() {
        this._super('onAllChecked', arguments);
        this.bdDropOrderedFromSelection();
        this.bdScheduleDecorate();
    },

    bdDropOrderedFromSelection: function() {
        var massCollection = this.context ? this.context.get('mass_collection') : null;
        if (!massCollection) {
            return;
        }
        var ordered = massCollection.filter(function(model) {
            return !!model.get('bd_ordered');
        });
        if (ordered.length) {
            massCollection.remove(ordered);
        }
    }
})

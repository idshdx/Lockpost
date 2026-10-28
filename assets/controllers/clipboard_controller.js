import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'label'];
    static values = {
        successLabel: String,
        originalLabel: String,
        failureLabel: { type: String, default: 'Copy failed' },
    };

    async copy() {
        // The async Clipboard API only exists in secure contexts; on plain
        // http:// (hostname or raw IP) navigator.clipboard is undefined and the
        // legacy execCommand path below is the only option.
        if (navigator.clipboard && navigator.clipboard.writeText) {
            try {
                await navigator.clipboard.writeText(this.sourceTarget.value);
                this._flashLabel(this.successLabelValue);
                return;
            } catch {
                // Permission denied or document not focused — fall through to
                // the legacy path rather than reporting a false success.
            }
        }

        if (this._execCommandCopy()) {
            this._flashLabel(this.successLabelValue);
        } else {
            this._flashLabel(this.failureLabelValue);
            this._selectForManualCopy();
        }
    }

    /**
     * Legacy copy path.
     *
     * execCommand('copy') operates on the current selection, so the element has
     * to be focused and fully selected first — select() alone is not always
     * enough, and browsers refuse to build a selection inside a display:none
     * element (the verify page keeps its source in a hidden textarea). The
     * element is revealed only for the duration of the synchronous copy.
     *
     * @returns {boolean} whether the browser reported a successful copy
     */
    _execCommandCopy() {
        const el = this.sourceTarget;
        const wasHidden = el.classList.contains('d-none');

        if (wasHidden) {
            el.classList.remove('d-none');
        }

        try {
            this._selectAll(el);
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            if (wasHidden) {
                el.classList.add('d-none');
            }
        }
    }

    /**
     * Selects the whole source and gives it focus.
     */
    _selectAll(el) {
        if (typeof el.focus === 'function') {
            el.focus();
        }

        if (typeof el.setSelectionRange === 'function' && typeof el.value === 'string') {
            el.setSelectionRange(0, el.value.length);
        } else if (typeof el.select === 'function') {
            el.select();
        }
    }

    /**
     * Leaves the text selected so the user can finish with Ctrl/Cmd+C when the
     * automatic copy did not go through.
     */
    _selectForManualCopy() {
        const el = this.sourceTarget;

        if (el.classList.contains('d-none')) {
            return;
        }

        try {
            this._selectAll(el);
        } catch {
            // Best effort — the failure label is already showing.
        }
    }

    /**
     * Shows a temporary status label, then restores the original one.
     * Any pending reset is cancelled so rapid clicks cannot restore early.
     */
    _flashLabel(text) {
        if (!this.hasLabelTarget) {
            return;
        }

        this.labelTarget.textContent = text;
        clearTimeout(this._resetTimer);
        this._resetTimer = setTimeout(() => {
            this.labelTarget.textContent = this.originalLabelValue;
        }, 2000);
    }

    disconnect() {
        clearTimeout(this._resetTimer);
    }
}

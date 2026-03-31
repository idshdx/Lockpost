import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'label'];
    static values = {
        successLabel: String,
        originalLabel: String,
    };

    async copy() {
        const text = this.sourceTarget.value;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                this._swapLabel();
            } catch {
                this._execCommandCopy();
            }
        } else {
            this._execCommandCopy();
        }
    }

    _execCommandCopy() {
        this.sourceTarget.select();
        document.execCommand('copy');
        this._swapLabel();
    }

    _swapLabel() {
        if (!this.hasLabelTarget) return;

        this.labelTarget.textContent = this.successLabelValue;
        setTimeout(() => {
            this.labelTarget.textContent = this.originalLabelValue;
        }, 2000);
    }
}

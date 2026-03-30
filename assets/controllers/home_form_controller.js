import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['loading', 'form'];

    submit() {
        // Show spinner when form is submitted (server-side processing)
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove('d-none');
        }
        // Disable the submit button to prevent double-submit
        const btn = this.element.querySelector('[type="submit"]');
        if (btn) btn.disabled = true;
    }
}

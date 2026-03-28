import { Controller } from '@hotwired/stimulus';
import openpgp from 'openpgp';

export default class extends Controller {
    static targets = ['message', 'feedback', 'loading', 'submitBtn'];
    static values = {
        recipient: String,
        publicKey: String,
        submitUrl: String,
        homeUrl: String,
    };

    connect() {
        try {
            if (!openpgp) {
                this._showFeedback('danger', 'Encryption library failed to load. Please reload the page.');
                this.submitBtnTarget.disabled = true;
            }
        } catch (e) {
            this._showFeedback('danger', 'Encryption library failed to load. Please reload the page.');
            this.submitBtnTarget.disabled = true;
        }
    }

    async submit(event) {
        event.preventDefault();

        this._hideFeedback();
        this._setLoading(true);

        try {
            const publicKey = await openpgp.readKey({ armoredKey: this.publicKeyValue });
            const encrypted = await openpgp.encrypt({
                message: await openpgp.createMessage({ text: this.messageTarget.value }),
                encryptionKeys: publicKey,
            });

            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ encrypted, recipient: this.recipientValue }),
            });

            if (response.ok) {
                this._showFeedback('success', 'Your message has been encrypted and sent successfully. Redirecting…');
                setTimeout(() => {
                    window.location.href = this.homeUrlValue;
                }, 3000);
            } else {
                let errorMessage = 'An error occurred while sending your message.';
                try {
                    const data = await response.json();
                    if (data.error) {
                        errorMessage = data.error;
                    } else if (data.errors) {
                        errorMessage = Array.isArray(data.errors)
                            ? data.errors.join(' ')
                            : String(data.errors);
                    }
                } catch {
                    // JSON parse failed — keep default message
                }
                this._showFeedback('danger', errorMessage);
            }
        } catch (error) {
            this._showFeedback('danger', error.message || 'An unexpected error occurred.');
        } finally {
            this._setLoading(false);
        }
    }

    _showFeedback(type, message) {
        this.feedbackTarget.className = `alert alert-${type}`;
        this.feedbackTarget.textContent = message;
        this.feedbackTarget.removeAttribute('hidden');
        this.feedbackTarget.classList.remove('d-none');
    }

    _hideFeedback() {
        this.feedbackTarget.classList.add('d-none');
    }

    _setLoading(isLoading) {
        if (isLoading) {
            this.loadingTarget.classList.remove('d-none');
            this.submitBtnTarget.disabled = true;
            this.messageTarget.disabled = true;
        } else {
            this.loadingTarget.classList.add('d-none');
            this.submitBtnTarget.disabled = false;
            this.messageTarget.disabled = false;
        }
    }
}

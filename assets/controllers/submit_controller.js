import { Controller } from '@hotwired/stimulus';
// openpgp is loaded on demand — see assets/openpgp_loader.js. A static import
// here would abort the whole module graph on insecure origins and kill Stimulus.
import { loadOpenpgp, hasWebCrypto, INSECURE_CONTEXT_MESSAGE } from '../openpgp_loader.js';


export default class SubmitController extends Controller {
    static targets = ['message', 'feedback', 'loading', 'submitBtn'];
    static values = {
        token: String,
        recipient: String,
        publicKey: String,
        submitUrl: String,
        homeUrl: String,
        csrfToken: String,
    };

    connect() {
        // OpenPGP.js needs WebCrypto, which browsers only expose in secure
        // contexts. Explain that up-front instead of letting the user type a
        // message that can never be encrypted.
        if (!hasWebCrypto()) {
            this._showFeedback('danger', INSECURE_CONTEXT_MESSAGE);
            this.submitBtnTarget.disabled = true;
        }
    }

    async submit(event) {
        event.preventDefault();

        this._hideFeedback();

        if (!hasWebCrypto()) {
            this._showFeedback('danger', INSECURE_CONTEXT_MESSAGE);
            this.submitBtnTarget.disabled = true;
            return;
        }

        this._setLoading(true);

        // Once the message has been accepted the button must stay disabled
        // until the redirect happens; re-enabling it in finally would leave a
        // window in which a second click sends the message again.
        let sent = false;

        try {
            const openpgp = await loadOpenpgp();
            const publicKey = await openpgp.readKey({ armoredKey: this.publicKeyValue });
            const encrypted = await openpgp.encrypt({
                message: await openpgp.createMessage({ text: this.messageTarget.value }),
                encryptionKeys: publicKey,
            });

            // Clear plaintext from memory after successful encryption.
            // Overwrite the textarea value to prevent recovery from memory.
            this.messageTarget.value = '';

            const token = this.tokenValue || this.recipientValue;
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    encrypted,
                    token: token,
                    recipient: this.recipientValue,
                    _csrf_token: this.csrfTokenValue,
                }),
            });

            if (response.ok) {
                sent = true;
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
            // Do not expose raw error messages — they may contain key fingerprints,
            // internal key IDs, or other sensitive information.
            // Map known error types to user-friendly messages.
            let userMessage = 'An unexpected error occurred during encryption. Please try again.';

            if (error instanceof Error) {
                const msg = error.message.toLowerCase();
                if (msg.includes('webcrypto') || msg.includes('crypto.subtle')) {
                    userMessage = INSECURE_CONTEXT_MESSAGE;
                } else if (msg.includes('public key') && msg.includes('expired')) {
                    userMessage = 'The recipient\'s public key has expired. Ask them to share a new link.';
                } else if (msg.includes('public key') && (msg.includes('invalid') || msg.includes('parse'))) {
                    userMessage = 'The recipient\'s public key could not be read. Please ask them to share a valid PGP key.';
                } else if (msg.includes('network')) {
                    userMessage = 'Network error. Please check your connection and try again.';
                } else if (msg.includes('encrypt')) {
                    userMessage = 'Encryption failed. Please check your message and try again.';
                }
            }

            this._showFeedback('danger', userMessage);
        } finally {
            this._setLoading(false);

            // _setLoading(false) re-enables the submit button; keep it disabled
            // after a successful send so the message cannot be sent twice while
            // the page waits for the redirect.
            if (sent) {
                this.submitBtnTarget.disabled = true;
            }
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

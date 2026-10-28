import { Controller } from '@hotwired/stimulus';
// openpgp is loaded on demand — see assets/openpgp_loader.js. A static import
// here would abort the whole module graph on insecure origins and kill Stimulus.
import { loadOpenpgp, INSECURE_CONTEXT_MESSAGE } from '../openpgp_loader.js';

export default class extends Controller {
    static targets = ['signedMessage', 'publicKey', 'result', 'submitBtn', 'loading'];

    async verify(event) {
        event.preventDefault();

        const signedText = this.signedMessageTarget.value.trim();
        const pubKeyArmored = this.publicKeyTarget.value.trim();

        if (!signedText || !pubKeyArmored) {
            this._showResult('danger', 'Please fill in both fields.');
            return;
        }

        this._setLoading(true);
        this._hideResult();

        try {
            const openpgp = await loadOpenpgp();
            const publicKey = await openpgp.readKey({ armoredKey: pubKeyArmored });

            // The signed message is a PGP cleartext-signed message
            // (-----BEGIN PGP SIGNED MESSAGE----- ... -----END PGP SIGNATURE-----)
            const message = await openpgp.readCleartextMessage({ cleartextMessage: signedText });

            const verificationResult = await openpgp.verify({
                message,
                verificationKeys: publicKey,
            });

            const { verified, keyID } = verificationResult.signatures[0];

            try {
                await verified; // throws if invalid
                this._showResult('success',
                    `✓ Signature is valid. The message was signed by key ID: ${keyID.toHex().toUpperCase()}`
                );
            } catch (e) {
                this._showResult('danger',
                    `✗ Signature verification failed: ${e.message}`
                );
            }
        } catch (error) {
            this._showResult('danger', this._friendlyError(error));
        } finally {
            this._setLoading(false);
        }
    }

    /**
     * Keeps the existing user-facing text, but replaces the WebCrypto failure —
     * which is really "this page is not HTTPS" — with an actionable message.
     */
    _friendlyError(error) {
        const message = error instanceof Error ? error.message : String(error);

        if (/webcrypto|crypto\.subtle/i.test(message)) {
            return INSECURE_CONTEXT_MESSAGE;
        }

        return `Error: ${message}`;
    }

    _showResult(type, message) {
        this.resultTarget.className = `alert alert-${type} mt-3`;
        this.resultTarget.textContent = message;
        this.resultTarget.classList.remove('d-none');
    }

    _hideResult() {
        this.resultTarget.classList.add('d-none');
    }

    _setLoading(isLoading) {
        this.loadingTarget.classList.toggle('d-none', !isLoading);
        this.submitBtnTarget.disabled = isLoading;
    }
}

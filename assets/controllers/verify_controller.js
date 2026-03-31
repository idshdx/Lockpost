import { Controller } from '@hotwired/stimulus';
import * as openpgp from 'openpgp';

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
            this._showResult('danger', `Error: ${error.message}`);
        } finally {
            this._setLoading(false);
        }
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

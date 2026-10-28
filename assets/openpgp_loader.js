/**
 * On-demand OpenPGP.js loader.
 *
 * OpenPGP.js probes WebCrypto while its module is being *evaluated* and throws
 * `The WebCrypto API is not available` when `crypto.subtle` is missing — which
 * is always the case on insecure origins (plain http:// over a hostname or IP).
 *
 * The Stimulus bundle eagerly imports every controller from
 * `/assets/@symfony/stimulus-bundle/controllers.js`, so a top-level
 * `import * as openpgp from 'openpgp'` in a controller makes that throw abort
 * the whole ES module graph: `app` never evaluates, Stimulus never boots, and
 * every controller (clipboard, home-form, submit, verify) silently dies — the
 * copy button stops responding and the "Looking up PGP key…" spinner never
 * appears.
 *
 * Loading the library only when encryption is actually requested keeps the rest
 * of the UI alive on every origin and confines the WebCrypto limitation to the
 * one action that genuinely needs it.
 *
 * @returns {Promise<typeof import('openpgp')>}
 */
let pending = null;

export function loadOpenpgp() {
    if (!pending) {
        pending = import('openpgp').catch((error) => {
            // Drop the rejected promise so a later attempt (e.g. after the
            // page is served over HTTPS, or a transient network failure) can
            // retry instead of re-throwing the cached rejection forever.
            pending = null;
            throw error;
        });
    }

    return pending;
}

/**
 * Whether the browser exposes WebCrypto, which OpenPGP.js requires.
 *
 * `crypto.subtle` is only available in secure contexts, so this is false for
 * plain http:// pages served from a real hostname or IP address.
 *
 * @returns {boolean}
 */
export function hasWebCrypto() {
    return !!(typeof globalThis.crypto !== 'undefined' && globalThis.crypto.subtle);
}

/**
 * Message shown when encryption is impossible because the page is insecure.
 * Kept identical everywhere so users get one consistent, actionable explanation.
 */
export const INSECURE_CONTEXT_MESSAGE =
    'This page must be served over HTTPS to encrypt messages: your browser ' +
    'blocks the WebCrypto API on plain HTTP, so client-side encryption is ' +
    'unavailable here. Ask the site operator to enable HTTPS.';

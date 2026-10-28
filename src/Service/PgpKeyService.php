<?php

namespace App\Service;

use App\DTO\PgpKeyResult;
use App\Exception\AppException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PgpKeyService
{
    /**
     * Key servers are queried in order. keys.openpgp.org is first because it
     * performs email-verified key publishing, making it the most trustworthy
     * source for a recipient's verified public key.
     *
     * ubuntu.com is kept as a fallback but its keys are NOT email-verified.
     * pgp.mit.edu is kept as a last resort.
     */
    private const KEY_SERVERS = [
        'https://keys.openpgp.org',
        'https://keyserver.ubuntu.com',
        'https://pgp.mit.edu',
    ];

    private const TIMEOUT = 8;

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public static function getKeyServerNames(): array
    {
        return array_map(
            fn(string $url) => parse_url($url, PHP_URL_HOST),
            self::KEY_SERVERS
        );
    }

    /**
     * Check if a public key exists for a given email address.
     *
     * Queries all key servers concurrently and short-circuits on the first
     * server that returns a valid PGP public key block with a matching UID.
     */
    public function verifyPublicKeyExists(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->findFirstValidResult($email) !== null;
    }

    /**
     * Retrieve the PGP public key for a given email address.
     *
     * Queries all key servers concurrently and short-circuits on the first
     * server that returns a valid PGP public key block with a matching UID,
     * cancelling the remaining in-flight requests to minimise latency.
     *
     * The returned key block is verified to contain the requested email
     * address in its UID — a key returned by a keyserver for a different
     * email is rejected.
     *
     * @throws AppException If no public key with matching UID could be retrieved.
     */
    public function getPublicKeyByEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        $result = $this->findFirstValidResult($email);

        if ($result === null) {
            throw new AppException('No public key found for the provided email address');
        }

        return $result->publicKey;
    }

    /**
     * Retrieve the PGP public key AND metadata for a given email address.
     *
     * Same as getPublicKeyByEmail() but returns a PgpKeyResult containing
     * the source server, key fingerprint, and the list of email UIDs
     * found in the key block.
     *
     * @throws AppException If no public key with matching UID could be retrieved.
     */
    public function getPgpKeyResult(string $email): PgpKeyResult
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        $result = $this->findFirstValidResult($email);

        if ($result === null) {
            throw new AppException('No public key found for the provided email address');
        }

        return $result;
    }

    /**
     * Fire all key-server requests concurrently and short-circuit on the
     * first response that contains a valid PGP public key block whose
     * UID matches the requested email.
     *
     * Uses the machine-readable (mr) format from keys.openpgp.org to
     * extract the fingerprint and verify the email UID. For other
     * keyservers, falls back to parsing the armored key block.
     *
     * All requests are dispatched simultaneously. Each response is checked
     * via getStatusCode() and getContent(false) — the false parameter
     * suppresses HTTP error exceptions on 4xx/5xx. The first response with
     * a 2xx status AND a key block with matching UID wins; its body is
     * returned immediately. All remaining responses are cancelled in a
     * finally block so that MockResponse::__destruct never throws
     * ClientException for unconsumed responses in tests.
     *
     * @return PgpKeyResult|null The first valid result with matching UID, or null.
     */
    private function findFirstValidResult(string $email): ?PgpKeyResult
    {
        $responses = [];
        $serverUrls = [];
        foreach (self::KEY_SERVERS as $server) {
            $url = $this->buildLookupUrl($server, $email);
            $serverUrls[] = $server;
            $responses[] = $this->httpClient->request('GET', $url, [
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 3,
            ]);
        }

        try {
            foreach ($responses as $index => $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    $body = $response->getContent(false);

                    if ($statusCode < 200 || $statusCode >= 300) {
                        continue;
                    }

                    if (!str_contains($body, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                        continue;
                    }

                    // A single response can contain several complete key blocks
                    // (keyservers return every key matching an email search).
                    // Check each in order; the first whose UID matches wins.
                    foreach ($this->extractKeyBlocks($body) as $keyBlock) {
                        if (!$this->keyHasEmailUid($keyBlock, $email)) {
                            continue;
                        }

                        $fingerprint = $this->extractFingerprint($body, $keyBlock);
                        $emails = $this->extractEmailsFromKeyBlock($keyBlock);

                        return new PgpKeyResult(
                            publicKey: $keyBlock,
                            source: $serverUrls[$index],
                            fingerprint: $fingerprint,
                            emails: $emails,
                        );
                    }
                } catch (\Throwable) {
                    // Transport error or HTTP error — skip this response
                    continue;
                }
            }
        } finally {
            // Cancel any remaining responses so MockResponse destructors
            // don't throw ClientException for unconsumed 4xx/5xx responses.
            // cancel() is a no-op on already-completed responses.
            foreach ($responses as $response) {
                try {
                    $response->cancel();
                } catch (\Throwable) {
                    // Best-effort cleanup
                }
            }
        }

        return null;
    }

    /**
     * Build the key server lookup URL.
     *
     * keys.openpgp.org supports an mr=1 (machine-readable) parameter
     * that returns the key with searchable UID fields. Other servers
     * use the standard HKP lookup.
     */
    private function buildLookupUrl(string $server, string $email): string
    {
        $query = http_build_query([
            'op'    => 'get',
            'search' => $email,
            'mr'    => '1',
        ]);

        return "$server/pks/lookup?$query";
    }

    /**
     * Extract every complete PGP public key block from an HTTP response body.
     *
     * A keyserver response can contain several concatenated blocks (one per key
     * matching the search), so all of them are returned in order. Handles both raw
     * key blocks and keyserver result pages that embed a block in surrounding HTML.
     *
     * @return array<int, string>
     */
    private function extractKeyBlocks(string $body): array
    {
        if (!preg_match_all('/-+BEGIN PGP PUBLIC KEY BLOCK-+.*?-+END PGP PUBLIC KEY BLOCK-+/s', $body, $matches)) {
            return [];
        }

        return array_map(trim(...), $matches[0]);
    }

    /**
     * Verify that an armored PGP key block contains the given email
     * in one of its UID packets.
     */
    private function keyHasEmailUid(string $keyBlock, string $email): bool
    {
        $emails = $this->extractEmailsFromKeyBlock($keyBlock);

        return in_array(strtolower($email), array_map('strtolower', $emails), true);
    }

    /**
     * Extract all email addresses from the UID packets of an armored PGP key block.
     *
     * The armored body is base64-encoded binary OpenPGP packets, so the UIDs are
     * not visible as plaintext in the armor. A regex over the armor text therefore
     * only ever matched keyserver-added metadata such as keys.openpgp.org's
     * "Comment:" header — which is not key material and is absent on other
     * keyservers (e.g. keyserver.ubuntu.com emits "Comment: Hostname:"). That made
     * the UID check pass only for keys.openpgp.org results, silently rejecting
     * valid keys from the fallback servers.
     *
     * This decodes the armor and walks the packets to read the actual User ID
     * packets (tag 13), which are self-signed and bound to the key, and extracts
     * the email addresses from them.
     *
     * @return array<int, string>
     */
    private function extractEmailsFromKeyBlock(string $keyBlock): array
    {
        $emails = [];

        foreach ($this->extractUidsFromKeyBlock($keyBlock) as $uid) {
            // "Name (comment) <email@domain>" — the common UID form.
            if (preg_match_all('/<([^<>@\s]+@[^<>@\s]+)>/', $uid, $matches)) {
                $emails = array_merge($emails, $matches[1]);
            }

            // A UID that is a bare email address with no display name.
            $trimmed = trim($uid);
            if (preg_match('/^[^\s<>@]+@[^\s<>@]+\.[A-Za-z]{2,}$/', $trimmed)) {
                $emails[] = $trimmed;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Extract the raw User ID packet contents (tag 13) from an armored key block.
     *
     * Returns an empty array when the block cannot be decoded or contains no UID
     * packets, so that an undecodable key is rejected rather than trusted.
     *
     * @return array<int, string>
     */
    private function extractUidsFromKeyBlock(string $keyBlock): array
    {
        $binary = $this->dearmor($keyBlock);

        if ($binary === null) {
            return [];
        }

        $uids = [];

        foreach ($this->parsePackets($binary) as $packet) {
            if ($packet['tag'] === 13) { // User ID packet (RFC 4880 §5.11)
                $uids[] = $packet['body'];
            }
        }

        return $uids;
    }

    /**
     * Decode an ASCII-armored PGP packet into its binary form.
     *
     * Strips the optional armor headers ("Version:", "Comment:", …) and the
     * trailing CRC24 checksum line, which starts with "=" and is not base64 data
     * and must be removed before decoding.
     */
    private function dearmor(string $armor): ?string
    {
        if (!preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+\r?\n(.*?)-+END PGP PUBLIC KEY BLOCK-+/s', $armor, $m)) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($m[1]));

        // Skip leading armor headers and blank lines. A header is "Key: value";
        // base64 data never contains a colon, so this cannot eat payload lines.
        while ($lines !== [] && preg_match('/^([A-Za-z][A-Za-z-]*:\s|$)/', (string) $lines[0])) {
            array_shift($lines);
        }

        $data = [];

        foreach ($lines as $line) {
            // The trailing CRC24 line starts with "="; stop at it.
            if (preg_match('/^\s*=/', $line)) {
                break;
            }

            $data[] = $line;
        }

        $base64 = preg_replace('/\s+/', '', implode('', $data));

        if ($base64 === null || $base64 === '') {
            return null;
        }

        $binary = base64_decode($base64, true);

        return $binary === false ? null : $binary;
    }

    /**
     * Walk a binary OpenPGP packet stream and return each packet's tag and body.
     *
     * Implements the RFC 4880 §4.2 packet header encoding for both the old and
     * new formats, including partial body lengths, so that multi-packet key blocks
     * (public key, UID, subkey, signature) are traversed correctly.
     *
     * @return array<int, array{tag: int, body: string}>
     */
    private function parsePackets(string $binary): array
    {
        $packets = [];
        $offset = 0;
        $length = strlen($binary);

        while ($offset < $length) {
            $header = ord($binary[$offset]);

            // A packet header always has the high bit set; anything else means
            // we are out of sync and should stop rather than misparse.
            if (($header & 0x80) === 0) {
                break;
            }

            $newFormat = ($header & 0x40) !== 0;

            // New format: 6-bit tag in bits 5..0. Old format: 4-bit tag in
            // bits 5..2 and a 2-bit length type in bits 1..0.
            if ($newFormat) {
                $tag = $header & 0x3F;
                $lengthType = 0;
            } else {
                $tag = ($header >> 2) & 0x0F;
                $lengthType = $header & 0x03;
            }

            ++$offset;

            $body = $this->readPacketBody($binary, $offset, $newFormat, $lengthType);

            if ($body === null) {
                break;
            }

            $packets[] = ['tag' => $tag, 'body' => $body];
        }

        return $packets;
    }

    /**
     * Read a packet body of the given format, advancing $offset past it.
     *
     * Partial body lengths (RFC 4880 §4.2.2.4) chain multiple chunks; they are
     * concatenated here so the returned body is the complete packet payload.
     */
    private function readPacketBody(string $binary, int &$offset, bool $newFormat, int $lengthType = 0): ?string
    {
        $length = strlen($binary);
        $body = '';

        while (true) {
            if ($offset >= $length) {
                return $body === '' ? null : $body;
            }

            if ($newFormat) {
                $first = ord($binary[$offset++]);

                if ($first < 192) {
                    $chunkLength = $first;
                    $partial = false;
                } elseif ($first < 224) {
                    if ($offset >= $length) {
                        return null;
                    }
                    $chunkLength = (($first - 192) << 8) + ord($binary[$offset++]) + 192;
                    $partial = false;
                } elseif ($first < 255) {
                    $chunkLength = 1 << ($first & 0x1F);
                    $partial = true;
                } else {
                    if ($offset + 4 > $length) {
                        return null;
                    }
                    $chunkLength = unpack('N', substr($binary, $offset, 4))[1];
                    $offset += 4;
                    $partial = false;
                }
            } else {
                $partial = false;

                if ($lengthType === 0) {
                    $chunkLength = ord($binary[$offset++]);
                } elseif ($lengthType === 1) {
                    if ($offset + 2 > $length) {
                        return null;
                    }
                    $chunkLength = unpack('n', substr($binary, $offset, 2))[1];
                    $offset += 2;
                } elseif ($lengthType === 2) {
                    if ($offset + 4 > $length) {
                        return null;
                    }
                    $chunkLength = unpack('N', substr($binary, $offset, 4))[1];
                    $offset += 4;
                } else {
                    // Indeterminate length: the rest of the stream is the body.
                    $chunkLength = $length - $offset;
                }
            }

            if ($offset + $chunkLength > $length) {
                return null;
            }

            $body .= substr($binary, $offset, $chunkLength);
            $offset += $chunkLength;

            if (!$partial) {
                return $body;
            }

            // Partial body length: another length-prefixed chunk follows.
        }
    }

    /**
     * Extract the key fingerprint from the HTTP response body.
     *
     * keys.openpgp.org mr=1 format includes a pub fingerprints field.
     * Falls back to the fingerprint from the keyserver response header.
     */
    private function extractFingerprint(string $body, string $keyBlock): string
    {
        // Try to get fingerprint from keys.openpgp.org mr=1 response
        // Format: "pub 4096R/XXXXXXXX 2024-01-01 [SC] [expires: YYYY-MM-DD]"
        if (preg_match('/pub\s+\S+\s+([A-Fa-f0-9]+)/', $body, $m)) {
            return strtoupper($m[1]);
        }

        // Try the armored key block header comment
        // Some keyservers include: "# Fingerprint: XXXX"
        if (preg_match('/# Fingerprint:\s*([A-Fa-f0-9\s]+)/i', $body, $m)) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }

        // Try to get fingerprint from the armored key's comment field
        // The key block may contain: "# fingerprint=XXXX"
        if (preg_match('/# fingerprint=([A-Fa-f0-9]+)/i', $keyBlock, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }
}

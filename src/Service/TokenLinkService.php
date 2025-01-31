<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;

class TokenLinkService
{
    private string $appSecret;
    private const CIPHER = 'aes-256-cbc';

    public function __construct(string $appSecret)
    {
        $this->appSecret = $appSecret;
    }

    public function generateLink(string $email): string
    {
        $expiration = time() + (30 * 24 * 60 * 60); // 30 days expiration
        $data = json_encode([
            'email' => $email,
            'exp' => $expiration
        ], JSON_THROW_ON_ERROR);

        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivlen); // Use random_bytes() for secure IV generation

        $key = hash('sha256', $this->appSecret, true);

        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new AppException('Failed to encrypt data');
        }

        // Generate HMAC for message authentication
        $hmac = hash_hmac('sha256', $encrypted . $iv, $key, true);

        // Return encoded token (HMAC + IV + Encrypted Data)
        return strtr(base64_encode($hmac . $iv . $encrypted), '+/', '-_');
    }

    public function validateLink(string $token): string
    {
        try {
            // Restore base64 standard characters
            $token = strtr($token, '-_', '+/');
            $decoded = base64_decode($token);
            if ($decoded === false) {
                throw new AppException('Invalid token format');
            }

            $key = hash('sha256', $this->appSecret, true);
            $hmacSize = 32; // SHA256 produces 32 bytes
            $ivlen = openssl_cipher_iv_length(self::CIPHER);

            $hmac = substr($decoded, 0, $hmacSize);
            $iv = substr($decoded, $hmacSize, $ivlen);
            $encrypted = substr($decoded, $hmacSize + $ivlen);

            // Validate IV
            if (empty($iv) || strlen($iv) !== $ivlen) {
                throw new AppException('Invalid or corrupted IV');
            }

            // Verify the HMAC
            $calculatedHmac = hash_hmac('sha256', $encrypted . $iv, $key, true);
            if (!hash_equals($hmac, $calculatedHmac)) {
                throw new AppException('Token has been tampered with');
            }

            // Decrypt the data
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new AppException('Failed to decrypt data');
            }

            // Validate decrypted data
            $data = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($data['email'], $data['exp'])) {
                throw new AppException('Invalid token data');
            }

            // Ensure token has not expired
            if ($data['exp'] < time()) {
                throw new AppException('Link has expired');
            }

            return $data['email'];
        } catch (Exception $e) {
            throw new AppException($e->getMessage());
        }
    }
}

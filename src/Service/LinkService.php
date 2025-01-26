<?php

namespace App\Service;

use App\Exception\AppException;

class LinkService
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
        ]);

        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivlen);
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

        $hmac = hash_hmac('sha256', $encrypted . $iv, $key, true);
        return base64_encode($hmac . $iv . $encrypted);
    }

    public function validateLink(string $token): string
    {
        try {
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

            $calculatedHmac = hash_hmac('sha256', $encrypted . $iv, $key, true);
            if (!hash_equals($hmac, $calculatedHmac)) {
                throw new AppException('Token has been tampered with');
            }

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

            $data = json_decode($decrypted, true);
            if (!isset($data['email']) || !isset($data['exp'])) {
                throw new AppException('Invalid token data');
            }

            if ($data['exp'] < time()) {
                throw new AppException('Link has expired');
            }

            return $data['email'];
        } catch (\Exception $e) {
            throw new AppException($e->getMessage());
        }
    }
}
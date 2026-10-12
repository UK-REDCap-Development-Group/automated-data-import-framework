<?php
namespace UKModules\ADIF\Cache;

/**
 * Encrypts cached values for identifying sources. Hashing the key isn't enough
 * on its own: responses often echo the input (a geocoder returns the matched
 * address), so the stored value would expose what the hashed key hides.
 *
 * AES-256-GCM with a key derived from the same out-of-database secret as
 * KeyBuilder. Values that fail to decrypt (secret rotated, tampering) are
 * treated as cache misses rather than errors.
 */
final class PayloadCipher
{
    private const CIPHER = 'aes-256-gcm';
    private string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Cache secret must be at least 32 bytes.');
        }
        $this->key = hash_hmac('sha256', 'adif-cache-payload-v1', $secret, true);
    }

    public function seal(CacheEntry $e): CacheEntry
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(json_encode($e->value), self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Could not encrypt cache payload.');
        }
        return new CacheEntry(['__sealed' => base64_encode($iv . $tag . $ct)],
            $e->providerId, $e->version, $e->retrievedAt, $e->origin);
    }

    public function open(CacheEntry $e): ?CacheEntry
    {
        $blob = base64_decode((string) ($e->value['__sealed'] ?? ''), true);
        if ($blob === false || strlen($blob) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($blob, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA,
            substr($blob, 0, 12), substr($blob, 12, 16));
        $value = $plain === false ? null : json_decode($plain, true);
        return is_array($value)
            ? new CacheEntry($value, $e->providerId, $e->version, $e->retrievedAt, $e->origin)
            : null;
    }
}

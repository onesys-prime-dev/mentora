<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Encryption\EncrypterInterface;
use Config\Encryption as EncryptionConfig;
use Config\Messages as MessagesConfig;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Encrypts message bodies at rest with the Messages key. Each ciphertext is
 * bound to its conversation and sender, so a copied row is unreadable
 * anywhere else. Fails closed: with no usable key it refuses to encrypt and
 * decrypts nothing.
 */
class MessageCipher
{
    private const MIN_KEY_BYTES = 32;

    private string $key;

    /** @var list<string> */
    private array $previousKeys;

    private ?EncrypterInterface $encrypter = null;

    public function __construct(?MessagesConfig $config = null)
    {
        $config ??= config(MessagesConfig::class);

        $this->key = self::resolveKey((string) $config->encryptionKey);

        $previous = $config->previousKeys;
        if (is_string($previous)) {
            $previous = array_map(trim(...), explode(',', $previous));
        }

        $this->previousKeys = [];
        foreach ($previous as $candidate) {
            $resolved = self::resolveKey((string) $candidate);
            if (strlen($resolved) >= self::MIN_KEY_BYTES) {
                $this->previousKeys[] = $resolved;
            }
        }
    }

    public function isUsable(): bool
    {
        return strlen($this->key) >= self::MIN_KEY_BYTES;
    }

    /**
     * @throws RuntimeException when there is no usable key
     */
    public function encrypt(string $body, int $conversationId, int $senderId): string
    {
        if (! $this->isUsable()) {
            throw new RuntimeException('The Messages encryption key is missing or too short.');
        }

        $envelope = json_encode(['c' => $conversationId, 's' => $senderId, 'b' => $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return base64_encode($this->encrypter()->encrypt($envelope));
    }

    /**
     * The body, or null when it cannot be read for any reason.
     */
    public function decrypt(string $stored, int $conversationId, int $senderId): ?string
    {
        if (! $this->isUsable()) {
            return null;
        }

        try {
            $raw = base64_decode($stored, true);
            if ($raw === false || $raw === '') {
                return null;
            }

            $envelope = json_decode($this->encrypter()->decrypt($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($envelope)
            || ($envelope['c'] ?? null) !== $conversationId
            || ($envelope['s'] ?? null) !== $senderId
            || ! is_string($envelope['b'] ?? null)) {
            return null;
        }

        return $envelope['b'];
    }

    private function encrypter(): EncrypterInterface
    {
        if ($this->encrypter === null) {
            $config               = new EncryptionConfig();
            $config->key          = $this->key;
            $config->previousKeys = $this->previousKeys;

            $this->encrypter = Services::encrypter($config, false);
        }

        return $this->encrypter;
    }

    /**
     * Same prefixes as Config\Encryption. A malformed value resolves to ''.
     */
    private static function resolveKey(string $key): string
    {
        if (str_starts_with($key, 'hex2bin:')) {
            $hex = substr($key, 8);

            return ctype_xdigit($hex) && strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : '';
        }

        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }
}

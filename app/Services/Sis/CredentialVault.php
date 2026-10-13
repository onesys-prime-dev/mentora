<?php

declare(strict_types=1);

namespace App\Services\Sis;

use Config\Encryption;
use Throwable;

/**
 * Seals integration credentials with the application's encryption key, so the
 * database (and its backups) never hold them in plain text. The sealed text
 * is opened only when a connector is built; it is never sent to a page.
 *
 * Losing or changing `encryption.key` makes sealed credentials unreadable;
 * they must then be entered again.
 */
class CredentialVault
{
    public function __construct(private readonly ?string $key = null)
    {
    }

    /** @param array<string, string> $values */
    public function seal(array $values): string
    {
        return base64_encode($this->encrypter()->encrypt((string) json_encode($values)));
    }

    /**
     * @return array<string, string>
     *
     * @throws SisException when the text cannot be opened with the current key
     */
    public function open(string $sealed): array
    {
        try {
            $decoded = json_decode($this->encrypter()->decrypt((string) base64_decode($sealed, true)), true);
        } catch (SisException $e) {
            throw $e;
        } catch (Throwable) {
            throw new SisException('The saved credentials cannot be read with the current encryption key. Enter them again.');
        }

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function encrypter(): \CodeIgniter\Encryption\EncrypterInterface
    {
        $config = config(Encryption::class);

        if ($this->key !== null) {
            $config      = clone $config;
            $config->key = $this->key;
        }

        if ($config->key === '') {
            throw new SisException('The server has no encryption key, so credentials cannot be stored. Run "php spark key:generate" and deploy the new .env.');
        }

        return service('encrypter', $config, false);
    }
}

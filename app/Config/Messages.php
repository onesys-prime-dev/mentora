<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for Messages. The key encrypts message bodies at rest and is kept
 * apart from the app's general encryption key, so it can be rotated alone.
 *
 * Both accept the same forms as Config\Encryption: a raw string, "hex2bin:..."
 * or "base64:...". previousKeys is an array or a comma-separated string of
 * older keys that may still decrypt existing messages. BaseConfig only
 * resolves those prefixes for Config\Encryption, so MessageCipher does it.
 */
class Messages extends BaseConfig
{
    public string $encryptionKey = '';

    public array|string $previousKeys = '';
}

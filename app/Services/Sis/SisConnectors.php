<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * The connector types the platform knows, and how to build one for an
 * institution. A new SIS product is added here (its fields and a class), and
 * nowhere else in the admin pages.
 */
class SisConnectors
{
    public const FILE = 'file';

    /** The base folder for file exchange; one subfolder per institution. */
    public static function baseFolder(): string
    {
        return rtrim((string) env('sis.path', WRITEPATH . 'sis'), '/\\');
    }

    /**
     * The folders a file connection uses for an institution. They are derived,
     * never typed in, so a connection cannot point outside its own folder.
     *
     * @return array{inbound: string, outbound: string}
     */
    public static function folders(int $institutionId): array
    {
        $root = self::baseFolder() . DIRECTORY_SEPARATOR . $institutionId;

        return ['inbound' => $root . DIRECTORY_SEPARATOR . 'inbound', 'outbound' => $root . DIRECTORY_SEPARATOR . 'outbound'];
    }

    /**
     * Connector type => label and the credential fields it takes. A secret
     * field is write-only: it can be set or replaced, never read back.
     *
     * @return array<string, array{label: string, fields: array<string, array{label: string, help: string, min: int}>}>
     */
    public static function types(): array
    {
        return [
            self::FILE => [
                'label'  => 'CSV files in a shared folder',
                'fields' => [
                    'signing_key' => [
                        'label' => 'Signing key (optional)',
                        'help'  => 'When set, every file must come with a .sig file holding its HMAC-SHA256, and grades are signed the same way.',
                        'min'   => 16,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $credentials opened credentials
     *
     * @throws SisException for an unknown connector type
     */
    public static function make(string $type, int $institutionId, array $credentials = []): SisConnector
    {
        if ($type === self::FILE) {
            $folders = self::folders($institutionId);
            $key     = ($credentials['signing_key'] ?? '') !== '' ? $credentials['signing_key'] : null;

            return new FileConnector($folders['inbound'], $folders['outbound'], $key);
        }

        throw new SisException("Unknown connector type \"{$type}\".");
    }
}

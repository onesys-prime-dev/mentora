<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Secrets never reach the audit log: any value under a secret-looking key is
 * replaced before the entry is stored, at any depth.
 *
 * @internal
 */
final class AuditRedactionTest extends CIUnitTestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideSecretKeys(): iterable
    {
        foreach (['password', 'Password', 'new_password', 'passphrase', 'secret', 'client_secret', 'api_key', 'apiKey', 'api-key', 'access_token', 'token', 'credentials', 'private_key', 'sis_credential'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('provideSecretKeys')]
    public function testSecretLookingKeysAreRedacted(string $key): void
    {
        $this->assertSame([$key => '[redacted]'], AuditLogger::redact([$key => 'hunter2']));
    }

    public function testOrdinaryValuesAreKept(): void
    {
        $values = ['name' => 'Acme University', 'status' => 'active', 'seats' => 500, 'enabled' => true, 'note' => null];

        $this->assertSame($values, AuditLogger::redact($values));
    }

    public function testNestedSecretsAreRedactedToo(): void
    {
        $redacted = AuditLogger::redact([
            'connection' => ['host' => 'sis.example', 'auth' => ['user' => 'svc', 'password' => 'hunter2']],
            'name'       => 'SIS',
        ]);

        $this->assertSame('sis.example', $redacted['connection']['host']);
        $this->assertSame('svc', $redacted['connection']['auth']['user']);
        $this->assertSame('[redacted]', $redacted['connection']['auth']['password']);
        $this->assertSame('SIS', $redacted['name']);
    }

    public function testListsAreLeftAlone(): void
    {
        $this->assertSame([['a' => 1], ['b' => 2]], AuditLogger::redact([['a' => 1], ['b' => 2]]));
    }
}

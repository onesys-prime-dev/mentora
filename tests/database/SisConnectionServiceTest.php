<?php

declare(strict_types=1);

use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Sis\CredentialVault;
use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisConnectors;
use App\Services\Sis\SisException;
use App\Services\Sis\SisRunLog;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The SIS connection settings (sis_connections), their sealed credentials,
 * the connection test, and the run history (sis_runs).
 *
 * @internal
 */
final class SisConnectionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const SECRET = 'correct-horse-battery-staple-signing';

    private int $institution;

    private \CodeIgniter\Shield\Entities\User $actor;

    private string $folder;

    private SisConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        Services::injectMock('credentialVault', new CredentialVault('database-test-encryption-key-0123456789'));

        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-sis-' . bin2hex(random_bytes(4));
        $_ENV['sis.path'] = $this->folder;

        $this->institution = PeopleFactory::institution('SISCO', 'active');
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');
        $this->service     = new SisConnectionService();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->folder);
        unset($_ENV['sis.path']);

        Services::resetSingle('credentialVault');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function turnOnTheFlag(): void
    {
        (new FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->actor);
    }

    private function drop(string $name, string $contents): void
    {
        $inbound = SisConnectors::folders($this->institution)['inbound'];

        if (! is_dir($inbound)) {
            mkdir($inbound, 0770, true);
        }

        file_put_contents($inbound . DIRECTORY_SEPARATOR . $name, $contents);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return db_connect()->table('sis_connections')->where('institution_id', $this->institution)->get()->getRowArray();
    }

    public function testNothingIsConfiguredToStartWith(): void
    {
        $view = $this->service->view($this->institution);

        $this->assertSame('file', $view['connector']);
        $this->assertFalse($view['enabled']);
        $this->assertFalse($view['hasCredentials']);
        $this->assertNull($view['credentialsSetAt']);
        $this->assertFalse($view['flagOn']);
    }

    public function testCredentialsAreStoredSealedAndNeverComeBack(): void
    {
        $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);

        $stored = $this->row()['credentials'];
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);

        $view = $this->service->view($this->institution);
        $this->assertTrue($view['hasCredentials']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($view));

        // The connector built from the saved row does get the opened value.
        $this->assertSame(['signing_key' => self::SECRET], service('credentialVault')->open($stored));
    }

    public function testBlankInputKeepsWhatIsSavedAndClearRemovesIt(): void
    {
        $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);
        $first = $this->row()['credentials'];

        $this->service->save($this->institution, false, ['signing_key' => ''], false, $this->actor);
        $this->assertSame($first, $this->row()['credentials']);

        $this->service->save($this->institution, false, [], true, $this->actor);
        $this->assertNull($this->row()['credentials']);
        $this->assertFalse($this->service->view($this->institution)['hasCredentials']);
    }

    public function testAShortKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('16 to 255');

        $this->service->save($this->institution, false, ['signing_key' => 'short'], false, $this->actor);
    }

    public function testItCannotBeEnabledWhileTheFeatureIsOff(): void
    {
        try {
            $this->service->save($this->institution, true, [], false, $this->actor);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sis.sync', $e->getMessage());
        }

        $this->assertNull(db_connect()->table('sis_connections')->where('institution_id', $this->institution)->get()->getRowArray());
    }

    public function testItCanBeEnabledOnceTheFeatureIsOnAndSavedAgainWithoutDuplicating(): void
    {
        $this->turnOnTheFlag();

        $this->service->save($this->institution, true, [], false, $this->actor);
        $this->service->save($this->institution, false, [], false, $this->actor);

        $this->assertSame(1, db_connect()->table('sis_connections')->where('institution_id', $this->institution)->countAllResults());
        $this->assertFalse($this->service->view($this->institution)['enabled']);
        $this->assertTrue($this->service->view($this->institution)['flagOn']);
    }

    public function testAnUnknownInstitutionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->save(999999, false, [], false, $this->actor);
    }

    public function testSavingIsAuditedWithoutTheSecret(): void
    {
        $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);

        $log = db_connect()->table('audit_logs')->where('action', 'sis.connection_saved')->get()->getRowArray();

        $this->assertNotNull($log);
        $this->assertSame($this->institution, (int) $log['institution_id']);
        $this->assertStringNotContainsString(self::SECRET, (string) $log['new_values']);
        $this->assertStringContainsString('"login_replaced":true', (string) $log['new_values']);
    }

    public function testSavingKeepsNothingWhenTheAuditEntryFails(): void
    {
        Services::injectMock('auditLogger', new class () extends \App\Services\Audit\AuditLogger {
            public function log(string $action, ?\CodeIgniter\Shield\Entities\User $actor = null, array $details = []): int
            {
                throw new RuntimeException('audit down');
            }
        });

        try {
            $this->service->save($this->institution, false, [], false, $this->actor);
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }
    }

    public function testWithoutAnEncryptionKeyCredentialsAreRefusedWithAnExplanation(): void
    {
        $config      = config(\Config\Encryption::class);
        $original    = $config->key;
        $config->key = '';
        Services::injectMock('credentialVault', new CredentialVault());

        try {
            $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);
            $this->fail('Expected an exception.');
        } catch (SisException $e) {
            $this->assertStringContainsString('encryption key', $e->getMessage());
        } finally {
            $config->key = $original;
        }

        $this->assertNull(db_connect()->table('sis_connections')->where('institution_id', $this->institution)->get()->getRowArray());
    }

    public function testATestRecordsARunAndAnAuditEntry(): void
    {
        $this->drop('students.csv', "external_id,email,first_name,last_name\nS1,a@example.com,Ann,Lee\n");
        $now = new DateTimeImmutable('2026-10-10 09:00:00');

        $result = $this->service->test($this->institution, $this->actor, $now);

        $this->assertTrue($result->ok);

        $run = (new SisRunLog())->history($this->institution)[0];
        $this->assertSame('test', $run['trigger']);
        $this->assertSame('succeeded', $run['status']);
        $this->assertSame('2026-10-10 09:00:00', $run['started_at']);
        $this->assertStringContainsString('students.csv: 1 usable', (string) $run['message']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'sis.connection_tested')->countAllResults());
    }

    public function testAFailedTestIsRecordedAsFailed(): void
    {
        $result = $this->service->test($this->institution, $this->actor);

        $this->assertFalse($result->ok);
        $this->assertSame('failed', (new SisRunLog())->history($this->institution)[0]['status']);
    }

    public function testATestWithCredentialsThatCannotBeOpenedFailsWithAnExplanation(): void
    {
        $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);
        Services::injectMock('credentialVault', new CredentialVault('another-encryption-key-9876543210-xyz'));

        $result = $this->service->test($this->institution, $this->actor);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Enter them again', $result->message);
    }

    public function testTheSavedKeyIsUsedWhenReadingSignedFiles(): void
    {
        $this->service->save($this->institution, false, ['signing_key' => self::SECRET], false, $this->actor);
        $contents = "external_id,email,first_name,last_name\nS1,a@example.com,Ann,Lee\n";
        $this->drop('students.csv', $contents);

        $this->assertFalse($this->service->test($this->institution, $this->actor)->ok, 'unsigned file is refused');

        $this->drop('students.csv.sig', hash_hmac('sha256', $contents, self::SECRET));

        $this->assertTrue($this->service->test($this->institution, $this->actor)->ok);
        $this->assertCount(1, $this->service->connector($this->institution)->read('students')->records);
    }

    public function testRunsRecordTheirCountsAndListNewestFirst(): void
    {
        $log = new SisRunLog();
        $t0  = new DateTimeImmutable('2026-10-10 08:00:00');

        $first  = $log->start($this->institution, SisRunLog::INBOUND, SisRunLog::SCHEDULED, null, true, $t0);
        $second = $log->start($this->institution, SisRunLog::OUTBOUND, SisRunLog::MANUAL, (int) $this->actor->id, false, $t0->modify('+1 hour'));

        $log->finish($first, true, ['created' => 5, 'updated' => 3, 'skipped' => 2, 'failed' => 1], 'done', $t0->modify('+5 minutes'));
        $log->finish($second, false, [], str_repeat('x', 2000), $t0->modify('+70 minutes'));

        $history = $log->history($this->institution);

        $this->assertSame([$second, $first], array_map(static fn (array $r): int => (int) $r['id'], $history));
        $this->assertSame('failed', $history[0]['status']);
        $this->assertSame(1000, strlen((string) $history[0]['message']));
        $this->assertSame('outbound', $history[0]['direction']);
        $this->assertSame([5, 3, 2, 1], [(int) $history[1]['created_count'], (int) $history[1]['updated_count'], (int) $history[1]['skipped_count'], (int) $history[1]['failed_count']]);
        $this->assertSame(1, (int) $history[1]['dry_run']);
        $this->assertSame('2026-10-10 08:05:00', $history[1]['finished_at']);
    }

    public function testARunWithAnUnknownDirectionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SisRunLog())->start($this->institution, 'sideways', SisRunLog::MANUAL);
    }

    public function testTheHistoryIsPerInstitution(): void
    {
        $other = PeopleFactory::institution('OTHER2');
        $log   = new SisRunLog();

        $log->start($other, SisRunLog::INBOUND, SisRunLog::MANUAL);

        $this->assertSame([], $log->history($this->institution));
        $this->assertCount(1, $log->history($other));
    }

    public function testRemovingTheInstitutionRemovesItsConnectionAndRuns(): void
    {
        $this->service->save($this->institution, false, [], false, $this->actor);
        (new SisRunLog())->start($this->institution, SisRunLog::INBOUND, SisRunLog::MANUAL);

        db_connect()->table('institutions')->where('id', $this->institution)->delete();

        $this->assertSame(0, db_connect()->table('sis_connections')->countAllResults());
        $this->assertSame(0, db_connect()->table('sis_runs')->countAllResults());
    }
}

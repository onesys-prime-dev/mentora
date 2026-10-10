<?php

declare(strict_types=1);

use App\Services\Sis\ConnectionResult;
use App\Services\Sis\CredentialVault;
use App\Services\Sis\FileConnector;
use App\Services\Sis\SisConnector;
use App\Services\Sis\SisConnectors;
use App\Services\Sis\SisException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The file connector against a real temporary folder, as the contract every
 * connector follows: test, read, publish grades. Plus the registry and the
 * credential vault.
 *
 * @internal
 */
final class FileConnectorTest extends CIUnitTestCase
{
    private const KEY = 'a-long-shared-signing-key';

    private string $root;

    private string $inbound;

    private string $outbound;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-sis-' . bin2hex(random_bytes(4));
        $this->inbound  = $this->root . DIRECTORY_SEPARATOR . 'inbound';
        $this->outbound = $this->root . DIRECTORY_SEPARATOR . 'outbound';
    }

    protected function tearDown(): void
    {
        foreach ([$this->inbound, $this->outbound] as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    private function drop(string $name, string $contents, bool $sign = false): void
    {
        if (! is_dir($this->inbound)) {
            mkdir($this->inbound, 0770, true);
        }

        file_put_contents($this->inbound . DIRECTORY_SEPARATOR . $name, $contents);

        if ($sign) {
            file_put_contents($this->inbound . DIRECTORY_SEPARATOR . $name . '.sig', hash_hmac('sha256', $contents, self::KEY));
        }
    }

    private function connector(?string $key = null): FileConnector
    {
        return new FileConnector($this->inbound, $this->outbound, $key);
    }

    private const STUDENTS = "external_id,email,first_name,last_name\nS1,ann@example.com,Ann,Lee\nS2,bad,Ben,Ng\n";

    public function testItIsAConnector(): void
    {
        $this->assertInstanceOf(SisConnector::class, $this->connector());
    }

    public function testTestingWithoutAnInboundFolderFailsWithAReason(): void
    {
        $result = $this->connector()->testConnection();

        $this->assertInstanceOf(ConnectionResult::class, $result);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('does not exist', $result->message);
    }

    public function testTestingAnEmptyFolderFailsAndNamesTheFilesItWants(): void
    {
        mkdir($this->inbound, 0770, true);

        $result = $this->connector()->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('students.csv', $result->message);
    }

    public function testTestingReportsWhatEachFileHolds(): void
    {
        $this->drop('students.csv', self::STUDENTS);

        $result = $this->connector()->testConnection();

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('students.csv: 1 usable, 1 rejected', $result->message);
    }

    public function testTestingFailsWhenAFileCannotBeUsed(): void
    {
        $this->drop('students.csv', "external_id,email\nS1,a@example.com\n");

        $result = $this->connector()->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('missing the column', $result->message);
    }

    public function testItSaysWhichDatasetsTheSisHasProvided(): void
    {
        $this->drop('students.csv', self::STUDENTS);

        $this->assertTrue($this->connector()->has('students'));
        $this->assertFalse($this->connector()->has('courses'));
        $this->assertFalse($this->connector()->has('../students'));
    }

    public function testReadingReturnsTheBatch(): void
    {
        $this->drop('students.csv', self::STUDENTS);

        $batch = $this->connector()->read('students');

        $this->assertCount(1, $batch->records);
        $this->assertCount(1, $batch->rejected);
        $this->assertSame([2], $batch->rows);
        $this->assertSame(3, $batch->rejected[0]['row']);
    }

    public function testReadingAMissingFileIsRefused(): void
    {
        mkdir($this->inbound, 0770, true);

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('was not found');

        $this->connector()->read('courses');
    }

    public function testReadingAnUnknownDatasetIsRefused(): void
    {
        $this->expectException(SisException::class);

        $this->connector()->read('../../etc/passwd');
    }

    public function testAnOversizedFileIsRefused(): void
    {
        $this->drop('students.csv', 'x');
        $handle = fopen($this->inbound . DIRECTORY_SEPARATOR . 'students.csv', 'r+');
        ftruncate($handle, FileConnector::MAX_BYTES + 1);
        fclose($handle);

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('larger than');

        $this->connector()->read('students');
    }

    public function testWithAKeyAGoodSignatureIsAccepted(): void
    {
        $this->drop('students.csv', self::STUDENTS, true);

        $this->assertCount(1, $this->connector(self::KEY)->read('students')->records);
    }

    public function testWithAKeyAMissingSignatureIsRefused(): void
    {
        $this->drop('students.csv', self::STUDENTS);

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('no signature');

        $this->connector(self::KEY)->read('students');
    }

    public function testWithAKeyAnAlteredFileIsRefused(): void
    {
        $this->drop('students.csv', self::STUDENTS, true);
        file_put_contents($this->inbound . DIRECTORY_SEPARATOR . 'students.csv', self::STUDENTS . "S3,eve@example.com,Eve,Wu\n");

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('does not match');

        $this->connector(self::KEY)->read('students');
    }

    public function testGradesAreWrittenAndSendingTheSameBatchAgainReplacesIt(): void
    {
        $rows = [['course_external_id' => 'C1', 'student_external_id' => 'S1', 'percentage' => 91.5, 'remarks' => 'Passed', 'ignored' => 'x']];
        $file = $this->outbound . DIRECTORY_SEPARATOR . 'grades-2026-t1.csv';

        $this->connector()->publishGrades('2026-t1', $rows);
        $this->assertSame("course_external_id,student_external_id,percentage,remarks\nC1,S1,91.5,Passed\n", file_get_contents($file));

        $this->connector()->publishGrades('2026-t1', $rows);
        $this->assertSame(1, count(glob($this->outbound . DIRECTORY_SEPARATOR . '*')));
        $this->assertSame("course_external_id,student_external_id,percentage,remarks\nC1,S1,91.5,Passed\n", file_get_contents($file));
    }

    public function testGradesAreSignedWhenAKeyIsSet(): void
    {
        $this->connector(self::KEY)->publishGrades('b1', [['course_external_id' => 'C1', 'student_external_id' => 'S1', 'percentage' => 80, 'remarks' => '']]);

        $file = $this->outbound . DIRECTORY_SEPARATOR . 'grades-b1.csv';

        $this->assertSame(hash_hmac('sha256', (string) file_get_contents($file), self::KEY), file_get_contents($file . '.sig'));
    }

    public function testABatchKeyThatCouldEscapeTheFolderIsRefused(): void
    {
        $this->expectException(SisException::class);

        $this->connector()->publishGrades('../evil', []);
    }

    public function testTheOutboundFolderCanBeMadeOnlyIfItCanBe(): void
    {
        mkdir($this->root, 0770, true);
        file_put_contents($this->outbound, 'a file, not a folder');

        try {
            $this->connector()->publishGrades('b1', []);
            $this->fail('Expected an exception.');
        } catch (SisException $e) {
            $this->assertStringContainsString('outbound folder', $e->getMessage());
        } finally {
            unlink($this->outbound);
        }
    }

    public function testTheRegistryBuildsAFileConnectorFromFoldersItDerivesItself(): void
    {
        $folders = SisConnectors::folders(7);

        $this->assertStringEndsWith('7' . DIRECTORY_SEPARATOR . 'inbound', $folders['inbound']);
        $this->assertStringEndsWith('7' . DIRECTORY_SEPARATOR . 'outbound', $folders['outbound']);
        $this->assertInstanceOf(FileConnector::class, SisConnectors::make('file', 7, ['signing_key' => self::KEY]));
        $this->assertInstanceOf(FileConnector::class, SisConnectors::make('file', 7));
        $this->assertArrayHasKey('signing_key', SisConnectors::types()['file']['fields']);
    }

    public function testTheRegistryRefusesAnUnknownConnector(): void
    {
        $this->expectException(SisException::class);

        SisConnectors::make('mystery', 1);
    }

    public function testTheVaultSealsAndOpensAndTheSealedTextHidesTheValue(): void
    {
        $vault  = new CredentialVault('unit-test-encryption-key-0123456789');
        $sealed = $vault->seal(['signing_key' => self::KEY]);

        $this->assertStringNotContainsString(self::KEY, $sealed);
        $this->assertSame(['signing_key' => self::KEY], $vault->open($sealed));
    }

    public function testTheVaultCannotOpenWhatAnotherKeySealed(): void
    {
        $sealed = (new CredentialVault('unit-test-encryption-key-0123456789'))->seal(['signing_key' => self::KEY]);

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('Enter them again');

        (new CredentialVault('a-different-key-9876543210-abcdef'))->open($sealed);
    }

    public function testTheVaultExplainsAMissingKey(): void
    {
        $config      = config(\Config\Encryption::class);
        $original    = $config->key;
        $config->key = '';

        try {
            (new CredentialVault())->seal(['a' => 'b']);
            $this->fail('Expected an exception.');
        } catch (SisException $e) {
            $this->assertStringContainsString('key:generate', $e->getMessage());
        } finally {
            $config->key = $original;
        }
    }
}

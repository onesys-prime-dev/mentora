<?php

declare(strict_types=1);

use App\Services\Sis\FileConnector;
use App\Services\Sis\UploadConnections;
use PHPUnit\Framework\TestCase;

/**
 * The stand-in connection for uploaded files: it answers "configured, enabled,
 * feature on" so the importer runs unchanged, and hands out a file connector on
 * the uploaded folder (never a signed one, never anywhere else).
 *
 * @internal
 */
final class UploadConnectionsTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-upload-conn-' . bin2hex(random_bytes(4));
        mkdir($this->folder, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
    }

    public function testItSaysTheConnectionIsReadyWhateverTheInstitution(): void
    {
        $view = (new UploadConnections($this->folder))->view(12345);

        $this->assertTrue($view['configured']);
        $this->assertTrue($view['enabled']);
        $this->assertTrue($view['flagOn']);
        $this->assertFalse($view['hasCredentials']);
        $this->assertSame($this->folder, $view['folders']['inbound']);
    }

    public function testItReadsTheUploadedFilesAndNothingElse(): void
    {
        file_put_contents($this->folder . DIRECTORY_SEPARATOR . 'students.csv', "external_id,email,first_name,last_name\nS1,a@b.test,Ann,Lee\n");

        $connector = (new UploadConnections($this->folder))->connector(1);

        $this->assertInstanceOf(FileConnector::class, $connector);
        $this->assertTrue($connector->has('students'));
        $this->assertFalse($connector->has('courses'));
        $this->assertCount(1, $connector->read('students')->records);
    }
}

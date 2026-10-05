<?php

declare(strict_types=1);

use App\Services\Storage\StorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Where uploads live, how a Range header is read, how an upload is stored,
 * and which files may be deleted once nothing points at them any more.
 *
 * @internal
 */
final class StorageServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private StorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new StorageService();
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        $dir = $this->storage->root() . 'tests-upload';
        foreach (glob($dir . '/*') ?: [] as $leftover) {
            unlink($leftover);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Locating files
    |--------------------------------------------------------------------------
    */

    public function testFilesLiveUnderWritableUploadsAndAreServedThroughTheFileRoute(): void
    {
        $this->assertSame(WRITEPATH . 'uploads/', $this->storage->root());
        $this->assertSame(WRITEPATH . 'uploads/a/b.pdf', $this->storage->path('a/b.pdf'));
        $this->assertSame(WRITEPATH . 'uploads/a/b.pdf', $this->storage->path('/a/b.pdf'), 'a leading slash cannot climb out');
        $this->assertSame(site_url('files/7'), $this->storage->url(7));
        $this->assertSame(site_url('files/7') . '?download=1', $this->storage->url(7, true));
    }

    /*
    |--------------------------------------------------------------------------
    | Range header
    |--------------------------------------------------------------------------
    */

    public static function ranges(): array
    {
        return [
            'no header' => ['', 1000, null],
            'not bytes' => ['items=0-5', 1000, null],
            'bare dash' => ['bytes=-', 1000, null],
            'closed' => ['bytes=0-99', 1000, [0, 99]],
            'open ended' => ['bytes=900-', 1000, [900, 999]],
            'end past the file' => ['bytes=900-5000', 1000, [900, 999]],
            'suffix' => ['bytes=-100', 1000, [900, 999]],
            'suffix longer than file' => ['bytes=-5000', 1000, [0, 999]],
            'first of several' => ['bytes=0-9,20-29', 1000, [0, 9]],
            'start at the end' => ['bytes=1000-', 1000, false],
            'start after end' => ['bytes=2000-3000', 1000, false],
            'backwards' => ['bytes=50-10', 1000, false],
            'empty file' => ['bytes=0-10', 0, false],
            'capped to a chunk' => ['bytes=0-', 10 * 1024 * 1024, [0, StorageService::RANGE_CHUNK_BYTES - 1]],
            'capped from the middle' => ['bytes=100-', 10 * 1024 * 1024, [100, 100 + StorageService::RANGE_CHUNK_BYTES - 1]],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testRangeHeaders(string $header, int $size, array|false|null $expected): void
    {
        $this->assertSame($expected, $this->storage->parseRange($header, $size));
    }

    /*
    |--------------------------------------------------------------------------
    | Uploading
    |--------------------------------------------------------------------------
    */

    public function testAnUploadIsMovedIntoTheGivenDirectoryUnderARandomName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'hello upload');
        // Not a real HTTP upload, so PHP's own "was this uploaded?" check would refuse it.
        $upload = new class ($tmp, 'My Notes.txt', 'text/plain', filesize($tmp), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function move(string $targetPath, ?string $name = null, bool $overwrite = false): bool
            {
                return rename($this->getRealPath(), rtrim($targetPath, '/') . '/' . $name);
            }
        };

        mkdir($this->storage->root() . 'tests-upload', 0775, true);

        $stored = $this->storage->upload($upload, 'tests-upload/');

        $this->assertSame('My Notes.txt', $stored['original_name']);
        $this->assertSame('txt', $stored['extension']);
        $this->assertSame(12, $stored['size']);
        $this->assertSame('text/plain', $stored['mime_type']);
        $this->assertSame('tests-upload/' . $stored['filename'], $stored['storage_key']);
        $this->assertNotSame('My Notes.txt', $stored['filename'], 'never stored under the client-supplied name');
        $this->assertSame('hello upload', file_get_contents($this->storage->path($stored['storage_key'])));

        @unlink($tmp);
    }

    /*
    |--------------------------------------------------------------------------
    | Deleting
    |--------------------------------------------------------------------------
    */

    public function testDeleteRemovesTheFileAndIgnoresOnesThatAreAlreadyGone(): void
    {
        $file = CourseFactory::file(CourseFactory::user('student'));
        $key = db_connect()->table('files')->where('id', $file['id'])->get()->getRowArray()['storage_key'];

        $this->storage->delete($key);
        $this->assertFileDoesNotExist($file['path']);

        $this->storage->delete($key);
        $this->storage->delete('tests/never-existed.txt');
        $this->addToAssertionCount(1);
    }

    private function fileRow(int $id): bool
    {
        return db_connect()->table('files')->where('id', $id)->countAllResults() === 1;
    }

    public function testPurgeRemovesFilesNothingPointsAtAnyMore(): void
    {
        $file = CourseFactory::file(CourseFactory::user('student'));

        $this->storage->purgeFiles([$file['id']]);

        $this->assertFalse($this->fileRow($file['id']));
        $this->assertFileDoesNotExist($file['path']);
    }

    public function testPurgeLeavesFilesThatAreStillInUse(): void
    {
        $teacher = CourseFactory::user('instructor');
        $offering = CourseFactory::offering();
        $unit = CourseFactory::unit(CourseFactory::module($offering));
        $item = CourseFactory::items($unit, [['type' => 'pdf']])[0];

        $used = CourseFactory::file($teacher);
        CourseFactory::resource($item, 'pdf', null, $used['id']);

        $db = db_connect();
        $announced = CourseFactory::file($teacher);
        $db->table('announcements')->insert(['offering_id' => $offering, 'author_id' => $teacher->id, 'subject' => 'S', 'message' => '{}']);
        $db->table('announcement_attachments')->insert(['announcement_id' => $db->insertID(), 'file_id' => $announced['id'], 'order_index' => 1]);

        $thread = CourseFactory::thread($offering, $teacher);
        $discussed = CourseFactory::file($teacher);
        $db->table('discussion_attachments')->insert(['thread_id' => $thread, 'file_id' => $discussed['id'], 'order_index' => 1]);

        $loose = CourseFactory::file($teacher);

        $this->storage->purgeFiles([$used['id'], $announced['id'], $discussed['id'], $loose['id']]);

        foreach (['resource' => $used, 'announcement' => $announced, 'discussion' => $discussed] as $what => $kept) {
            $this->assertTrue($this->fileRow($kept['id']), $what);
            $this->assertFileExists($kept['path']);
        }
        $this->assertFalse($this->fileRow($loose['id']));
        $this->assertFileDoesNotExist($loose['path']);
    }

    public function testPurgeToleratesEmptyDuplicateAndBlankIds(): void
    {
        $file = CourseFactory::file(CourseFactory::user('student'));

        $this->storage->purgeFiles([]);
        $this->storage->purgeFiles([null, 0, '']);
        $this->assertTrue($this->fileRow($file['id']));

        $this->storage->purgeFiles([$file['id'], (string) $file['id'], null]);
        $this->assertFalse($this->fileRow($file['id']));
    }
}

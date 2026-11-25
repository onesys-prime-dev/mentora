<?php

declare(strict_types=1);

use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Storage\StorageService;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Profile photos (FR6, D6): accepted by content, never by name; re-encoded square JPEGs at 256 and
 * 64 px with no metadata; one current pair per person; audited without values; a URL only while
 * the institution allows photos, and the same one again when it allows them back.
 *
 * @internal
 */
final class ProfilePhotoServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $owner;

    private User $admin;

    private string $folder;

    private StorageService $storage;

    private ProfilePhotoService $service;

    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = PeopleFactory::institution('PPH');
        $this->owner       = PeopleFactory::person('student', 'Ana', 'Reyes', null, $this->institution);
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        // Files go to a temp root instead of writable/uploads.
        $this->folder  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-photo-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $this->storage = new class ($this->folder) extends StorageService {
            public function __construct(private readonly string $folder)
            {
            }

            public function root(): string
            {
                return $this->folder;
            }
        };
        $this->service = new ProfilePhotoService($this->storage);

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->folder . '*' . DIRECTORY_SEPARATOR . '*') ?: [], ...$this->temps] as $file) {
            @unlink($file);
        }
        foreach (glob($this->folder . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->folder);

        parent::tearDown();
    }

    /** A file presented as an upload PHP vouches for, with whatever name and type the browser claimed. */
    private function upload(string $bytes, string $clientName, string $clientType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pph');
        file_put_contents($path, $bytes);
        $this->temps[] = $path;

        return new class ($path, $clientName, $clientType, strlen($bytes), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        };
    }

    private function image(int $w, int $h, string $type = 'png'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 64, 166));
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($img),
            'webp' => imagewebp($img),
            default => imagepng($img),
        };

        return (string) ob_get_clean();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the current 256 and 64 files rows */
    private function pair(): array
    {
        $db  = db_connect();
        $id  = $db->table('user_profiles')->select('photo_file_id')->where('user_id', $this->owner->id)->get()->getRow('photo_file_id');
        $this->assertNotNull($id, 'the profile points at a photo');
        $big = $db->table('files')->where('id', $id)->get()->getRowArray();
        $sm  = $db->table('files')->where('storage_key', preg_replace('/\.jpg$/', '-64.jpg', $big['storage_key']))->get()->getRowArray();
        $this->assertNotNull($sm, 'the 64 px file sits beside the 256 px one');

        return [$big, $sm];
    }

    private function refused(callable $do): ProfileEditException
    {
        try {
            $do();
        } catch (ProfileEditException $e) {
            return $e;
        }

        $this->fail('Expected the photo to be refused.');
    }

    public function testAPngNamedJpgIsAcceptedByItsContent(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(300, 300), 'me.jpg', 'image/jpeg'), $this->owner);

        [$big] = $this->pair();
        $this->assertSame('image/jpeg', $big['mime_type']);
        $this->assertSame('image/jpeg', getimagesize($this->storage->path($big['storage_key']))['mime']);
        $this->assertSame((int) $this->owner->id, (int) $big['owner_user_id']);
    }

    public function testWebpIsAccepted(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(100, 100, 'webp'), 'me.webp', 'image/webp'), $this->owner);

        $this->pair();
    }

    public function testATextFileNamedPngIsRefused(): void
    {
        $e = $this->refused(fn () => $this->service->upload((int) $this->owner->id, $this->upload('hello, not an image', 'me.png', 'image/png'), $this->owner));

        $this->assertSame('photo', $e->field);
        $this->assertSame(0, db_connect()->table('files')->countAllResults());
    }

    public function testAGifIsRefused(): void
    {
        $img = imagecreatetruecolor(100, 100);
        ob_start();
        imagegif($img);
        $gif = (string) ob_get_clean();

        $e = $this->refused(fn () => $this->service->upload((int) $this->owner->id, $this->upload($gif, 'me.png', 'image/png'), $this->owner));
        $this->assertStringContainsString('JPEG, PNG or WebP', $e->getMessage());
    }

    public function testMoreThanFiveMegabytesIsRefused(): void
    {
        // A valid PNG padded past the limit: getimagesize still reads it, the size rule must refuse it.
        $bytes = $this->image(300, 300);
        $bytes .= str_repeat("\0", ProfilePhotoService::MAX_BYTES + 1 - strlen($bytes));

        $e = $this->refused(fn () => $this->service->upload((int) $this->owner->id, $this->upload($bytes, 'me.png', 'image/png'), $this->owner));
        $this->assertStringContainsString('5 MB', $e->getMessage());
    }

    public function testATinyOrHugeImageIsRefused(): void
    {
        foreach ([[40, 200], [4001, 64]] as [$w, $h]) {
            $e = $this->refused(fn () => $this->service->upload((int) $this->owner->id, $this->upload($this->image($w, $h), 'me.png', 'image/png'), $this->owner));
            $this->assertStringContainsString('between 64 and 4000 pixels', $e->getMessage());
        }
        $this->assertSame(0, db_connect()->table('files')->countAllResults());
    }

    public function testAPhotoTakenSidewaysComesOutUpright(): void
    {
        // 200x100, red on the left half and blue on the right, tagged Orientation 6 (rotate 90 degrees clockwise to view):
        // upright it is 100x200 with red on top, so the top of the square must be red and the bottom blue.
        $img = imagecreatetruecolor(200, 100);
        imagefilledrectangle($img, 0, 0, 99, 99, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 100, 0, 199, 99, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg    = (string) ob_get_clean();
        $tiff    = 'II*' . chr(0) . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $payload = 'Exif' . chr(0) . chr(0) . $tiff;
        $tagged  = chr(0xFF) . chr(0xD8) . chr(0xFF) . chr(0xE1) . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);

        $this->service->upload((int) $this->owner->id, $this->upload($tagged, 'me.jpg', 'image/jpeg'), $this->owner);

        [$big] = $this->pair();
        $out   = imagecreatefromjpeg($this->storage->path($big['storage_key']));
        $top   = imagecolorsforindex($out, imagecolorat($out, 200, 20));
        $low   = imagecolorsforindex($out, imagecolorat($out, 200, 236));
        $this->assertGreaterThan(150, $top['red'], 'the top is red');
        $this->assertLessThan(100, $top['blue']);
        $this->assertGreaterThan(150, $low['blue'], 'the bottom is blue');
    }

    public function testALargeSidewaysPhotoIsShrunkBeforeItIsTurnedSoItFitsA128MbLimit(): void
    {
        // 4000x4000 decoded is 64 MB; turning it as well would need another 64 MB, past a 128 MB memory_limit.
        $img = imagecreatetruecolor(4000, 4000);
        imagefilledrectangle($img, 0, 0, 1999, 3999, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 2000, 0, 3999, 3999, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        unset($img);
        $tiff    = 'II*' . chr(0) . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $payload = 'Exif' . chr(0) . chr(0) . $tiff;
        $file    = $this->upload(chr(0xFF) . chr(0xD8) . chr(0xFF) . chr(0xE1) . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2), 'me.jpg', 'image/jpeg');
        unset($jpeg);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->service->upload((int) $this->owner->id, $file, $this->owner);

        $this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage() - $before, 'one decoded copy plus a small one, never two full copies');

        [$big] = $this->pair();
        $out   = imagecreatefromjpeg($this->storage->path($big['storage_key']));
        $this->assertGreaterThan(150, imagecolorsforindex($out, imagecolorat($out, 128, 20))['red'], 'still upright: red on top');
        $this->assertGreaterThan(150, imagecolorsforindex($out, imagecolorat($out, 128, 236))['blue'], 'blue at the bottom');
    }

    public function testTheStoredPhotosCarryNoExif(): void
    {
        // A JPEG with an APP1 Exif segment (minimal TIFF header plus a marker string) right after SOI.
        $jpeg    = $this->image(300, 200, 'jpeg');
        $payload = "Exif\0\0" . "II*\0\x08\0\0\0" . "\0\0" . "\0\0\0\0" . 'GPS-SECRET-48.8584N';
        $withExif = "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
        $this->assertNotFalse(getimagesize('data://image/jpeg;base64,' . base64_encode($withExif)), 'the fixture is a readable JPEG');

        $this->service->upload((int) $this->owner->id, $this->upload($withExif, 'me.jpg', 'image/jpeg'), $this->owner);

        foreach ($this->pair() as $file) {
            $stored = (string) file_get_contents($this->storage->path($file['storage_key']));
            $this->assertStringNotContainsString('Exif', $stored);
            $this->assertStringNotContainsString('GPS-SECRET', $stored);
        }
    }

    public function testANonSquareImageIsStoredSquareAt256And64(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(800, 300), 'me.png', 'image/png'), $this->owner);

        [$big, $sm] = $this->pair();
        $this->assertSame([256, 256], array_slice(getimagesize($this->storage->path($big['storage_key'])), 0, 2));
        $this->assertSame([64, 64], array_slice(getimagesize($this->storage->path($sm['storage_key'])), 0, 2));
        $this->assertSame((int) filesize($this->storage->path($big['storage_key'])), (int) $big['file_size']);
    }

    public function testReplacingLeavesOnePairAndDeletesTheOldFiles(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(300, 300), 'a.png', 'image/png'), $this->owner);
        $old = $this->pair();

        $this->service->upload((int) $this->owner->id, $this->upload($this->image(400, 400, 'jpeg'), 'b.jpg', 'image/jpeg'), $this->owner);
        $new = $this->pair();

        $this->assertNotSame($old[0]['id'], $new[0]['id']);
        $this->assertSame(2, db_connect()->table('files')->where('owner_user_id', $this->owner->id)->countAllResults());
        foreach ($old as $file) {
            $this->assertFileDoesNotExist($this->storage->path($file['storage_key']));
        }
        foreach ($new as $file) {
            $this->assertFileExists($this->storage->path($file['storage_key']));
        }
    }

    public function testRemovingClearsThePhotoAndDeletesBothFiles(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(300, 300), 'a.png', 'image/png'), $this->owner);
        $pair = $this->pair();

        $this->service->remove((int) $this->owner->id, $this->owner);

        $this->assertNull(db_connect()->table('user_profiles')->select('photo_file_id')->where('user_id', $this->owner->id)->get()->getRow('photo_file_id'));
        $this->assertSame(0, db_connect()->table('files')->countAllResults());
        foreach ($pair as $file) {
            $this->assertFileDoesNotExist($this->storage->path($file['storage_key']));
        }
        $this->assertNull($this->service->url((int) $this->owner->id, 256));
    }

    public function testOwnerActionsAreAuditedWithoutValues(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(300, 300), 'a.png', 'image/png'), $this->owner);
        $this->service->remove((int) $this->owner->id, $this->owner);

        $rows = db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['profile.photo_updated', 'profile.photo_removed'], array_column($rows, 'action'));
        foreach ($rows as $row) {
            $this->assertSame((int) $this->owner->id, (int) $row['actor_user_id']);
            $this->assertSame((string) $this->owner->id, $row['target_id']);
            $this->assertNull($row['old_values']);
            $this->assertNull($row['new_values']);
        }
    }

    public function testAdminActionsAreAuditedWithTheActorAndTarget(): void
    {
        $this->service->upload((int) $this->owner->id, $this->upload($this->image(300, 300), 'a.png', 'image/png'), $this->admin);
        $this->service->remove((int) $this->owner->id, $this->admin);

        $rows = db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['profile.photo_uploaded_by_admin', 'profile.photo_removed_by_admin'], array_column($rows, 'action'));
        foreach ($rows as $row) {
            $this->assertSame((int) $this->admin->id, (int) $row['actor_user_id']);
            $this->assertSame('user', $row['target_type']);
            $this->assertSame((string) $this->owner->id, $row['target_id']);
            $this->assertSame($this->institution, (int) $row['institution_id']);
        }
    }

    public function testUrlIsNullWithoutAPhotoOrWhilePhotosAreOffAndTheSameWhenBackOn(): void
    {
        $id = (int) $this->owner->id;
        $this->assertNull($this->service->url($id, 256));

        $this->service->upload($id, $this->upload($this->image(300, 300), 'a.png', 'image/png'), $this->owner);
        [$big] = $this->pair();
        $url   = $this->service->url($id, 256);
        $this->assertSame(site_url("people/{$id}/photo/256") . '?v=' . $big['id'], $url);
        $this->assertSame(site_url("people/{$id}/photo/64") . '?v=' . $big['id'], $this->service->url($id, 64));

        $avatar = (new AvatarData())->forMany([$id])[$id];
        $this->assertSame($this->service->url($id, 64), $avatar['photo_url']);
        $this->assertSame($url, $avatar['photo_url_lg']);

        db_connect()->table('institutions')->where('id', $this->institution)->update(['profile_photos_enabled' => 0]);
        $this->assertNull($this->service->url($id, 256));
        $this->assertNull((new AvatarData())->forMany([$id])[$id]['photo_url']);

        db_connect()->table('institutions')->where('id', $this->institution)->update(['profile_photos_enabled' => 1]);
        $this->assertSame($url, $this->service->url($id, 256));
    }
}

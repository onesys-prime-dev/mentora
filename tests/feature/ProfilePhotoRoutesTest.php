<?php

declare(strict_types=1);

use App\Services\Profiles\ProfilePhotoService;
use App\Services\Storage\StorageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * /people/{id}/photo/{64|256} (Review Focus 3): the photo is sent only to the person and to viewers
 * FR2 lets see it, only while their institution has photos on and only while it exists; turning
 * photos back on sends the same file. Avatars show it where it is allowed and initials otherwise.
 *
 * @internal
 */
final class ProfilePhotoRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        $this->buildScenario(); // $this->student and $this->instructor share a course in $this->institution

        $path = tempnam(sys_get_temp_dir(), 'pph');
        $img  = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        imagepng($img, $path);
        $file = new class ($path, 'me.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        };
        (new ProfilePhotoService())->upload((int) $this->student->id, $file, $this->student);
        @unlink($path);
    }

    protected function tearDown(): void
    {
        // Only the photos this test wrote under writable/uploads.
        $storage = new StorageService();
        foreach (db_connect()->table('files')->where('owner_user_id', $this->student->id)->get()->getResultArray() as $f) {
            $storage->delete($f['storage_key']);
        }
        $this->releaseScenario();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
    }

    /** @return array{int, string, \CodeIgniter\HTTP\ResponseInterface|null} */
    private function photo(User $viewer, int $size = 256, ?string $etag = null): array
    {
        $this->signIn($viewer);
        if ($etag !== null) {
            $this->withHeaders(['If-None-Match' => $etag]);
        }
        try {
            $r = $this->call('GET', "/people/{$this->student->id}/photo/{$size}")->response();

            return [$r->getStatusCode(), (string) $r->getBody(), $r];
        } catch (PageNotFoundException) {
            return [404, '', null];
        }
    }

    private function photosEnabled(bool $on): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['profile_photos_enabled' => $on ? 1 : 0]);
    }

    public function testThePersonAndAViewerFr2AllowsGetTheJpeg(): void
    {
        foreach ([$this->student, $this->instructor] as $viewer) {
            foreach ([256, 64] as $size) {
                [$status, $body, $r] = $this->photo($viewer, $size);
                $this->assertSame(200, $status, "viewer {$viewer->id}, {$size} px");
                $this->assertSame('image/jpeg', getimagesizefromstring($body)['mime']);
                $this->assertSame($size, getimagesizefromstring($body)[0]);
                $this->assertStringStartsWith('image/jpeg', $r->getHeaderLine('Content-Type'));
                $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
                // Revalidated every time, so photos going off or access ending takes effect at once.
                $this->assertSame('private, no-cache', $r->getHeaderLine('Cache-Control'));
                $this->assertNotSame('', $r->getHeaderLine('ETag'));
            }
        }
    }

    public function testAMatchingEtagAnswers304WithoutTheBodyAndADifferentOneSendsTheFile(): void
    {
        $etag = $this->photo($this->instructor)[2]->getHeaderLine('ETag');
        $this->assertNotSame($etag, $this->photo($this->instructor, 64)[2]->getHeaderLine('ETag'));

        [$status, $body, $r] = $this->photo($this->instructor, 256, $etag);
        $this->assertSame(304, $status);
        $this->assertSame('', $body);
        $this->assertSame('private, no-cache', $r->getHeaderLine('Cache-Control'));

        $this->assertSame(200, $this->photo($this->instructor, 256, '"other"')[0]);

        // Revalidation still checks access: photos off is a 404, not a 304.
        $this->photosEnabled(false);
        $this->assertSame(404, $this->photo($this->instructor, 256, $etag)[0]);
    }

    public function testViewersFr2ForbidsGet404(): void
    {
        $other  = PeopleFactory::person('instructor', 'Otto', 'Elsewhere', null, PeopleFactory::institution('PHX'));
        $parent = PeopleFactory::person('parent', 'Pia', 'Unlinked', null, $this->institution);

        foreach ([$other, $parent] as $viewer) {
            [$status, $body] = $this->photo($viewer);
            $this->assertSame(404, $status, "viewer {$viewer->id}");
            $this->assertSame('', $body);
        }
    }

    public function testRemovedPhotoAnswers404(): void
    {
        (new ProfilePhotoService())->remove((int) $this->student->id, $this->student);

        $this->assertSame(404, $this->photo($this->student)[0]);
        $this->assertSame(404, $this->photo($this->instructor, 64)[0]);
    }

    public function testPhotosOffAnswers404AndBackOnSendsTheSameFile(): void
    {
        [, $before] = $this->photo($this->instructor);

        $this->photosEnabled(false);
        $this->assertSame(404, $this->photo($this->instructor)[0]);
        $this->assertSame(404, $this->photo($this->student)[0]);

        $this->photosEnabled(true);
        [$status, $after] = $this->photo($this->instructor);
        $this->assertSame(200, $status);
        $this->assertSame($before, $after);
    }

    public function testAvatarsShowThePhotoWhereAllowedAndInitialsWhilePhotosAreOff(): void
    {
        $url = (new ProfilePhotoService())->url((int) $this->student->id, 256);
        $this->assertNotNull($url);

        $this->signIn($this->student);
        $own = (string) $this->call('GET', '/profile')->response()->getBody();
        $this->assertStringContainsString('src="' . esc($url, 'attr') . '"', $own);
        $this->assertMatchesRegularExpression('/alt="Photo of [^"]+"/', $own);

        $this->signIn($this->instructor);
        $card = (string) $this->call('GET', "/people/{$this->student->id}/card")->response()->getBody();
        $this->assertStringContainsString('src="' . esc($url, 'attr') . '"', $card);
        $this->assertStringContainsString('alt="Photo of ', $card);

        $this->photosEnabled(false);
        $this->signIn($this->instructor);
        $card = (string) $this->call('GET', "/people/{$this->student->id}/card")->response()->getBody();
        $this->assertStringNotContainsString('/photo/', $card);
        $this->assertStringNotContainsString('<img', $card);

        $this->signIn($this->student);
        $this->assertStringNotContainsString("people/{$this->student->id}/photo/", (string) $this->call('GET', '/profile')->response()->getBody());
    }
}

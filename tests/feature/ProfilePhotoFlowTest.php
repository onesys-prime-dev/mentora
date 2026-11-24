<?php

declare(strict_types=1);

use App\Services\Profiles\ProfileModeration;
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
 * The photo's whole life through the routes and pages (Task 4.2): the owner's dialog, remove and
 * the school switch, the administrator's upload and removal for their own institution only, and the
 * notice the owner gets. (A real multipart upload cannot pass is_uploaded_file in a test, so the
 * successful uploads go through the services with a stand-in file; the routes are tested for
 * everything that does not need the file.)
 *
 * @internal
 */
final class ProfilePhotoFlowTest extends RouteTestCase
{
    use MessagingScenario;

    private User $admin;

    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->buildScenario(); // $this->student and $this->instructor share a course in $this->institution
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $storage = new StorageService();
        foreach (db_connect()->table('files')->whereIn('owner_user_id', [$this->student->id])->get()->getResultArray() as $f) {
            $storage->delete($f['storage_key']);
        }
        array_map('unlink', array_filter($this->temps, 'is_file'));
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

    private function file(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pph');
        $img  = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        imagepng($img, $path);
        $this->temps[] = $path;

        return new class ($path, 'me.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        };
    }

    private function givePhoto(User $owner): void
    {
        (new ProfilePhotoService())->upload((int) $owner->id, $this->file(), $owner);
    }

    private function photosEnabled(bool $on): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['profile_photos_enabled' => $on ? 1 : 0]);
    }

    private function body(User $viewer, string $uri): string
    {
        $this->signIn($viewer);
        $r = $this->call('GET', $uri)->response();
        $this->assertSame(200, $r->getStatusCode(), $uri);

        return (string) $r->getBody();
    }

    /** @return array<string, string> */
    private function json(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    private function photoId(): mixed
    {
        return db_connect()->table('user_profiles')->where('user_id', $this->student->id)->get()->getRow('photo_file_id');
    }

    private function audited(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    // The owner ---------------------------------------------------------------------------------------------------

    public function testTheProfileHasThePencilAndDialogAndShowsThePhotoToTheOwnerAndAViewer(): void
    {
        $empty = $this->body($this->student, '/profile');
        $this->assertStringContainsString('data-photo-pencil', $empty);
        $this->assertStringContainsString('data-photo-crop', $empty);
        $this->assertStringContainsString('Choose a JPEG, PNG or WebP image up to 5 MB.', $empty);
        $this->assertStringNotContainsString('data-photo-remove', $empty);

        $this->givePhoto($this->student);
        $url = (new ProfilePhotoService())->url((int) $this->student->id, 256);

        $own = $this->body($this->student, '/profile');
        $this->assertStringContainsString('src="' . esc($url, 'attr') . '"', $own);
        $this->assertStringContainsString('data-photo-remove', $own);

        $card = $this->body($this->instructor, "/people/{$this->student->id}/card");
        $this->assertStringContainsString('src="' . esc($url, 'attr') . '"', $card);
    }

    public function testRemovingBringsBackInitialsAndIsAudited(): void
    {
        $this->givePhoto($this->student);
        $this->signIn($this->student);

        $r = $this->withHeaders($this->json())->step('POST', 'profile/photo/remove');

        $r->assertStatus(200);
        $this->assertNull($this->photoId());
        $this->assertSame(1, $this->audited('profile.photo_removed'));
        $this->assertStringNotContainsString("people/{$this->student->id}/photo/", $this->body($this->student, '/profile'));
    }

    public function testNoFileIsA422NamingTheProblem(): void
    {
        $this->signIn($this->student);

        $r = $this->withHeaders($this->json())->step('POST', 'profile/photo');

        $r->assertStatus(422);
        $this->assertSame('Choose an image file.', json_decode($r->getJSON(), true)['errors']['photo']);
    }

    public function testAReadOnlyInstitutionRefusesTheRemoval(): void
    {
        $this->givePhoto($this->student);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->student);

        $this->step('POST', 'profile/photo/remove')->assertRedirect();

        $this->assertNotNull($this->photoId());
        $this->assertStringContainsString('read-only', (string) session('error'));
    }

    public function testAnUploadIsRefusedWhilePhotosAreOff(): void
    {
        $this->photosEnabled(false);

        try {
            (new ProfilePhotoService())->upload((int) $this->student->id, $this->file(), $this->student);
            $this->fail('Expected a refusal.');
        } catch (App\Services\Profiles\ProfileEditException $e) {
            $this->assertStringContainsString('turned profile photos off', $e->getMessage());
        }
        $this->assertNull($this->photoId());
    }

    // The school's switch -----------------------------------------------------------------------------------------

    public function testOffHidesEveryPhotoUrlKeepsTheFilesAndOnBringsTheSameUrlBack(): void
    {
        $this->givePhoto($this->student);
        $url   = (new ProfilePhotoService())->url((int) $this->student->id, 256);
        $key   = db_connect()->table('files')->where('id', $this->photoId())->get()->getRow('storage_key');
        $files = db_connect()->table('files')->where('owner_user_id', $this->student->id)->countAllResults();

        $this->photosEnabled(false);

        foreach ([[$this->student, '/profile'], [$this->instructor, "/people/{$this->student->id}/card"], [$this->instructor, "/people/{$this->student->id}/panel"], [$this->instructor, "/people/{$this->student->id}"]] as [$who, $uri]) {
            $this->assertStringNotContainsString("people/{$this->student->id}/photo/", $this->body($who, $uri), $uri);
        }
        $this->signIn($this->instructor);
        try {
            $this->assertSame(404, $this->call('GET', "/people/{$this->student->id}/photo/256")->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertTrue(is_file((new StorageService())->path($key)), 'the file is kept on disk');
        $this->assertSame($files, db_connect()->table('files')->where('owner_user_id', $this->student->id)->countAllResults(), 'and in files');

        $this->photosEnabled(true);
        $this->assertSame($url, (new ProfilePhotoService())->url((int) $this->student->id, 256));
        $this->assertStringContainsString('src="' . esc($url, 'attr') . '"', $this->body($this->student, '/profile'));
    }

    public function testThePhotoControlExplainsOffAndOffersNoUpload(): void
    {
        $this->photosEnabled(false);
        $body = $this->body($this->student, '/profile');

        $this->assertStringContainsString('Your school has turned profile photos off. Your photo is kept and will show again if they turn them back on.', $body);
        $this->assertStringNotContainsString('data-photo-crop', $body);
    }

    public function testTheChecklistOffersAPhotoOnlyWhileThereIsNoneAndPhotosAreOn(): void
    {
        $this->assertStringContainsString('data-checklist-item="photo"', $this->body($this->student, '/profile'));

        $this->photosEnabled(false);
        $this->assertStringNotContainsString('data-checklist-item="photo"', $this->body($this->student, '/profile'));

        $this->photosEnabled(true);
        $this->givePhoto($this->student);
        $this->assertStringNotContainsString('data-checklist-item="photo"', $this->body($this->student, '/profile'));
    }

    public function testTheInstitutionSettingsSwitchSavesAndIsAudited(): void
    {
        $page = $this->body($this->admin, '/institution/settings');
        $this->assertStringContainsString('role="switch"', $page);
        $this->assertStringContainsString("Show people's profile photos. When off, everyone sees initials; photos are kept.", $page);

        $name = db_connect()->table('institutions')->where('id', $this->institution)->get()->getRow('name');
        $this->signIn($this->admin);
        $this->step('POST', 'institution/settings', ['name' => $name, 'profile_photos_enabled' => '0']);

        $this->assertSame('0', (string) db_connect()->table('institutions')->where('id', $this->institution)->get()->getRow('profile_photos_enabled'));
        $row = db_connect()->table('audit_logs')->where('action', 'institution.profile_updated')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringContainsString('profile_photos_enabled', (string) json_encode($row));

        $this->step('POST', 'institution/settings', ['name' => $name, 'profile_photos_enabled' => '1']);
        $this->assertSame('1', (string) db_connect()->table('institutions')->where('id', $this->institution)->get()->getRow('profile_photos_enabled'));
    }

    // The administrator -------------------------------------------------------------------------------------------

    public function testAnAdminSeesPhotoActionsOnThePersonPageAndALinkInTheModal(): void
    {
        $this->givePhoto($this->student);

        $page = $this->body($this->admin, "/people/{$this->student->id}");
        $this->assertStringContainsString('data-dialog-open="person-photo-upload"', $page);
        $this->assertStringContainsString('data-dialog-open="person-photo-remove"', $page);
        $this->assertStringContainsString("institution/people/{$this->student->id}/photo/remove", $page);

        $panel = $this->body($this->admin, "/people/{$this->student->id}/panel");
        $this->assertStringContainsString('data-admin-photo-link', $panel);

        $this->assertStringNotContainsString('person-photo-upload', $this->body($this->instructor, "/people/{$this->student->id}"));
    }

    public function testTheAdminRemovesAPhotoAndTheOwnerIsToldInTheAppAndByEmail(): void
    {
        $this->givePhoto($this->student);
        $this->signIn($this->admin);

        $this->withHeaders($this->json())->step('POST', "institution/people/{$this->student->id}/photo/remove")->assertStatus(200);

        $this->assertNull($this->photoId());
        $this->assertSame(1, $this->audited('profile.photo_removed_by_admin'));
        $bell = db_connect()->table('notifications')->where('user_id', $this->student->id)->where('type', 'profile.photo')->get()->getRowArray();
        $this->assertSame('Your photo was removed by your school', $bell['title']);
        $this->assertSame(site_url('profile'), \App\Services\Notifications\NotificationService::href((string) $bell['link']));
        $mail = db_connect()->table('mail_outbox')->where('user_id', $this->student->id)->where('kind', 'photo')->get()->getRowArray();
        $this->assertSame('removed', $mail['detail']);
    }

    public function testTheAdminAddsAPhotoForSomeoneAndTheyAreTold(): void
    {
        (new ProfileModeration())->uploadPhoto((int) $this->student->id, $this->file(), $this->admin);

        $this->assertNotNull($this->photoId());
        $this->assertSame(1, $this->audited('profile.photo_uploaded_by_admin'));
        $this->assertSame('Your school added a profile photo for you', db_connect()->table('notifications')->where('user_id', $this->student->id)->where('type', 'profile.photo')->get()->getRow('title'));
        $this->assertSame('added', db_connect()->table('mail_outbox')->where('user_id', $this->student->id)->where('kind', 'photo')->get()->getRow('detail'));
    }

    public function testAnAdminOfAnotherInstitutionGetsA404(): void
    {
        $this->givePhoto($this->student);
        $other = PeopleFactory::person('institutional_admin', 'Olga', 'Elsewhere', null, PeopleFactory::institution('PFX'));

        try {
            (new ProfileModeration())->removePhoto((int) $this->student->id, $other);
            $this->fail('Expected a 404.');
        } catch (PageNotFoundException) {
            $this->assertNotNull($this->photoId());
        }

        $this->signIn($other);
        try {
            $status = $this->step('POST', "institution/people/{$this->student->id}/photo/remove")->response()->getStatusCode();
            $this->assertSame(404, $status);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull($this->photoId());
        $this->assertSame(0, db_connect()->table('notifications')->where('type', 'profile.photo')->countAllResults());
    }

    public function testAPersonWithoutPeopleManageIsRefused(): void
    {
        $this->givePhoto($this->student);
        $this->signIn($this->instructor);

        try {
            $status = $this->step('POST', "institution/people/{$this->student->id}/photo/remove")->response()->getStatusCode();
            $this->assertNotSame(200, $status);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull($this->photoId());
    }

    public function testAReadOnlyInstitutionRefusesTheAdminToo(): void
    {
        $this->givePhoto($this->student);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->admin);

        $this->step('POST', "institution/people/{$this->student->id}/photo/remove")->assertRedirect();

        $this->assertNotNull($this->photoId());
    }
}

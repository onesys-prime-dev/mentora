<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionProfileService;
use App\Services\Institutions\StructureException;
use App\Services\Storage\StorageService;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The institution's profile and branding: what is accepted and refused, that
 * the code never changes, colours that are the Mentora defaults store
 * nothing, the logo's rules and that replacing it frees the old one, and the
 * audit trail.
 *
 * @internal
 */
final class InstitutionProfileServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $actor;

    private InstitutionProfileService $service;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('PRFA');
        $this->other       = SisFixture::institution('PRFB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        // A storage that keeps files in a temp folder instead of moving PHP uploads.
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-logo-' . bin2hex(random_bytes(4));
        mkdir($folder, 0770, true);
        $this->written[] = $folder;

        $this->service = new InstitutionProfileService(new class ($folder) extends StorageService {
            public function __construct(private readonly string $folder)
            {
            }

            public function upload(\CodeIgniter\Files\File $file, string $directory): array
            {
                $name = bin2hex(random_bytes(6)) . '.png';
                copy($file instanceof UploadedFile ? $file->getTempName() : $file->getRealPath(), $this->folder . DIRECTORY_SEPARATOR . $name);

                return ['storage_key' => "{$directory}/{$name}", 'original_name' => $file instanceof UploadedFile ? $file->getClientName() : $name, 'filename' => $name, 'mime_type' => (string) $file->getMimeType(), 'extension' => 'png', 'size' => $file->getSize()];
            }

            public function path(string $storageKey): string
            {
                return $this->folder . DIRECTORY_SEPARATOR . basename($storageKey);
            }
        });

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($path);
        }

        parent::tearDown();
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    private function row(?int $id = null): array
    {
        return db_connect()->table('institutions')->where('id', $id ?? $this->institution)->get()->getRowArray();
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /**
     * A real image on disk, presented as an upload that PHP vouches for.
     */
    private function image(int $width = 200, int $height = 100, string $type = 'png', ?int $bytes = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        $this->written[] = $path;

        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 64, 166));
        $type === 'jpeg' ? imagejpeg($img, $path) : imagepng($img, $path);

        if ($bytes !== null) {
            file_put_contents($path, str_repeat('x', $bytes), FILE_APPEND);
        }

        return new class ($path, 'logo.' . $type, $type === 'jpeg' ? 'image/jpeg' : 'image/png', (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        };
    }

    public function testTheProfileIsSavedAndTheCodeIsLeftAlone(): void
    {
        $this->service->saveProfile($this->institution, [
            'name' => '  Lakeside College ', 'contact_email' => 'office@lakeside.test', 'contact_phone' => '+63 (2) 8123-4567', 'address' => '12 Shore Road',
            'code' => 'HACKED',
        ], $this->actor);

        $row = $this->row();

        $this->assertSame('Lakeside College', $row['name']);
        $this->assertSame('office@lakeside.test', $row['contact_email']);
        $this->assertSame('+63 (2) 8123-4567', $row['contact_phone']);
        $this->assertSame('12 Shore Road', $row['address']);
        $this->assertSame('PRFA', $row['code'], 'the code is immutable');
        $this->assertSame(1, $this->audit('institution.profile_updated'));
    }

    public function testEmptyContactDetailsAreStoredAsNothing(): void
    {
        $this->service->saveProfile($this->institution, ['name' => 'Lakeside', 'contact_email' => 'a@b.test', 'contact_phone' => '12345', 'address' => 'x'], $this->actor);
        $this->service->saveProfile($this->institution, ['name' => 'Lakeside', 'contact_email' => '', 'contact_phone' => '', 'address' => ''], $this->actor);

        $row = $this->row();

        $this->assertNull($row['contact_email']);
        $this->assertNull($row['contact_phone']);
        $this->assertNull($row['address']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadProfiles(): iterable
    {
        yield 'a one letter name' => [['name' => 'A'], 'name'];
        yield 'a name that is too long' => [['name' => str_repeat('x', 151)], 'name'];
        yield 'an email that is not one' => [['name' => 'Lakeside', 'contact_email' => 'nope'], 'contact_email'];
        yield 'a phone with letters' => [['name' => 'Lakeside', 'contact_phone' => '555-CALL-NOW'], 'contact_phone'];
        yield 'an address that is too long' => [['name' => 'Lakeside', 'address' => str_repeat('x', 501)], 'address'];
    }

    /**
     * @param array<string, string> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadProfiles')]
    public function testABadProfileIsRefusedBesideTheField(array $input, string $field): void
    {
        $before = $this->row();

        $e = $this->refused(fn () => $this->service->saveProfile($this->institution, $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame($before['name'], $this->row()['name']);
    }

    public function testColoursAreSavedAndAuditedAndTheDefaultsStoreNothing(): void
    {
        $this->service->saveBranding($this->institution, ['primary' => '#8b1e3f', 'accent' => '#1B5E20'], $this->actor);

        $row = $this->row();
        $this->assertSame('#8B1E3F', $row['brand_primary']);
        $this->assertSame('#1B5E20', $row['brand_accent']);
        $this->assertSame(1, $this->audit('institution.branding_updated'));

        $this->service->saveBranding($this->institution, ['primary' => '#0040a6', 'accent' => '#2e7d4f'], $this->actor);

        $row = $this->row();
        $this->assertNull($row['brand_primary'], 'the Mentora colour is not stored, so it follows the platform');
        $this->assertNull($row['brand_accent']);
    }

    public function testAColourThatIsTooLightIsRefusedAndNothingIsSaved(): void
    {
        $e = $this->refused(fn () => $this->service->saveBranding($this->institution, ['primary' => '#7FB2FF', 'accent' => '#2E7D4F'], $this->actor));

        $this->assertArrayHasKey('primary', $e->errors());
        $this->assertStringContainsString('too light', $e->errors()['primary']);
        $this->assertNull($this->row()['brand_primary']);

        $e = $this->refused(fn () => $this->service->saveBranding($this->institution, ['primary' => '#0040A6', 'accent' => '#FFD700'], $this->actor));

        $this->assertArrayHasKey('accent', $e->errors());
        $this->assertSame(0, $this->audit('institution.branding_updated'));
    }

    public function testResettingBrandingGoesBackToMentoraAndKeepsTheLogo(): void
    {
        $this->service->setLogo($this->institution, $this->image(), $this->actor);
        $this->service->saveBranding($this->institution, ['primary' => '#8B1E3F', 'accent' => '#1B5E20'], $this->actor);

        $this->service->resetBranding($this->institution, $this->actor);

        $row = $this->row();
        $this->assertNull($row['brand_primary']);
        $this->assertNull($row['brand_accent']);
        $this->assertNotNull($row['logo_file_id']);
        $this->assertFalse($this->service->get($this->institution)['custom']);
    }

    public function testALogoIsStoredAsAFileOfTheInstitutionAndCountsAsStorage(): void
    {
        $this->service->setLogo($this->institution, $this->image(), $this->actor);

        $row  = $this->row();
        $file = db_connect()->table('files')->where('id', $row['logo_file_id'])->get()->getRowArray();

        $this->assertNotNull($file);
        $this->assertSame('active', $file['status']);
        $this->assertSame((int) $this->actor->id, (int) $file['owner_user_id']);
        $this->assertSame(1, $this->audit('institution.logo_changed'));

        $usage = (new \App\Services\Subscriptions\PlanLimits())->usage($this->institution);
        $this->assertSame((int) $file['file_size'], $usage['storage_bytes'], 'the logo is counted against the plan storage');
    }

    public function testReplacingTheLogoFreesTheOldOne(): void
    {
        $this->service->setLogo($this->institution, $this->image(200, 100), $this->actor);
        $first = (int) $this->row()['logo_file_id'];

        $this->service->setLogo($this->institution, $this->image(120, 120), $this->actor);

        $this->assertNotSame($first, (int) $this->row()['logo_file_id']);
        $this->assertSame(0, db_connect()->table('files')->where('id', $first)->countAllResults(), 'the replaced file is gone');
    }

    public function testRemovingTheLogoFreesIt(): void
    {
        $this->service->setLogo($this->institution, $this->image(), $this->actor);
        $id = (int) $this->row()['logo_file_id'];

        $this->service->removeLogo($this->institution, $this->actor);

        $this->assertNull($this->row()['logo_file_id']);
        $this->assertSame(0, db_connect()->table('files')->where('id', $id)->countAllResults());
    }

    public function testALogoThatIsTooBigOrTheWrongKindIsRefused(): void
    {
        $tooHeavy = $this->image(100, 100, 'png', InstitutionProfileService::LOGO_MAX_BYTES + 10);
        $e        = $this->refused(fn () => $this->service->setLogo($this->institution, $tooHeavy, $this->actor));
        $this->assertStringContainsString('1 MB', $e->errors()['logo']);

        $huge = $this->image(InstitutionProfileService::LOGO_MAX_PIXELS + 1, 100);
        $e    = $this->refused(fn () => $this->service->setLogo($this->institution, $huge, $this->actor));
        $this->assertStringContainsString('pixels', $e->errors()['logo']);

        $tiny = $this->image(10, 10);
        $this->refused(fn () => $this->service->setLogo($this->institution, $tiny, $this->actor));

        $e = $this->refused(fn () => $this->service->setLogo($this->institution, null, $this->actor));
        $this->assertArrayHasKey('logo', $e->errors());

        $this->assertNull($this->row()['logo_file_id']);
    }

    public function testAnImageClaimingToBeSomethingElseIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        $this->written[] = $path;
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $svg = new class ($path, 'logo.svg', 'image/svg+xml', (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return 'image/svg+xml';
            }
        };

        $e = $this->refused(fn () => $this->service->setLogo($this->institution, $svg, $this->actor));

        $this->assertStringContainsString('PNG, JPEG or WebP', $e->errors()['logo']);
    }

    public function testAnotherInstitutionIsNeverTouched(): void
    {
        $this->service->saveBranding($this->institution, ['primary' => '#8B1E3F', 'accent' => '#1B5E20'], $this->actor);
        $this->service->saveProfile($this->institution, ['name' => 'Lakeside'], $this->actor);

        $theirs = $this->row($this->other);

        $this->assertNull($theirs['brand_primary']);
        $this->assertSame('PRFB College', $theirs['name']);
    }
}

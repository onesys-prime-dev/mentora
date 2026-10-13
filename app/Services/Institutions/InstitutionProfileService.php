<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Files\FileModel;
use App\Services\Storage\StorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;

/**
 * What an institution says about itself, as its institutional administrator
 * keeps it: the profile (display name, contact email and phone, address) and
 * the branding (logo and two colours).
 *
 * Rules:
 *  - The code is never changed here.
 *  - Contact details are optional; an email must be an email, a phone number
 *    only digits, spaces and + - ( ).
 *  - Colours are written as hex and must be readable (BrandColours): a colour
 *    that is too light is refused with the contrast it would give. Choosing
 *    the Mentora colours stores nothing, so the institution follows the
 *    platform if it ever changes.
 *  - The logo is a PNG, JPEG or WebP of at most 1 MB and 2000 pixels a side
 *    (SVG is left out: it can carry script). It is a file of the institution,
 *    so it counts against its storage; replacing or removing it frees the old
 *    one.
 *  - Every change is audited with old and new values.
 */
class InstitutionProfileService
{
    public const LOGO_MAX_BYTES = 1048576;

    public const LOGO_MAX_PIXELS = 2000;

    public const LOGO_MIN_PIXELS = 32;

    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private readonly ?StorageService $storage = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $institutionId): array
    {
        $row = db_connect()->table('institutions')->where('id', $institutionId)->get()->getRowArray() ?? [];

        $row['primary'] = $row['brand_primary'] ?? BrandColours::DEFAULT_PRIMARY;
        $row['accent']  = $row['brand_accent'] ?? BrandColours::DEFAULT_ACCENT;
        $row['custom']  = ($row['brand_primary'] ?? null) !== null || ($row['brand_accent'] ?? null) !== null;

        return $row;
    }

    /**
     * @param array<string, mixed> $input name, contact_email, contact_phone, address
     *
     * @throws StructureException
     */
    public function saveProfile(int $institutionId, array $input, ?User $actor = null): void
    {
        $name    = trim((string) ($input['name'] ?? ''));
        $email   = trim((string) ($input['contact_email'] ?? ''));
        $phone   = trim((string) ($input['contact_phone'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw StructureException::field('name', 'The name must be 2 to 150 characters.');
        }

        if ($email !== '' && (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw StructureException::field('contact_email', 'Enter a valid email address, or leave it empty.');
        }

        if ($phone !== '' && (mb_strlen($phone) > 40 || preg_match('/^[0-9 +().-]{5,40}$/', $phone) !== 1)) {
            throw StructureException::field('contact_phone', 'A phone number may hold digits, spaces and + - ( ) only.');
        }

        if (mb_strlen($address) > 500) {
            throw StructureException::field('address', 'The address can be at most 500 characters.');
        }

        $new = [
            'name'          => $name,
            'contact_email' => $email === '' ? null : $email,
            'contact_phone' => $phone === '' ? null : $phone,
            'address'       => $address === '' ? null : $address,
        ];

        $this->update($institutionId, $new, 'institution.profile_updated', $actor);
    }

    /**
     * @param array<string, mixed> $input primary, accent
     *
     * @throws StructureException
     */
    public function saveBranding(int $institutionId, array $input, ?User $actor = null): void
    {
        $primary = BrandColours::normalise((string) ($input['primary'] ?? BrandColours::DEFAULT_PRIMARY));
        $accent  = BrandColours::normalise((string) ($input['accent'] ?? BrandColours::DEFAULT_ACCENT));

        $problem = BrandColours::primaryProblem((string) ($input['primary'] ?? ''));

        if ($problem !== null) {
            throw StructureException::field('primary', $problem);
        }

        $problem = BrandColours::accentProblem((string) ($input['accent'] ?? ''));

        if ($problem !== null) {
            throw StructureException::field('accent', $problem);
        }

        $this->update($institutionId, [
            'brand_primary' => $primary === BrandColours::DEFAULT_PRIMARY ? null : $primary,
            'brand_accent'  => $accent === BrandColours::DEFAULT_ACCENT ? null : $accent,
        ], 'institution.branding_updated', $actor);
    }

    /** Back to the Mentora colours (the logo is left). */
    public function resetBranding(int $institutionId, ?User $actor = null): void
    {
        $this->update($institutionId, ['brand_primary' => null, 'brand_accent' => null], 'institution.branding_updated', $actor);
    }

    /**
     * Replaces the logo.
     *
     * @throws StructureException
     * @throws \App\Exceptions\PlanLimitExceeded when there is no storage left
     */
    public function setLogo(int $institutionId, ?UploadedFile $file, ?User $actor = null): void
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw StructureException::field('logo', 'Choose an image file.');
        }

        if (! $file->isValid()) {
            throw StructureException::field('logo', 'The image could not be uploaded: ' . $file->getErrorString() . '.');
        }

        $mime = (string) $file->getMimeType();

        if (! isset(self::LOGO_TYPES[$mime])) {
            throw StructureException::field('logo', 'The logo must be a PNG, JPEG or WebP image.');
        }

        if ($file->getSize() > self::LOGO_MAX_BYTES) {
            throw StructureException::field('logo', 'The logo can be at most 1 MB.');
        }

        $size = @getimagesize($file->getTempName());

        if ($size === false) {
            throw StructureException::field('logo', 'That file is not a readable image.');
        }

        if (max($size[0], $size[1]) > self::LOGO_MAX_PIXELS || min($size[0], $size[1]) < self::LOGO_MIN_PIXELS) {
            throw StructureException::field('logo', 'The logo must be between ' . self::LOGO_MIN_PIXELS . ' and ' . self::LOGO_MAX_PIXELS . ' pixels on each side.');
        }

        $stored = ($this->storage ?? new StorageService())->upload($file, 'institution-logos');

        $files = new FileModel();
        $files->insert([
            'owner_user_id'     => ($actor ?? auth()->user())?->id,
            'original_filename' => $stored['original_name'],
            'stored_filename'   => $stored['filename'],
            'mime_type'         => $stored['mime_type'],
            'file_size'         => $stored['size'],
            'storage_key'       => $stored['storage_key'],
            'status'            => 'active',
        ]);

        $fileId = (int) $files->getInsertID();
        $old    = $this->get($institutionId)['logo_file_id'];

        $this->update($institutionId, ['logo_file_id' => $fileId], 'institution.logo_changed', $actor);

        $this->release($old);
    }

    /** Takes the logo away. */
    public function removeLogo(int $institutionId, ?User $actor = null): void
    {
        $old = $this->get($institutionId)['logo_file_id'];

        $this->update($institutionId, ['logo_file_id' => null], 'institution.logo_changed', $actor);

        $this->release($old);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Writes only what changed, and audits it.
     *
     * @param array<string, mixed> $new
     */
    private function update(int $institutionId, array $new, string $action, ?User $actor): void
    {
        $before = $this->get($institutionId);

        $old     = [];
        $changed = [];

        foreach ($new as $field => $value) {
            if ((string) ($before[$field] ?? '') !== (string) ($value ?? '')) {
                $old[$field]     = $before[$field] ?? null;
                $changed[$field] = $value;
            }
        }

        if ($changed === []) {
            return;
        }

        db_connect()->table('institutions')->where('id', $institutionId)->update($changed + ['updated_at' => date('Y-m-d H:i:s')]);

        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $changed,
        ]);
    }

    /** Frees a logo file nothing uses any more. */
    private function release(mixed $fileId): void
    {
        if ($fileId !== null && (int) $fileId > 0) {
            ($this->storage ?? new StorageService())->purgeFiles([(int) $fileId]);
        }
    }
}

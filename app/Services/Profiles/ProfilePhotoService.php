<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Models\Files\FileModel;
use App\Services\Storage\StorageService;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;

/**
 * A person's profile photo (FR6, D6).
 *
 *  - Accepted by content (getimagesize of the upload), never by its name or the browser's type:
 *    JPEG, PNG or WebP, at most 5 MB, 64 to 4000 pixels a side.
 *  - Centre-cropped square and re-encoded from pixels to JPEG at 256 and 64 px, so EXIF/GPS never
 *    survives. Both are files rows owned by the subject; user_profiles.photo_file_id points at the
 *    256 one, and the 64 one sits beside it as "{name}-64.jpg" (D6 allows no other column).
 *  - Served only through /people/{id}/photo/{size} (ProfilePhotoController), never by a public URL.
 *  - Who may call upload()/remove() is the controller's to decide; this records the actor.
 */
class ProfilePhotoService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIN_PIXELS = 64;

    public const MAX_PIXELS = 4000;

    private const SIZES = [256, 64];

    /** The longest side an upload is worked on at, after decoding. */
    private const WORK_PIXELS = 1024;

    private const FOLDER = 'profile-photos';

    /**
     * The subject's institution's photo switch, for a query over user_profiles aliased `up`
     * (the first membership of a live institution, as PlanLimits::institutionsOf picks it).
     */
    public const ENABLED_SQL = '(SELECT i.profile_photos_enabled FROM institution_users iu JOIN institutions i ON i.id = iu.institution_id'
        . ' WHERE iu.user_id = up.user_id AND i.deleted_at IS NULL ORDER BY iu.id LIMIT 1)';

    public function __construct(private readonly ?StorageService $storage = null)
    {
    }

    /**
     * @throws ProfileEditException
     * @throws \App\Exceptions\PlanLimitExceeded when the school has no storage left
     */
    public function upload(int $subjectId, UploadedFile $file, User $actor): void
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new ProfileEditException('Choose an image file.', 'photo');
        }
        if (! $file->isValid()) {
            throw new ProfileEditException('The photo could not be uploaded: ' . $file->getErrorString() . '.', 'photo');
        }

        if (! $this->state($subjectId)['on']) {
            throw new ProfileEditException('Your school has turned profile photos off.', 'photo');
        }

        $temp = $file->getTempName();
        if ((int) @filesize($temp) > self::MAX_BYTES) {
            throw new ProfileEditException('The photo can be at most 5 MB.', 'photo');
        }

        $info = @getimagesize($temp);
        $read = match ($info['mime'] ?? null) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default      => null,
        };
        if ($read === null) {
            throw new ProfileEditException('The photo must be a JPEG, PNG or WebP image.', 'photo');
        }
        if (! function_exists($read)) {
            throw new ProfileEditException('This server cannot read that kind of image. Choose a JPEG or PNG.', 'photo');
        }
        if (min($info[0], $info[1]) < self::MIN_PIXELS || max($info[0], $info[1]) > self::MAX_PIXELS) {
            throw new ProfileEditException('The photo must be between ' . self::MIN_PIXELS . ' and ' . self::MAX_PIXELS . ' pixels on each side.', 'photo');
        }

        $source = @$read($temp);
        if ($source === false) {
            throw new ProfileEditException('That file is not a readable image.', 'photo');
        }

        $db  = db_connect();
        $old = $db->table('user_profiles')->select('photo_file_id')->where('user_id', $subjectId)->get()->getRowArray();
        if ($old === null) {
            throw new ProfileEditException('There is no profile to add a photo to.', 'photo');
        }

        // Shrunk before it is turned, so a big photo never needs two full-size copies at once (a 4000 px square is
        // 64 MB decoded, and the server's memory_limit may be 128 MB).
        $source = $this->shrink($source);

        if ($info['mime'] === 'image/jpeg') {
            $source = $this->upright($source, $temp);
        }

        $encoded = $this->squares($source, imagesx($source), imagesy($source));
        (new PlanLimits())->assertCanStore($actor, array_sum(array_map('strlen', $encoded)));

        $storage = $this->storage();
        $name    = bin2hex(random_bytes(16));
        $keys    = [];
        foreach ($encoded as $size => $bytes) {
            $filename     = $name . ($size === 64 ? '-64' : '') . '.jpg';
            $keys[$size]  = [$filename, self::FOLDER . '/' . $filename];
            $path         = $storage->path($keys[$size][1]);
            is_dir(dirname($path)) || mkdir(dirname($path), 0770, true);
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                array_map(static fn (array $k) => $storage->delete($k[1]), $keys);

                throw new ProfileEditException('The photo could not be saved. Try again.', 'photo');
            }
        }

        $files = new FileModel();
        $ids   = [];
        $saved = false;
        try {
            $db->transStart();
            foreach ($encoded as $size => $bytes) {
                $files->insert([
                    'owner_user_id'     => $subjectId,
                    'original_filename' => "profile-photo-{$size}.jpg",
                    'stored_filename'   => $keys[$size][0],
                    'mime_type'         => 'image/jpeg',
                    'file_size'         => strlen($bytes),
                    'storage_key'       => $keys[$size][1],
                    'status'            => 'active',
                ]);
                $ids[$size] = (int) $files->getInsertID();
            }
            $db->table('user_profiles')->where('user_id', $subjectId)->update(['photo_file_id' => $ids[256], 'updated_at' => date('Y-m-d H:i:s')]);
            $db->transComplete();
            $saved = $db->transStatus();
        } finally {
            // Whether the transaction failed or threw, the new files must not be left behind.
            if (! $saved) {
                foreach ($keys as [, $key]) {
                    $storage->delete($key);
                }
            }
        }
        if (! $saved) {
            throw new ProfileEditException('The photo could not be saved. Try again.', 'photo');
        }

        $this->release($old['photo_file_id']);
        $this->audit($actor, $subjectId, 'updated', 'uploaded_by_admin');
    }

    public function remove(int $subjectId, User $actor): void
    {
        $db  = db_connect();
        $old = $db->table('user_profiles')->select('photo_file_id')->where('user_id', $subjectId)->get()->getRow('photo_file_id');
        if ($old === null) {
            return;
        }

        $db->table('user_profiles')->where('user_id', $subjectId)->update(['photo_file_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->release($old);
        $this->audit($actor, $subjectId, 'removed', 'removed_by_admin');
    }

    /**
     * Whether the subject has a stored photo and whether their institution shows photos, for the controls
     * (the photo is kept while photos are off, so "has" does not depend on "on").
     *
     * @return array{has: bool, on: bool}
     */
    public function state(int $subjectId): array
    {
        $row = db_connect()->table('user_profiles up')->select('up.photo_file_id, ' . self::ENABLED_SQL . ' AS photos_on', false)
            ->where('up.user_id', $subjectId)->get()->getRowArray();

        return ['has' => $row !== null && $row['photo_file_id'] !== null, 'on' => $row !== null && (int) $row['photos_on'] === 1];
    }

    /** The photo's URL at 64 or 256 px, or null when there is none or the subject's institution has photos off. */
    public function url(int $subjectId, int $size): ?string
    {
        $row = db_connect()->table('user_profiles up')->select('up.photo_file_id, ' . self::ENABLED_SQL . ' AS photos_on', false)
            ->where('up.user_id', $subjectId)->get()->getRowArray();

        return $row === null ? null : self::urlFor($subjectId, $row['photo_file_id'], $row['photos_on'], $size);
    }

    /** url() from values already read (AvatarData's batch). The version changes on replace, so browsers may cache. */
    public static function urlFor(int $subjectId, mixed $photoFileId, mixed $photosOn, int $size): ?string
    {
        if ($photoFileId === null || (int) $photosOn !== 1) {
            return null;
        }

        return site_url("people/{$subjectId}/photo/" . ($size >= 128 ? 256 : 64)) . '?v=' . (int) $photoFileId;
    }

    /**
     * The stored file a /people/{id}/photo/{size} request should send, when the institution allows photos;
     * the caller has already checked the viewer may see the photo.
     *
     * @return array<string, mixed>|null the files row
     */
    public function file(int $subjectId, int $size): ?array
    {
        $row = db_connect()->table('user_profiles up')->select('f.storage_key, ' . self::ENABLED_SQL . ' AS photos_on', false)
            ->join('files f', 'f.id = up.photo_file_id')->where('up.user_id', $subjectId)->get()->getRowArray();
        if ($row === null || (int) $row['photos_on'] !== 1) {
            return null;
        }

        $key  = $size === 64 ? $this->smallKey($row['storage_key']) : $row['storage_key'];
        $file = db_connect()->table('files')->where('storage_key', $key)->where('status', 'active')->get()->getRowArray();

        return $file !== null && is_file($this->storage()->path($key)) ? $file : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** The image scaled down to at most WORK_PIXELS on its longer side (the stored sizes are far smaller). */
    private function shrink(\GdImage $img): \GdImage
    {
        $w     = imagesx($img);
        $h     = imagesy($img);
        $ratio = self::WORK_PIXELS / max($w, $h);

        return $ratio >= 1 ? $img : (imagescale($img, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))) ?: $img);
    }

    /** A JPEG's EXIF Orientation applied to its pixels, so a sideways phone photo is stored upright. */
    private function upright(\GdImage $img, string $path): \GdImage
    {
        $orientation = function_exists('exif_read_data') ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $flip        = static fn (\GdImage $i, int $mode): \GdImage => imageflip($i, $mode) ? $i : $i;
        $turn        = static fn (\GdImage $i, int $deg): \GdImage => imagerotate($i, $deg, 0) ?: $i;

        return match ($orientation) {
            2       => $flip($img, IMG_FLIP_HORIZONTAL),
            3       => $turn($img, 180),
            4       => $flip($img, IMG_FLIP_VERTICAL),
            5       => $turn($flip($img, IMG_FLIP_HORIZONTAL), 90),
            6       => $turn($img, -90),
            7       => $turn($flip($img, IMG_FLIP_HORIZONTAL), -90),
            8       => $turn($img, 90),
            default => $img,
        };
    }

    /**
     * The largest centred square, resampled to each size and written as JPEG.
     *
     * @return array<int, string> size => JPEG bytes
     */
    private function squares(\GdImage $source, int $width, int $height): array
    {
        $side = min($width, $height);
        $out  = [];
        foreach (self::SIZES as $size) {
            $img = imagecreatetruecolor($size, $size);
            // Transparent PNG/WebP pixels become white rather than black.
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagecopyresampled($img, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);
            ob_start();
            imagejpeg($img, null, 85);
            $out[$size] = (string) ob_get_clean();
        }

        return $out;
    }

    private function smallKey(string $storageKey): string
    {
        return (string) preg_replace('/\.jpg$/', '-64.jpg', $storageKey);
    }

    /** Deletes a photo pair (rows and files) the profile no longer points at. */
    private function release(mixed $fileId): void
    {
        if ($fileId === null) {
            return;
        }
        $key   = db_connect()->table('files')->select('storage_key')->where('id', $fileId)->get()->getRow('storage_key');
        $small = $key === null ? null : db_connect()->table('files')->select('id')->where('storage_key', $this->smallKey($key))->get()->getRow('id');
        $this->storage()->purgeFiles([(int) $fileId, $small]);
    }

    /** D16: no values, only who did it to whom. */
    private function audit(User $actor, int $subjectId, string $own, string $byAdmin): void
    {
        service('auditLogger')->log('profile.photo_' . ((int) $actor->id === $subjectId ? $own : $byAdmin), $actor, [
            'target_type'    => 'user',
            'target_id'      => $subjectId,
            'institution_id' => (new PlanLimits())->institutionOf($subjectId),
        ]);
    }

    private function storage(): StorageService
    {
        return $this->storage ?? new StorageService();
    }
}

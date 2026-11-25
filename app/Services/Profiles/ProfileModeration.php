<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Institutions\InstitutionScope;
use App\Services\Notifications\MailOutbox;
use App\Services\Notifications\NotificationService;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * What an institution's administrator may do to someone else's profile (D6): remove their photo, or
 * add one on their behalf. The subject must belong to the administrator's institution (anyone else is a
 * 404, so ids cannot be probed). The owner is told in the app and by email; the audit row is the
 * activity Settings shows them. Whether the caller may manage people is the route's filter. Changing their own photo tells them nothing.
 */
class ProfileModeration
{
    public function __construct(private readonly ?ProfilePhotoService $photos = null)
    {
    }

    public function removePhoto(int $subjectId, User $admin): void
    {
        $this->guard($subjectId, $admin);

        if (! $this->photos()->state($subjectId)['has']) {
            return;
        }

        $this->photos()->remove($subjectId, $admin);
        $subjectId === (int) $admin->id || $this->tell($subjectId, 'removed', 'Your photo was removed by your school', 'Your school removed your profile photo.');
    }

    /**
     * @throws ProfileEditException
     * @throws \App\Exceptions\PlanLimitExceeded
     */
    public function uploadPhoto(int $subjectId, UploadedFile $file, User $admin): void
    {
        $this->guard($subjectId, $admin);

        $this->photos()->upload($subjectId, $file, $admin);
        $subjectId === (int) $admin->id || $this->tell($subjectId, 'added', 'Your school added a profile photo for you', 'You can change or remove it on your profile.');
    }

    /** Fields an administrator may clear when they are misused (D5, FR9), with the words the owner is told. */
    public const CLEARABLE = [
        'preferred_first_name' => 'preferred name',
        'pronouns'             => 'pronouns',
        'bio'                  => 'bio',
        'office_location'      => 'office location',
        'consultation_hours'   => 'consultation hours',
        'work_phone'           => 'work phone',
    ];

    /**
     * Empties one profile field. The audit row names the field, never the old value.
     *
     * @throws PageNotFoundException a person of another institution, or a field that cannot be cleared
     */
    public function clearField(int $subjectId, string $field, User $admin): void
    {
        $this->guard($subjectId, $admin);

        if (! isset(self::CLEARABLE[$field])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $db = db_connect();

        // Filled means not null and not empty, as the clear menu decides what to offer.
        if ($db->table('user_profiles')->where('user_id', $subjectId)->where("{$field} IS NOT NULL AND {$field} != ''", null, false)->countAllResults() === 0) {
            return;
        }

        $institution = (new PlanLimits())->institutionOf($subjectId);

        $db->transStart();
        $db->table('user_profiles')->where('user_id', $subjectId)->update([$field => null]);
        service('auditLogger')->log('profile.field_cleared_by_admin', $admin, ['target_type' => 'user', 'target_id' => $subjectId, 'institution_id' => $institution, 'new' => ['field' => $field]]);
        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new \RuntimeException('The field could not be cleared.');
        }

        if ($subjectId === (int) $admin->id) {
            return;
        }

        $label = self::CLEARABLE[$field];
        $body  = "Your school cleared your {$label}.";
        (new NotificationService())->send($subjectId, 'profile.field', $body, 'You can add it again on your profile.', false, "profile.field:{$subjectId}:{$field}:" . bin2hex(random_bytes(6)), $institution, 'profile');

        if ($institution !== null) {
            (new MailOutbox())->tell($institution, $subjectId, MailOutbox::FIELD_CLEARED, $label);

            return;
        }

        $owner = model(UserModel::class)->findById($subjectId);
        $owner === null || SecurityNotice::send($owner, "Your {$label} was cleared", $body . "\n\nYou can add it again on your profile. If this wasn't expected, contact your school.\n");
    }

    private function guard(int $subjectId, User $admin): void
    {
        (new InstitutionScope($admin))->require('person', $subjectId);
    }

    /** The bell now, the email through the outbox. */
    private function tell(int $subjectId, string $what, string $title, string $body): void
    {
        $institution = (new PlanLimits())->institutionOf($subjectId);

        (new NotificationService())->send($subjectId, 'profile.photo', $title, $body, false, "profile.photo:{$subjectId}:{$what}:" . bin2hex(random_bytes(6)), $institution, 'profile');

        if ($institution !== null) {
            (new MailOutbox())->tell($institution, $subjectId, MailOutbox::PHOTO, $what);
        }
    }

    private function photos(): ProfilePhotoService
    {
        return $this->photos ?? new ProfilePhotoService();
    }
}

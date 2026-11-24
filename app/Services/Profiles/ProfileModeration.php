<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Institutions\InstitutionScope;
use App\Services\Notifications\MailOutbox;
use App\Services\Notifications\NotificationService;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;

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

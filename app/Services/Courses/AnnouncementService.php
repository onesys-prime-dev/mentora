<?php

namespace App\Services\Courses;

use App\Models\Courses\AnnouncementModel;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

class AnnouncementService
{
    protected AnnouncementModel $announcementModel;

    public function __construct()
    {
        $this->announcementModel = new AnnouncementModel();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

        return [

            'html' => view(
                'pages/courses/features/announcements/index'
            ),

        ];

    }

    /**
     * A new post to one section, instructor-only. Reused by the
     * Announcements tab (once it's built) and the Calendar's date-click
     * menu — same authorization, same validation, one place either can
     * call into.
     *
     * @return array{success: bool, message?: string, announcement?: array}
     */
    public function create(User $user, array $data): array
    {
        if (! $user->inGroup('instructor')) {
            return ['success' => false, 'message' => 'Only an instructor can post an announcement.'];
        }

        $offeringId = (int) ($data['offering_id'] ?? 0);

        $teaches = db_connect()->table('instructor_assignments')
            ->where('offering_id', $offeringId)
            ->where('instructor_user_id', $user->id)
            ->countAllResults() > 0;

        if (! $teaches) {
            return ['success' => false, 'message' => 'You do not teach this section.'];
        }

        $payload = [
            'offering_id' => $offeringId,
            'author_id'   => $user->id,
            'title'       => trim((string) ($data['title'] ?? '')),
            'body'        => trim((string) ($data['body'] ?? '')),
            'status'      => 'Published',
        ];

        if (! $this->announcementModel->validate($payload)) {

            return [
                'success' => false,
                'message' => implode(' ', $this->announcementModel->errors()),
            ];

        }

        $id = $this->announcementModel->insert($payload, true);

        return [
            'success'      => true,
            'announcement' => $this->announcementModel->find($id),
        ];
    }
}

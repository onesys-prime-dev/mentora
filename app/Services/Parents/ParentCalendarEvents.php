<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Services\Calendar\CalendarEventService;
use CodeIgniter\Shield\Entities\User;

/**
 * What a parent's calendar holds for one child: the published deadlines and
 * the announcements of the child's courses. Never the child's own reminders
 * (private to them), and never a link into the student's pages (a parent
 * cannot open those).
 *
 * The child is passed in as the student. A student who also holds a staff
 * role is read as that staff member by the shared event code (drafts, and
 * a role lookup that would overwrite the parent's own), so such a child
 * has no events here.
 */
class ParentCalendarEvents extends CalendarEventService
{
    /**
     * @return array{events: list<array>, courses: list<array>}
     */
    public function forRange(User $user, string $from, string $to): array
    {
        if (! ParentAccessService::readsAsStudent($user)) {
            return ['events' => [], 'courses' => []];
        }

        $courses     = $this->courseService->getCourseOfferings($user);
        $offeringIds = array_map('intval', array_column($courses, 'offering_id'));

        $events = array_merge(
            $this->assessmentEvents($user, $offeringIds, $courses, $from, $to),
            $this->announcementEvents($user, $offeringIds, $courses, $from, $to),
        );

        usort($events, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return ['events' => $events, 'courses' => $courses];
    }

    /**
     * Course announcements, linking to the parent's Announcements tab for that
     * course (never the student's pages) and without resolving anyone's role.
     */
    protected function announcementEvents(User $user, array $offeringIds, array $courses, string $from, string $to): array
    {
        $labels = $this->courseLabels($courses);
        $slugs  = [];
        $child  = (int) $user->id;

        helper(['announcement', 'url']);

        foreach ($courses as $course) {
            $slugs[(int) $course['offering_id']] = url_title((string) $course['course_title'], '-', true);
        }

        return array_map(static function (array $row) use ($labels, $slugs, $child): array {
            $preview = announcement_plain_text($row['message']);

            return [
                'kind'        => 'announcement',
                'id'          => (int) $row['id'],
                'date'        => substr((string) $row['created_at'], 0, 10),
                'time'        => date('g:i A', strtotime($row['created_at'])),
                'title'       => $row['subject'],
                'course'      => $labels[(int) $row['offering_id']] ?? '',
                'offering_id' => (int) $row['offering_id'],
                'tone'        => 'info',
                'url'         => site_url('parent/children/' . $child . '/courses/' . (int) $row['offering_id'] . '/' . ($slugs[(int) $row['offering_id']] ?? 'course') . '/announcements?announcement=' . (int) $row['id']),
                'detail'      => mb_strlen($preview) > 80 ? mb_substr($preview, 0, 79) . '…' : $preview,
            ];
        }, $this->announcementModel->retrieveForOfferingsInRange($offeringIds, $from, $to));
    }
}

<?php

namespace App\Services\Calendar;

use App\Services\Courses\CourseService;
use App\Models\Courses\AnnouncementModel;
use App\Models\Users\ReminderModel;

use CodeIgniter\Shield\Entities\User;

/**
 * Everything that lands on a person's Calendar, across every course they're
 * in — not one section at a time, the way Grades or Assessments are. Three
 * kinds of event, each already real data elsewhere in the app:
 *
 *   assessment   → due dates (student: published only; instructor: their
 *                  own drafts too, since a due date they set is exactly
 *                  the kind of thing a calendar should remind them of)
 *   announcement → posts to a course the person is in
 *   reminder     → personal, never shared — only the owner ever sees theirs
 *
 * Recurring weekly class sessions (course_schedules) are deliberately left
 * out of this pass — mixing a repeating block into a day/month/year grid
 * is a separate piece of work, and deadlines are the more useful thing to
 * get right first.
 */
class CalendarEventService
{
    protected CourseService $courseService;

    protected AnnouncementModel $announcementModel;

    protected ReminderModel $reminderModel;

    public function __construct()
    {
        $this->courseService = new CourseService();
        $this->announcementModel = new AnnouncementModel();
        $this->reminderModel = new ReminderModel();
    }

    /**
     * @return array{events: list<array>, courses: list<array>}
     */
    public function forRange(User $user, string $from, string $to): array
    {
        $courses = $this->courseService->getCourseOfferings($user);

        $offeringIds = array_map('intval', array_column($courses, 'offering_id'));

        $events = array_merge(
            $this->assessmentEvents($user, $offeringIds, $courses, $from, $to),
            $this->announcementEvents($user, $offeringIds, $courses, $from, $to),
            $this->reminderEvents((int) $user->id, $from, $to),
        );

        usort($events, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return ['events' => $events, 'courses' => $courses];
    }

    /*
    |--------------------------------------------------------------------------
    | Assessments
    |--------------------------------------------------------------------------
    */

    protected function assessmentEvents(User $user, array $offeringIds, array $courses, string $from, string $to): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $labels = $this->courseLabels($courses);

        // The chairperson sees what an instructor does: every course of the program, drafts included.
        $isInstructor = $user->inGroup('instructor') || $user->inGroup('chairperson');

        $builder = db_connect()->table('assessments a')
            ->select([
                'a.id', 'a.title', 'a.due_date', 'a.status',
                'cm.offering_id',
                'at.name AS assessment_type',
            ])
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->join('assessment_types at', 'at.id = a.assessment_type_id', 'left')
            ->whereIn('cm.offering_id', $offeringIds)
            ->where('a.due_date >=', $from)
            ->where('a.due_date <=', $to);

        if ($isInstructor) {

            $builder->where('a.status !=', 'Archived');

        } else {

            // Same rule as the course's own lists: a student only learns of
            // a due date while its module, and the unit and item it hangs
            // off (if any), are published.
            $builder->where('a.status', 'Published')
                ->where('cm.status', 'published')
                ->join('learning_items li', 'li.id = a.learning_item_id', 'left')
                ->join('learning_units lu', 'lu.id = li.learning_unit_id', 'left')
                ->groupStart()
                    ->where('a.learning_item_id IS NULL', null, false)
                    ->orGroupStart()
                        ->where('li.status', 'published')
                        ->where('lu.status', 'published')
                    ->groupEnd()
                ->groupEnd();

        }

        return array_map(function (array $row) use ($labels) {

            $offeringId = (int) $row['offering_id'];

            return [
                'kind'    => 'assessment',
                'id'      => (int) $row['id'],
                'date'    => substr((string) $row['due_date'], 0, 10),
                'time'    => date('g:i A', strtotime($row['due_date'])),
                'title'   => $row['title'],
                'course'  => $labels[$offeringId] ?? '',
                'offering_id' => $offeringId,
                'tone'    => $row['status'] === 'Draft' ? 'muted' : 'warning',
                'detail'  => ($row['assessment_type'] ?: 'Assessment') . ($row['status'] === 'Draft' ? ' · Draft' : ''),
            ];

        }, $builder->get()->getResultArray());
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    protected function announcementEvents(User $user, array $offeringIds, array $courses, string $from, string $to): array
    {
        $labels = $this->courseLabels($courses);

        $active = (new \App\Services\Auth\ActiveRoleService())->resolve($user);

        $role = match (true) {
            in_array($active, ['instructor', 'chairperson'], true) => $active,
            $user->inGroup('instructor')  => 'instructor',
            $user->inGroup('chairperson') => 'chairperson',
            default                       => 'student',
        };

        $slugs = [];

        foreach ($courses as $course) {
            $slugs[(int) $course['offering_id']] = url_title($course['course_title'], '-', true);
        }

        helper('announcement');

        return array_map(static function (array $row) use ($labels, $slugs, $role) {

            $preview = announcement_plain_text($row['message']);

            return [
                'kind'    => 'announcement',
                'id'      => (int) $row['id'],
                'date'    => substr((string) $row['created_at'], 0, 10),
                'time'    => date('g:i A', strtotime($row['created_at'])),
                'title'   => $row['subject'],
                'course'  => $labels[(int) $row['offering_id']] ?? '',
                'offering_id' => (int) $row['offering_id'],
                'tone'    => 'info',
                // Straight into the course's Announcements tab, landing on this post.
                'url'     => site_url("{$role}/courses/{$row['offering_id']}/" . ($slugs[(int) $row['offering_id']] ?? 'course') . "/announcements?announcement={$row['id']}"),
                'detail'  => mb_strlen($preview) > 80 ? mb_substr($preview, 0, 79) . '…' : $preview,
            ];

        }, $this->announcementModel->retrieveForOfferingsInRange($offeringIds, $from, $to));
    }

    /*
    |--------------------------------------------------------------------------
    | Reminders
    |--------------------------------------------------------------------------
    */

    protected function reminderEvents(int $userId, string $from, string $to): array
    {
        return array_map(static fn (array $row) => [
            'kind'    => 'reminder',
            'id'      => (int) $row['id'],
            'date'    => substr((string) $row['remind_at'], 0, 10),
            'time'    => date('g:i A', strtotime($row['remind_at'])),
            'title'   => $row['title'],
            'course'  => '',
            'offering_id' => $row['offering_id'] !== null ? (int) $row['offering_id'] : null,
            'tone'    => $row['status'] === 'done' ? 'muted' : 'neutral',
            'detail'  => $row['note'] ?? '',
        ], $this->reminderModel->retrieveForUserInRange($userId, $from, $to));
    }

    /** offering_id => "IT101 - A", for the label on every event card. */
    protected function courseLabels(array $courses): array
    {
        $labels = [];

        foreach ($courses as $course) {
            $labels[(int) $course['offering_id']] = $course['course_code'] . ' - ' . $course['section_code'];
        }

        return $labels;
    }
}

<?php

declare(strict_types=1);

use App\Models\Courses\AnnouncementModel;
use App\Services\Courses\AnnouncementService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A course announcement dated for a later day (a post from the calendar) stays out of sight until
 * that day (QA AMB-COM-01): not in the course feed, the dashboard or the calendar. The course's
 * own instructors still see it in the feed, so its author can find it before then. And every post
 * shows once, however many roles its author holds (QA AMB-COM-03).
 *
 * @internal
 */
final class AnnouncementsOnTheirDayAndOnceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AnnouncementModel $model;
    private int $offering;
    private User $instructor;
    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model      = new AnnouncementModel();
        $this->offering   = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->ada = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $this->ada);
    }

    private function post(string $subject, string $when): void
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->instructor->id, 'subject' => $subject,
            'message' => json_encode(['version' => 1, 'blocks' => []]), 'is_pinned' => 0, 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function subjects(array $rows): array
    {
        return array_column($rows, 'subject');
    }

    public function testAPostDatedLaterIsNotInTheFeedTheDashboardOrTheCalendarYet(): void
    {
        $this->post('Today', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->post('Later', date('Y-m-d 08:00:00', strtotime('+3 days')));

        $this->assertSame(['Today'], $this->subjects($this->model->retrieveForOffering($this->offering)));
        $this->assertSame(['Today'], $this->subjects($this->model->retrieveForOfferings([$this->offering])));
        $this->assertSame(['Today'], $this->subjects($this->model->retrieveForOfferingsInRange([$this->offering], date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+7 days')))));
    }

    public function testAStudentDoesNotSeeItButTheCoursesInstructorDoes(): void
    {
        $this->post('Later', date('Y-m-d 08:00:00', strtotime('+3 days')));
        $feed = new AnnouncementService();

        // The feed's cards only: the full page's composer caches the signed-in profile for the whole run.
        $this->assertStringNotContainsString('Later', $feed->loadMore($this->ada, $this->offering, ['page' => 1])['html']);
        $this->assertStringContainsString('Later', $feed->loadMore($this->instructor, $this->offering, ['page' => 1])['html']);
    }

    public function testAPostShowsOnceWhateverRolesItsAuthorHolds(): void
    {
        $this->instructor->addGroup('chairperson');
        $this->post('Once', date('Y-m-d H:i:s', strtotime('-1 hour')));

        $this->assertSame(['Once'], $this->subjects($this->model->retrieveForOffering($this->offering)));
        $this->assertSame(['Once'], $this->subjects($this->model->retrieveForOfferings([$this->offering])));
    }
}

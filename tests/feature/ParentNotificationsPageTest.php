<?php

declare(strict_types=1);

use App\Services\Parents\ParentNotifier;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * What a parent sees on the shared notifications page, and that the link in
 * a parent notification opens the right parent page for that child.
 *
 * @internal
 */
final class ParentNotificationsPageTest extends RouteTestCase
{
    private int $institution;

    private User $pam;

    private User $ann;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();

        $this->institution = PeopleFactory::institution('PNP');
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $teacher           = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $this->pam->id, 'student_user_id' => $this->ann->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->offering = CourseFactory::offering('A');
        CourseFactory::assignInstructor($this->offering, $teacher);
        CourseFactory::enroll($this->offering, $this->ann);

        $scheme = GradeFactory::scheme($this->offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $this->offering, 'student_id' => $this->ann->id, 'grading_scheme_id' => $scheme, 'remarks' => 'Passed',
            'finalized_at' => date('Y-m-d H:i:s', time() - 3600), 'final_percentage' => 88.0, 'calculated_percentage' => 88.0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new ParentNotifier())->finalGrades();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    public function testAParentSeesTheirParentNotificationsAndNobodyElsesOnTheSharedPage(): void
    {
        $other = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        db_connect()->table('notifications')->insert(['user_id' => $other->id, 'type' => 'x', 'title' => 'Olga only', 'body' => 'b', 'urgent' => 0, 'dedupe_key' => 'k', 'created_at' => date('Y-m-d H:i:s')]);
        $this->loggedInAs($this->pam);

        $response = $this->call('GET', '/notifications')->response();
        $body     = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Final grade available for Ann', $body);
        $this->assertStringNotContainsString('Olga only', $body);
    }

    public function testTheLinkOpensTheRightParentPageForThatChild(): void
    {
        $link = db_connect()->table('notifications')->where(['user_id' => $this->pam->id, 'type' => 'parent.final'])->get()->getRowArray()['link'];
        $this->loggedInAs($this->pam);

        $response = $this->call('GET', '/' . $link)->response();

        $this->assertSame(200, $response->getStatusCode(), $link);
        $this->assertStringContainsString('data-course-feature="grades"', (string) $response->getBody());
    }

    public function testTheAnnouncementLinkOpensToo(): void
    {
        db_connect()->table('announcements')->insert([
            'offering_id' => $this->offering, 'author_id' => $this->pam->id, 'subject' => 'Room change', 'message' => '{}', 'is_pinned' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new ParentNotifier())->announcements();

        $link = db_connect()->table('notifications')->where(['user_id' => $this->pam->id, 'type' => 'parent.announcement'])->get()->getRowArray()['link'];
        $this->loggedInAs($this->pam);

        $this->assertSame(200, $this->call('GET', '/' . $link)->response()->getStatusCode(), $link);
    }
}

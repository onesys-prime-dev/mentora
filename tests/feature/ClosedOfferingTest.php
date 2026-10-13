<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * An offering that is no longer active (its term was closed, or it was
 * archived) can be looked at but not changed: every write under
 * .../api/courses/{offering}/... is refused for the instructor and the
 * student, before a controller runs; reads are untouched; an active offering
 * is unaffected; and the course page says it is closed.
 *
 * @internal
 */
final class ClosedOfferingTest extends RouteTestCase
{
    private int $offering;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering   = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->student    = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
    }

    private function close(string $status = 'completed'): void
    {
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => $status]);
    }

    private function write(User $user, string $path, array $body = [])
    {
        $prefix = $user->inGroup('instructor') ? 'instructor' : 'student';
        $this->loggedInAs($user);

        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->call('post', "{$prefix}/api/courses/{$this->offering}/{$path}");
    }

    public function testAnActiveOfferingTakesWritesAsBefore(): void
    {
        $result = $this->write($this->instructor, 'announcements', ['subject' => 'Welcome', 'message' => '{}']);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('announcements')->countAllResults());
    }

    public function testACompletedOfferingRefusesTheInstructorsWrites(): void
    {
        $this->close();

        $result = $this->write($this->instructor, 'announcements', ['subject' => 'Welcome', 'message' => '{}']);

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame('offering_closed', json_decode($result->getJSON(), true)['error']);
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testAnArchivedOfferingIsNotOpenedAtAll(): void
    {
        $this->close('archived');

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->write($this->instructor, 'announcements', ['subject' => 'Hi', 'message' => '{}']);
    }

    public function testACompletedOfferingRefusesTheStudentsWrites(): void
    {
        $this->close();

        $result = $this->write($this->student, 'discussions', ['title' => 'A question', 'body' => 'Hello']);

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame('offering_closed', json_decode($result->getJSON(), true)['error']);
    }

    public function testReadsAreNotAffected(): void
    {
        $this->close();
        $this->loggedInAs($this->instructor);

        $result = $this->withHeaders(['Accept' => 'application/json'])->call('get', "instructor/api/courses/{$this->offering}/announcements");

        $this->assertNotSame(403, $result->response()->getStatusCode());
    }

    public function testAnotherOfferingStaysWritable(): void
    {
        $this->close();

        $other = CourseFactory::offering('B');
        CourseFactory::assignInstructor($other, $this->instructor);
        $this->loggedInAs($this->instructor);

        $result = $this->withBody(json_encode(['subject' => 'Other', 'message' => '{}']))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->call('post', "instructor/api/courses/{$other}/announcements");

        $this->assertSame(200, $result->response()->getStatusCode());
    }

    public function testTheCalendarCannotPostAnAnnouncementIntoAClosedOffering(): void
    {
        $this->close();
        $this->loggedInAs($this->instructor);

        $result = $this->withBody(json_encode(['offering_id' => $this->offering, 'title' => 'Hi', 'body' => 'There']))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->call('post', 'instructor/api/calendar/announcements');

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testTheCoursePageSaysItIsClosed(): void
    {
        $this->loggedInAs($this->student);
        $open = $this->call('get', "student/courses/{$this->offering}/course/overview")->response()->getBody();
        $this->assertStringNotContainsString('data-offering-closed', $open);

        $this->close();
        $this->loggedInAs($this->student);
        $closed = $this->call('get', "student/courses/{$this->offering}/course/overview")->response()->getBody();

        $this->assertStringContainsString('data-offering-closed', $closed);
        $this->assertStringContainsString('This course is closed', $closed);
    }
}

<?php

declare(strict_types=1);

use App\Services\Parents\ParentNotifier;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * The scheduled sweep (`php spark parents:notify`): it finds every kind of
 * event in one run, tells each parent once however often it runs, says
 * nothing about old news, and its dry run changes and sends nothing.
 *
 * @internal
 */
final class ParentsNotifyCommandTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private FakeEmail $mailer;

    private int $institution;

    private User $pam;

    private User $olga;

    private User $ann;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('PNC');
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $this->institution);
        $this->olga        = PeopleFactory::person('parent', 'Olga', 'Parent', 'olga@example.test', $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        foreach ([$this->pam, $this->olga] as $parent) {
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $this->ann->id, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** An active offering Ann is enrolled in, with a published scheme and a record. */
    private function course(string $section, string $remarks, ?string $finalizedAt = null): int
    {
        $offering = CourseFactory::offering($section);
        CourseFactory::assignInstructor($offering, $this->teacher);
        CourseFactory::enroll($offering, $this->ann);

        $scheme = GradeFactory::scheme($offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $this->ann->id, 'grading_scheme_id' => $scheme, 'remarks' => $remarks,
            'finalized_at' => $finalizedAt, 'final_percentage' => $finalizedAt ? 88.0 : null, 'calculated_percentage' => 88.0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $offering;
    }

    /** One of every event: a fresh final grade, an INC, an announcement and overdue work. */
    private function everything(): void
    {
        $this->course('A', 'Passed', date('Y-m-d H:i:s', time() - 86400));
        $inc = $this->course('B', 'INC');

        db_connect()->table('announcements')->insert([
            'offering_id' => $inc, 'author_id' => $this->teacher->id, 'subject' => 'Room change', 'message' => '{}', 'is_pinned' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $module = CourseFactory::module($inc);
        $id     = CourseFactory::assessment($inc, $this->teacher, $module);
        db_connect()->table('assessments')->where('id', $id)->update([
            'title' => 'Essay', 'status' => 'Published', 'total_points' => 10, 'due_date' => date('Y-m-d H:i:s', time() - 86400),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);
    }

    private function told(): int
    {
        return db_connect()->table('notifications')->like('type', 'parent.', 'after')->countAllResults();
    }

    public function testTheSweepFindsEveryKindOfEventAndTellsNobodyTwice(): void
    {
        $this->everything();

        $counts = (new ParentNotifier())->sweep();

        $this->assertSame(['final' => 2, 'standing' => 2, 'announcement' => 2, 'missing' => 2], $counts);
        $this->assertSame(8, $this->told());
        $this->assertSame(['final' => 0, 'standing' => 0, 'announcement' => 0, 'missing' => 0], (new ParentNotifier())->sweep());
        $this->assertSame(8, $this->told());
    }

    public function testNoMessageOfAnyKindCarriesAGrade(): void
    {
        $this->everything();
        (new ParentNotifier())->sweep();

        $this->assertNotEmpty($this->mailer->sent);

        foreach (db_connect()->table('notifications')->like('type', 'parent.', 'after')->get()->getResultArray() as $note) {
            $this->assertDoesNotMatchRegularExpression('/\d\s*%|%\s*\d|points|score/i', $note['title'] . ' ' . $note['body'], $note['type']);
        }

        foreach ($this->mailer->sent as $mail) {
            $this->assertDoesNotMatchRegularExpression('/\d\s*%|%\s*\d|points|score/i', $mail['subject'] . ' ' . $mail['message']);
        }
    }

    public function testOldNewsIsNotRetold(): void
    {
        $this->course('A', 'Passed', date('Y-m-d H:i:s', time() - 20 * 86400));
        $ended = $this->course('B', 'INC');
        db_connect()->table('course_offerings')->where('id', $ended)->update(['status' => 'completed']);

        $this->assertSame(['final' => 0, 'standing' => 0, 'announcement' => 0, 'missing' => 0], (new ParentNotifier())->sweep());
        $this->assertSame(0, $this->told());
    }

    public function testADryRunCountsButChangesAndSendsNothing(): void
    {
        $this->everything();

        $counts = (new ParentNotifier(dryRun: true))->sweep();

        $this->assertSame(['final' => 2, 'standing' => 2, 'announcement' => 2, 'missing' => 2], $counts);
        $this->assertSame(0, $this->told());
        $this->assertSame(0, db_connect()->table('parent_alerted_items')->countAllResults());
        $this->assertSame([], $this->mailer->sent);

        $this->assertSame(['final' => 2, 'standing' => 2, 'announcement' => 2, 'missing' => 2], (new ParentNotifier())->sweep(), 'the real run still has everything to tell');
    }

    public function testTheCommandReportsWhatItTold(): void
    {
        $this->everything();

        command('parents:notify');

        $out = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Final grades: 2', $out);
        $this->assertStringContainsString('Standings: 2', $out);
        $this->assertStringContainsString('Announcements: 2', $out);
        $this->assertStringContainsString('Work not handed in: 2', $out);
        $this->assertSame(8, $this->told());
    }

    public function testTheCommandDryRunSaysWouldAndChangesNothing(): void
    {
        $this->everything();

        command('parents:notify --dry-run');

        $this->assertStringContainsString('Would tell', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->told());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testTheCommandWithNothingToTellSaysSo(): void
    {
        command('parents:notify');

        $this->assertStringContainsString('Final grades: 0', $this->getStreamFilterBuffer());
    }
}

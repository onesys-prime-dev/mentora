<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingSchemeService;
use App\Services\Grades\InstructorGradesOverview;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The terms and section cards behind the instructor Grades page.
 *
 * @internal
 */
final class InstructorGradesOverviewTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $instructor;

    private InstructorGradesOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = CourseFactory::user('instructor');
        $this->overview = new InstructorGradesOverview();
    }

    private function taught(string $section = 'A', ?User $instructor = null): int
    {
        $offering = CourseFactory::offering($section);
        CourseFactory::assignInstructor($offering, $instructor ?? $this->instructor);

        return $offering;
    }

    private function termOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
    }

    /** A published scheme whose one category carries the whole weight, so nothing stops publishing. */
    private function publish(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        return $scheme;
    }

    /** @return array<int, array<string, mixed>> keyed by offering id */
    private function cards(int $termId): array
    {
        $out = [];

        foreach ($this->overview->sections($this->instructor, $termId) as $row) {
            $out[$row['offering_id']] = $row;
        }

        return $out;
    }

    public function testTermsAreTheOnesTheyTeachInNewestFirst(): void
    {
        $older = $this->taught();
        $newer = $this->taught();
        $this->taught('A', CourseFactory::user('instructor')); // another instructor's term

        $db = db_connect();
        $db->table('academic_terms')->where('id', $this->termOf($older))->update(['start_date' => '2025-08-01']);
        $db->table('academic_terms')->where('id', $this->termOf($newer))->update(['start_date' => '2026-08-01']);

        $terms = $this->overview->terms($this->instructor);

        $this->assertSame([$this->termOf($newer), $this->termOf($older)], array_column($terms, 'id'));
        $this->assertSame(['id', 'name', 'academic_year', 'status'], array_keys($terms[0]));
        $this->assertIsInt($terms[0]['id']);
    }

    public function testTheDefaultTermIsTheActiveOne(): void
    {
        $terms = [
            ['id' => 5, 'name' => 'a', 'academic_year' => 'y', 'status' => 'completed'],
            ['id' => 4, 'name' => 'b', 'academic_year' => 'y', 'status' => 'active'],
            ['id' => 3, 'name' => 'c', 'academic_year' => 'y', 'status' => 'upcoming'],
        ];

        $this->assertSame(4, $this->overview->defaultTermId($terms, null));
        $this->assertSame(3, $this->overview->defaultTermId($terms, 3));
        $this->assertSame(4, $this->overview->defaultTermId($terms, 99), 'an unlisted term is ignored');
        $this->assertSame(5, $this->overview->defaultTermId([$terms[0], $terms[2]], null), 'no active term falls back to the first');
        $this->assertNull($this->overview->defaultTermId([], null));
    }

    public function testEachSectionSaysWhereItStands(): void
    {
        $finalized = $this->taught('A');
        $unpublished = $this->taught('B');
        $waiting = $this->taught('C');
        $blocked = $this->taught('D');
        $ready = $this->taught('E');
        $db = db_connect();

        // Same term for all five.
        $termId = $this->termOf($finalized);
        $db->table('course_offerings')->whereIn('id', [$unpublished, $waiting, $blocked, $ready])->update(['term_id' => $termId]);

        // Course codes sort by their number, so keep the sections in A-E order.
        $db->table('course_offerings')->whereIn('id', [$finalized, $unpublished, $waiting, $blocked, $ready])->update(['course_id' => $this->courseOf($finalized)]);

        foreach ([$finalized, $waiting, $blocked, $ready] as $offering) {
            $this->publish($offering);
        }

        $db->table('grade_finalizations')->insert(['course_offering_id' => $finalized, 'finalized_by' => $this->instructor->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        // A scheme that is not published.
        $draft = GradeFactory::scheme($unpublished);
        $db->table('grading_schemes')->where('id', $draft)->update(['status' => 'Draft']);

        // Two students with ungraded work.
        $quiz = CourseFactory::assessment($waiting, $this->instructor, CourseFactory::module($waiting), null, 'Quiz');
        $db->table('assessments')->where('id', $quiz)->update(['status' => 'Published']);

        foreach ([1, 2] as $i) {
            $student = CourseFactory::user('student');
            CourseFactory::enroll($waiting, $student);
            AssessmentFactory::attempt($quiz, $student, 'Submitted');
        }

        // Students of every status; only enrolled and completed count.
        CourseFactory::enroll($ready, CourseFactory::user('student'), 'enrolled');
        CourseFactory::enroll($ready, CourseFactory::user('student'), 'completed');
        CourseFactory::enroll($ready, CourseFactory::user('student'), 'dropped');

        $db->table('grade_override_requests')->insert([
            'offering_id' => $blocked, 'student_id' => CourseFactory::user('student')->id, 'requested_percentage' => 80,
            'reason' => 'Appeal', 'requested_by' => $this->instructor->id, 'requester_role' => 'instructor',
            'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);

        $cards = $this->cards($termId);

        $this->assertSame('finalized', $cards[$finalized]['state']);
        $this->assertSame('Finalized', $cards[$finalized]['note']);
        $this->assertSame('not_published', $cards[$unpublished]['state']);
        $this->assertSame('Grades not published yet', $cards[$unpublished]['note']);
        $this->assertSame('waiting', $cards[$waiting]['state']);
        $this->assertSame('2 waiting to be graded', $cards[$waiting]['note']);
        $this->assertSame(2, $cards[$waiting]['waiting']);
        $this->assertSame('blocked', $cards[$blocked]['state']);
        $this->assertSame('1 override request open', $cards[$blocked]['note']);
        $this->assertSame('ready', $cards[$ready]['state']);
        $this->assertSame('Ready to finalize', $cards[$ready]['note']);
        $this->assertSame(2, $cards[$ready]['students']);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($this->overview->sections($this->instructor, $termId), 'section_code'));
        $this->assertSame(
            ['offering_id', 'course_code', 'course_title', 'section_code', 'slug', 'students', 'waiting', 'state', 'note', 'offering_status', 'has_scheme'],
            array_keys($cards[$ready])
        );
    }

    private function courseOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->course_id;
    }

    public function testASectionWithoutASchemeIsNotGivenOne(): void
    {
        $offering = $this->taught();

        $cards = $this->cards($this->termOf($offering));

        $this->assertSame('not_published', $cards[$offering]['state']);
        $this->assertFalse($cards[$offering]['has_scheme']);
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->countAllResults());
    }

    public function testHasSchemeSaysWhetherTheSectionHasAnySchemeAtAll(): void
    {
        $bare = $this->taught('A');
        $draft = $this->taught('B');
        $published = $this->taught('C');
        db_connect()->table('course_offerings')->whereIn('id', [$draft, $published])->update(['term_id' => $this->termOf($bare)]);

        GradeFactory::scheme($draft); // not published, but it exists
        $this->publish($published);

        $cards = $this->cards($this->termOf($bare));

        $this->assertFalse($cards[$bare]['has_scheme']);
        $this->assertTrue($cards[$draft]['has_scheme']);
        $this->assertTrue($cards[$published]['has_scheme']);
    }

    public function testAPublishedSchemeThatCouldNotBePublishedNowIsBlockedNotReady(): void
    {
        $noCategories = $this->taught('A');
        $badWeights = $this->taught('B');
        $badPeriod = $this->taught('C');
        $fine = $this->taught('D');
        $db = db_connect();
        $termId = $this->termOf($noCategories);
        $db->table('course_offerings')->whereIn('id', [$badWeights, $badPeriod, $fine])->update(['term_id' => $termId]);

        // Published, but with no category at all.
        $scheme = GradeFactory::scheme($noCategories);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);

        // Published, but the category weights total 90%. An archived category does not count.
        $scheme = GradeFactory::scheme($badWeights);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Quizzes', 40);
        GradeFactory::category($scheme, 'Exams', 50, null, 2);
        $archived = GradeFactory::category($scheme, 'Old', 10, null, 3);
        $db->table('grade_categories')->where('id', $archived)->update(['status' => 'Archived']);

        // Published with periods: the periods total 100%, but the second one has no category.
        $scheme = GradeFactory::scheme($badPeriod, 75.0, true);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        $prelim = GradeFactory::period($scheme, 'Prelim', 50, 1);
        GradeFactory::period($scheme, 'Finals', 50, 2);
        GradeFactory::category($scheme, 'Exams', 100, $prelim);

        $this->publish($fine);

        $cards = $this->cards($termId);

        $this->assertSame('blocked', $cards[$noCategories]['state']);
        $this->assertSame('Add at least one category before publishing.', $cards[$noCategories]['note']);
        $this->assertSame('blocked', $cards[$badWeights]['state']);
        $this->assertSame('Category weights add up to 90% — they must total 100%.', $cards[$badWeights]['note']);
        $this->assertSame('blocked', $cards[$badPeriod]['state']);
        $this->assertSame('Add at least one category to "Finals" before publishing.', $cards[$badPeriod]['note']);
        $this->assertSame('ready', $cards[$fine]['state']);

        // The same words the course's own check gives.
        $schemes = new GradingSchemeService();
        foreach ([$noCategories, $badWeights, $badPeriod] as $offering) {
            $id = (int) $db->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRow()->id;
            $this->assertSame($schemes->publishProblem($id), $cards[$offering]['note']);
        }
    }

    public function testArchivedSectionsAndTheirTermsAreLeftOut(): void
    {
        $active = $this->taught('A');
        $completed = $this->taught('B');
        $archived = $this->taught('C');
        $termId = $this->termOf($active);
        $db = db_connect();
        $db->table('course_offerings')->whereIn('id', [$completed, $archived])->update(['term_id' => $termId]);
        $db->table('course_offerings')->where('id', $completed)->update(['status' => 'completed']);
        $db->table('course_offerings')->where('id', $archived)->update(['status' => 'archived']);

        // A term in which the only section they taught is archived.
        $old = $this->taught('D');
        $db->table('course_offerings')->where('id', $old)->update(['status' => 'archived']);

        $this->assertEqualsCanonicalizing([$active, $completed], array_keys($this->cards($termId)));
        $this->assertSame([], $this->overview->sections($this->instructor, $this->termOf($old)));
        $this->assertSame([$termId], array_column($this->overview->terms($this->instructor), 'id'));
    }

    public function testAnotherInstructorsSectionsAreNotListed(): void
    {
        $mine = $this->taught();
        $theirs = $this->taught('B', CourseFactory::user('instructor'));
        db_connect()->table('course_offerings')->where('id', $theirs)->update(['term_id' => $this->termOf($mine)]);

        $this->assertSame([$mine], array_keys($this->cards($this->termOf($mine))));
    }

    public function testTheDefaultSectionIsTheBusiest(): void
    {
        $sections = [
            ['offering_id' => 1, 'waiting' => 1],
            ['offering_id' => 2, 'waiting' => 4],
            ['offering_id' => 3, 'waiting' => 4],
        ];

        $this->assertSame(2, $this->overview->defaultSectionId($sections, null));
        $this->assertSame(3, $this->overview->defaultSectionId($sections, 3));
        $this->assertNull($this->overview->defaultSectionId([], null));
    }

    public function testTheSummaryCountsWaitingReadyAndFinalized(): void
    {
        $sections = [
            ['waiting' => 2, 'state' => 'waiting'],
            ['waiting' => 3, 'state' => 'waiting'],
            ['waiting' => 0, 'state' => 'ready'],
            ['waiting' => 0, 'state' => 'finalized'],
            ['waiting' => 0, 'state' => 'blocked'],
        ];

        $this->assertSame(['waiting' => 5, 'ready' => 1, 'finalized' => 1, 'total' => 5], $this->overview->summary($sections));
        $this->assertSame(['waiting' => 0, 'ready' => 0, 'finalized' => 0, 'total' => 0], $this->overview->summary([]));
    }

    public function testTheQueryCountDoesNotGrowWithSections(): void
    {
        $few = $this->busyTerm(3);
        $many = $this->busyTerm(15);

        $this->overview->sections($this->instructor, $few); // warm up

        $three = $this->queriesOf(fn () => $this->overview->sections($this->instructor, $few));
        $fifteen = $this->queriesOf(fn () => $this->overview->sections($this->instructor, $many));

        $this->assertGreaterThan(0, $three);
        $this->assertSame($three, $fifteen);
    }

    private function busyTerm(int $sections): int
    {
        $termId = null;

        for ($i = 0; $i < $sections; $i++) {
            $offering = $this->taught((string) ($i + 1));
            $termId ??= $this->termOf($offering);
            db_connect()->table('course_offerings')->where('id', $offering)->update(['term_id' => $termId]);
            $this->publish($offering);

            $module = CourseFactory::module($offering);
            $assessments = [];

            for ($a = 0; $a < 2; $a++) {
                $assessments[] = CourseFactory::assessment($offering, $this->instructor, $module, null, 'Quiz');
            }

            db_connect()->table('assessments')->whereIn('id', $assessments)->update(['status' => 'Published']);

            for ($s = 0; $s < 50; $s++) {
                $student = CourseFactory::user('student');
                CourseFactory::enroll($offering, $student);
                AssessmentFactory::attempt($assessments[0], $student, 'Submitted');
            }
        }

        return $termId;
    }

    private function queriesOf(callable $work): int
    {
        $queries = 0;

        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }
}

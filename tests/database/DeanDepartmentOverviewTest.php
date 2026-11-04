<?php

declare(strict_types=1);

use App\Services\Grades\DeanDepartmentOverview;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;

/**
 * The sections behind the dean's Department tab: every section of the dean's department(s), through the
 * chairperson's program query, with a Signed off column, a program filter and the strip's counts.
 *
 * @internal
 */
final class DeanDepartmentOverviewTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DeanDepartmentOverview $overview;

    private User $dean;

    private int $institution;

    private int $department;

    private int $programA;

    private int $programB;

    private int $term;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overview = new DeanDepartmentOverview();

        ['institution' => $this->institution, 'department' => $this->department] = DeanFactory::department('DG');

        // Two sub-departments of the dean's department; B's program sorts before A's by name.
        $this->programA = DeanFactory::program(DeanFactory::subDepartment($this->department, 'A'), 'Zoology');
        db_connect()->table('programs')->where('id', $this->programA)->update(['name' => 'Zoology']);
        $this->programB = DeanFactory::program(DeanFactory::subDepartment($this->department, 'B'), 'Biology');
        db_connect()->table('programs')->where('id', $this->programB)->update(['name' => 'Biology']);

        $this->term = DeanFactory::term($this->institution);
        $this->dean = DeanFactory::dean($this->institution, $this->department);
    }

    private function section(string $code, ?int $program = null, string $status = 'active', ?int $term = null): int
    {
        return DeanFactory::offering($program ?? $this->programA, $term ?? $this->term, $code, 'A', $status);
    }

    private function publish(int $offering): void
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);
    }

    private function finalize(int $offering): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => $this->dean->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function signOff(int $offering, ?int $openMarker = 1): void
    {
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $offering, 'signed_by' => $this->dean->id, 'signed_at' => date('Y-m-d H:i:s'), 'open_marker' => $openMarker]);
    }

    private function overrideRequest(int $offering, ?int $openMarker = 1): void
    {
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $offering, 'student_id' => CourseFactory::user('student')->id, 'requested_percentage' => 80,
            'reason' => 'Appeal', 'requested_by' => $this->dean->id, 'requester_role' => 'instructor',
            'status' => $openMarker === 1 ? 'awaiting_dean' : 'approved', 'open_marker' => $openMarker,
        ]);
    }

    /** @return list<int> */
    private function ids(?int $program = null, array $opts = []): array
    {
        return array_column($this->overview->sections($this->dean, $this->term, $program, $opts)['rows'], 'offering_id');
    }

    public function testOnlyTheDepartmentsSectionsAreListed(): void
    {
        $a1        = $this->section('A101');
        $completed = $this->section('A102', null, 'completed');
        $this->section('A103', null, 'archived');
        $b1 = $this->section('B101', $this->programB);

        // Another department of the same institution, and another institution.
        $other    = DeanFactory::department('OT', $this->institution);
        $otherPrg = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'O'), 'Other');
        $this->section('O101', $otherPrg);

        $older = DeanFactory::term($this->institution, 'Older', '2025-08-01');
        $this->section('A104', null, 'active', $older);

        $this->assertSame([$a1, $completed, $b1], $this->ids());
        $this->assertSame(3, $this->overview->sections($this->dean, $this->term, null, [])['total']);

        $rows = $this->overview->all($this->dean, $this->term, null);
        $this->assertSame([$a1, $completed, $b1], array_column($rows, 'offering_id'));
        $this->assertSame(
            ['offering_id', 'course_code', 'course_title', 'section_code', 'slug', 'instructors', 'average', 'at_risk', 'waiting', 'state', 'note', 'has_scheme', 'offering_status', 'program_id', 'signed_off'],
            array_keys($rows[0]),
            "the chairperson's row plus program_id and signed_off"
        );
        $this->assertSame($this->programA, $rows[0]['program_id']);
        $this->assertSame($this->programB, $rows[2]['program_id']);

        $this->assertSame(
            [['id' => $this->programB, 'name' => 'Biology'], ['id' => $this->programA, 'name' => 'Zoology']],
            $this->overview->programs($this->dean),
            'programs of the department, by name'
        );

        $terms = $this->overview->terms($this->dean);
        $this->assertSame([$this->term, $older], array_column($terms, 'id'));
        $this->assertSame($this->term, $this->overview->defaultTermId($terms, null));
        $this->assertSame($older, $this->overview->defaultTermId($terms, $older));

        db_connect()->table('academic_terms')->where('id', $this->term)->update(['end_date' => '2026-12-18']);
        $this->assertSame('2026-12-18', $this->overview->termEnd($this->term));

        // An ended dean assignment reaches nothing, without an error.
        db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)
            ->update(['status' => 'ended', 'active_marker' => null, 'ended_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->overview->programs($this->dean));
        $this->assertSame([], $this->overview->terms($this->dean));
        $this->assertSame([], $this->overview->all($this->dean, $this->term, null));
        $this->assertSame(
            ['rows' => [], 'total' => 0, 'strip' => ['finalized' => 0, 'signed_off' => 0, 'total' => 0, 'requests_waiting' => 0]],
            $this->overview->sections($this->dean, $this->term, null, [])
        );
    }

    public function testProgramFilterAndAllPrograms(): void
    {
        $a1 = $this->section('A101');
        $a2 = $this->section('A102');
        $b1 = $this->section('B101', $this->programB);

        $other    = DeanFactory::department('OT', $this->institution);
        $otherPrg = DeanFactory::program(DeanFactory::subDepartment($other['department'], 'O'), 'Other');
        $this->section('O101', $otherPrg);

        $this->assertSame([$a1, $a2, $b1], $this->ids(null));
        $this->assertSame([$a1, $a2], $this->ids($this->programA));
        $this->assertSame([$b1], $this->ids($this->programB));
        $this->assertSame([$b1], array_column($this->overview->all($this->dean, $this->term, $this->programB), 'offering_id'));
        $this->assertSame([], $this->ids($otherPrg), "another department's program reaches nothing");

        $this->assertSame(3, $this->overview->sections($this->dean, $this->term, null, [])['strip']['total']);
        $this->assertSame(2, $this->overview->sections($this->dean, $this->term, $this->programA, [])['strip']['total']);
    }

    public function testSignedOffColumnAndStrip(): void
    {
        $signed = $this->section('A101');
        $this->publish($signed);
        $this->finalize($signed);
        $this->signOff($signed);

        $finalOnly = $this->section('A102');
        $this->publish($finalOnly);
        $this->finalize($finalOnly);
        $this->signOff($finalOnly, null); // a sign-off that was ended

        $open = $this->section('B101', $this->programB);
        $this->publish($open);

        $rows = array_column($this->overview->all($this->dean, $this->term, null), 'signed_off', 'offering_id');
        $this->assertSame([$signed => true, $finalOnly => false, $open => false], $rows);

        $all = $this->overview->sections($this->dean, $this->term, null, []);
        $this->assertSame(['finalized' => 2, 'signed_off' => 1, 'total' => 3, 'requests_waiting' => 0], $all['strip']);

        // The strip counts the whole program choice, whatever the search and filter.
        $narrowed = $this->overview->sections($this->dean, $this->term, null, ['q' => 'B101', 'notFinalized' => true]);
        $this->assertSame($all['strip'], $narrowed['strip']);
        $this->assertSame([$open], array_column($narrowed['rows'], 'offering_id'));
        $this->assertSame(1, $narrowed['total']);

        $this->assertSame(
            ['finalized' => 0, 'signed_off' => 0, 'total' => 1, 'requests_waiting' => 0],
            $this->overview->sections($this->dean, $this->term, $this->programB, [])['strip']
        );
    }

    public function testRequestsWaitingCountsOpenOverrideRequests(): void
    {
        $a1 = $this->section('A101');
        $b1 = $this->section('B101', $this->programB);
        $this->section('A103', null, 'archived');

        $this->overrideRequest($a1);
        $this->overrideRequest($a1);
        $this->overrideRequest($b1);
        $this->overrideRequest($b1, null); // decided, no longer open

        $archived = (int) db_connect()->table('course_offerings')->where('section_code', 'A')->where('status', 'archived')->get()->getRow('id');
        $this->overrideRequest($archived); // not a listed section

        $this->assertSame(3, $this->overview->sections($this->dean, $this->term, null, [])['strip']['requests_waiting']);
        $this->assertSame(2, $this->overview->sections($this->dean, $this->term, $this->programA, [])['strip']['requests_waiting']);
        $this->assertSame(1, $this->overview->sections($this->dean, $this->term, $this->programB, [])['strip']['requests_waiting']);
    }

    public function testSortBySignedOff(): void
    {
        $a = $this->section('A101');
        $b = $this->section('A102');
        $c = $this->section('A103');
        $this->signOff($b);

        $this->assertSame([$a, $c, $b], $this->ids(null, ['sort' => 'signed_off', 'dir' => 'asc']));
        $this->assertSame([$b, $a, $c], $this->ids(null, ['sort' => 'signed_off', 'dir' => 'desc']), 'ties keep the section order');
        $this->assertSame([$c, $b, $a], $this->ids(null, ['sort' => 'section', 'dir' => 'desc']));
    }

    public function testTheQueryCountDoesNotGrowWithSections(): void
    {
        $few  = $this->busyTerm(3);
        $many = $this->busyTerm(15);

        $this->overview->sections($this->dean, $few, null, []); // warm up

        $three   = $this->queriesOf(fn () => $this->overview->sections($this->dean, $few, null, ['sort' => 'signed_off']));
        $fifteen = $this->queriesOf(fn () => $this->overview->sections($this->dean, $many, null, ['sort' => 'signed_off']));

        $this->assertGreaterThan(0, $three);
        $this->assertSame($three, $fifteen);

        $this->assertSame(
            $this->queriesOf(fn () => $this->overview->all($this->dean, $few, $this->programA)),
            $this->queriesOf(fn () => $this->overview->all($this->dean, $many, $this->programA))
        );
    }

    private function busyTerm(int $sections): int
    {
        $term = DeanFactory::term($this->institution, 'Busy ' . (++$this->seq), '2027-0' . $this->seq . '-01');

        for ($i = 0; $i < $sections; $i++) {
            $offering = $this->section('BZ' . $this->seq . '-' . $i, $i % 2 === 0 ? $this->programA : $this->programB, 'active', $term);
            $this->publish($offering);
            CourseFactory::assignInstructor($offering, CourseFactory::user('instructor'));

            for ($s = 0; $s < 5; $s++) {
                $student = CourseFactory::user('student');
                CourseFactory::enroll($offering, $student);
                db_connect()->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student->id, 'calculated_percentage' => 60 + $s, 'remarks' => 'Passed']);
            }

            if ($i % 3 === 0) {
                $this->finalize($offering);
                $this->signOff($offering);
            }

            $this->overrideRequest($offering);
        }

        return $term;
    }

    private function queriesOf(callable $work): int
    {
        $queries  = 0;
        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);
        $work();
        Events::removeListener('DBQuery', $listener);

        return $queries;
    }
}

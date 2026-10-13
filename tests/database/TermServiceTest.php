<?php

declare(strict_types=1);

use App\Services\Institutions\StructureException;
use App\Services\Institutions\TermService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The academic terms' rules: validation with the field named, uniqueness,
 * overlap and double-active warnings (allowed, not refused), what a closed
 * term refuses, going back to upcoming only with nobody enrolled, scoping
 * and audit.
 *
 * @internal
 */
final class TermServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $actor;

    private TermService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('TRMA', false);
        $this->other       = SisFixture::institution('TRMB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new TermService();

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function input(array $override = []): array
    {
        return $override + ['name' => 'First Semester', 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-20'];
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    private function audit(string $action): ?array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id', 'desc')->get()->getRowArray();
    }

    public function testATermIsCreatedUpcomingAndAudited(): void
    {
        $result = $this->service->create($this->institution, $this->input(['academic_year' => '2026/2027']), $this->actor);

        $row = db_connect()->table('academic_terms')->where('id', $result['id'])->get()->getRowArray();

        $this->assertSame('upcoming', $row['status']);
        $this->assertSame('2026-2027', $row['academic_year']);
        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame([], $result['warnings']);
        $this->assertNotNull($this->audit('structure.term_created'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadTerms(): iterable
    {
        yield 'no name' => [['name' => ''], 'name'];
        yield 'a one letter name' => [['name' => 'A'], 'name'];
        yield 'a year that is wrong' => [['academic_year' => '2026-2030'], 'academic_year'];
        yield 'no start' => [['start_date' => ''], 'start_date'];
        yield 'a start that is not a date' => [['start_date' => '2026-02-30'], 'start_date'];
        yield 'no end' => [['end_date' => ''], 'end_date'];
        yield 'an end before the start' => [['end_date' => '2026-07-01'], 'end_date'];
        yield 'an end on the start' => [['end_date' => '2026-08-01'], 'end_date'];
        yield 'a term of three years' => [['end_date' => '2029-08-01'], 'end_date'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadTerms')]
    public function testBadInputIsRefusedWithTheFieldNamed(array $override, string $field): void
    {
        $e = $this->refused(fn () => $this->service->create($this->institution, $this->input($override), $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame(0, db_connect()->table('academic_terms')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testTheSameNameInTheSameYearIsRefusedButAnotherYearOrInstitutionIsFine(): void
    {
        $this->service->create($this->institution, $this->input(), $this->actor);

        $e = $this->refused(fn () => $this->service->create($this->institution, $this->input(['start_date' => '2027-01-05', 'end_date' => '2027-05-20']), $this->actor));
        $this->assertArrayHasKey('name', $e->errors());

        $this->service->create($this->institution, $this->input(['academic_year' => '2027-2028', 'start_date' => '2027-08-01', 'end_date' => '2027-12-20']), $this->actor);
        $this->service->create($this->other, $this->input(['name' => 'First Semester', 'academic_year' => '2026-2027']), $this->actor);

        $this->assertSame(2, db_connect()->table('academic_terms')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testAnOverlappingTermIsAllowedWithAWarning(): void
    {
        $this->service->create($this->institution, $this->input(), $this->actor);

        $result = $this->service->create($this->institution, $this->input(['name' => 'Summer Term', 'start_date' => '2026-12-01', 'end_date' => '2027-02-01']), $this->actor);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('First Semester', $result['warnings'][0]);
        $this->assertSame(2, db_connect()->table('academic_terms')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testAnotherInstitutionsTermsNeverCountAsOverlap(): void
    {
        $result = $this->service->create($this->institution, $this->input(['start_date' => '2026-08-01', 'end_date' => '2026-12-20']), $this->actor);

        $this->assertSame([], $result['warnings'], 'the other institution has a term in the same dates');
    }

    public function testATermCanBeEditedAndOnlyWhatChangedIsAudited(): void
    {
        $id = $this->service->create($this->institution, $this->input(), $this->actor)['id'];

        $this->service->update($this->institution, $id, $this->input(['name' => 'Fall Semester', 'end_date' => '2026-12-18']), $this->actor);

        $row = db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray();
        $this->assertSame('Fall Semester', $row['name']);
        $this->assertSame('2026-12-18', $row['end_date']);

        $log = $this->audit('structure.term_updated');
        $this->assertStringContainsString('Fall Semester', (string) $log['new_values']);
        $this->assertStringNotContainsString('start_date', (string) $log['new_values']);
    }

    public function testSavingWithNothingChangedWritesNoAuditEntry(): void
    {
        $id = $this->service->create($this->institution, $this->input(), $this->actor)['id'];

        $this->service->update($this->institution, $id, $this->input(), $this->actor);

        $this->assertNull($this->audit('structure.term_updated'));
    }

    public function testATermCanBeStartedAndMovedBackWhenNobodyIsEnrolled(): void
    {
        $id = $this->service->create($this->institution, $this->input(), $this->actor)['id'];

        $this->assertSame([], $this->service->setStatus($this->institution, $id, 'active', $this->actor));
        $this->assertSame('active', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.term_started'));

        $this->service->setStatus($this->institution, $id, 'upcoming', $this->actor);
        $this->assertSame('upcoming', db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.term_set_upcoming'));
    }

    public function testStartingASecondTermWarnsThatAnotherIsActive(): void
    {
        $first  = $this->service->create($this->institution, $this->input(), $this->actor)['id'];
        $second = $this->service->create($this->institution, $this->input(['name' => 'Second Semester', 'start_date' => '2027-01-10', 'end_date' => '2027-05-20']), $this->actor)['id'];

        $this->service->setStatus($this->institution, $first, 'active', $this->actor);
        $warnings = $this->service->setStatus($this->institution, $second, 'active', $this->actor);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('First Semester', $warnings[0]);
        $this->assertSame('active', db_connect()->table('academic_terms')->where('id', $second)->get()->getRowArray()['status']);
    }

    public function testATermWithStudentsEnrolledCannotGoBackToUpcoming(): void
    {
        $course = SisFixture::course($this->other, 'C1', 'IT101');
        $termId = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        CourseFactory::enroll($course['offering'], PeopleFactory::person('student', 'Stu', 'Dent', null, $this->other));

        $e = $this->refused(fn () => $this->service->setStatus($this->other, $termId, 'upcoming', $this->actor));

        $this->assertStringContainsString('1 student enrolled', $e->getMessage());
        $this->assertSame('active', db_connect()->table('academic_terms')->where('id', $termId)->get()->getRowArray()['status']);
    }

    public function testACompletedTermIsClosedAndCannotBeEditedOrMoved(): void
    {
        $id = $this->service->create($this->institution, $this->input(), $this->actor)['id'];
        db_connect()->table('academic_terms')->where('id', $id)->update(['status' => 'completed']);

        $e = $this->refused(fn () => $this->service->update($this->institution, $id, $this->input(['name' => 'Renamed']), $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'active', $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());
    }

    public function testCompletedIsNotSettableFromHere(): void
    {
        $id = $this->service->create($this->institution, $this->input(), $this->actor)['id'];

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'completed', $this->actor));

        $this->assertStringContainsString('close a term', $e->getMessage());
    }

    public function testATermOfAnotherInstitutionIsA404ForEveryChange(): void
    {
        $foreign = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            fn () => $this->service->update($this->institution, $foreign, $this->input(['name' => 'Hacked']), $this->actor),
            fn () => $this->service->setStatus($this->institution, $foreign, 'upcoming', $this->actor),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign term must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheListHasOnlyThisInstitutionsTermsNewestFirstWithCountsAndOverlaps(): void
    {
        $a = $this->service->create($this->institution, $this->input(), $this->actor)['id'];
        $this->service->create($this->institution, $this->input(['name' => 'Summer', 'start_date' => '2026-12-01', 'end_date' => '2027-02-01']), $this->actor);
        $this->service->create($this->institution, $this->input(['name' => 'Next Year', 'academic_year' => '2027-2028', 'start_date' => '2027-08-01', 'end_date' => '2027-12-20']), $this->actor);

        $list = $this->service->list($this->institution);

        $this->assertSame(['Next Year', 'Summer', 'First Semester'], array_column($list, 'name'));
        $this->assertSame([], $list[0]['overlaps']);
        $this->assertSame(['First Semester'], $list[1]['overlaps']);
        $this->assertSame(['Summer'], $list[2]['overlaps']);
        $this->assertSame(0, (int) $list[2]['offering_count']);
        $this->assertSame($a, (int) $list[2]['id']);
    }
}

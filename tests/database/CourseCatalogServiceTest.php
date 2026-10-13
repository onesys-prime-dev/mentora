<?php

declare(strict_types=1);

use App\Services\Institutions\CourseCatalogService;
use App\Services\Institutions\StructureException;
use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisOwnership;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The course catalog's rules: codes and units, uniqueness within a program,
 * what cannot be switched off, the SIS lock on code/title/units, listing with
 * filters, sort and paging, and scoping to the institution.
 *
 * @internal
 */
final class CourseCatalogServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $program;

    private User $actor;

    private CourseCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->institution = SisFixture::institution('CATA');
        $this->other       = SisFixture::institution('CATB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new CourseCatalogService();

        $this->program = $this->programOf($this->institution);

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function programOf(int $institutionId): int
    {
        return (int) db_connect()->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$institutionId])->getRow()->id;
    }

    private function create(string $code = 'IT101', string $title = 'Introduction to Computing', array $more = []): int
    {
        return $this->service->create($this->institution, $more + ['program_id' => $this->program, 'course_code' => $code, 'course_title' => $title, 'units' => '3'], $this->actor);
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

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    public function testACourseIsCreatedUnderItsProgramWithTidyValuesAndAnAuditEntry(): void
    {
        $id = $this->create(' it 101 ', '  Introduction to Computing ', ['units' => '2.75', 'description' => ' First course. ']);

        $row = db_connect()->table('courses')->where('id', $id)->get()->getRowArray();

        $this->assertSame('IT 101', $row['course_code']);
        $this->assertSame('Introduction to Computing', $row['course_title']);
        $this->assertEqualsWithDelta(2.8, (float) $row['units'], 0.01);
        $this->assertSame('First course.', $row['description']);
        $this->assertSame('active', $row['status']);
        $this->assertSame($this->program, (int) $row['program_id']);

        $log = $this->audit('structure.course_created');
        $this->assertNotNull($log);
        $this->assertSame($this->institution, (int) $log['institution_id']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadCourses(): iterable
    {
        yield 'no code' => [['course_code' => '', 'course_title' => 'A title'], 'course_code'];
        yield 'a code with a symbol' => [['course_code' => 'IT#1', 'course_title' => 'A title'], 'course_code'];
        yield 'a code that is too long' => [['course_code' => str_repeat('A', 51), 'course_title' => 'A title'], 'course_code'];
        yield 'a title that is too short' => [['course_code' => 'OK1', 'course_title' => 'ab'], 'course_title'];
        yield 'no units' => [['course_code' => 'OK1', 'course_title' => 'A title', 'units' => ''], 'units'];
        yield 'units that are not a number' => [['course_code' => 'OK1', 'course_title' => 'A title', 'units' => 'three'], 'units'];
        yield 'zero units' => [['course_code' => 'OK1', 'course_title' => 'A title', 'units' => '0'], 'units'];
        yield 'too many units' => [['course_code' => 'OK1', 'course_title' => 'A title', 'units' => '100'], 'units'];
        yield 'a description that is too long' => [['course_code' => 'OK1', 'course_title' => 'A title', 'units' => '3', 'description' => str_repeat('x', 5001)], 'description'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadCourses')]
    public function testBadInputIsRefusedWithTheFieldNamed(array $input, string $field): void
    {
        $input += ['units' => '3'];

        $e = $this->refused(fn () => $this->service->create($this->institution, $input + ['program_id' => $this->program], $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertNull($this->audit('structure.course_created'));
    }

    public function testACodeIsUniqueWithinItsProgramOnly(): void
    {
        $this->create('IT101');

        $e = $this->refused(fn () => $this->create('it101', 'A different title'));
        $this->assertArrayHasKey('course_code', $e->errors());

        // The same code in another institution's program is fine.
        $this->service->create($this->other, ['program_id' => $this->programOf($this->other), 'course_code' => 'IT101', 'course_title' => 'Elsewhere', 'units' => '3'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testNothingIsCreatedUnderAnInactiveProgram(): void
    {
        db_connect()->table('programs')->where('id', $this->program)->update(['status' => 'inactive']);

        $e = $this->refused(fn () => $this->create());

        $this->assertArrayHasKey('program_id', $e->errors());
    }

    public function testACourseCannotBeFiledUnderAnotherInstitutionsProgram(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->create($this->institution, ['program_id' => $this->programOf($this->other), 'course_code' => 'EVIL1', 'course_title' => 'Evil course', 'units' => '3'], $this->actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing and the SIS lock
    |--------------------------------------------------------------------------
    */

    public function testACourseCanBeEditedAndTheChangeIsAuditedWithOldAndNew(): void
    {
        $id = $this->create();

        $this->service->update($this->institution, $id, ['course_code' => 'IT101A', 'course_title' => 'Computing, revised', 'units' => '4', 'description' => 'Now with a description.'], $this->actor);

        $row = db_connect()->table('courses')->where('id', $id)->get()->getRowArray();
        $this->assertSame('IT101A', $row['course_code']);
        $this->assertSame('Computing, revised', $row['course_title']);

        $log = $this->audit('structure.course_updated');
        $this->assertStringContainsString('IT101A', (string) $log['new_values']);
        $this->assertStringContainsString('IT101', (string) $log['old_values']);
    }

    public function testSavingWithNothingChangedWritesNoAuditEntry(): void
    {
        $id = $this->create();

        $this->service->update($this->institution, $id, ['course_code' => 'IT101', 'course_title' => 'Introduction to Computing', 'units' => '3', 'description' => ''], $this->actor);

        $this->assertNull($this->audit('structure.course_updated'));
    }

    public function testACodeCannotBeChangedOntoAnotherCoursesCode(): void
    {
        $this->create('IT101');
        $second = $this->create('IT102', 'Second course');

        $e = $this->refused(fn () => $this->service->update($this->institution, $second, ['course_code' => 'IT101', 'course_title' => 'Second course', 'units' => '3'], $this->actor));

        $this->assertArrayHasKey('course_code', $e->errors());
    }

    private function sisManage(int $courseId): void
    {
        (new \App\Services\Flags\FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->actor);
        (new SisConnectionService())->save($this->institution, true, [], false, $this->actor);

        db_connect()->table('sis_links')->insert(['institution_id' => $this->institution, 'entity' => 'course', 'external_id' => 'C-' . $courseId, 'local_id' => $courseId, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testASisManagedCourseKeepsItsCodeTitleAndUnitsButTheDescriptionCanChange(): void
    {
        $id = $this->create();
        $this->sisManage($id);

        $this->assertTrue((new SisOwnership())->managesCourse($id));

        foreach ([['course_code' => 'NEW1'], ['course_title' => 'A brand new title'], ['units' => '5']] as $change) {
            $input = $change + ['course_code' => 'IT101', 'course_title' => 'Introduction to Computing', 'units' => '3', 'description' => ''];
            $e     = $this->refused(fn () => $this->service->update($this->institution, $id, $input, $this->actor));

            $this->assertStringContainsString('student information system', $e->getMessage());
            $this->assertArrayHasKey(array_key_first($change), $e->errors());
        }

        $this->service->update($this->institution, $id, ['course_code' => 'IT101', 'course_title' => 'Introduction to Computing', 'units' => '3', 'description' => 'Ours to write.'], $this->actor);

        $this->assertSame('Ours to write.', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['description']);
        $this->assertSame('IT101', db_connect()->table('courses')->where('id', $id)->get()->getRowArray()['course_code']);
    }

    public function testTheLockEndsWhenTheConnectionIsSwitchedOffAndNeverTouchesOtherCourses(): void
    {
        $managed = $this->create('IT101');
        $own     = $this->create('IT102', 'Our own course');
        $this->sisManage($managed);

        $this->service->update($this->institution, $own, ['course_code' => 'IT102', 'course_title' => 'Our own course, renamed', 'units' => '3'], $this->actor);
        $this->assertSame('Our own course, renamed', db_connect()->table('courses')->where('id', $own)->get()->getRowArray()['course_title']);

        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertFalse((new SisOwnership())->managesCourse($managed));
        $this->service->update($this->institution, $managed, ['course_code' => 'IT101', 'course_title' => 'Now editable', 'units' => '3'], $this->actor);
        $this->assertSame('Now editable', db_connect()->table('courses')->where('id', $managed)->get()->getRowArray()['course_title']);
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function testACourseWithOfferingsInAnActiveTermCannotBeSwitchedOff(): void
    {
        $course = SisFixture::course($this->institution, 'C1', 'BUSY1');

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $course['course'], 'archived', $this->actor));

        $this->assertStringContainsString('1 offering in an active term', $e->getMessage());
        $this->assertSame('active', db_connect()->table('courses')->where('id', $course['course'])->get()->getRowArray()['status']);

        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);

        $this->service->setStatus($this->institution, $course['course'], 'archived', $this->actor);

        $this->assertSame('archived', db_connect()->table('courses')->where('id', $course['course'])->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.course_archived'));
    }

    public function testACourseGoesOffAndBackOnAndNotBackOnUnderAnInactiveProgram(): void
    {
        $id = $this->create();

        $this->service->setStatus($this->institution, $id, 'inactive', $this->actor);
        $this->assertNotNull($this->audit('structure.course_deactivated'));

        db_connect()->table('programs')->where('id', $this->program)->update(['status' => 'inactive']);
        $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'active', $this->actor));

        db_connect()->table('programs')->where('id', $this->program)->update(['status' => 'active']);
        $this->service->setStatus($this->institution, $id, 'active', $this->actor);

        $this->assertNotNull($this->audit('structure.course_reactivated'));
    }

    public function testAnUnknownStatusAndTheSameStatusAreHandled(): void
    {
        $id = $this->create();

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'deleted', $this->actor));
        $this->assertStringContainsString('not a status', $e->getMessage());

        $this->service->setStatus($this->institution, $id, 'active', $this->actor);
        $this->assertNull($this->audit('structure.course_reactivated'));
    }

    public function testAnotherInstitutionsCourseIsA404ForEveryChange(): void
    {
        $foreign = $this->service->create($this->other, ['program_id' => $this->programOf($this->other), 'course_code' => 'THEIRS', 'course_title' => 'Their course', 'units' => '3'], $this->actor);

        foreach ([
            fn () => $this->service->update($this->institution, $foreign, ['course_code' => 'X1', 'course_title' => 'Hacked title', 'units' => '3'], $this->actor),
            fn () => $this->service->setStatus($this->institution, $foreign, 'archived', $this->actor),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign course must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    public function testTheListHoldsOnlyThisInstitutionsCoursesWithTheirProgramAndFlags(): void
    {
        $this->create('IT101');
        $this->service->create($this->other, ['program_id' => $this->programOf($this->other), 'course_code' => 'THEIRS', 'course_title' => 'Their course', 'units' => '3'], $this->actor);
        $managed = $this->create('IT102', 'Managed course');
        $this->sisManage($managed);

        $result = $this->service->list($this->institution);

        $this->assertSame(2, $result['total']);
        $this->assertSame(['IT101', 'IT102'], array_column($result['rows'], 'course_code'));
        $this->assertSame('BSIT', $result['rows'][0]['program_code']);
        $this->assertSame([0, 1], array_map(static fn (array $r): int => (int) $r['sis_managed'], $result['rows']));
    }

    public function testTheListCanBeSearchedFilteredAndSorted(): void
    {
        $this->create('ENG200', 'Technical Writing', ['units' => '2']);
        $this->create('IT101', 'Introduction to Computing');
        $this->create('IT301', 'Databases', ['units' => '4']);
        $this->service->setStatus($this->institution, (int) db_connect()->table('courses')->where('course_code', 'ENG200')->get()->getRowArray()['id'], 'archived', $this->actor);

        // A search matches the code or the title ("Technical Writing" has "it" in it).
        $this->assertSame(['ENG200', 'IT101', 'IT301'], array_column($this->service->list($this->institution, ['q' => 'IT'])['rows'], 'course_code'));
        $this->assertSame(['IT101'], array_column($this->service->list($this->institution, ['q' => 'Computing'])['rows'], 'course_code'));
        $this->assertSame(['IT301'], array_column($this->service->list($this->institution, ['q' => 'databases'])['rows'], 'course_code'));
        $this->assertSame(['ENG200'], array_column($this->service->list($this->institution, ['status' => 'archived'])['rows'], 'course_code'));
        $this->assertSame(['IT301', 'IT101', 'ENG200'], array_column($this->service->list($this->institution, ['sort' => 'code', 'dir' => 'desc'])['rows'], 'course_code'));
        $this->assertSame(['IT301', 'IT101', 'ENG200'], array_column($this->service->list($this->institution, ['sort' => 'units', 'dir' => 'desc'])['rows'], 'course_code'));
        $this->assertSame($this->service->list($this->institution)['total'], $this->service->list($this->institution, ['sort' => 'nonsense', 'dir' => 'sideways'])['total'], 'an unknown sort is ignored');
        $this->assertCount(3, $this->service->list($this->institution, ['program' => $this->program])['rows']);
        $this->assertSame([], $this->service->list($this->institution, ['program' => $this->programOf($this->other)])['rows']);
    }

    public function testTheListPagesAndClampsAnOutOfRangePage(): void
    {
        for ($i = 1; $i <= CourseCatalogService::PER_PAGE + 5; $i++) {
            $this->create(sprintf('C%03d', $i), 'Course number ' . $i);
        }

        $first = $this->service->list($this->institution);
        $this->assertSame(CourseCatalogService::PER_PAGE + 5, $first['total']);
        $this->assertSame(2, $first['pages']);
        $this->assertCount(CourseCatalogService::PER_PAGE, $first['rows']);

        $second = $this->service->list($this->institution, ['page' => 2]);
        $this->assertCount(5, $second['rows']);

        $this->assertSame(2, $this->service->list($this->institution, ['page' => 99])['page']);
        $this->assertSame(1, $this->service->list($this->institution, ['page' => -4])['page']);
    }

    public function testProgramsForTheFormsAreTheInstitutionsOwn(): void
    {
        $mine = $this->service->programs($this->institution);

        $this->assertSame([$this->program], array_column($mine, 'id'));

        db_connect()->table('programs')->where('id', $this->program)->update(['status' => 'inactive']);

        $this->assertSame([], $this->service->programs($this->institution));
        $this->assertCount(1, $this->service->programs($this->institution, false));
    }
}

<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingSchemeService;
use App\Services\Courses\RosterRiskService;
use App\Services\Institutions\AcademicDefaults;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The academic defaults end to end: what is accepted and refused, that a new
 * offering's grading scheme starts from the institution's values (and another
 * institution's does not), that existing schemes are left alone, and that the
 * thresholds change who the at-risk list holds.
 *
 * @internal
 */
final class AcademicDefaultsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $actor;

    private AcademicDefaults $defaults;

    private int $platformScale;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('ACDA');
        $this->other       = SisFixture::institution('ACDB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->defaults    = new AcademicDefaults();

        // The platform's template: 72%, a scale and two categories.
        $this->platformScale = GradeFactory::scale();
        GradeFactory::systemDefault(72.0, ['Quizzes' => 40, 'Exams' => 60]);
        db_connect()->table('grading_schemes')->where(['is_system_default' => 1, 'course_offering_id' => null])->update(['grade_scale_id' => $this->platformScale]);

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function offering(int $institution, string $section = 'A'): int
    {
        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'C' . $section . $institution, 'course_title' => 'Course', 'units' => 3]);
        $course = (int) $db->insertID();
        $term   = (int) $db->table('academic_terms')->where('institution_id', $institution)->get()->getRowArray()['id'];

        return (new OfferingService())->create($institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => $section, 'capacity' => '30'], $this->actor);
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

    /** @return array<string, mixed> */
    private function scheme(int $offeringId): array
    {
        return db_connect()->table('grading_schemes')->where('course_offering_id', $offeringId)->get()->getRowArray();
    }

    /** @return list<array{string, float}> */
    private function categories(int $schemeId): array
    {
        $rows = db_connect()->table('grade_categories')->where('grading_scheme_id', $schemeId)->orderBy('sort_order')->get()->getResultArray();

        return array_map(static fn (array $r): array => [$r['name'], (float) $r['weight_percentage']], $rows);
    }

    public function testWithNothingSetANewOfferingGetsThePlatformTemplate(): void
    {
        $offering = $this->offering($this->institution);

        $scheme = (new GradingSchemeService())->forOffering($offering);

        $this->assertSame(72.0, (float) $scheme['passing_percentage']);
        $this->assertSame($this->platformScale, (int) $scheme['grade_scale_id']);
        $this->assertSame([['Quizzes', 40.0], ['Exams', 60.0]], $this->categories((int) $scheme['id']));
    }

    public function testANewOfferingStartsFromTheInstitutionsDefaults(): void
    {
        $scale = GradeFactory::scale(['A' => 95, 'F' => 0]);

        $this->defaults->save($this->institution, [
            'passing_percentage' => '60', 'grade_scale_id' => (string) $scale,
            'category_name' => ['Projects', 'Finals', '', '', '', ''], 'category_weight' => ['70', '30', '', '', '', ''],
        ], $this->actor);

        $scheme = (new GradingSchemeService())->forOffering($this->offering($this->institution));

        $this->assertSame(60.0, (float) $scheme['passing_percentage']);
        $this->assertSame($scale, (int) $scheme['grade_scale_id']);
        $this->assertSame([['Projects', 70.0], ['Finals', 30.0]], $this->categories((int) $scheme['id']));
    }

    public function testAnotherInstitutionStillGetsThePlatformTemplate(): void
    {
        $this->defaults->save($this->institution, ['passing_percentage' => '60', 'category_name' => ['Only'], 'category_weight' => ['100']], $this->actor);

        $scheme = (new GradingSchemeService())->forOffering($this->offering($this->other));

        $this->assertSame(72.0, (float) $scheme['passing_percentage']);
        $this->assertSame([['Quizzes', 40.0], ['Exams', 60.0]], $this->categories((int) $scheme['id']));
    }

    public function testAnOfferingThatAlreadyHasASchemeKeepsIt(): void
    {
        $offering = $this->offering($this->institution);
        $before   = (new GradingSchemeService())->forOffering($offering);

        $this->defaults->save($this->institution, ['passing_percentage' => '50', 'category_name' => ['Only'], 'category_weight' => ['100']], $this->actor);

        $after = (new GradingSchemeService())->forOffering($offering);

        $this->assertSame((int) $before['id'], (int) $after['id']);
        $this->assertSame(72.0, (float) $after['passing_percentage']);
    }

    public function testTheInstitutionsTemplateIsNeverMistakenForThePlatformsOne(): void
    {
        $this->defaults->save($this->institution, ['category_name' => ['Only'], 'category_weight' => ['100']], $this->actor);

        $platform = (new \App\Models\Grades\GradingSchemeModel())->findSystemDefault();

        $this->assertNull($platform['institution_id']);
        $this->assertSame([['Quizzes', 40.0], ['Exams', 60.0]], $this->categories((int) $platform['id']));
    }

    public function testResetGoesBackToThePlatformValuesForNewOfferings(): void
    {
        $this->defaults->save($this->institution, ['passing_percentage' => '60', 'inactive_days' => '30', 'category_name' => ['Only'], 'category_weight' => ['100']], $this->actor);
        $this->defaults->reset($this->institution, $this->actor);

        $state = $this->defaults->forInstitution($this->institution);
        $this->assertFalse($state['passingFromInstitution']);
        $this->assertFalse($state['categoriesFromInstitution']);
        $this->assertSame(14, $state['inactiveDays']);

        $scheme = (new GradingSchemeService())->forOffering($this->offering($this->institution));
        $this->assertSame(72.0, (float) $scheme['passing_percentage']);
        $this->assertSame(2, db_connect()->table('audit_logs')->where('action', 'institution.academic_defaults_updated')->countAllResults());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideBadInput(): iterable
    {
        yield 'a passing mark of 0' => [['passing_percentage' => '0'], 'passing_percentage'];
        yield 'a passing mark over 100' => [['passing_percentage' => '101'], 'passing_percentage'];
        yield 'a passing mark that is text' => [['passing_percentage' => 'high'], 'passing_percentage'];
        yield 'a scale that does not exist' => [['grade_scale_id' => '999999'], 'grade_scale_id'];
        yield 'silence of 0 days' => [['inactive_days' => '0'], 'inactive_days'];
        yield 'silence of over a year' => [['inactive_days' => '400'], 'inactive_days'];
        yield 'behind below 0' => [['behind_below_percent' => '0'], 'behind_below_percent'];
        yield 'overdue grading of 0 days' => [['overdue_grading_days' => '0'], 'overdue_grading_days'];
        yield 'a high load of 0 units' => [['high_load_units' => '0'], 'high_load_units'];
        yield 'a high load of over 100 units' => [['high_load_units' => '101'], 'high_load_units'];
        yield 'a high load that is text' => [['high_load_units' => 'lots'], 'high_load_units'];
        yield 'weights that do not add up' => [['category_name' => ['A', 'B'], 'category_weight' => ['60', '30']], 'categories'];
        yield 'a category with no name' => [['category_name' => [''], 'category_weight' => ['100']], 'categories'];
        yield 'a category listed twice' => [['category_name' => ['A', 'a'], 'category_weight' => ['50', '50']], 'categories'];
        yield 'a weight of 0' => [['category_name' => ['A', 'B'], 'category_weight' => ['0', '100']], 'categories'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadInput')]
    public function testBadInputIsRefusedBesideTheFieldAndNothingIsSaved(array $input, string $field): void
    {
        $e = $this->refused(fn () => $this->defaults->save($this->institution, $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame(0, db_connect()->table('institution_academic_defaults')->countAllResults());
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'institution.academic_defaults_updated')->countAllResults());
    }

    public function testTheHighLoadIsThePlatformsUntilTheInstitutionSetsItAndIsAuditedWhenItDoes(): void
    {
        $this->assertSame(AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, $this->defaults->highLoadUnitsFor($this->institution));

        $this->defaults->save($this->institution, ['high_load_units' => '15'], $this->actor);

        $this->assertSame(15, $this->defaults->highLoadUnitsFor($this->institution));
        $this->assertTrue($this->defaults->forInstitution($this->institution)['highLoadFromInstitution']);
        $this->assertSame(AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, $this->defaults->highLoadUnitsFor($this->other), 'another institution is not affected');
        $this->assertStringContainsString('"high_load":15', (string) db_connect()->table('audit_logs')->where('action', 'institution.academic_defaults_updated')->get()->getRowArray()['new_values']);

        $this->defaults->save($this->institution, ['high_load_units' => ''], $this->actor);

        $this->assertSame(AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, $this->defaults->highLoadUnitsFor($this->institution), 'cleared, it is the platform value again');

        $this->defaults->save($this->institution, ['high_load_units' => '12'], $this->actor);
        $this->defaults->reset($this->institution, $this->actor);

        $this->assertSame(AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS, $this->defaults->highLoadUnitsFor($this->institution));
    }

    public function testTheThresholdsAreTheInstitutionsForItsOfferingsOnly(): void
    {
        $mine   = $this->offering($this->institution);
        $theirs = $this->offering($this->other);

        $this->defaults->save($this->institution, ['inactive_days' => '30', 'behind_below_percent' => '70', 'overdue_grading_days' => '3'], $this->actor);

        $map = $this->defaults->thresholdsForOfferings([$mine, $theirs]);

        $this->assertSame(['inactiveDays' => 30, 'behindBelow' => 70, 'overdueGradingDays' => 3], $map[$mine]);
        $this->assertSame(['inactiveDays' => 14, 'behindBelow' => 50, 'overdueGradingDays' => 7], $map[$theirs]);
        $this->assertSame(30, $this->defaults->thresholdsForOffering($mine)['inactiveDays']);
        $this->assertSame(30, $this->defaults->thresholdsForUser($this->actor)['inactiveDays']);
    }

    public function testTheAtRiskListFollowsTheInstitutionsThresholds(): void
    {
        $offering = $this->offering($this->institution);
        $teacher  = PeopleFactory::person('instructor', 'Tom', 'Teacher', null, $this->institution);
        CourseFactory::assignInstructor($offering, $teacher);

        $module = CourseFactory::module($offering);
        $unit   = CourseFactory::unit($module);
        $items  = CourseFactory::items($unit, [['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'], ['title' => 'Four']]);

        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        CourseFactory::enroll($offering, $student);

        // Active 10 days ago, 3 of 4 items done: fine under the platform's 14 days and 50%.
        CourseFactory::progress($items[0], $student);
        CourseFactory::progress($items[1], $student);
        CourseFactory::progress($items[2], $student);
        db_connect()->table('learning_completions')->update(['completed_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

        $this->assertSame([], (new RosterRiskService())->atRisk([$offering]));

        // The institution counts a week of silence as inactive.
        $this->defaults->save($this->institution, ['inactive_days' => '7'], $this->actor);

        $list = (new RosterRiskService())->atRisk([$offering]);
        $this->assertCount(1, $list);
        $this->assertSame('inactive', $list[0]['reason']);

        // Or: back to 14 days but "behind" is below 90%: 75% is behind.
        $this->defaults->save($this->institution, ['inactive_days' => '14', 'behind_below_percent' => '90'], $this->actor);

        $list = (new RosterRiskService())->atRisk([$offering]);
        $this->assertCount(1, $list);
        $this->assertSame('behind', $list[0]['reason']);
    }

    public function testEveryChangeIsAuditedWithOldAndNewValues(): void
    {
        $this->defaults->save($this->institution, ['passing_percentage' => '60', 'inactive_days' => '30'], $this->actor);

        $audit = db_connect()->table('audit_logs')->where('action', 'institution.academic_defaults_updated')->get()->getRowArray();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('"mark":72', (string) $audit['old_values']);
        $this->assertStringContainsString('"mark":60', (string) $audit['new_values']);
    }
}

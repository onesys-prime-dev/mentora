<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionScope;
use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * "Does this belong to my institution?" for everything the institutional
 * administrator can be handed an id for. A foreign or missing id is never
 * owned, and `require()` answers it with a 404.
 *
 * @internal
 */
final class InstitutionScopeTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $mine;

    private int $theirs;

    private InstitutionScope $scope;

    /** @var array<string, int> */
    private array $own = [];

    /** @var array<string, int> */
    private array $foreign = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine   = SisFixture::institution('SCOPEA');
        $this->theirs = SisFixture::institution('SCOPEB');

        $this->own     = $this->tree($this->mine, 'A');
        $this->foreign = $this->tree($this->theirs, 'B');

        $admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->mine);
        $this->scope = new InstitutionScope($admin);
    }

    /**
     * One of everything, hung under the institution.
     *
     * @return array<string, int>
     */
    private function tree(int $institutionId, string $tag): array
    {
        $db = db_connect();

        $department = (int) $db->table('departments')->where('institution_id', $institutionId)->get()->getRowArray()['id'];
        $sub        = (int) $db->table('sub_departments')->where('department_id', $department)->get()->getRowArray()['id'];
        $program    = (int) $db->table('programs')->where('sub_department_id', $sub)->get()->getRowArray()['id'];
        $term       = (int) $db->table('academic_terms')->where('institution_id', $institutionId)->get()->getRowArray()['id'];

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => "SC{$tag}1", 'course_title' => "Course {$tag}", 'units' => 3]);
        $course = (int) $db->insertID();
        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A']);
        $offering = (int) $db->insertID();

        $person = PeopleFactory::person('student', 'Stu', "Dent{$tag}", null, $institutionId);

        return [
            'department' => $department, 'subDepartment' => $sub, 'program' => $program, 'term' => $term,
            'course' => $course, 'offering' => $offering, 'person' => (int) $person->id,
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function provideKinds(): iterable
    {
        foreach (['department', 'subDepartment', 'program', 'course', 'offering', 'term', 'person'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideKinds')]
    public function testItOwnsItsOwnAndNeverAForeignOne(string $kind): void
    {
        $method = 'owns' . ucfirst($kind);

        $this->assertTrue($this->scope->{$method}($this->own[$kind]), "own {$kind}");
        $this->assertFalse($this->scope->{$method}($this->foreign[$kind]), "foreign {$kind}");
        $this->assertFalse($this->scope->{$method}(999999), "missing {$kind}");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideKinds')]
    public function testRequirePassesForOwnAnd404sForeignAndMissing(string $kind): void
    {
        $this->scope->require($kind, $this->own[$kind]);

        foreach ([$this->foreign[$kind], 999999] as $id) {
            try {
                $this->scope->require($kind, $id);
                $this->fail("{$kind} {$id} must be refused");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheInstitutionComesFromWhoIsSignedInNotFromAnId(): void
    {
        $this->assertSame($this->mine, $this->scope->institutionId());
        $this->assertSame($this->mine, $this->scope->requireInstitutionId());
    }

    public function testSomeoneWithNoInstitutionOwnsNothing(): void
    {
        $nobody = new InstitutionScope(PeopleFactory::person('institutional_admin', 'No', 'Home'));

        $this->assertNull($nobody->institutionId());
        $this->assertFalse($nobody->ownsDepartment($this->own['department']));
        $this->assertFalse($nobody->ownsPerson($this->own['person']));
        $this->assertFalse($nobody->ownsOffering($this->own['offering']));
        $this->assertFalse($nobody->ownsTerm($this->own['term']));

        $this->expectException(PageNotFoundException::class);
        $nobody->requireInstitutionId();
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->scope->require('mystery', 1);
    }

    public function testARemovedPersonIsNotOwned(): void
    {
        db_connect()->table('users')->where('id', $this->own['person'])->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertFalse($this->scope->ownsPerson($this->own['person']));
    }

    public function testAPersonInTwoInstitutionsIsOwnedByBoth(): void
    {
        db_connect()->table('institution_users')->insert(['institution_id' => $this->theirs, 'user_id' => $this->own['person'], 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->scope->ownsPerson($this->own['person']));
    }
}

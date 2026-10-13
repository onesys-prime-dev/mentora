<?php

declare(strict_types=1);

use App\Models\Academic\ParentStudentLinkModel;
use App\Services\Institutions\ParentLinkService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The parent-to-student links: who may be linked, by number or email,
 * skipped names with their reason, no duplicates, removal that touches
 * nothing else, the list, the migration's key, and scoping.
 *
 * @internal
 */
final class ParentLinkServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $actor;

    private User $parent;

    private ParentLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('PARA');
        $this->other       = SisFixture::institution('PARB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@parents.test', $this->institution);
        $this->service     = new ParentLinkService();

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function student(string $first, ?string $number = null, ?int $institution = null): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', strtolower($first) . '@parents.test', $institution ?? $this->institution);

        if ($number !== null) {
            db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);
        }

        return $user;
    }

    private function links(): int
    {
        return (new ParentStudentLinkModel())->where('parent_user_id', $this->parent->id)->countAllResults();
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

    public function testStudentsAreLinkedByNumberOrEmailAndAudited(): void
    {
        $this->student('Ann', '2026-001');
        $this->student('Ben');

        $result = $this->service->link($this->institution, (int) $this->parent->id, "2026-001\nBen@Parents.test", $this->actor);

        $this->assertSame(2, $result['linked']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(2, $this->links());
        $this->assertSame(2, db_connect()->table('audit_logs')->where('action', 'parent.linked')->countAllResults());
    }

    public function testThoseWhoCannotBeLinkedAreSkippedWithTheReasonAndTheRestAreLinked(): void
    {
        $this->student('Ann', '2026-001');
        $this->student('Zed', '2026-009', $this->other);
        PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@parents.test', $this->institution);

        $result = $this->service->link($this->institution, (int) $this->parent->id, '2026-001, 2026-009, tom@parents.test, ghost@parents.test', $this->actor);

        $this->assertSame(1, $result['linked']);

        $reasons = array_column($result['skipped'], 'reason', 'identifier');
        $this->assertStringContainsString('not a student number or email in your institution', $reasons['2026-009']);
        $this->assertStringContainsString('student role', $reasons['tom@parents.test']);
        $this->assertCount(1, $result['warnings']);
    }

    public function testAnExistingLinkIsSkippedAndNothingLinkedIsRefused(): void
    {
        $this->student('Ann', '2026-001');
        $this->service->link($this->institution, (int) $this->parent->id, '2026-001', $this->actor);

        $e = $this->refused(fn () => $this->service->link($this->institution, (int) $this->parent->id, '2026-001', $this->actor));

        $this->assertArrayHasKey('students', $e->errors());
        $this->assertStringContainsString('already linked', $e->errors()['students']);
        $this->assertSame(1, $this->links());
    }

    public function testAnEmptyBoxIsRefused(): void
    {
        $e = $this->refused(fn () => $this->service->link($this->institution, (int) $this->parent->id, "  \n", $this->actor));

        $this->assertArrayHasKey('students', $e->errors());
    }

    public function testALinkIsRemovedAndNothingElseChanges(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $this->service->link($this->institution, (int) $this->parent->id, '2026-001', $this->actor);
        $id = (int) (new ParentStudentLinkModel())->first()['id'];

        $this->service->unlink($this->institution, $id, $this->actor);

        $this->assertSame(0, $this->links());
        $this->assertSame(1, db_connect()->table('users')->where(['id' => $ann->id, 'active' => 1])->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'parent.unlinked')->countAllResults());
    }

    public function testTheListShowsParentsWithTheirStudentsAndFiltersBySearch(): void
    {
        $this->student('Ann', '2026-001');
        $this->service->link($this->institution, (int) $this->parent->id, '2026-001', $this->actor);
        PeopleFactory::person('parent', 'Quinn', 'Other', 'quinn@parents.test', $this->institution);
        PeopleFactory::person('parent', 'Rae', 'Elsewhere', 'rae@parents.test', $this->other);

        $all = $this->service->list($this->institution);

        $this->assertSame(2, $all['total']);
        $this->assertSame('Ann Student', $all['rows'][1]['students'][0]['name'] ?? $all['rows'][0]['students'][0]['name']);

        $byName = $this->service->list($this->institution, ['q' => 'pam@parents']);
        $this->assertSame(1, $byName['total']);
        $this->assertCount(1, $byName['rows'][0]['students']);
    }

    public function testTheSameStudentCanBeLinkedToTwoParents(): void
    {
        $this->student('Ann', '2026-001');
        $second = PeopleFactory::person('parent', 'Quinn', 'Parent', null, $this->institution);

        $this->service->link($this->institution, (int) $this->parent->id, '2026-001', $this->actor);
        $this->service->link($this->institution, (int) $second->id, '2026-001', $this->actor);

        $this->assertSame(2, (new ParentStudentLinkModel())->countAllResults());
    }

    public function testAnotherInstitutionsParentsLinksAndNonParentsAreNotFound(): void
    {
        $theirParent = PeopleFactory::person('parent', 'Rae', 'Elsewhere', null, $this->other);
        $student     = $this->student('Zed', '2026-009', $this->other);

        $db = db_connect();
        $db->table('parent_student_links')->insert(['institution_id' => $this->other, 'parent_user_id' => $theirParent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);
        $linkId = (int) $db->insertID();

        foreach ([
            fn () => $this->service->link($this->institution, (int) $theirParent->id, '2026-001', $this->actor),
            fn () => $this->service->link($this->institution, (int) $this->actor->id, '2026-001', $this->actor),
            fn () => $this->service->link($this->institution, 999999, '2026-001', $this->actor),
            fn () => $this->service->unlink($this->institution, $linkId, $this->actor),
        ] as $do) {
            try {
                $do();
                $this->fail('A foreign id must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, (new ParentStudentLinkModel())->countAllResults());
    }
}

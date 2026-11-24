<?php

declare(strict_types=1);

use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileContextResolver;
use App\Services\Profiles\ProfileReader;
use CodeIgniter\Events\Events;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/** @internal */
final class ProfileReaderTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = PeopleFactory::institution('PR1');
    }

    private function profile(int $id, array $set): void
    {
        db_connect()->table('user_profiles')->where('user_id', $id)->update($set);
    }

    private function addGroup(int $id, string $group): void
    {
        db_connect()->table('auth_groups_users')->insert(['user_id' => $id, 'group' => $group, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testBuildNeverReturnsAKeyOutsideFields(): void
    {
        $p = PeopleFactory::person('instructor', 'Ann', 'Lee', null, $this->inst);
        $this->profile((int) $p->id, ['bio' => 'Hello', 'office_location' => 'Rm 1', 'pronouns' => 'she/her']);

        $m = (new ProfileReader())->build((int) $p->id, ['names']);
        $this->assertSame(['id', 'display_name', 'legal_name', 'pronouns'], array_keys($m));
        $this->assertNull($m['pronouns']);

        $full = (new ProfileReader())->build((int) $p->id, ['names', 'pronouns', 'roles', 'bio', 'staff_info', 'email']);
        $this->assertSame('she/her', $full['pronouns']);
        $this->assertSame('Hello', $full['bio']);
        $this->assertSame('Rm 1', $full['staff_info']['office_location']);
        $this->assertSame($p->email, $full['email']);
        foreach (['student_details', 'courses', 'children', 'parents', 'photo'] as $k) {
            $this->assertArrayNotHasKey($k, $full);
        }
    }

    public function testDisplayNameUsesPreferredFirstAndLegalNameIsFull(): void
    {
        $p = PeopleFactory::person('student', 'Robert', 'Reyes', null, $this->inst);
        $this->profile((int) $p->id, ['preferred_first_name' => 'Bob', 'middle_name' => 'Q', 'suffix' => 'Jr']);

        $m = (new ProfileReader())->build((int) $p->id, ['names']);
        $this->assertSame('Bob Reyes', $m['display_name']);
        $this->assertSame('Robert Q Reyes Jr', $m['legal_name']);
    }

    public function testRolesAreSortedByRank(): void
    {
        $p = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->inst);
        $this->addGroup((int) $p->id, 'instructor');
        $this->addGroup((int) $p->id, 'parent');

        $roles = (new ProfileReader())->build((int) $p->id, ['roles'])['roles'];
        $this->assertSame(['instructor', 'student', 'parent'], array_column($roles, 'role'));
    }

    public function testInstructorDetailIsHomeDepartmentElseTeachesIn(): void
    {
        ['college' => $c] = DeanFactory::college('PR', $this->inst);
        $d1      = DeanFactory::department($c, 'PR1');
        $d2      = DeanFactory::department($c, 'PR2');
        $term    = DeanFactory::term($this->inst, 'Now', date('Y-m-d', strtotime('-10 days')));
        $prog1   = DeanFactory::program($d1, 'PR1');
        $prog2   = DeanFactory::program($d2, 'PR2');
        $teacher = PeopleFactory::person('instructor', 'Tia', 'Teach', null, $this->inst);
        CourseFactory::assignInstructor(DeanFactory::offering($prog1, $term, 'A1'), $teacher);
        CourseFactory::assignInstructor(DeanFactory::offering($prog2, $term, 'B1'), $teacher);
        CourseFactory::assignInstructor(DeanFactory::offering($prog2, $term, 'B2'), $teacher);

        $detail = static fn () => (new ProfileReader())->build((int) $teacher->id, ['roles'])['roles'][0]['detail'];
        $this->assertSame('Teaches in Department PR2', $detail());

        $this->profile((int) $teacher->id, ['home_department_id' => $d1]);
        $this->assertSame('Department PR1', $detail());

        $none = PeopleFactory::person('instructor', 'No', 'Class', null, $this->inst);
        $this->assertNull((new ProfileReader())->build((int) $none->id, ['roles'])['roles'][0]['detail']);
    }

    public function testResolverBuildsContext(): void
    {
        $resolver = new ProfileContextResolver();
        $other    = PeopleFactory::institution('PR2');
        $viewer   = PeopleFactory::person('instructor', 'Vi', 'Ewer', null, $this->inst);
        $student  = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->inst);

        $this->assertTrue($resolver->for($viewer, (int) $viewer->id)->isSelf);

        $c = $resolver->for($viewer, (int) $student->id);
        $this->assertFalse($c->isSelf);
        $this->assertTrue($c->sameInstitution);
        $this->assertSame('instructor', $c->viewerRank);
        $this->assertSame(['student'], $c->subjectRoles);
        $this->assertTrue($c->subjectActive);
        $this->assertFalse($c->viewerIsInstitutionAdmin);

        $foreign = PeopleFactory::person('student', 'Far', 'Away', null, $other);
        $this->assertFalse($resolver->for($viewer, (int) $foreign->id)->sameInstitution);

        $this->assertNull($resolver->for($viewer, 999999));
    }

    public function testResolverParentChildDeactivatedAdminAndMultiRole(): void
    {
        $resolver = new ProfileContextResolver();
        $parent   = PeopleFactory::person('parent', 'Pa', 'Rent', null, $this->inst);
        $child    = PeopleFactory::person('student', 'Ch', 'Ild', null, $this->inst);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->inst, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);

        $c = $resolver->for($parent, (int) $child->id);
        $this->assertTrue($c->viewerIsParentOfSubject);
        $this->assertFalse($c->viewerIsChildOfSubject);
        $this->assertTrue($resolver->for($child, (int) $parent->id)->viewerIsChildOfSubject);

        db_connect()->table('users')->where('id', $child->id)->update(['active' => 0]);
        $this->assertFalse($resolver->for($parent, (int) $child->id)->subjectActive);
        db_connect()->table('users')->where('id', $child->id)->update(['active' => 1]);
        db_connect()->table('user_account_controls')->insert(['user_id' => $child->id, 'deactivated_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse($resolver->for($parent, (int) $child->id)->subjectActive);

        $sys = PeopleFactory::person('admin', 'Sys', 'Admin', null, $this->inst);
        $this->assertTrue($resolver->for($parent, (int) $sys->id)->subjectIsSystemAdmin);
        $this->assertNull($resolver->for($sys, (int) $parent->id)->viewerRank);

        $multi = PeopleFactory::person('student', 'Mu', 'Lti', null, $this->inst);
        $this->addGroup((int) $multi->id, 'institutional_admin');
        $this->addGroup((int) $multi->id, 'chairperson');
        $c = $resolver->for($multi, (int) $parent->id);
        $this->assertSame('chairperson', $c->viewerRank);
        $this->assertTrue($c->viewerIsInstitutionAdmin);
    }

    public function testAvatarForManyIsOneQueryAndShaped(): void
    {
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = (int) PeopleFactory::person('student', 'Émile', 'Zola', null, $this->inst)->id;
        }
        $this->profile($ids[0], ['preferred_first_name' => 'élan']);

        $queries = 0;
        $l       = static function () use (&$queries): void {
            $queries++;
        };
        Events::on('DBQuery', $l);
        $out = (new AvatarData())->forMany([...$ids, 999999]);
        Events::removeListener('DBQuery', $l);

        $this->assertSame(1, $queries);
        $this->assertCount(40, $out);
        $this->assertSame('ÉZ', $out[$ids[1]]['initials']);
        $this->assertSame('ÉZ', $out[$ids[0]]['initials']);
        $this->assertSame('élan Zola', $out[$ids[0]]['name']);
        $this->assertSame(AvatarData::COLOURS[$ids[1] % 8], $out[$ids[1]]['colour']);
        $this->assertNull($out[$ids[1]]['photo_url']);
        $this->assertCount(8, AvatarData::COLOURS);
    }

    public function testStudentDetailsHasProgramYearSectionCollegeOnly(): void
    {
        ['college' => $c] = DeanFactory::college('SD', $this->inst);
        $dept = DeanFactory::department($c, 'SD');
        $prog = DeanFactory::program($dept, 'SD');
        $term = DeanFactory::term($this->inst, 'Now', date('Y-m-d', strtotime('-10 days')));
        $s    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->inst);
        $this->profile((int) $s->id, ['program_id' => $prog, 'student_number' => '2026-0001']);

        $db = db_connect();
        $d  = (new ProfileReader())->build((int) $s->id, ['student_details'])['student_details'];
        $this->assertSame(['program', 'year', 'section', 'college'], array_keys($d));
        $this->assertNull($d['year']);
        $this->assertNull($d['section']);
        $this->assertSame('Program SD', $d['program']);

        $db->table('class_sections')->insert(['institution_id' => $this->inst, 'term_id' => $term, 'program_id' => $prog, 'year_level' => 3, 'code' => 'BSIT-3A']);
        $db->table('class_section_students')->insert(['class_section_id' => $db->insertID(), 'student_user_id' => $s->id]);
        $d = (new ProfileReader())->build((int) $s->id, ['student_details'])['student_details'];
        $this->assertSame(3, $d['year']);
        $this->assertSame('BSIT-3A', $d['section']);
        $this->assertSame('College SD', $d['college']);
    }

    public function testParentLinkMustBeLiveInTheLinkInstitution(): void
    {
        $resolver = new ProfileContextResolver();
        $other    = PeopleFactory::institution('PR3');
        $parent   = PeopleFactory::person('parent', 'Pa', 'Rent', null, $this->inst);
        $child    = PeopleFactory::person('student', 'Ch', 'Ild', null, $this->inst);

        $this->assertFalse($resolver->for($parent, (int) $child->id)->viewerIsParentOfSubject);

        // A link recorded against another institution than the memberships is stale.
        db_connect()->table('parent_student_links')->insert(['institution_id' => $other, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse($resolver->for($parent, (int) $child->id)->viewerIsParentOfSubject);
        $this->assertFalse($resolver->for($child, (int) $parent->id)->viewerIsChildOfSubject);

        db_connect()->table('parent_student_links')->truncate();
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->inst, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue($resolver->for($parent, (int) $child->id)->viewerIsParentOfSubject);
        $this->assertTrue($resolver->for($child, (int) $parent->id)->viewerIsChildOfSubject);
        $this->assertFalse($resolver->for($child, (int) $parent->id)->viewerIsParentOfSubject);
    }
}

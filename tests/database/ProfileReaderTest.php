<?php

declare(strict_types=1);

use App\Services\Profiles\AvatarData;
use App\Services\Profiles\ProfileContextResolver;
use App\Services\Profiles\ProfileReader;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
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

    private function viewer(): User
    {
        return PeopleFactory::person('instructor', 'Vi', 'Ewer', null, $this->inst);
    }

    /** @return array<string, mixed>|null */
    private function read(int $subjectId, ?User $viewer = null): ?array
    {
        return (new ProfileReader())->forViewer($viewer ?? $this->viewer(), $subjectId);
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

        db_connect()->table('user_account_controls')->insert(['user_id' => $p->id, 'deactivated_at' => date('Y-m-d H:i:s')]);
        $m = $this->read((int) $p->id);
        $this->assertSame(['id', 'display_name', 'legal_name', 'pronouns', 'roles', 'photo', 'deactivated_badge'], array_keys($m));
        $this->assertNull($m['pronouns']);
        db_connect()->table('user_account_controls')->where('user_id', $p->id)->delete();

        $full = $this->read((int) $p->id);
        $this->assertSame('she/her', $full['pronouns']);
        $this->assertSame('Hello', $full['bio']);
        $this->assertSame('Rm 1', $full['staff_info']['office_location']);
        $this->assertSame($p->email, $full['email']);
        foreach (['student_details', 'children', 'parents', 'deactivated_badge', 'admin_actions'] as $k) {
            $this->assertArrayNotHasKey($k, $full);
        }
    }

    public function testDisplayNameUsesPreferredFirstAndLegalNameIsFull(): void
    {
        $p = PeopleFactory::person('student', 'Robert', 'Reyes', null, $this->inst);
        $this->profile((int) $p->id, ['preferred_first_name' => 'Bob', 'middle_name' => 'Q', 'suffix' => 'Jr']);

        $m = $this->read((int) $p->id);
        $this->assertSame('Bob Reyes', $m['display_name']);
        $this->assertSame('Robert Q Reyes Jr', $m['legal_name']);
    }

    public function testRolesAreSortedByRank(): void
    {
        $p = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->inst);
        $this->addGroup((int) $p->id, 'instructor');
        $this->addGroup((int) $p->id, 'parent');

        $roles = $this->read((int) $p->id)['roles'];
        $this->assertSame(['instructor', 'student', 'parent'], array_column($roles, 'role'));
    }

    public function testCoursesCarrySectionCodeAndOriginForADualRoleSubject(): void
    {
        ['college' => $c] = DeanFactory::college('CS', $this->inst);
        $prog = DeanFactory::program(DeanFactory::department($c, 'CS'), 'CS');
        $term = DeanFactory::term($this->inst, 'Now', date('Y-m-d', strtotime('-10 days')));
        $subj = PeopleFactory::person('instructor', 'Duo', 'Role', null, $this->inst);
        $this->addGroup((int) $subj->id, 'student');
        $a = DeanFactory::offering($prog, $term, 'IT101', 'A');
        $b = DeanFactory::offering($prog, $term, 'IT101', 'B');
        $e = DeanFactory::offering($prog, $term, 'IT200', 'C');
        CourseFactory::assignInstructor($a, $subj);
        CourseFactory::assignInstructor($b, $subj);
        CourseFactory::enroll($e, $subj);

        $rows = $this->read((int) $subj->id, $subj)['courses'];
        $got  = array_map(static fn (array $r): string => $r['origin'] . ':' . $r['code'] . ' ' . $r['section'], $rows);
        sort($got);
        $this->assertSame(['enrolled:IT200 C', 'teaching:IT101 A', 'teaching:IT101 B'], $got);
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

        $detail = fn () => $this->read((int) $teacher->id)['roles'][0]['detail'];
        $this->assertSame('Teaches in Department PR2', $detail());

        $this->profile((int) $teacher->id, ['home_department_id' => $d1]);
        $this->assertSame('Department PR1', $detail());

        $none = PeopleFactory::person('instructor', 'No', 'Class', null, $this->inst);
        $this->assertNull($this->read((int) $none->id)['roles'][0]['detail']);
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
        $d  = $this->read((int) $s->id)['student_details'];
        $this->assertSame(['program', 'year', 'section', 'college'], array_keys($d));
        $this->assertNull($d['year']);
        $this->assertNull($d['section']);
        $this->assertSame('Program SD', $d['program']);

        $db->table('class_sections')->insert(['institution_id' => $this->inst, 'term_id' => $term, 'program_id' => $prog, 'year_level' => 3, 'code' => 'BSIT-3A']);
        $db->table('class_section_students')->insert(['class_section_id' => $db->insertID(), 'student_user_id' => $s->id]);
        $d = $this->read((int) $s->id)['student_details'];
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

        db_connect()->table('parent_student_links')->emptyTable();
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->inst, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue($resolver->for($parent, (int) $child->id)->viewerIsParentOfSubject);
        $this->assertTrue($resolver->for($child, (int) $parent->id)->viewerIsChildOfSubject);
        $this->assertFalse($resolver->for($child, (int) $parent->id)->viewerIsParentOfSubject);
    }

    private function link(User $parent, User $child): void
    {
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->inst, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<string> */
    private function roleNames(?array $m): array
    {
        return array_column($m['roles'] ?? [], 'role');
    }

    public function testForViewerReturnsNullWhenNotVisible(): void
    {
        $parent  = PeopleFactory::person('parent', 'Un', 'Linked', null, $this->inst);
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->inst);
        $this->assertNull($this->read((int) $student->id, $parent));
        $this->assertNull($this->read((int) $student->id, PeopleFactory::person('instructor', 'Far', 'Away', null, PeopleFactory::institution('PR2'))));
        $this->assertNull($this->read((int) PeopleFactory::person('admin', 'Sys', 'Admin', null, $this->inst)->id));
        $this->assertNull($this->read(999999));
        $m = $this->read((int) $student->id);
        $this->assertSame(['id', 'display_name', 'legal_name', 'pronouns', 'roles', 'bio', 'email', 'student_details', 'courses', 'photo'], array_keys($m));
    }

    public function testRolesShownPerViewer(): void
    {
        $studentParent = PeopleFactory::person('student', 'Sp', 'One', null, $this->inst);
        $this->addGroup((int) $studentParent->id, 'parent');
        $peer = PeopleFactory::person('student', 'Pe', 'Er', null, $this->inst);
        $this->assertSame(['student'], $this->roleNames($this->read((int) $studentParent->id, $peer)));
        $this->assertSame(['student', 'parent'], $this->roleNames($this->read((int) $studentParent->id)));

        $teacherParent = PeopleFactory::person('instructor', 'Tp', 'Two', null, $this->inst);
        $this->addGroup((int) $teacherParent->id, 'parent');
        $parent = PeopleFactory::person('parent', 'Pa', 'Rent', null, $this->inst);
        $this->assertSame(['instructor'], $this->roleNames($this->read((int) $teacherParent->id, $parent)));

        $teacherStudent = PeopleFactory::person('instructor', 'Ts', 'Three', null, $this->inst);
        $this->addGroup((int) $teacherStudent->id, 'student');
        $this->assertSame(['instructor'], $this->roleNames($this->read((int) $teacherStudent->id, $parent)));
        $this->link($parent, $teacherStudent);
        $this->assertSame(['instructor', 'student'], $this->roleNames($this->read((int) $teacherStudent->id, $parent)));

        // A child sees the parent role of their own parent; a deactivated subject keeps the same filter.
        $kid = PeopleFactory::person('student', 'Ki', 'Dd', null, $this->inst);
        $this->link($teacherParent, $kid);
        $this->assertSame(['instructor', 'parent'], $this->roleNames($this->read((int) $teacherParent->id, $kid)));
        db_connect()->table('user_account_controls')->insert(['user_id' => $studentParent->id, 'deactivated_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(['student'], $this->roleNames($this->read((int) $studentParent->id, $peer)));
    }

    public function testRoleDetailsForAdminChairDeanAndSystemAdmin(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ad', 'Min', null, $this->inst);
        $this->assertSame('Institution PR1', $this->read((int) $admin->id)['roles'][0]['detail']);

        ['college' => $c] = DeanFactory::college('MC', $this->inst);
        $dB    = DeanFactory::department($c, 'MB');
        $dA    = DeanFactory::department($c, 'MA');
        $chair = DeanFactory::chair($dB);
        db_connect()->table('chairperson_assignments')->insert(['department_id' => $dA, 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('Department MA', $this->read((int) $chair->id)['roles'][0]['detail']);

        $c2   = DeanFactory::college('MD', $this->inst)['college'];
        $dean = DeanFactory::dean($this->inst, $c2);
        db_connect()->table('dean_assignments')->insert(['college_id' => $c, 'dean_user_id' => $dean->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('College MC', $this->read((int) $dean->id)['roles'][0]['detail']);

        $sys = PeopleFactory::person('admin', 'Sys', 'Admin', null, $this->inst);
        $this->assertSame([['role' => 'admin', 'detail' => null]], $this->read((int) $sys->id, $sys)['roles']);
    }
}

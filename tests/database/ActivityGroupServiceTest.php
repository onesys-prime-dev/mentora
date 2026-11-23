<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupLimits;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Attaching a set to an assignment: the activity gets its own copy of the groups, limited to who may do the work.
 *
 * @internal
 */
final class ActivityGroupServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ActivityGroupService $service;

    private GroupSetService $sets;

    private int $offering;

    private int $module;

    private int $teacher;

    /** @var list<int> */
    private array $students = [];

    private int $set;

    private int $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->build();
    }

    private function build(): void
    {
        $this->service  = new ActivityGroupService();
        $this->sets     = new GroupSetService();
        $this->offering = CourseFactory::offering();
        $this->module   = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        $this->students = [];

        for ($i = 0; $i < 4; $i++) {
            $student          = CourseFactory::user();
            $this->students[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $this->set        = $this->sets->create($this->offering, ['name' => 'Set', 'formation_mode' => 'self_signup', 'min_size' => 2, 'max_size' => 3], $this->teacher);
        $a                = $this->sets->addGroup($this->set, 'A', $this->teacher);
        $b                = $this->sets->addGroup($this->set, 'B', $this->teacher);
        $members          = new GroupMembershipService();
        $members->addToSet($a, $this->students[0], $this->teacher);
        $members->addToSet($a, $this->students[1], $this->teacher);
        $members->addToSet($b, $this->students[2], $this->teacher);
        $this->assignment = CourseFactory::assessment($this->offering, $instructor, $this->module, null, 'Assignment');
    }

    private function refused(callable $fn): StructureException
    {
        try {
            $fn();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected a StructureException.');
    }

    private function audited(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /** @return array<string, list<int>> group name => member ids, for the activity */
    private function activityGroups(int $configId): array
    {
        $out = [];

        foreach (db_connect()->table('student_groups')->where('config_id', $configId)->orderBy('name')->get()->getResultArray() as $g) {
            $ids = array_column(db_connect()->table('student_group_members')->where('group_id', $g['id'])->orderBy('student_user_id')->get()->getResultArray(), 'student_user_id');
            $out[$g['name']] = array_map('intval', $ids);
        }

        return $out;
    }

    public function testAttachCopiesGroupsAndSettings(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, ['grading_mode' => 'individual'], $this->teacher);

        $config = $this->service->findByAssessment($this->assignment);
        $this->assertSame($id, (int) $config['id']);
        $this->assertSame($this->set, (int) $config['source_set_id']);
        $this->assertSame('self_signup', $config['formation_mode']);
        $this->assertSame(['2', '3'], [$config['min_size'], $config['max_size']]);
        $this->assertSame('individual', $config['grading_mode']);
        $this->assertSame('block', $config['ungrouped_policy']);
        $this->assertSame(['A' => [$this->students[0], $this->students[1]], 'B' => [$this->students[2]]], $this->activityGroups($id));
        $this->assertSame(0, db_connect()->table('student_group_members')->where('config_id', $id)->where('set_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testCopyIsIndependentOfSet(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        $before = $this->activityGroups($id);

        $groupA = (int) db_connect()->table('student_groups')->where('set_id', $this->set)->where('name', 'A')->get()->getRowArray()['id'];
        (new GroupMembershipService())->moveInSet($this->students[0], (int) db_connect()->table('student_groups')->where('set_id', $this->set)->where('name', 'B')->get()->getRowArray()['id'], $this->teacher);
        $this->sets->renameGroup($groupA, 'Renamed', $this->teacher);
        $this->sets->deleteGroup((int) db_connect()->table('student_groups')->where('set_id', $this->set)->where('name', 'B')->get()->getRowArray()['id'], $this->teacher);

        $this->assertSame($before, $this->activityGroups($id));

        // and the other way round
        db_connect()->table('student_group_members')->where('config_id', $id)->where('student_user_id', $this->students[1])->delete();
        $this->assertSame(1, db_connect()->table('student_group_members')->where('group_id', $groupA)->countAllResults());
    }

    public function testPoolLimitedToAssigneesWhenSettingOn(): void
    {
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'assign_to_specific_students' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('assessment_assignees')->insert(['assessment_id' => $this->assignment, 'student_user_id' => $this->students[0], 'created_at' => date('Y-m-d H:i:s')]);

        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);

        $this->assertSame([$this->students[0]], $this->service->pool($id));
        $this->assertSame(['A' => [$this->students[0]], 'B' => []], $this->activityGroups($id), 'students who were not assigned are not copied');
    }

    public function testPoolIsOfferingWhenNoAssigneeList(): void
    {
        $id = $this->service->attachAssessment($this->assignment, null, [], $this->teacher);
        $this->assertEqualsCanonicalizing($this->students, $this->service->pool($id));

        // the setting exists but is off: still everyone
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'assign_to_specific_students' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertEqualsCanonicalizing($this->students, $this->service->pool($id));
    }

    public function testOnlyAssignmentTypeAssessmentsAccepted(): void
    {
        $quiz = CourseFactory::assessment($this->offering, CourseFactory::user('instructor'), $this->module, null, 'Quiz');

        $this->assertSame('Groups can be used on assignments only.', $this->refused(fn () => $this->service->attachAssessment($quiz, null, [], $this->teacher))->getMessage());
        $this->assertNull($this->service->findByAssessment($quiz));
    }

    public function testOver50GroupsRefused(): void
    {
        for ($i = 0; $i < GroupLimits::MAX_GROUPS_PER_ACTIVITY - 1; $i++) {
            db_connect()->table('student_groups')->insert(['set_id' => $this->set, 'name' => "X{$i}", 'status' => 'active']);
        }

        // 2 + 49 = 51
        $this->assertStringContainsString('at most', $this->refused(fn () => $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher))->getMessage());
        $this->assertNull($this->service->findByAssessment($this->assignment));
    }

    public function testOneConfigPerAssessment(): void
    {
        $this->service->attachAssessment($this->assignment, null, [], $this->teacher);

        $this->refused(fn () => $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher));
        $this->assertSame(1, db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->countAllResults());
    }

    public function testSetFromAnotherOfferingRefused(): void
    {
        $other    = CourseFactory::offering('B');
        $foreign  = $this->sets->create($other, ['name' => 'Other', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 4], $this->teacher);

        $this->refused(fn () => $this->service->attachAssessment($this->assignment, $foreign, [], $this->teacher));
        $this->assertNull($this->service->findByAssessment($this->assignment));
    }

    public function testMembersNoLongerInPoolAreNotCopied(): void
    {
        db_connect()->table('enrollments')->where('offering_id', $this->offering)->where('student_user_id', $this->students[1])->update(['status' => 'dropped']);

        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);

        $this->assertSame(['A' => [$this->students[0]], 'B' => [$this->students[2]]], $this->activityGroups($id));
    }

    public function testAttachIsAtomicOnFailure(): void
    {
        // Real transactions are needed to see a rollback: leave the one the isolation opened and build again.
        db_connect()->transRollback();
        $this->build();

        $failing = new class () extends ActivityGroupService {
            protected function copyGroups(int $configId, string $column, int $sourceId): void
            {
                parent::copyGroups($configId, $column, $sourceId);

                throw new RuntimeException('boom');
            }
        };

        try {
            $this->refused(fn () => $failing->attachAssessment($this->assignment, $this->set, [], $this->teacher));

            $this->assertSame(0, db_connect()->table('activity_group_configs')->where('assessment_id', $this->assignment)->countAllResults());
            $this->assertSame(0, db_connect()->table('student_groups')->where('config_id IS NOT NULL', null, false)->countAllResults());
            $this->assertSame(0, $this->audited('groups.activity_attached'));
        } finally {
            db_connect()->table('course_offerings')->where('id', $this->offering)->delete();
            db_connect()->transBegin();
        }
    }

    public function testRecopyAndCopyFromRefusedWhenFrozen(): void
    {
        $id    = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        $other = $this->service->attachAssessment(CourseFactory::assessment($this->offering, CourseFactory::user('instructor'), $this->module, null, 'Assignment'), null, [], $this->teacher);

        // Before any freeze both work and replace the copy.
        $this->sets->addGroup($this->set, 'C', $this->teacher);
        $this->service->recopy($id, $this->teacher);
        $this->assertCount(3, $this->activityGroups($id));
        $this->service->copyFromActivity($other, $id, $this->teacher);
        $this->assertSame($this->activityGroups($id), $this->activityGroups($other));

        db_connect()->table('student_groups')->where('config_id', $id)->limit(1)->update(['frozen_at' => date('Y-m-d H:i:s')]);
        $before = $this->activityGroups($id);

        $this->refused(fn () => $this->service->recopy($id, $this->teacher));
        $this->refused(fn () => $this->service->copyFromActivity($id, $other, $this->teacher));
        $this->assertSame($before, $this->activityGroups($id));
    }

    public function testRecopyRefusedWithoutSourceSet(): void
    {
        $id = $this->service->attachAssessment($this->assignment, null, [], $this->teacher);

        $this->assertStringContainsString('not made from a group set', $this->refused(fn () => $this->service->recopy($id, $this->teacher))->getMessage());
    }

    public function testCopyFromActivityOfAnotherOfferingRefused(): void
    {
        $id      = $this->service->attachAssessment($this->assignment, null, [], $this->teacher);
        $other   = CourseFactory::offering('B');
        $module  = CourseFactory::module($other);
        $foreign = $this->service->attachAssessment(CourseFactory::assessment($other, CourseFactory::user('instructor'), $module, null, 'Assignment'), null, [], $this->teacher);

        $this->assertStringContainsString('another activity of this course', $this->refused(fn () => $this->service->copyFromActivity($id, $foreign, $this->teacher))->getMessage());
    }

    public function testDetachRemovesGroups(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);

        $this->service->detach($id, $this->teacher);

        $this->assertNull($this->service->findByAssessment($this->assignment));
        $this->assertSame(0, db_connect()->table('student_groups')->where('config_id', $id)->countAllResults());
        $this->assertSame(0, db_connect()->table('student_group_members')->where('config_id', $id)->countAllResults());
        $this->assertSame(1, $this->audited('groups.activity_detached'));
        $this->assertSame(2, db_connect()->table('student_groups')->where('set_id', $this->set)->countAllResults(), 'the set is untouched');
    }

    public function testDetachRefusedWhenFrozen(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        db_connect()->table('student_groups')->where('config_id', $id)->limit(1)->update(['frozen_at' => date('Y-m-d H:i:s')]);

        $this->refused(fn () => $this->service->detach($id, $this->teacher));
        $this->assertCount(2, $this->activityGroups($id));
    }

    public function testFinalizedOfferingRefuses(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        $second = CourseFactory::assessment($this->offering, CourseFactory::user('instructor'), $this->module, null, 'Assignment');
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->service->attachAssessment($second, $this->set, [], $this->teacher));
        $this->refused(fn () => $this->service->recopy($id, $this->teacher));
        $this->refused(fn () => $this->service->copyFromActivity($id, $id, $this->teacher));
        $this->refused(fn () => $this->service->detach($id, $this->teacher));
        $this->assertCount(2, $this->activityGroups($id));
        $this->assertNull($this->service->findByAssessment($second));
    }

    public function testAttachWritesAuditRow(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);

        $row = db_connect()->table('audit_logs')->where('action', 'groups.activity_attached')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, $this->audited('groups.activity_attached'));
        $this->assertStringContainsString((string) $id, (string) json_encode($row));
    }

    public function testConfigForeignKeysCascade(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        $this->assertSame(2, db_connect()->table('student_groups')->where('config_id', $id)->countAllResults());

        db_connect()->table('activity_group_configs')->where('id', $id)->delete();

        $this->assertSame(0, db_connect()->table('student_groups')->where('config_id', $id)->countAllResults());
        $this->assertSame(0, db_connect()->table('student_group_members')->where('config_id', $id)->countAllResults());
        $this->assertSame(2, db_connect()->table('student_groups')->where('set_id', $this->set)->countAllResults());
    }

    public function testAddRenameDeleteActivityGroupsAudit(): void
    {
        $id     = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);
        $before = $this->audited('groups.group_added');

        $c = $this->service->addGroup($id, '  Team C ', $this->teacher);
        $this->assertSame('Team C', db_connect()->table('student_groups')->where('id', $c)->get()->getRow()->name);
        $this->assertSame($id, (int) db_connect()->table('student_groups')->where('id', $c)->get()->getRow()->config_id);

        $this->service->renameGroup($c, 'Gamma', $this->teacher);
        $this->assertSame('Gamma', db_connect()->table('student_groups')->where('id', $c)->get()->getRow()->name);
        $this->assertArrayHasKey('name', $this->refused(fn () => $this->service->renameGroup($c, ' ', $this->teacher))->errors());

        $a = (int) db_connect()->table('student_groups')->where('config_id', $id)->where('name', 'A')->get()->getRow()->id;
        $this->service->deleteGroup($a, $this->teacher);
        $this->assertSame(0, db_connect()->table('student_group_members')->where('group_id', $a)->countAllResults());
        $this->assertSame(3, db_connect()->table('student_group_members')->where('set_id', $this->set)->countAllResults(), 'the set keeps its members');

        $this->assertSame($before + 1, $this->audited('groups.group_added'));
        $this->assertSame(1, $this->audited('groups.group_renamed'));
        $this->assertSame(1, $this->audited('groups.group_deleted'));

        // A set group is not an activity group.
        $setGroup = (int) db_connect()->table('student_groups')->where('set_id', $this->set)->get()->getRow()->id;
        $this->refused(fn () => $this->service->renameGroup($setGroup, 'X', $this->teacher));
    }

    public function testActivityGroupLimitsFrozenAndFinalized(): void
    {
        $id = $this->service->attachAssessment($this->assignment, $this->set, [], $this->teacher);

        for ($i = 0; $i < GroupLimits::MAX_GROUPS_PER_ACTIVITY - 2; $i++) {
            db_connect()->table('student_groups')->insert(['config_id' => $id, 'name' => "X{$i}", 'status' => 'active']);
        }

        $this->assertStringContainsString('at most', $this->refused(fn () => $this->service->addGroup($id, 'One too many', $this->teacher))->getMessage());

        $a = (int) db_connect()->table('student_groups')->where('config_id', $id)->where('name', 'A')->get()->getRow()->id;
        db_connect()->table('student_groups')->where('id', $a)->update(['frozen_at' => date('Y-m-d H:i:s')]);
        $this->refused(fn () => $this->service->deleteGroup($a, $this->teacher));
        $this->assertSame(2, db_connect()->table('student_group_members')->where('group_id', $a)->countAllResults());

        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        $this->refused(fn () => $this->service->renameGroup($a, 'Late', $this->teacher));
        $this->assertSame('A', db_connect()->table('student_groups')->where('id', $a)->get()->getRow()->name);
    }

    public function testNewMigrationsRollBackAndForward(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-01-000004_CreateActivityGroupConfigs.php';
        require_once APPPATH . 'Database/Migrations/2026-12-01-000005_AddConfigForeignKeysToGroups.php';

        $keys   = new \App\Database\Migrations\AddConfigForeignKeysToGroups();
        $config = new \App\Database\Migrations\CreateActivityGroupConfigs();

        db_connect()->transRollback(); // DDL commits

        try {
            $keys->down();
            $config->down();
            db_connect()->resetDataCache();
            $this->assertFalse(db_connect()->tableExists('activity_group_configs'));
        } finally {
            db_connect()->query('DROP TABLE IF EXISTS activity_group_configs');
            $config->up();
            $keys->up();
            db_connect()->resetDataCache();
        }

        $this->assertTrue(db_connect()->tableExists('activity_group_configs'));
        db_connect()->transBegin();
    }
}

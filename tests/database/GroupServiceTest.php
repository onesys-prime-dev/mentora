<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Staff groups: who may start one, whom they may put in it, and that a group
 * is made whole or not at all.
 *
 * @internal
 */
final class GroupServiceTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $department;

    private int $subA;

    private int $subB;

    private User $chairA;

    private User $chairB;

    private User $dean;

    private User $teacherA1;

    private User $teacherA2;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::department('GR1');
        $this->institution = $d['institution'];
        $this->department  = $d['department'];
        $this->subA        = DeanFactory::subDepartment($this->department, 'GRA');
        $this->subB        = DeanFactory::subDepartment($this->department, 'GRB');
        $term              = DeanFactory::term($this->institution);
        $offeringA         = DeanFactory::offering(DeanFactory::program($this->subA, 'GPA'), $term, 'GA101');
        $offeringB         = DeanFactory::offering(DeanFactory::program($this->subB, 'GPB'), $term, 'GB101');

        $this->chairA = DeanFactory::chair($this->subA, 'Cara');
        $this->chairB = DeanFactory::chair($this->subB, 'Bram');
        $this->dean   = DeanFactory::dean($this->institution, $this->department, 'Dana');

        $this->teacherA1 = PeopleFactory::person('instructor', 'Ivy', 'Alpha', null, $this->institution);
        $this->teacherA2 = PeopleFactory::person('instructor', 'Ian', 'Alpha', null, $this->institution);
        $this->teacherB  = PeopleFactory::person('instructor', 'Ira', 'Bravo', null, $this->institution);

        CourseFactory::assignInstructor($offeringA, $this->teacherA1);
        CourseFactory::assignInstructor($offeringA, $this->teacherA2);
        CourseFactory::assignInstructor($offeringB, $this->teacherB);
    }

    private function groupService(): GroupService
    {
        return new GroupService();
    }

    private function messages(): MessageService
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageService(new MessageCipher($config));
    }

    /** @return array{int, int} conversations and participants */
    private function counts(): array
    {
        return [db_connect()->table('conversations')->countAllResults(), db_connect()->table('conversation_participants')->countAllResults()];
    }

    public function testAChairpersonStartsAGroupOfTheirOwnInstructors(): void
    {
        $group = $this->groupService()->create($this->chairA, '  Exam planning  ', [$this->teacherA1->id, $this->teacherA2->id]);

        $this->assertSame('group', $group['type']);
        $this->assertSame('Exam planning', $group['title']);
        $this->assertSame((int) $this->chairA->id, (int) $group['created_by']);
        $this->assertSame($this->institution, (int) $group['institution_id']);

        $members = array_map('intval', array_column(db_connect()->table('conversation_participants')->select('user_id')->where('conversation_id', $group['id'])->get()->getResultArray(), 'user_id'));
        $expected = [(int) $this->chairA->id, (int) $this->teacherA1->id, (int) $this->teacherA2->id];
        sort($members);
        sort($expected);
        $this->assertSame($expected, $members);

        $row = (new ConversationPresenter($this->cipher()))->present($this->teacherA1, (new ConversationService())->listFor($this->teacherA1, 'group'))[0];
        $this->assertSame('Exam planning', $row['name']);
    }

    public function testADeanStartsAGroupOfInstructorsAndChairpersonsOfTheDepartment(): void
    {
        $group = $this->groupService()->create($this->dean, 'Department heads', [$this->teacherA1->id, $this->teacherB->id, $this->chairA->id, $this->chairB->id]);

        $this->assertNotNull($group);
        $this->assertSame(5, db_connect()->table('conversation_participants')->where('conversation_id', $group['id'])->countAllResults());
    }

    public function testEveryMemberTheCreatorMayNotAddRefusesTheWholeGroup(): void
    {
        $student   = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $parent    = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $admin     = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $outsider  = PeopleFactory::person('instructor', 'Out', 'Sider', null, DeanFactory::department('GR2')['institution']);
        $inactive  = PeopleFactory::person('instructor', 'Ina', 'Ctive', null, $this->institution, false);
        $before    = $this->counts();

        // A chairperson: another sub-department's instructor, a chairperson, the dean, and every kind of non-instructor.
        foreach ([$this->teacherB, $this->chairB, $this->dean, $student, $parent, $admin, $outsider, $inactive] as $bad) {
            $this->assertNull($this->groupService()->create($this->chairA, 'Group', [$this->teacherA1->id, $bad->id]), (string) $bad->first_name);
        }

        // A dean: students, parents, admins, deans and outsiders stay out.
        foreach ([$student, $parent, $admin, $outsider, $inactive] as $bad) {
            $this->assertNull($this->groupService()->create($this->dean, 'Group', [$this->teacherA1->id, $bad->id]), (string) $bad->first_name);
        }

        $this->assertSame($before, $this->counts(), 'nothing was created');
    }

    public function testDuplicatesAndTheCreatorsOwnIdAreCollapsed(): void
    {
        $group = $this->groupService()->create($this->chairA, 'Group', [$this->teacherA1->id, (string) $this->teacherA1->id, $this->chairA->id]);

        $this->assertSame(2, db_connect()->table('conversation_participants')->where('conversation_id', $group['id'])->countAllResults());
    }

    public function testTheTitleAndTheMemberListAreChecked(): void
    {
        $one    = [$this->teacherA1->id];
        $before = $this->counts();

        $this->assertNull($this->groupService()->create($this->chairA, '', $one));
        $this->assertNull($this->groupService()->create($this->chairA, "  \t ", $one));
        $this->assertNull($this->groupService()->create($this->chairA, str_repeat('x', GroupService::MAX_TITLE + 1), $one));
        $this->assertNotNull($this->groupService()->create($this->chairA, str_repeat('é', GroupService::MAX_TITLE), $one));
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', []));
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', [$this->chairA->id]), 'only themselves');
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', range(1000, 1000 + GroupService::MAX_MEMBERS)));
        $this->assertSame([$before[0] + 1, $before[1] + 2], $this->counts());
    }

    public function testNobodyElseStartsGroupsOrSearchesForMembers(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $parent  = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ([$this->teacherA1, $student, $parent, $admin] as $user) {
            $this->assertNull($this->groupService()->create($user, 'Group', [$this->teacherA2->id]), (string) $user->first_name);
            $this->assertSame([], $this->groupService()->candidates($user, 'Alpha'), (string) $user->first_name);
        }
    }

    public function testCandidatesFollowTheScopeAndTheMinimumQuery(): void
    {
        $chair = $this->groupService()->candidates($this->chairA, 'Alpha');
        $this->assertEqualsCanonicalizing([(int) $this->teacherA1->id, (int) $this->teacherA2->id], array_column($chair, 'id'));
        $this->assertSame(['instructor', 'instructor'], array_column($chair, 'role'));

        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'Bravo'), 'another sub-department');
        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'A'), 'too short');
        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'Cara'), 'never the creator');

        $dean = $this->groupService()->candidates($this->dean, 'Bravo');
        $this->assertSame([(int) $this->teacherB->id], array_column($dean, 'id'));
        $this->assertSame([(int) $this->chairB->id], array_column($this->groupService()->candidates($this->dean, 'Bram'), 'id'));
        $this->assertSame(['id', 'name', 'role'], array_keys($chair[0]));
    }

    public function testMembersPostAndOnlyTheCreatorModerates(): void
    {
        $group = (int) $this->groupService()->create($this->chairA, 'Group', [$this->teacherA1->id, $this->teacherA2->id])['id'];

        $this->assertTrue($this->messages()->send($this->teacherA1, $group, 'Hello team', 'uuid-a1-1')['ok']);
        $id = (int) $this->messages()->send($this->teacherA2, $group, 'Off topic', 'uuid-a2-1')['message']['id'];

        $this->assertFalse($this->messages()->remove($this->teacherA1, $group, $id));
        $this->assertTrue($this->messages()->remove($this->chairA, $group, $id));

        $service = new ConversationService();
        $creator = (new ConversationPresenter($this->cipher()))->present($this->chairA, $service->listFor($this->chairA, 'group'))[0];
        $member  = (new ConversationPresenter($this->cipher()))->present($this->teacherA1, $service->listFor($this->teacherA1, 'group'))[0];

        $this->assertTrue($creator['can_moderate']);
        $this->assertFalse($member['can_moderate']);
    }

    private function cipher(): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }
}

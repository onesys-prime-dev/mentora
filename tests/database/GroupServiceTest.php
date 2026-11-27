<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionAccess;
use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
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

    private int $college;

    private int $departmentA;

    private int $departmentB;

    private int $offeringA;

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

        $d                 = DeanFactory::college('GR1');
        $this->institution = $d['institution'];
        $this->college  = $d['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'GRA');
        $this->departmentB        = DeanFactory::department($this->college, 'GRB');
        $term              = DeanFactory::term($this->institution);
        $offeringA         = DeanFactory::offering(DeanFactory::program($this->departmentA, 'GPA'), $term, 'GA101');
        $offeringB         = DeanFactory::offering(DeanFactory::program($this->departmentB, 'GPB'), $term, 'GB101');

        $this->chairA = DeanFactory::chair($this->departmentA, 'Cara');
        $this->chairB = DeanFactory::chair($this->departmentB, 'Bram');
        $this->dean   = DeanFactory::dean($this->institution, $this->college, 'Dana');

        $this->teacherA1 = PeopleFactory::person('instructor', 'Ivy', 'Alpha', null, $this->institution);
        $this->teacherA2 = PeopleFactory::person('instructor', 'Ian', 'Alpha', null, $this->institution);
        $this->teacherB  = PeopleFactory::person('instructor', 'Ira', 'Bravo', null, $this->institution);

        $this->offeringA = $offeringA;

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

    public function testADeanStartsAGroupOfInstructorsAndChairpersonsOfTheCollege(): void
    {
        $group = $this->groupService()->create($this->dean, 'College heads', [$this->teacherA1->id, $this->teacherB->id, $this->chairA->id, $this->chairB->id]);

        $this->assertNotNull($group);
        $this->assertSame(5, db_connect()->table('conversation_participants')->where('conversation_id', $group['id'])->countAllResults());
    }

    public function testEveryMemberTheCreatorMayNotAddRefusesTheWholeGroup(): void
    {
        $parent    = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $outsider  = PeopleFactory::person('instructor', 'Out', 'Sider', null, DeanFactory::college('GR2')['institution']);
        $inactive  = PeopleFactory::person('instructor', 'Ina', 'Ctive', null, $this->institution, false);
        $before    = $this->counts();

        // D1 opens the institution; a parent (no group capacity), outsiders and the inactive stay out.
        foreach ([$this->chairA, $this->dean] as $creator) {
            foreach ([$parent, $outsider, $inactive] as $bad) {
                $this->assertNull($this->groupService()->create($creator, 'Group', [$this->teacherA1->id, $bad->id]), (string) $bad->first_name);
            }
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

        // A blank name is allowed now (the group is named after its members): see testAnUntitledGroupIsNamedAfterItsOtherMembers.
        $this->assertNull($this->groupService()->create($this->chairA, str_repeat('x', GroupService::MAX_TITLE + 1), $one));
        $this->assertNotNull($this->groupService()->create($this->chairA, str_repeat('é', GroupService::MAX_TITLE), $one));
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', []));
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', [$this->chairA->id]), 'only themselves');
        $this->assertNull($this->groupService()->create($this->chairA, 'Group', range(1000, 1000 + GroupService::MAX_MEMBERS)));
        $this->assertSame([$before[0] + 1, $before[1] + 2], $this->counts());
    }

    public function testAParentNeverStartsGroupsOrSearchesForMembers(): void
    {
        $parent = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);

        $this->assertNull($this->groupService()->create($parent, 'Group', [$this->teacherA2->id]));
        $this->assertSame([], $this->groupService()->candidates($parent, 'Alpha'));
    }

    public function testCandidatesFollowTheScopeAndTheMinimumQuery(): void
    {
        $chair = $this->groupService()->candidates($this->chairA, 'Alpha');
        $this->assertEqualsCanonicalizing([(int) $this->teacherA1->id, (int) $this->teacherA2->id], array_column($chair, 'id'));
        $this->assertSame(['instructor', 'instructor'], array_column($chair, 'role'));

        $this->assertSame([(int) $this->teacherB->id], array_column($this->groupService()->candidates($this->chairA, 'Bravo'), 'id'), 'another department (D1)');
        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'A'), 'too short');
        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'Cara'), 'never the creator');

        $dean = $this->groupService()->candidates($this->dean, 'Bravo');
        $this->assertSame([(int) $this->teacherB->id], array_column($dean, 'id'));
        $this->assertSame([(int) $this->chairB->id], array_column($this->groupService()->candidates($this->dean, 'Bram'), 'id'));
        $this->assertSame(['id', 'name', 'role', 'avatar'], array_keys($chair[0]));
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

    public function testACandidateShowsTheirPreferredNameAndAvatarButIsFoundOnlyByTheLegalName(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->teacherA1->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);

        $found = $this->groupService()->candidates($this->chairA, 'Dela');

        $this->assertSame('Jun Dela Cruz', $found[0]['name']);
        $this->assertSame((int) $this->teacherA1->id, $found[0]['avatar']['id']);
        $this->assertTrue($found[0]['avatar']['interactive']);
        $this->assertSame([], $this->groupService()->candidates($this->chairA, 'Jun'), 'the preferred name is not searchable');
    }

    public function testCandidatesAreLookedUpOnce(): void
    {
        $n = 0;
        $l = static function (Query $q) use (&$n): void {
            $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
        };
        Events::on('DBQuery', $l);
        $found = $this->groupService()->candidates($this->chairA, 'Alpha');
        Events::removeListener('DBQuery', $l);

        $this->assertCount(2, $found);
        $this->assertSame(1, $n);
    }

    /** @return list<User> students enrolled in offering A (they share a course with each other and the instructors) */
    private function students(int $n): array
    {
        $out = [];

        for ($i = 1; $i <= $n; $i++) {
            $out[] = $s = PeopleFactory::person('student', 'Stu' . $i, 'Pupil', null, $this->institution);
            CourseFactory::enroll($this->offeringA, $s);
        }

        return $out;
    }

    /** @return list<string> */
    private function audited(): array
    {
        return array_column(db_connect()->table('audit_logs')->select('action')->like('action', 'messages.', 'after')->orderBy('id')->get()->getResultArray(), 'action');
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('conversations')->where('id', $id)->get()->getRowArray();
    }

    private function active(int $id, User $u): bool
    {
        return db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $u->id, 'left_at' => null])->countAllResults() > 0;
    }

    public function testStudentCanCreateGroup(): void
    {
        [$a, $b, $c] = $this->students(3);

        $group = $this->groupService()->create($a, 'Study group', [$b->id, $c->id]);

        $this->assertNotNull($group);
        $this->assertSame((int) $a->id, (int) $group['owner_id']);
        $this->assertSame((int) $a->id, (int) $group['created_by']);
        $this->assertSame(3, db_connect()->table('conversation_participants')->where('conversation_id', $group['id'])->countAllResults());
        // Being added is told at once (S2-g): the two others, never the creator.
        $this->assertSame(2, db_connect()->table('notifications')->where('type', 'message')->like('dedupe_key', 'group-add:' . $group['id'] . ':', 'after')->countAllResults());
    }

    /** S2-g: being added is in the app at once; it is emailed as their email setting says (D18), never in quiet hours. */
    public function testBeingAddedIsEmailedAsTheirEmailSettingSays(): void
    {
        $prefs = db_connect()->table('message_preferences');
        // teacherA2 switched email off; teacherB emails only from people they have talked with (they share no course
        // with chairA); the dean is in quiet hours all day. teacherA1 keeps the default: every message.
        $prefs->insert(['user_id' => $this->teacherA2->id, 'email_alerts' => 0, 'email_scope' => 'everyone']);
        $prefs->insert(['user_id' => $this->teacherB->id, 'email_alerts' => 1, 'email_scope' => 'talked']);
        $prefs->insert(['user_id' => $this->dean->id, 'email_alerts' => 1, 'email_scope' => 'everyone', 'quiet_mode' => 'own', 'quiet_start' => '00:00', 'quiet_end' => '23:59']);

        $group = $this->groupService()->create($this->chairA, 'Planning', [$this->teacherA1->id, $this->teacherA2->id, $this->teacherB->id, $this->dean->id]);
        $this->assertNotNull($group);

        $emailed = static fn ($user): ?int => ($row = db_connect()->table('notifications')->where('user_id', $user->id)->like('dedupe_key', 'group-add:' . $group['id'] . ':', 'after')->get()->getRowArray()) === null ? null : (int) $row['urgent'];

        $this->assertSame(1, $emailed($this->teacherA1));
        $this->assertSame(0, $emailed($this->teacherA2));
        $this->assertSame(0, $emailed($this->teacherB));
        $this->assertSame(0, $emailed($this->dean));
    }

    /** M6: the people a creator may add come a page at a time from a cursor, so every match can be reached. */
    public function testCandidatesArePagedSoEveryMatchCanBeReached(): void
    {
        $ids = [];

        for ($i = 1; $i <= 23; $i++) {
            $ids[] = (int) PeopleFactory::person('instructor', 'Quill' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Paging', null, $this->institution)->id;
        }

        $first = $this->groupService()->candidatePage($this->chairA, 'Quill', null);
        $this->assertCount(20, $first['candidates']);
        $this->assertNotNull($first['next']);

        $second = $this->groupService()->candidatePage($this->chairA, 'Quill', $first['next']);
        $this->assertNull($second['next']);

        $seen = [...array_column($first['candidates'], 'id'), ...array_column($second['candidates'], 'id')];
        $this->assertSame([], array_values(array_diff($ids, $seen)), 'every match is on a page');
        $this->assertSame(count($seen), count(array_unique($seen)), 'nobody twice');

        // A parent is never offered, and a cursor naming someone not offered finds nothing.
        $parent = PeopleFactory::person('parent', 'Quill', 'Parent', null, $this->institution);
        $this->assertNotContains((int) $parent->id, $seen);
        $this->assertSame(['candidates' => [], 'next' => null], $this->groupService()->candidatePage($this->chairA, 'Quill', (int) $parent->id));
    }

    /** A blank name leaves the group untitled: each member sees it named after the others, as Messenger does. */
    public function testAnUntitledGroupIsNamedAfterItsOtherMembers(): void
    {
        [$a, $b, $c, $d, $e] = $this->students(5);

        $group = $this->groupService()->create($a, "  \t ", [$b->id, $c->id]);
        $this->assertNotNull($group);
        $this->assertNull($group['title']);

        $name = fn (User $viewer, int $id): string => array_values(array_filter(
            (new ConversationPresenter($this->cipher()))->present($viewer, (new ConversationService())->listFor($viewer, 'group')),
            static fn (array $row): bool => $row['id'] === $id,
        ))[0]['name'];

        $this->assertSame('Stu2 Pupil and Stu3 Pupil', $name($a, (int) $group['id']));
        $this->assertSame('Stu1 Pupil and Stu3 Pupil', $name($b, (int) $group['id']));
        $this->assertSame('You were added to a group.', db_connect()->table('notifications')->where('user_id', $b->id)->like('dedupe_key', 'group-add:' . $group['id'] . ':', 'after')->get()->getRowArray()['body']);

        $big = $this->groupService()->create($a, '', [$b->id, $c->id, $d->id, $e->id]);
        $this->assertSame('Stu2 Pupil, Stu3 Pupil and 2 others', $name($a, (int) $big['id']));

        // A name is still at most MAX_TITLE characters.
        $this->assertNull($this->groupService()->create($a, str_repeat('x', GroupService::MAX_TITLE + 1), [$b->id]));
    }

    public function testParentCannotCreateOrBeAdded(): void
    {
        [$a, $b] = $this->students(2);
        $parent  = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $group   = $this->groupService()->create($a, 'G', [$b->id]);

        $this->assertNull($this->groupService()->create($parent, 'G', [$a->id]));
        $this->assertNull($this->groupService()->create($a, 'G2', [$parent->id]));
        $this->assertSame(['added' => [], 'refused' => [(int) $parent->id]], $this->groupService()->add($a, (int) $group['id'], [$parent->id]));

        // An administrator may not slip a parent in either.
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->assertSame([(int) $parent->id], $this->groupService()->add($admin, (int) $group['id'], [$parent->id])['refused']);
    }

    public function testMemberMustBeReachableByActor(): void
    {
        [$a, $b] = $this->students(2);
        $stranger = PeopleFactory::person('student', 'Far', 'Away', null, $this->institution);
        (new \App\Services\Messaging\StudentMessaging())->setScope($this->institution, 'course');
        $group    = $this->groupService()->create($a, 'G', [$b->id]);

        $this->assertNull($this->groupService()->create($a, 'G2', [$b->id, $stranger->id]));
        $this->assertSame(['added' => [], 'refused' => [(int) $stranger->id]], $this->groupService()->add($a, (int) $group['id'], [$stranger->id]));
        $this->assertFalse($this->active((int) $group['id'], $stranger));
    }

    public function testBlockedActorCannotAddBlocker(): void
    {
        [$a, $b, $c] = $this->students(3);
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $b->id, 'blocked_id' => $a->id, 'created_at' => date('Y-m-d H:i:s')]);
        $group = $this->groupService()->create($a, 'G', [$c->id]);

        $this->assertNull($this->groupService()->create($a, 'G2', [$b->id]));
        $this->assertSame([(int) $b->id], $this->groupService()->add($a, (int) $group['id'], [$b->id])['refused']);
    }

    public function testFiftyMemberLimit(): void
    {
        $this->assertSame(50, GroupService::MAX_MEMBERS);

        [$a, $extra] = $this->students(2);
        $group       = (int) $this->groupService()->create($a, 'G', [$extra->id])['id'];

        // Fill the group to the limit directly: 48 more people besides the two.
        for ($i = 0; $i < 48; $i++) {
            $p = PeopleFactory::person('student', 'Fill' . $i, 'Er', null, $this->institution);
            db_connect()->table('conversation_participants')->insert(['conversation_id' => $group, 'user_id' => $p->id, 'joined_at' => date('Y-m-d H:i:s'), 'first_visible_message_id' => 0]);
        }

        [$late] = $this->students(1);
        $this->assertSame(['added' => [], 'refused' => [(int) $late->id]], $this->groupService()->add($a, $group, [$late->id]));

        db_connect()->table('conversation_participants')->where(['conversation_id' => $group, 'user_id' => $extra->id])->update(['left_at' => date('Y-m-d H:i:s')]);
        $this->assertSame([(int) $late->id], $this->groupService()->add($a, $group, [$late->id])['added']);
        $this->assertNull($this->groupService()->create($a, 'Too big', range(5001, 5050)));
    }

    public function testOwnerRemovesAndRenames(): void
    {
        [$a, $b, $c] = $this->students(3);
        $id          = (int) $this->groupService()->create($a, 'Old', [$b->id, $c->id])['id'];

        $this->assertFalse($this->groupService()->remove($b, $id, (int) $c->id), 'a member cannot remove');
        $this->assertFalse($this->groupService()->rename($b, $id, 'Mine'));
        $this->assertTrue($this->groupService()->remove($a, $id, (int) $c->id));
        $this->assertTrue($this->groupService()->rename($a, $id, '  New name '));
        $this->assertFalse($this->groupService()->rename($a, $id, ''));
        $this->assertFalse($this->groupService()->remove($a, $id, 999999));

        $this->assertSame('New name', $this->row($id)['title']);
        $this->assertFalse($this->active($id, $c));
        $this->assertTrue($this->active($id, $b));
        $this->assertSame(['group_created', 'member_removed', 'renamed'], array_column(db_connect()->table('conversation_events')->where('conversation_id', $id)->orderBy('id')->get()->getResultArray(), 'event'));
    }

    public function testMemberLeavesLosesAccess(): void
    {
        [$a, $b, $c] = $this->students(3);
        $id          = (int) $this->groupService()->create($a, 'G', [$b->id, $c->id])['id'];

        $this->assertTrue($this->groupService()->leave($b, $id));
        $this->assertFalse($this->groupService()->leave($b, $id), 'already out');
        $this->assertNull((new ConversationService())->find($b, $id));
        $this->assertSame('not_found', $this->messages()->send($b, $id, 'hi', 'uuid-left-b-1')['reason']);
        $this->assertSame((int) $a->id, (int) $this->row($id)['owner_id'], 'the owner is unchanged');
    }

    public function testHandoverPrefersStaff(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id, $this->teacherA1->id, $this->teacherA2->id])['id'];
        // The student has been there longest; of the two staff members, teacher A2.
        db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $b->id])->update(['joined_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
        db_connect()->table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $this->teacherA2->id])->update(['joined_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);

        $this->assertTrue($this->groupService()->leave($a, $id));
        $this->assertSame((int) $this->teacherA2->id, (int) $this->row($id)['owner_id']);

        // With no staff left, the longest-standing member.
        $this->groupService()->leave($this->teacherA2, $id);
        $this->groupService()->leave($this->teacherA1, $id);
        $this->assertSame((int) $b->id, (int) $this->row($id)['owner_id']);
    }

    public function testDeactivatedOwnerHandsOver(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id, $this->teacherA1->id])['id'];

        $this->groupService()->handOverFor((int) $a->id);

        $this->assertSame((int) $this->teacherA1->id, (int) $this->row($id)['owner_id']);
        $this->assertContains('messages.owner_changed', $this->audited());
    }

    public function testLastMemberClosesGroup(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];

        $this->assertTrue($this->groupService()->leave($b, $id));

        $this->assertNotNull($this->row($id)['closed_at']);
        $this->assertContains('messages.closed', $this->audited());
    }

    public function testClosedGroupIsReadOnlyNotDeleted(): void
    {
        [$a, $b, $c] = $this->students(3);
        $id          = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $this->assertTrue($this->messages()->send($a, $id, 'before', 'uuid-closed-1')['ok']);

        $this->assertTrue($this->groupService()->close($a, $id));
        $this->assertFalse($this->groupService()->close($a, $id), 'closing twice');

        $this->assertFalse($this->messages()->send($b, $id, 'after', 'uuid-closed-2')['ok']);
        $this->assertSame(1, db_connect()->table('messages')->where('conversation_id', $id)->countAllResults());
        $this->assertSame(['added' => [], 'refused' => [(int) $c->id]], $this->groupService()->add($a, $id, [$c->id]));
        $this->assertFalse($this->groupService()->rename($a, $id, 'x'));
        $this->assertFalse($this->groupService()->leave($b, $id));
        $this->assertNotNull((new ConversationService())->find($b, $id), 'still readable');

        // The list must not offer a composer that the send then refuses (FR7).
        $rows = array_values(array_filter(
            (new ConversationPresenter($this->cipher()))->present($b, array_merge((new ConversationService())->listFor($b, 'group'), (new ConversationService())->listFor($b, 'group', false, true))),
            static fn (array $r): bool => (int) $r['id'] === $id,
        ));
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['can_post']);
    }

    public function testAnInstitutionAdminManagesAnyGroup(): void
    {
        [$a, $b, $c] = $this->students(3);
        $admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $id          = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];

        $this->assertSame([(int) $c->id], $this->groupService()->add($admin, $id, [$c->id])['added']);
        $this->assertTrue($this->groupService()->rename($admin, $id, 'Admin name'));
        $this->assertTrue($this->groupService()->remove($admin, $id, (int) $b->id));
        $this->assertTrue($this->groupService()->close($admin, $id));
    }

    public function testEveryEventAudited(): void
    {
        [$a, $b, $c] = $this->students(3);
        db_connect()->table('audit_logs')->emptyTable();

        $id = (int) $this->groupService()->create($a, 'GroupTitleOne', [$b->id])['id'];
        $this->groupService()->add($a, $id, [$c->id]);
        $this->groupService()->remove($a, $id, (int) $c->id);
        $this->groupService()->rename($a, $id, 'GroupTitleTwo');
        $this->groupService()->leave($a, $id); // the owner leaves: ownership passes, then the group is down to one and closes
        $this->assertTrue($this->active($id, $b));

        $actions = $this->audited();
        foreach (['group_created', 'member_added', 'member_removed', 'renamed', 'member_left', 'owner_changed', 'closed'] as $name) {
            $this->assertContains('messages.' . $name, $actions);
        }

        // Ids and outcomes only: no title in any entry.
        $details = implode(' ', array_column(db_connect()->table('audit_logs')->select('new_values')->like('action', 'messages.', 'after')->get()->getResultArray(), 'new_values'));
        $this->assertStringNotContainsString('GroupTitle', $details);
    }

    public function testReadOnlyInstitutionRefusesGroupChanges(): void
    {
        [$a, $b, $c] = $this->students(3);
        $id          = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $refused     = function () use ($a, $b, $c, $id): void {
            $this->assertNull($this->groupService()->create($a, 'G2', [$b->id]));
            $this->assertSame([(int) $c->id], $this->groupService()->add($a, $id, [$c->id])['refused']);
            $this->assertFalse($this->groupService()->rename($a, $id, 'x'));
            $this->assertFalse($this->groupService()->remove($a, $id, (int) $b->id));
            $this->assertFalse($this->groupService()->leave($b, $id));
            $this->assertFalse($this->groupService()->close($a, $id));
        };

        // The read-only state (subscription ended, still inside the grace window), then the past state (deleted).
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->assertSame('read_only', (new InstitutionAccess())->stateFor((int) $a->id));
        $refused();

        db_connect()->table('institutions')->where('id', $this->institution)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $refused();
    }

    public function testEventsKeepTheNewestTwoHundred(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $rows    = [];

        for ($i = 0; $i < 205; $i++) {
            $rows[] = ['conversation_id' => $id, 'event' => 'renamed', 'actor_id' => $a->id, 'target_id' => null, 'created_at' => date('Y-m-d H:i:s')];
        }

        db_connect()->table('conversation_events')->insertBatch($rows);
        $newest = (int) db_connect()->table('conversation_events')->selectMax('id', 'm')->where('conversation_id', $id)->get()->getRow('m');

        $events = $this->groupService()->events($b, $id);
        $this->assertCount(200, $events);
        $this->assertSame($newest, $events[199]['id'], 'the newest line is kept');
        $this->assertSame($newest - 199, $events[0]['id'], 'oldest first');
    }

    public function testEventsAfterAnIdSendOnlyTheNewerOnes(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $this->groupService()->rename($a, $id, 'Two');
        $all = $this->groupService()->events($b, $id);

        $this->assertCount(2, $all);
        $this->assertSame([$all[1]['id']], array_column($this->groupService()->events($b, $id, null, null, $all[0]['id']), 'id'));
        $this->assertSame([], $this->groupService()->events($b, $id, null, null, $all[1]['id']));
    }

    public function testAutomaticCloseHasNoActorAndAHandoverNamesTheDeactivator(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $this->groupService()->leave($b, $id);

        $closed = db_connect()->table('conversation_events')->where(['conversation_id' => $id, 'event' => 'closed'])->get()->getRowArray();
        $this->assertNull($closed['actor_id']);
        $this->assertNull(db_connect()->table('audit_logs')->where('action', 'messages.closed')->get()->getRow('actor_user_id'));

        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $id2   = (int) $this->groupService()->create($a, 'G2', [$this->teacherA1->id, $this->teacherA2->id])['id'];
        $this->groupService()->handOverFor((int) $a->id, $admin);

        $this->assertSame((int) $admin->id, (int) db_connect()->table('audit_logs')->where('action', 'messages.owner_changed')->orderBy('id', 'DESC')->get()->getRow('actor_user_id'));
        $this->assertSame((int) $a->id, (int) db_connect()->table('conversation_events')->where(['conversation_id' => $id2, 'event' => 'owner_changed'])->get()->getRow('actor_id'));
    }

    public function testABulkAddThatCrossesTheCapAddsOnlyWhatFits(): void
    {
        [$a, $extra] = $this->students(2);
        $group       = (int) $this->groupService()->create($a, 'G', [$extra->id])['id'];
        $now         = date('Y-m-d H:i:s');

        for ($i = 0; $i < 46; $i++) {
            $p = PeopleFactory::person('student', 'Cap' . $i, 'Er', null, $this->institution);
            db_connect()->table('conversation_participants')->insert(['conversation_id' => $group, 'user_id' => $p->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $late = $this->students(3);
        $res  = $this->groupService()->add($a, $group, array_map(static fn (User $u): int => (int) $u->id, $late));

        $this->assertCount(2, $res['added']);
        $this->assertCount(1, $res['refused']);
        $this->assertSame(50, count(db_connect()->table('conversation_participants')->where(['conversation_id' => $group, 'left_at' => null])->get()->getResultArray()));
    }

    public function testAnyRestrictionStopsCreatingAndAdding(): void
    {
        [$a, $b, $c] = $this->students(3);
        $id          = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        db_connect()->table('message_restrictions')->insert(['institution_id' => $this->institution, 'user_id' => $a->id, 'kind' => 'no_new', 'source' => 'admin', 'starts_at' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertNull($this->groupService()->create($a, 'G2', [$c->id]));
        $this->assertSame([(int) $c->id], $this->groupService()->add($a, $id, [$c->id])['refused']);
    }

    public function testAClosedGroupAllowsNeitherPinningNorDeleteForEveryone(): void
    {
        [$a, $b] = $this->students(2);
        $id      = (int) $this->groupService()->create($a, 'G', [$b->id])['id'];
        $sent    = $this->messages()->send($a, $id, 'hello', 'uuid-closed-pin-1');
        $this->assertTrue($sent['ok']);
        $mid     = (int) $sent['message']['id'];
        $actions = new MessageActions($this->messages(), null, null, null, $this->cipher());

        $this->assertTrue($actions->pinMessage($b, $id, $mid, true)['ok'], 'open: anyone pins');
        $this->assertTrue($this->groupService()->close($a, $id));

        $this->assertSame('not_allowed', $actions->pinMessage($b, $id, $mid, false)['reason']);
        $this->assertFalse($actions->deleteForEveryone($a, $id, $mid)['ok']);
        $page = $this->messages()->page($a, $id);
        $this->assertFalse($page['messages'][0]['can_delete_everyone']);
        $this->assertFalse($page['messages'][0]['can_pin']);
    }
}

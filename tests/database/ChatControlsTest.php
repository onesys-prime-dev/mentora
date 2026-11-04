<?php

declare(strict_types=1);

use App\Services\Messaging\ChatControls;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Who may flip a chat's controls, and what a flip writes.
 *
 * @internal
 */
final class ChatControlsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChatControls $controls;

    private int $institution;

    private int $department;

    private int $subDepartment;

    private int $program;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controls      = new ChatControls();
        $d                   = DeanFactory::department('CC');
        $this->institution   = $d['institution'];
        $this->department    = $d['department'];
        $this->subDepartment = DeanFactory::subDepartment($this->department, 'CC');
        $this->program       = DeanFactory::program($this->subDepartment, 'CC');
        $this->term          = DeanFactory::term($this->institution);
    }

    private function join(int $chat, User $user): void
    {
        db_connect()->table('conversation_participants')->ignore(true)->insert([
            'conversation_id' => $chat, 'user_id' => $user->id, 'joined_at' => date('Y-m-d H:i:s'), 'first_visible_message_id' => 0,
        ]);
    }

    /** A course chat with a student and instructor in it. @return array{int, User, User} chat, student, instructor */
    private function courseChat(): array
    {
        $student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution, true);
        $instructor = PeopleFactory::person('instructor', 'Ins', 'Truct', null, $this->institution, true);
        $offering   = DeanFactory::offering($this->program, $this->term, 'CC' . random_int(100, 999));
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);
        $chat = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];
        $this->join($chat, $student);
        $this->join($chat, $instructor);

        return [$chat, $student, $instructor];
    }

    /** @return array<string, mixed> */
    private function chatRow(int $chat): array
    {
        return db_connect()->table('conversations')->where('id', $chat)->get()->getRowArray();
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testTheInstructorMaySetEverySwitchAndLock(): void
    {
        [$chat, , $instructor] = $this->courseChat();

        foreach ([...ChatControls::SWITCHES, 'lock'] as $switch) {
            $result = $this->controls->set($instructor, $chat, $switch, false);

            $this->assertTrue($result['ok'], $switch);
            $this->assertFalse($result['controls'][$switch], $switch);
        }

        $row = $this->chatRow($chat);
        $this->assertFalse(ChatControls::allows($row, 'reactions'));
        $this->assertFalse(ChatControls::forConversation($row)['bump']);
    }

    public function testAChairpersonAndADeanInScopeMayManageACourseChat(): void
    {
        [$chat] = $this->courseChat();
        $chair  = DeanFactory::chair($this->subDepartment);
        $dean   = DeanFactory::dean($this->institution, $this->department);
        $this->join($chat, $chair);
        $this->join($chat, $dean);

        $this->assertTrue($this->controls->set($chair, $chat, 'replies', false)['ok']);
        $this->assertTrue($this->controls->set($dean, $chat, 'forwarding', false)['ok']);
        $this->assertTrue($this->controls->set($chair, $chat, 'lock', true)['ok']);
        $this->assertSame(1, (int) $this->chatRow($chat)['is_locked']);
    }

    public function testAnOutOfScopeChairpersonOrDeanGetsNull(): void
    {
        [$chat] = $this->courseChat();
        $other  = DeanFactory::department('CX', $this->institution);
        $chair  = DeanFactory::chair(DeanFactory::subDepartment($other['department'], 'CX'));
        $dean   = DeanFactory::dean($this->institution, $other['department']);
        $this->join($chat, $chair);
        $this->join($chat, $dean);

        $this->assertNull($this->controls->set($chair, $chat, 'replies', false));
        $this->assertNull($this->controls->set($dean, $chat, 'replies', false));
        $this->assertTrue(ChatControls::allows($this->chatRow($chat), 'replies'));
    }

    public function testParticipantChairAndDeanAreRefusedOutOfScopeAndAllowedInScope(): void
    {
        [$chat] = $this->courseChat();
        $other  = DeanFactory::department('CY', $this->institution);
        $people = [
            'out' => [DeanFactory::chair(DeanFactory::subDepartment($other['department'], 'CY')), DeanFactory::dean($this->institution, $other['department'])],
            'in'  => [DeanFactory::chair($this->subDepartment), DeanFactory::dean($this->institution, $this->department)],
        ];

        foreach ($people as $group) {
            foreach ($group as $user) {
                $this->join($chat, $user);
            }
        }

        foreach ($people['out'] as $user) {
            $this->assertNull($this->controls->set($user, $chat, 'replies', false));
            $this->assertNull($this->controls->set($user, 999999, 'replies', false));
        }

        $this->assertTrue(ChatControls::allows($this->chatRow($chat), 'replies'));

        foreach ($people['in'] as $user) {
            $this->assertTrue($this->controls->set($user, $chat, 'replies', false)['ok']);
            $this->assertTrue($this->controls->set($user, $chat, 'replies', true)['ok']);
        }
    }

    public function testAnInstitutionalAdminParticipantMayChangeASwitch(): void
    {
        [$chat] = $this->courseChat();
        $admin  = PeopleFactory::person('institutional_admin', 'Ad', 'Min', null, $this->institution, true);
        $this->join($chat, $admin);

        $this->assertTrue($this->controls->set($admin, $chat, 'bump', false)['ok']);
        $this->assertFalse(ChatControls::allows($this->chatRow($chat), 'bump'));
    }

    public function testOnlyTheCreatorChairpersonMayLockAStaffGroup(): void
    {
        $creator = DeanFactory::chair($this->subDepartment);
        $second  = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'CZ'), 'Other');
        $now     = date('Y-m-d H:i:s');
        db_connect()->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'group', 'created_by' => $creator->id, 'is_locked' => 0,
            'title' => 'Staff', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $group = (int) db_connect()->insertID();
        $this->join($group, $creator);
        $this->join($group, $second);

        $this->assertNull($this->controls->set($second, $group, 'lock', true));
        $this->assertSame(0, (int) $this->chatRow($group)['is_locked']);
        $this->assertTrue($this->controls->set($creator, $group, 'lock', true)['ok']);
        $this->assertSame(1, (int) $this->chatRow($group)['is_locked']);
        $this->assertSame(1, $this->auditCount('messages.chat_control_changed'));
    }

    public function testAStudentAndAnOutsiderGetNull(): void
    {
        [$chat, $student] = $this->courseChat();
        $outsider         = PeopleFactory::person('instructor', 'Out', 'Sider', null, $this->institution, true);

        $this->assertNull($this->controls->set($student, $chat, 'reactions', false));
        $this->assertNull($this->controls->set($outsider, $chat, 'reactions', false));
        $this->assertNull($this->controls->set($student, 999999, 'reactions', false));
    }

    public function testADirectChatCannotBeManagedByAnyone(): void
    {
        [, $student, $instructor] = $this->courseChat();
        $direct                   = (int) (new ConversationService())->startDirect($student, (int) $instructor->id)['id'];

        $this->assertNull($this->controls->set($instructor, $direct, 'reactions', false));
        $this->assertNull($this->controls->set($student, $direct, 'reactions', false));
    }

    public function testAnUnknownSwitchIsInvalid(): void
    {
        [$chat, , $instructor] = $this->courseChat();

        $this->assertSame(['ok' => false, 'reason' => 'invalid'], $this->controls->set($instructor, $chat, 'nope', true));
    }

    public function testASwitchChangeIsAuditedWithTheSwitchNameOnly(): void
    {
        [$chat, , $instructor] = $this->courseChat();

        $this->controls->set($instructor, $chat, 'bump', false);

        $row = db_connect()->table('audit_logs')->where('action', 'messages.chat_control_changed')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringContainsString('bump', (string) json_encode($row));
        $this->assertSame(1, $this->auditCount('messages.chat_control_changed'));
    }

    public function testSettingTheSameValueWritesNoAuditRow(): void
    {
        [$chat, , $instructor] = $this->courseChat();

        $this->assertTrue($this->controls->set($instructor, $chat, 'reactions', true)['ok']);
        $this->assertSame(0, $this->auditCount('messages.chat_control_changed'));
    }

    public function testLockingACourseChatKeepsItsExistingAuditAction(): void
    {
        [$chat, , $instructor] = $this->courseChat();

        $this->controls->set($instructor, $chat, 'lock', true);
        $this->controls->set($instructor, $chat, 'lock', false);

        $this->assertSame(1, $this->auditCount('messages.course_chat_locked'));
        $this->assertSame(1, $this->auditCount('messages.course_chat_unlocked'));
        $this->assertSame(0, $this->auditCount('messages.chat_control_changed'));
    }
}

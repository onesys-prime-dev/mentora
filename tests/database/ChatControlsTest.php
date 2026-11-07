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
 * Only the institution's administrator flips a chat's controls, member or not; everyone else gets null. And what a flip writes.
 *
 * @internal
 */
final class ChatControlsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChatControls $controls;

    private int $institution;

    private int $college;

    private int $department;

    private int $program;

    private int $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controls      = new ChatControls();
        $d                   = DeanFactory::college('CC');
        $this->institution   = $d['institution'];
        $this->college    = $d['college'];
        $this->department = DeanFactory::department($this->college, 'CC');
        $this->program       = DeanFactory::program($this->department, 'CC');
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

    private function admin(?int $institution = null): User
    {
        return PeopleFactory::person('institutional_admin', 'Ad', 'Min', null, $institution ?? $this->institution, true);
    }

    public function testTheAdministratorMaySetEverySwitchAndLockWithoutBeingInTheChat(): void
    {
        [$chat] = $this->courseChat();
        $admin  = $this->admin();

        foreach ([...ChatControls::SWITCHES, 'lock'] as $switch) {
            $result = $this->controls->set($admin, $chat, $switch, false);

            $this->assertTrue($result['ok'], $switch);
            $this->assertFalse($result['controls'][$switch], $switch);
        }

        $row = $this->chatRow($chat);
        $this->assertFalse(ChatControls::allows($row, 'reactions'));
        $this->assertFalse(ChatControls::forConversation($row)['bump']);
    }

    public function testInstructorsChairpersonsAndDeansGetNullEvenInTheirOwnChats(): void
    {
        [$chat, , $instructor] = $this->courseChat();
        $chair                 = DeanFactory::chair($this->department);
        $dean                  = DeanFactory::dean($this->institution, $this->college);
        $this->join($chat, $chair);
        $this->join($chat, $dean);

        foreach ([$instructor, $chair, $dean] as $user) {
            $this->assertNull($this->controls->set($user, $chat, 'replies', false));
        }

        $this->assertTrue(ChatControls::allows($this->chatRow($chat), 'replies'));
    }

    public function testAStaffGroupsCreatorCannotLockItButTheAdministratorCan(): void
    {
        $creator = DeanFactory::chair($this->department);
        $now     = date('Y-m-d H:i:s');
        db_connect()->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'group', 'created_by' => $creator->id, 'is_locked' => 0,
            'title' => 'Staff', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $group = (int) db_connect()->insertID();
        $this->join($group, $creator);

        $this->assertNull($this->controls->set($creator, $group, 'lock', true));
        $this->assertSame(0, (int) $this->chatRow($group)['is_locked']);
        $this->assertTrue($this->controls->set($this->admin(), $group, 'lock', true)['ok']);
        $this->assertSame(1, (int) $this->chatRow($group)['is_locked']);
        $this->assertSame(1, $this->auditCount('messages.chat_control_changed'));
    }

    public function testAStudentAnOutsiderAndAnotherInstitutionsAdministratorGetNull(): void
    {
        [$chat, $student] = $this->courseChat();
        $outsider         = PeopleFactory::person('instructor', 'Out', 'Sider', null, $this->institution, true);
        $other            = $this->admin(DeanFactory::college('CW')['institution']);

        $this->assertNull($this->controls->set($student, $chat, 'reactions', false));
        $this->assertNull($this->controls->set($outsider, $chat, 'reactions', false));
        $this->assertNull($this->controls->set($other, $chat, 'reactions', false));
        $this->assertNull($this->controls->set($this->admin(), 999999, 'reactions', false));
    }

    public function testADirectChatCannotBeManagedByAnyone(): void
    {
        [, $student, $instructor] = $this->courseChat();
        $direct                   = (int) (new ConversationService())->startDirect($student, (int) $instructor->id)['id'];

        $this->assertNull($this->controls->set($this->admin(), $direct, 'reactions', false));
        $this->assertNull($this->controls->set($student, $direct, 'reactions', false));
    }

    public function testAnUnknownSwitchIsInvalid(): void
    {
        [$chat] = $this->courseChat();

        $this->assertSame(['ok' => false, 'reason' => 'invalid'], $this->controls->set($this->admin(), $chat, 'nope', true));
    }

    public function testASwitchChangeIsAuditedWithTheSwitchNameOnly(): void
    {
        [$chat] = $this->courseChat();

        $this->controls->set($this->admin(), $chat, 'bump', false);

        $row = db_connect()->table('audit_logs')->where('action', 'messages.chat_control_changed')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringContainsString('bump', (string) json_encode($row));
        $this->assertSame(1, $this->auditCount('messages.chat_control_changed'));
    }

    public function testSettingTheSameValueWritesNoAuditRow(): void
    {
        [$chat] = $this->courseChat();

        $this->assertTrue($this->controls->set($this->admin(), $chat, 'reactions', true)['ok']);
        $this->assertSame(0, $this->auditCount('messages.chat_control_changed'));
    }

    public function testLockingACourseChatKeepsItsExistingAuditAction(): void
    {
        [$chat] = $this->courseChat();
        $admin  = $this->admin();

        $this->controls->set($admin, $chat, 'lock', true);
        $this->controls->set($admin, $chat, 'lock', false);

        $this->assertSame(1, $this->auditCount('messages.course_chat_locked'));
        $this->assertSame(1, $this->auditCount('messages.course_chat_unlocked'));
        $this->assertSame(0, $this->auditCount('messages.chat_control_changed'));
    }

    public function testThePickerListsOnlyTheAdministratorsOwnInstitutionsChatsAndNothingForOthers(): void
    {
        [$chat, $student] = $this->courseChat();
        $names            = array_column($this->controls->chatsFor($this->admin()), 'id');

        $this->assertContains($chat, $names);
        $this->assertSame([], $this->controls->chatsFor($student));
        $this->assertNotContains($chat, array_column($this->controls->chatsFor($this->admin(DeanFactory::college('CV')['institution'])), 'id'));
    }
}

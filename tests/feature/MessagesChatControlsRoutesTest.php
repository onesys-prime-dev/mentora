<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * POST conversations/{id}/controls through the filters.
 *
 * @internal
 */
final class MessagesChatControlsRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private int $courseChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $offering         = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        $this->courseChat = (int) (new CourseChatSync())->syncOffering($offering)['conversation_id'];

        foreach ([$this->student, $this->instructor] as $user) {
            $this->join($this->courseChat, $user);
        }
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();

        parent::tearDown();
    }

    private function join(int $chat, User $user): void
    {
        db_connect()->table('conversation_participants')->ignore(true)->insert([
            'conversation_id' => $chat, 'user_id' => $user->id, 'joined_at' => date('Y-m-d H:i:s'), 'first_visible_message_id' => 0,
        ]);
    }

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @param array<string, mixed> $body */
    private function flip(int $chat, array $body): TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $this->withBodyFormat('json');

        return $this->step('POST', "messages/api/conversations/{$chat}/controls", $body);
    }

    private function admin(): User
    {
        return PeopleFactory::person('institutional_admin', 'Ad', 'Min', null, $this->institution, true);
    }

    public function testTheAdministratorFlipsASwitchWithoutBeingInTheChat(): void
    {
        $this->signIn($this->admin());

        $response = $this->flip($this->courseChat, ['switch' => 'reactions', 'on' => false])->response();
        $json     = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($json['ok']);
        $this->assertFalse($json['controls']['reactions']);
        $this->assertSame(0, (int) db_connect()->table('conversations')->where('id', $this->courseChat)->get()->getRow()->allow_reactions);
    }

    public function testAnInvalidSwitchIsRefused(): void
    {
        $this->signIn($this->admin());

        $this->assertSame(422, $this->flip($this->courseChat, ['switch' => 'nope', 'on' => false])->response()->getStatusCode());
    }

    public function testAnInstructorAChairpersonAndAForeignChatAnswerLikeAMissingId(): void
    {
        $a        = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        $b        = PeopleFactory::person('instructor', 'Oth', 'Teach', null, $this->institution);
        $offering = DeanFactory::offering($this->program, $this->term, 'RT2');
        CourseFactory::enroll($offering, $a);
        CourseFactory::assignInstructor($offering, $b);
        $foreign = (int) (new ConversationService())->startDirect($a, (int) $b->id)['id'];

        $chair = DeanFactory::chair((int) db_connect()->table('programs')->where('id', $this->program)->get()->getRow()->sub_department_id);
        $this->join($this->courseChat, $chair);

        $this->signIn($this->admin());
        $missing = $this->flip(999999, ['switch' => 'reactions', 'on' => false])->response();
        $missing = [$missing->getStatusCode(), $missing->getBody()];
        $theirs  = $this->flip($foreign, ['switch' => 'reactions', 'on' => false])->response();

        $this->assertSame(404, $missing[0]);
        $this->assertSame($missing, [$theirs->getStatusCode(), $theirs->getBody()]);

        // Nobody else manages controls, in scope or not, in the chat or not.
        foreach ([$this->instructor, $chair, $this->student] as $user) {
            $this->signIn($user);
            $response = $this->flip($this->courseChat, ['switch' => 'lock', 'on' => true])->response();
            $this->assertSame($missing, [$response->getStatusCode(), $response->getBody()]);
        }

        $this->assertSame(0, (int) db_connect()->table('conversations')->where('id', $this->courseChat)->get()->getRow()->is_locked);
    }

    public function testThePickerListsChatsForTheAdministratorOnly(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $this->signIn($this->admin());
        $ids = array_column(json_decode($this->step('GET', 'messages/api/chat-controls')->response()->getBody(), true)['chats'], 'id');

        $this->assertContains($this->courseChat, $ids);

        $this->signIn($this->instructor);
        $this->withHeaders(['Accept' => 'application/json']);
        $this->assertSame([], json_decode($this->step('GET', 'messages/api/chat-controls')->response()->getBody(), true)['chats']);
    }

    public function testAReadOnlyInstitutionIsRefused(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->admin());

        $this->assertSame(403, $this->flip($this->courseChat, ['switch' => 'reactions', 'on' => false])->response()->getStatusCode());
    }

    public function testItIsNotFoundWhileTheFlagIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();
        $this->signIn($this->admin());

        $this->assertSame(404, $this->flip($this->courseChat, ['switch' => 'reactions', 'on' => false])->response()->getStatusCode());
    }
}

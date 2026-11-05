<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Filters\AccountStateFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The message action routes (react, reactors) through the filters.
 *
 * @internal
 */
final class MessagesActionRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private int $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->message = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();

        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @param array<string, mixed> $data */
    private function api(string $verb, string $uri, array $data = []): TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        if ($verb === 'POST') {
            $this->withBodyFormat('json');
        }

        return $this->step($verb, 'messages/api/' . $uri, $data);
    }

    private function react(?string $emoji, ?int $message = null): TestResponse
    {
        return $this->api('POST', "conversations/{$this->conversation}/messages/" . ($message ?? $this->message) . '/react', ['emoji' => $emoji]);
    }

    public function testSignedOutVisitorsAreSentToLogin(): void
    {
        foreach ([['POST', 'react'], ['GET', 'reactors']] as [$verb, $tail]) {
            $response = $this->api($verb, "conversations/{$this->conversation}/messages/{$this->message}/{$tail}", [])->response();

            $this->assertSame(302, $response->getStatusCode(), $tail);
        }
    }

    public function testReactingReturnsTheCountsAndReactorsListsNames(): void
    {
        $this->signIn($this->student);
        $response = $this->react('👍')->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['reactions' => [['emoji' => '👍', 'count' => 1, 'mine' => true]]], json_decode($response->getBody(), true));

        $list = $this->api('GET', "conversations/{$this->conversation}/messages/{$this->message}/reactors")->response();
        $this->assertSame(['reactors' => ['👍' => ['Stu Dent']]], json_decode($list->getBody(), true));
    }

    public function testAnEmojiOutsideThePaletteIsRefused(): void
    {
        $this->signIn($this->student);

        $this->assertSame(422, $this->react('🦄')->response()->getStatusCode());
    }

    public function testASwitchedOffChatIsRefusedAndAForeignMessageIs404(): void
    {
        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_reactions' => 0]);
        $this->signIn($this->student);
        $this->assertSame(403, $this->react('👍')->response()->getStatusCode());

        $this->assertSame(404, $this->react('👍', 999999)->response()->getStatusCode());

        $this->signIn(\Tests\Support\PeopleFactory::person('student', 'Out', 'Sider', null, $this->institution));
        $this->assertSame(404, $this->react('👍')->response()->getStatusCode());
    }

    public function testTheSixtyFirstReactionInAMinuteIs429(): void
    {
        $db = db_connect();

        for ($i = 0; $i < 60; $i++) {
            $db->table('messages')->insert([
                'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
                'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->table('message_reactions')->insert(['message_id' => $db->insertID(), 'user_id' => $this->student->id, 'emoji' => '👍', 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->signIn($this->student);

        $this->assertSame(429, $this->react('🔥')->response()->getStatusCode());
    }

    public function testDeleteForEveryoneMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/delete-everyone";

        $this->signIn($this->student);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());

        $this->signIn($this->instructor);
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());

        db_connect()->table('messages')->insert([
            'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s', time() - 20 * 60),
        ]);
        $old = $this->api('POST', $uri((int) db_connect()->insertID()))->response();
        $this->assertSame(403, $old->getStatusCode());
        $this->assertSame(['error' => 'too_late'], json_decode($old->getBody(), true));
    }

    public function testBumpMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/bump";

        // The seeded body is not valid ciphertext, so a bump of it is unavailable.
        $this->signIn($this->student);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());

        $this->signIn($this->instructor);
        $this->assertSame(503, $this->api('POST', $uri($this->message))->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_bump' => 0]);
        $this->assertSame(403, $this->api('POST', $uri($this->message))->response()->getStatusCode());
    }

    public function testPinMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id, string $verb = 'pin'): string => "conversations/{$this->conversation}/messages/{$id}/{$verb}";

        $this->signIn($this->student);
        $this->assertSame(404, $this->api('POST', $uri(999999))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message))->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', $uri($this->message, 'unpin'))->response()->getStatusCode());

        $ids = [$this->message];

        for ($i = 0; $i < 3; $i++) {
            db_connect()->table('messages')->insert([
                'conversation_id' => $this->conversation, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
                'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ids[] = (int) db_connect()->insertID();
        }

        foreach (array_slice($ids, 0, 3) as $id) {
            $this->assertSame(200, $this->api('POST', $uri($id))->response()->getStatusCode());
        }

        $limited = $this->api('POST', $uri($ids[3]))->response();
        $this->assertSame(409, $limited->getStatusCode());
        $this->assertSame(['error' => 'limit', 'max' => 3], json_decode($limited->getBody(), true));
    }

    public function testAStudentPinningInACourseChatIsForbidden(): void
    {
        $offering = (int) db_connect()->table('course_offerings')->get()->getRowArray()['id'];
        $course   = (int) (new \App\Services\Messaging\CourseChatSync())->syncOffering($offering)['conversation_id'];

        db_connect()->table('messages')->insert([
            'conversation_id' => $course, 'sender_id' => $this->instructor->id, 'body_enc' => 'x',
            'client_uuid' => $this->uuid(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) db_connect()->insertID();

        $this->signIn($this->student);
        $response = $this->api('POST', "conversations/{$course}/messages/{$id}/pin")->response();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'not_allowed'], json_decode($response->getBody(), true));
    }

    public function testForwardMapsOutcomesToStatuses(): void
    {
        $uri = fn (int $id): string => "conversations/{$this->conversation}/messages/{$id}/forward";

        $this->signIn($this->instructor);
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => 'x'])->response()->getStatusCode());
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => ['a']])->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', $uri(999999), ['targets' => [1]])->response()->getStatusCode());
        $this->assertSame(422, $this->api('POST', $uri($this->message), ['targets' => [1, 2, 3, 4, 5, 6]])->response()->getStatusCode());

        // The seeded body is not valid ciphertext, so the copy is unavailable.
        $this->assertSame(503, $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 0]);
        $this->assertSame(403, $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response()->getStatusCode());

        db_connect()->table('conversations')->where('id', $this->conversation)->update(['allow_forwarding' => 1, 'subject_student_id' => $this->student->id]);
        $denied = $this->api('POST', $uri($this->message), ['targets' => [$this->conversation]])->response();
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(['error' => 'parent_thread'], json_decode($denied->getBody(), true));
    }

    public function testTheSystemAdministratorReachesNeitherRoute(): void
    {
        $this->signIn(CourseFactory::user('admin'));

        $this->assertSame(404, $this->react('👍')->response()->getStatusCode());
        $this->assertSame(404, $this->api('GET', "conversations/{$this->conversation}/messages/{$this->message}/reactors")->response()->getStatusCode());
    }

    /**
     * Every endpoint added for message actions, as name => [verb, uri, body]; controls and changes take a conversation only.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    private function actionEndpoints(int $conversationId, int $messageId): array
    {
        $m = "conversations/{$conversationId}/messages/{$messageId}";

        return [
            'react'           => ['POST', "{$m}/react", ['emoji' => '👍']],
            'reactors'        => ['GET', "{$m}/reactors", []],
            'delete-everyone' => ['POST', "{$m}/delete-everyone", []],
            'bump'            => ['POST', "{$m}/bump", []],
            'forward'         => ['POST', "{$m}/forward", ['targets' => [$this->conversation]]],
            'pin'             => ['POST', "{$m}/pin", []],
            'unpin'           => ['POST', "{$m}/unpin", []],
            'controls'        => ['POST', "conversations/{$conversationId}/controls", ['switch' => 'reactions', 'on' => false]],
            'changes'         => ['GET', "conversations/{$conversationId}/changes?since=2000-01-01+00:00:00", []],
        ];
    }

    /** @return array<string, array{int, string}> */
    private function sweepActions(int $conversationId, int $messageId, bool $messageEndpointsOnly = false): array
    {
        $out = [];

        foreach ($this->actionEndpoints($conversationId, $messageId) as $name => [$verb, $uri, $data]) {
            if ($messageEndpointsOnly && in_array($name, ['controls', 'changes'], true)) {
                continue;
            }

            $response   = $this->api($verb, $uri, $data)->response();
            $out[$name] = [$response->getStatusCode(), $response->getBody()];
        }

        return $out;
    }

    public function testEveryActionEndpointAnswersForeignAndMissingIdsIdentically(): void
    {
        $db    = db_connect();
        $other = PeopleFactory::person('student', 'Oth', 'Er', null, $this->institution);
        $both  = PeopleFactory::person('instructor', 'Oth', 'Teach', null, $this->institution);
        $offer = DeanFactory::offering($this->program, $this->term, 'RT2');
        CourseFactory::enroll($offer, $other);
        CourseFactory::assignInstructor($offer, $both);
        $theirs       = (int) (new ConversationService())->startDirect($other, (int) $both->id)['id'];
        $theirMessage = (int) (new MessageService())->send($other, $theirs, 'private', $this->uuid())['message']['id'];

        $farInstitution = PeopleFactory::institution('FARY');
        $farDept        = DeanFactory::department('FY', $farInstitution);
        $farProgram     = DeanFactory::program(DeanFactory::subDepartment($farDept['department'], 'FY'), 'FY');
        $farStudent     = PeopleFactory::person('student', 'Far', 'S', null, $farInstitution);
        $farInstructor  = PeopleFactory::person('instructor', 'Far', 'I', null, $farInstitution);
        $farOffering    = DeanFactory::offering($farProgram, DeanFactory::term($farInstitution), 'FY1');
        CourseFactory::enroll($farOffering, $farStudent);
        CourseFactory::assignInstructor($farOffering, $farInstructor);
        $far        = (int) (new ConversationService())->startDirect($farStudent, (int) $farInstructor->id)['id'];
        $farMessage = (int) (new MessageService())->send($farStudent, $far, 'far', $this->uuid())['message']['id'];

        $reactionsBefore = $db->table('message_reactions')->countAllResults();
        $messagesBefore  = $db->table('messages')->orderBy('id')->get()->getResultArray();

        $this->signIn($this->student);
        $missing = $this->sweepActions(999999, 999999);

        foreach ($missing as $name => $answer) {
            $this->assertSame([404, \App\Filters\MessagesFilter::NOT_FOUND], [$answer[0], json_decode($answer[1], true)], $name);
        }

        foreach (['someone else\'s chat' => [$theirs, $theirMessage], 'another institution\'s chat' => [$far, $farMessage]] as $label => [$conversationId, $messageId]) {
            $this->assertSame($missing, $this->sweepActions($conversationId, $messageId), $label);
        }

        // A message of someone else's chat addressed through the person's own chat, and a missing id in it.
        $inOwn = $this->sweepActions($this->conversation, $theirMessage, true);
        $gone  = $this->sweepActions($this->conversation, 999999, true);

        $this->assertSame($gone, $inOwn);

        foreach ($inOwn as $name => $answer) {
            $this->assertSame($missing[$name], $answer, $name);
        }

        $this->assertSame($reactionsBefore, $db->table('message_reactions')->countAllResults());
        $this->assertSame($messagesBefore, $db->table('messages')->orderBy('id')->get()->getResultArray(), 'the probes changed nothing');
    }

    public function testEveryActionEndpointIsNotFoundWhileTheFlagIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();
        $this->signIn($this->student);

        foreach ($this->sweepActions($this->conversation, $this->message) as $name => $answer) {
            $this->assertSame(404, $answer[0], $name);
        }
    }

    public function testEveryWriteIsRefusedWithoutACsrfToken(): void
    {
        $this->signIn($this->instructor);
        $this->withoutCsrfToken();

        foreach ($this->actionEndpoints($this->conversation, $this->message) as $name => [$verb, $uri, $data]) {
            if ($verb === 'GET') {
                continue;
            }

            $response = $this->api($verb, $uri, $data)->response();

            $this->assertSame(403, $response->getStatusCode(), $name);
            $this->assertSame('csrf_expired', json_decode($response->getBody(), true)['error'] ?? null, $name);
        }

        $row = db_connect()->table('messages')->where('id', $this->message)->get()->getRowArray();
        $this->assertNull($row['pinned_at']);
        $this->assertNull($row['deleted_everyone_at']);
        $this->assertSame(0, db_connect()->table('message_reactions')->countAllResults());
    }

    public function testEveryWriteIsRefusedInAReadOnlyInstitution(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->signIn($this->instructor);

        foreach ($this->actionEndpoints($this->conversation, $this->message) as $name => [$verb, $uri, $data]) {
            if ($verb === 'GET') {
                continue;
            }

            $this->assertSame(403, $this->api($verb, $uri, $data)->response()->getStatusCode(), $name);
        }

        $row = db_connect()->table('messages')->where('id', $this->message)->get()->getRowArray();
        $this->assertNull($row['pinned_at']);
        $this->assertNull($row['deleted_everyone_at']);
    }

    public function testAnImpersonationSessionReachesNoActionEndpoint(): void
    {
        $admin = CourseFactory::user('admin');
        $this->signIn($admin);
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $this->withSession();
        $_SESSION['reauth'] = ['user' => $admin->id, 'at' => time()];
        $this->withSession();
        Services::resetSingle('auth');
        $this->step('POST', "admin/users/{$this->student->id}/impersonate", ['reason' => 'Support ticket 1234']);
        Services::resetSingle('auth');

        $this->assertSame((int) $this->student->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view of the student must be on');

        // Reads answer the shared 404; writes are stopped earlier, by the view-only rule of an impersonation session.
        foreach ($this->sweepActions($this->conversation, $this->message) as $name => $answer) {
            $error = json_decode($answer[1], true)['error'] ?? null;

            $this->assertContains([$answer[0], $error], [[404, 'not_found'], [403, 'impersonation_view_only']], $name);
        }

        $this->assertSame(0, db_connect()->table('message_reactions')->countAllResults());
    }

    public function testTheSystemAdministratorReachesNoActionEndpoint(): void
    {
        $this->signIn(CourseFactory::user('admin'));

        foreach ($this->sweepActions($this->conversation, $this->message) as $name => $answer) {
            $this->assertSame(404, $answer[0], $name);
        }
    }
}

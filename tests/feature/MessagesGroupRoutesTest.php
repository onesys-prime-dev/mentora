<?php

declare(strict_types=1);

use App\Services\Messaging\GroupService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The staff group routes through the filters: a chairperson starts a group of
 * their instructors; anyone else, and any member they may not add, gets the
 * shared 404 and nothing is created.
 *
 * @internal
 */
final class MessagesGroupRoutesTest extends RouteTestCase
{
    use ParentScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('GRT');
        $this->enableMessages();
    }

    protected function tearDown(): void
    {
        $this->releaseMessages();

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

    private function groupCount(): int
    {
        return db_connect()->table('conversations')->where('type', 'group')->countAllResults();
    }

    /**
     * A group the chair owns, with the instructor and a second instructor in it.
     *
     * @return array{0: int, 1: User}
     */
    private function ownedGroup(): array
    {
        $second = PeopleFactory::person('instructor', 'Sec', 'Ond', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $second);

        $group = (new GroupService())->create($this->chair, 'Planning', [(int) $this->instructor->id, (int) $second->id]);
        $this->assertNotNull($group);

        return [(int) $group['id'], $second];
    }

    private function activeCount(int $groupId): int
    {
        return db_connect()->table('conversation_participants')->where(['conversation_id' => $groupId, 'left_at' => null])->countAllResults();
    }

    public function testOwnerCanAddRemoveRenameClose(): void
    {
        [$id, $second] = $this->ownedGroup();
        $extra         = PeopleFactory::person('instructor', 'Thi', 'Rd', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $extra);
        $this->signIn($this->chair);

        $added = json_decode($this->api('POST', "groups/{$id}/members", ['member_ids' => [(int) $extra->id]])->response()->getBody(), true);
        $this->assertSame([(int) $extra->id], $added['added']);
        $this->assertSame(4, $this->activeCount($id));

        $members = json_decode($this->api('GET', "groups/{$id}/members")->response()->getBody(), true);
        $this->assertSame('Planning', $members['title']);
        $this->assertTrue($members['can_manage']);
        $this->assertCount(4, $members['members']);
        $this->assertSame([(int) $this->chair->id], array_values(array_column(array_filter($members['members'], static fn (array $m): bool => $m['owner']), 'id')));

        $this->assertSame(200, $this->api('POST', "groups/{$id}/members/{$second->id}/remove")->response()->getStatusCode());
        $this->assertSame(3, $this->activeCount($id));

        $this->assertSame(200, $this->api('POST', "groups/{$id}/rename", ['title' => 'Exam board'])->response()->getStatusCode());
        $this->assertSame('Exam board', db_connect()->table('conversations')->where('id', $id)->get()->getRow('title'));

        $this->assertSame(200, $this->api('POST', "groups/{$id}/close")->response()->getStatusCode());
        $this->assertNotNull(db_connect()->table('conversations')->where('id', $id)->get()->getRow('closed_at'));
    }

    public function testMemberCannotRemoveOthers(): void
    {
        [$id, $second] = $this->ownedGroup();
        $this->signIn($this->instructor);

        $this->assertSame(404, $this->api('POST', "groups/{$id}/members/{$second->id}/remove")->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', "groups/{$id}/rename", ['title' => 'Mine'])->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', "groups/{$id}/close")->response()->getStatusCode());
        $this->assertSame(404, $this->api('POST', "groups/{$id}/members", ['member_ids' => [(int) $this->child->id]])->response()->getStatusCode());
        $this->assertSame(3, $this->activeCount($id));

        $mine = json_decode($this->api('GET', "groups/{$id}/members")->response()->getBody(), true);
        $this->assertFalse($mine['can_manage']);

        // Leaving is any member's own right.
        $this->assertSame(200, $this->api('POST', "groups/{$id}/leave")->response()->getStatusCode());
        $this->assertSame(2, $this->activeCount($id));
    }

    public function testAdminCanManageAnyGroup(): void
    {
        [$id, $second] = $this->ownedGroup();
        $admin         = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->signIn($admin);

        $this->assertSame(200, $this->api('POST', "groups/{$id}/rename", ['title' => 'Renamed'])->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', "groups/{$id}/members/{$second->id}/remove")->response()->getStatusCode());
        $this->assertSame(200, $this->api('POST', "groups/{$id}/close")->response()->getStatusCode());
    }

    public function testNonMember404(): void
    {
        [$id] = $this->ownedGroup();
        $this->signIn($this->child);

        foreach ([['POST', "groups/{$id}/members", ['member_ids' => [1]]], ['POST', "groups/{$id}/members/{$this->instructor->id}/remove", []], ['POST', "groups/{$id}/leave", []], ['POST', "groups/{$id}/rename", ['title' => 'x']], ['POST', "groups/{$id}/close", []], ['GET', "groups/{$id}/members", []]] as [$verb, $uri, $data]) {
            $this->assertSame(404, $this->api($verb, $uri, $data)->response()->getStatusCode(), $uri);
        }

        $this->assertSame(404, $this->api('POST', 'groups/99999999/leave')->response()->getStatusCode());
        $this->assertSame(3, $this->activeCount($id));
    }

    public function testThePageCarriesTheGroupEventsAndTheChangesDoToo(): void
    {
        [$id, $second] = $this->ownedGroup();
        $this->signIn($this->chair);
        $this->api('POST', "groups/{$id}/members/{$second->id}/remove");

        $page = json_decode($this->api('GET', "conversations/{$id}/messages")->response()->getBody(), true);
        $this->assertSame(['group_created', 'member_removed'], array_column($page['events'], 'event'));
        $removed = $page['events'][1];
        $this->assertSame((int) $this->chair->id, $removed['actor_id']);
        $this->assertSame((int) $second->id, $removed['target_id']);
        $this->assertNotSame('', $removed['target_name']);
        $this->assertArrayHasKey('created_at', $removed);

        $changes = json_decode($this->api('GET', "conversations/{$id}/changes?since=" . urlencode(date('Y-m-d H:i:s', time() - 60)))->response()->getBody(), true);
        $this->assertContains('member_removed', array_column($changes['events'], 'event'));
    }

    public function testAPollDoesNotRepeatEventsAndTheChangesSendOnlyNewerOnes(): void
    {
        [$id, $second] = $this->ownedGroup();
        $this->signIn($this->chair);

        $page = json_decode($this->api('GET', "conversations/{$id}/messages")->response()->getBody(), true);
        $this->assertNotSame([], $page['events']);
        $last = max(array_column($page['events'], 'id'));

        $poll = json_decode($this->api('GET', "conversations/{$id}/messages?after=0")->response()->getBody(), true);
        $this->assertSame([], $poll['events'], 'a poll leaves events to the changes feed');

        $since = urlencode(date('Y-m-d H:i:s', time() - 60));
        $none  = json_decode($this->api('GET', "conversations/{$id}/changes?since={$since}&after_event={$last}")->response()->getBody(), true);
        $this->assertSame([], $none['events']);

        $this->api('POST', "groups/{$id}/members/{$second->id}/remove");
        $some = json_decode($this->api('GET', "conversations/{$id}/changes?since={$since}&after_event={$last}")->response()->getBody(), true);
        $this->assertSame(['member_removed'], array_column($some['events'], 'event'));
    }

    public function testARemovedMemberGetsTheSharedNotFoundOnPageAndChanges(): void
    {
        [$id, $second] = $this->ownedGroup();
        $this->signIn($this->chair);
        $this->api('POST', "groups/{$id}/members/{$second->id}/remove");

        $this->signIn($second);
        $this->assertSame(404, $this->api('GET', "conversations/{$id}/messages")->response()->getStatusCode());
        $this->assertSame(404, $this->api('GET', "conversations/{$id}/changes?since=" . urlencode(date('Y-m-d H:i:s', time() - 60)))->response()->getStatusCode());
    }

    public function testAChairpersonStartsAGroupOverHttp(): void
    {
        $this->signIn($this->chair);

        $response = $this->api('POST', 'groups', ['title' => 'Exam planning', 'member_ids' => [(int) $this->instructor->id]])->response();
        $body     = json_decode($response->getBody(), true)['conversation'];

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('group', $body['type']);
        $this->assertSame('Exam planning', $body['name']);
        $this->assertTrue($body['can_moderate']);
        $this->assertTrue($body['group_settings']);
        $this->assertSame(1, $this->groupCount());

        $this->signIn($this->instructor);
        $list = json_decode($this->api('GET', 'conversations?filter=group')->response()->getBody(), true)['conversations'];

        $this->assertSame(['Exam planning'], array_column($list, 'name'));
        $this->assertFalse($list[0]['can_moderate']);
    }

    public function testAMemberTheyMayNotAddAnswersLikeAnUnknownRecipientAndCreatesNothing(): void
    {
        $parent = PeopleFactory::person('parent', 'Par', 'Ent', null, $this->institution);
        $this->signIn($this->chair);

        $refused = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id, (int) $parent->id]])->response();
        $unknown = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [99999999]])->response();

        $this->assertSame(404, $refused->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $refused->getStatusCode());
        $this->assertSame($unknown->getBody(), $refused->getBody());
        $this->assertSame(0, $this->groupCount());
    }

    public function testMalformedInputIsTheSameNotFound(): void
    {
        $this->signIn($this->chair);

        foreach ([['title' => 'G'], ['title' => 'G', 'member_ids' => 'x'], ['title' => 'G', 'member_ids' => [['a']]], ['title' => ['x'], 'member_ids' => [1]], ['member_ids' => [1]]] as $input) {
            $this->assertSame(404, $this->api('POST', 'groups', $input)->response()->getStatusCode());
        }

        $this->assertSame(0, $this->groupCount());
    }

    public function testAParentDoesNotReachEitherRoute(): void
    {
        foreach ([$this->parent] as $user) {
            $this->signIn($user);

            // The test client reuses one response object, so read each answer before the next request.
            $created    = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id]])->response()->getStatusCode();
            $candidates = json_decode($this->api('GET', 'group-candidates?q=Tructor')->response()->getBody(), true)['candidates'] ?? [];

            $this->assertSame(404, $created, 'user ' . $user->id);
            $this->assertSame([], $candidates, 'user ' . $user->id);
        }

        $this->assertSame(0, $this->groupCount());
    }

    public function testCandidatesAreScopedAndCarryNoContactDetails(): void
    {
        $this->signIn($this->chair);

        $found = json_decode($this->api('GET', 'group-candidates?q=Tructor')->response()->getBody(), true)['candidates'];

        $this->assertSame([(int) $this->instructor->id], array_column($found, 'id'));
        $this->assertSame(['id', 'name', 'role', 'avatar'], array_keys($found[0]));
        $this->assertSame(['id', 'name', 'initials', 'colour', 'photoUrl', 'photoUrlLg', 'interactive'], array_keys($found[0]['avatar']), 'the avatar carries no contact details either');
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->signIn($this->chair);
        $this->withoutCsrfToken();

        $response = $this->api('POST', 'groups', ['title' => 'Group', 'member_ids' => [(int) $this->instructor->id]])->response();

        $this->assertNotContains($response->getStatusCode(), [200, 201]);
        $this->assertSame(0, $this->groupCount());
    }

    public function testThePageOffersTheNewGroupButtonToStaffAndStudentsButNotParents(): void
    {
        $this->withHeaders(['Accept' => 'text/html']);

        foreach ([[$this->chair, '1'], [$this->instructor, '1'], [$this->child, '1'], [$this->parent, '0']] as [$user, $flag]) {
            $this->signIn($user);
            $this->assertStringContainsString('data-can-group="' . $flag . '"', $this->step('GET', 'messages')->response()->getBody(), 'user ' . $user->id);
        }
    }
}

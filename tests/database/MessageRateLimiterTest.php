<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\MessageRateLimiter;
use App\Services\Messaging\Party;
use App\Services\Messaging\RateLimitedException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;

/**
 * The per-person limits of spec 5.3, counted from the rows already written.
 *
 * @internal
 */
final class MessageRateLimiterTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private int $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('RL');
        $this->thread = (int) (new ConversationService())->startDirect($this->parent, (int) $this->instructor->id)['id'];
    }

    private function messages(int $senderId, int $count, ?string $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('messages')->insert([
                'conversation_id' => $this->thread, 'sender_id' => $senderId, 'body_enc' => 'x',
                'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => $at ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function threads(int $creatorId, int $count, string $type = 'direct', ?string $at = null): void
    {
        $now = $at ?? date('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('conversations')->insert([
                'institution_id' => $this->institution, 'type' => $type, 'created_by' => $creatorId, 'is_locked' => 0,
                'direct_key' => $type === 'direct' ? bin2hex(random_bytes(32)) : null, 'title' => $type === 'group' ? 'G' : null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function reports(int $reporterId, int $count): void
    {
        $messageId = (int) db_connect()->table('messages')->select('id')->get()->getRowArray()['id'];

        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('message_reports')->insert([
                'institution_id' => $this->institution, 'message_id' => $messageId, 'reporter_id' => $reporterId, 'reason' => 'spam',
                'snapshot_enc' => 'x', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function limited(string $kind, int $userId, ?DateTimeImmutable $now = null, ?Party $party = null, ?string &$sentence = null): bool
    {
        try {
            (new MessageRateLimiter())->check($kind, $userId, $now, $party);

            return false;
        } catch (RateLimitedException $e) {
            $this->assertSame($kind, $e->kind());
            $this->assertNotSame('', $e->getMessage());
            $sentence = $e->getMessage();

            return true;
        }
    }

    public function testThirtyMessagesAMinutePassAndTheThirtyFirstDoesNot(): void
    {
        $this->messages((int) $this->parent->id, MessageRateLimiter::MESSAGES_PER_MINUTE - 1);
        $this->assertFalse($this->limited('message', (int) $this->parent->id));

        $this->messages((int) $this->parent->id, 1);
        $this->assertTrue($this->limited('message', (int) $this->parent->id));
    }

    public function testTheWindowSlidesAndOtherPeopleAreUnaffected(): void
    {
        $this->messages((int) $this->parent->id, MessageRateLimiter::MESSAGES_PER_MINUTE);

        $this->assertTrue($this->limited('message', (int) $this->parent->id));
        $this->assertFalse($this->limited('message', (int) $this->instructor->id));
        $this->assertFalse($this->limited('message', (int) $this->parent->id, new DateTimeImmutable('+61 seconds')));
    }

    public function testTenNewThreadsAnHourDirectAndGroupBothCount(): void
    {
        $this->threads((int) $this->chair->id, 6, 'direct');
        $this->threads((int) $this->chair->id, 3, 'group');
        $this->assertFalse($this->limited('thread', (int) $this->chair->id));

        $this->threads((int) $this->chair->id, 1, 'group');
        $this->assertTrue($this->limited('thread', (int) $this->chair->id));
        $this->assertFalse($this->limited('thread', (int) $this->chair->id, new DateTimeImmutable('+3601 seconds')));
    }

    public function testCourseChatsDoNotCountAsThreadsAPersonStarted(): void
    {
        $this->threads((int) $this->chair->id, 10, 'course');

        $this->assertFalse($this->limited('thread', (int) $this->chair->id));
    }

    private function party(int $id, string $role): Party
    {
        return new Party($id, [$role], true, $this->institution);
    }

    private function hoursAgo(int $hours): string
    {
        return date('Y-m-d H:i:s', time() - $hours * 3600);
    }

    public function testStudentHourlyAndDailyCaps(): void
    {
        $id = (int) $this->child->id;
        $p  = $this->party($id, 'student');

        $this->threads($id, 9);
        $this->assertFalse($this->limited('thread', $id, null, $p));
        $this->threads($id, 1);
        $this->assertTrue($this->limited('thread', $id, null, $p));

        $other = (int) $this->parent->id;
        $this->threads($other, 23, 'direct', $this->hoursAgo(2));
        $this->assertFalse($this->limited('thread', $other, null, $this->party($other, 'student')));
        $this->threads($other, 1, 'direct', $this->hoursAgo(2));
        $this->assertTrue($this->limited('thread', $other, null, $this->party($other, 'student'), $sentence));
        $this->assertSame("You've started a lot of conversations today. Try again tomorrow.", $sentence);
    }

    public function testStaffCaps(): void
    {
        $id = (int) $this->instructor->id;
        $p  = $this->party($id, 'instructor');

        $this->threads($id, 19);
        $this->assertFalse($this->limited('thread', $id, null, $p));
        $this->threads($id, 1);
        $this->assertTrue($this->limited('thread', $id, null, $p));

        $chair = (int) $this->chair->id;
        $this->threads($chair, 59, 'direct', $this->hoursAgo(2));
        $this->assertFalse($this->limited('thread', $chair, null, $this->party($chair, 'chairperson')));
        $this->threads($chair, 1, 'direct', $this->hoursAgo(2));
        $this->assertTrue($this->limited('thread', $chair, null, $this->party($chair, 'chairperson')));
    }

    public function testAdminExempt(): void
    {
        $id = (int) $this->chair->id;
        $this->threads($id, 70);

        $this->assertFalse($this->limited('thread', $id, null, new Party($id, ['institutional_admin'], true, $this->institution)));
    }

    public function testGroupCreationCounts(): void
    {
        $this->threads((int) $this->chair->id, 19);
        $service = new GroupService();

        $this->assertNotNull($service->create($this->chair, 'Dept', [(int) $this->instructor->id]));

        $this->expectException(RateLimitedException::class);
        $service->create($this->chair, 'Dept 2', [(int) $this->instructor->id]);
    }

    public function testTwentyReportsADay(): void
    {
        $this->messages((int) $this->instructor->id, 1);
        $this->reports((int) $this->parent->id, MessageRateLimiter::REPORTS_PER_DAY - 1);
        $this->assertFalse($this->limited('report', (int) $this->parent->id));

        $this->reports((int) $this->parent->id, 1);
        $this->assertTrue($this->limited('report', (int) $this->parent->id));
        $this->assertFalse($this->limited('report', (int) $this->parent->id, new DateTimeImmutable('+86401 seconds')));
    }
}

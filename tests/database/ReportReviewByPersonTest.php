<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\ReportService;
use App\Services\Messaging\RestrictionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Reports reviewed by person (FR6, pick 3): cases, removing a direct message, and the end of an automatic pause.
 *
 * @internal
 */
final class ReportReviewByPersonTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private int $chat;

    private User $admin;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('RP');
        $this->admin  = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->sender = PeopleFactory::person('student', 'Sam', 'Sender', null, $this->institution);
        $this->chat   = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function review(): ReportReviewService
    {
        return new ReportReviewService($this->parentCipher());
    }

    private function say(User $user, string $text, int $conversation): int
    {
        return (int) (new MessageService($this->parentCipher()))->send($user, $conversation, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    private function report(User $reporter, int $conversation, int $message): int
    {
        $this->assertTrue((new ReportService($this->parentCipher()))->report($reporter, $conversation, $message, 'harassment', null));

        return (int) db_connect()->table('message_reports')->selectMax('id', 'id')->get()->getRow('id');
    }

    /** A first-contact thread started by the sender and reported by a new stranger; the third one pauses them. */
    private function strangerReport(): int
    {
        $stranger = PeopleFactory::person('student', 'Stan', 'Stranger', null, $this->institution);
        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $db->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'direct', 'created_by' => (int) $this->sender->id, 'is_locked' => 0,
            'direct_key' => 'rp-' . bin2hex(random_bytes(6)), 'first_contact' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversation = (int) $db->insertID();

        foreach ([$this->sender, $stranger] as $user) {
            $db->table('conversation_participants')->insert(['conversation_id' => $conversation, 'user_id' => (int) $user->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $db->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => (int) $this->sender->id,
            'body_enc' => $this->parentCipher()->encrypt('hello there', $conversation, (int) $this->sender->id), 'client_uuid' => 'rp-' . bin2hex(random_bytes(6)), 'created_at' => $now,
        ]);

        return $this->report($stranger, $conversation, (int) $db->insertID());
    }

    /** @return int the pause id */
    private function pausedSender(): int
    {
        foreach (range(1, 3) as $i) {
            $this->strangerReport();
        }

        return (int) db_connect()->table('message_restrictions')->where(['user_id' => (int) $this->sender->id, 'source' => 'pause'])->get()->getRow('id');
    }

    /** @return array<string, mixed> */
    private function pauseRow(int $id): array
    {
        return db_connect()->table('message_restrictions')->where('id', $id)->get()->getRowArray();
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testCasesGroupReportsBySender(): void
    {
        $this->report($this->child, $this->chat, $this->say($this->instructor, 'one', $this->chat));
        $this->report($this->child, $this->chat, $this->say($this->instructor, 'two', $this->chat));
        $this->report($this->instructor, $this->chat, $this->say($this->child, 'three', $this->chat));

        $cases = $this->review()->cases($this->admin);

        $this->assertCount(2, $cases);
        $byUser = array_column($cases, null, 'user_id');
        $this->assertCount(2, $byUser[(int) $this->instructor->id]['reports']);
        $this->assertCount(1, $byUser[(int) $this->child->id]['reports']);
        $this->assertSame('Ins Tructor', $byUser[(int) $this->instructor->id]['name']);
        $this->assertSame('instructor', $byUser[(int) $this->instructor->id]['role']);
        $this->assertNull($byUser[(int) $this->instructor->id]['pause']);
        $this->assertNull($byUser[(int) $this->instructor->id]['restriction']);
        $this->assertStringNotContainsString('three', json_encode($cases));
        $this->assertSame([], $this->review()->cases($this->instructor));
    }

    public function testAdminCanRemoveDirectMessage(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->child, (int) $this->instructor->id)['id'];
        $id     = $this->say($this->instructor, 'private words', $direct);
        $report = $this->report($this->child, $direct, $id);

        $this->assertTrue($this->review()->view($this->admin, $report)['can_remove']);
        $this->assertTrue($this->review()->review($this->admin, $report, 'actioned', true));

        $row = db_connect()->table('messages')->where('id', $id)->get()->getRowArray();
        $this->assertNotNull($row['removed_at']);
        $this->assertSame((int) $this->admin->id, (int) $row['removed_by']);
        $this->assertSame(1, $this->audit('messages.message_removed'));

        foreach ([$this->child, $this->instructor] as $reader) {
            $shown = array_values(array_filter((new MessageService($this->parentCipher()))->page($reader, $direct)['messages'], static fn (array $m): bool => $m['id'] === $id))[0];
            $this->assertTrue($shown['removed']);
            $this->assertNull($shown['body']);
            $this->assertSame('Message removed by an administrator', $shown['removed_text']);
        }
    }

    public function testRemovingADirectMessageReachesAThreadThatIsAlreadyOpen(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->child, (int) $this->instructor->id)['id'];
        $id     = $this->say($this->instructor, 'private words', $direct);
        $report = $this->report($this->child, $direct, $id);
        $before = date('Y-m-d H:i:s', time() - 30);
        db_connect()->table('messages')->update(['changed_at' => date('Y-m-d H:i:s', time() - 600)]);

        $this->assertTrue($this->review()->review($this->admin, $report, 'actioned', true));

        $changed = (new MessageService($this->parentCipher()))->changedSince($this->child, $direct, $before)['messages'];

        $this->assertSame([$id], array_column($changed, 'id'));
        $this->assertSame('Message removed by an administrator', $changed[0]['removed_text']);
    }

    public function testReviewingAllCountedReportsEndsPause(): void
    {
        $pause = $this->pausedSender();
        $ids   = array_map('intval', array_column(db_connect()->table('message_reports')->where('pause_id', $pause)->get()->getResultArray(), 'id'));

        $this->assertCount(3, $ids);
        $this->assertTrue($this->review()->review($this->admin, $ids[0], 'dismissed', false));
        $this->assertTrue($this->review()->review($this->admin, $ids[1], 'dismissed', false));
        $this->assertNull($this->pauseRow($pause)['ended_at'], 'one report is still open');

        $this->assertTrue($this->review()->review($this->admin, $ids[2], 'dismissed', false));

        $row = $this->pauseRow($pause);
        $this->assertNotNull($row['ended_at']);
        $this->assertSame('reviewed', $row['end_reason']);
        $this->assertSame((int) $this->admin->id, (int) $row['ended_by']);
        $this->assertSame(1, $this->audit('messages.pause_ended'));
    }

    public function testReviewAllDecidesEveryOpenReportOfOnePerson(): void
    {
        $pause = $this->pausedSender();
        $this->report($this->child, $this->chat, $this->say($this->instructor, 'other person', $this->chat));

        $this->assertSame(3, $this->review()->reviewAll($this->admin, (int) $this->sender->id, 'dismiss'));
        $this->assertSame(1, db_connect()->table('message_reports')->where('status', 'open')->countAllResults());
        $this->assertSame('reviewed', $this->pauseRow($pause)['end_reason']);
        $this->assertSame(0, $this->review()->reviewAll($this->instructor, (int) $this->instructor->id, 'dismiss'));
    }

    public function testRestrictingEndsPause(): void
    {
        $pause = $this->pausedSender();

        $this->assertTrue((new RestrictionService())->restrict($this->admin, (int) $this->sender->id, 'no_send', null));

        $row = $this->pauseRow($pause);
        $this->assertSame('restricted', $row['end_reason']);
        $this->assertSame((int) $this->admin->id, (int) $row['ended_by']);
        $this->assertSame(1, $this->audit('messages.pause_ended'));
        $this->assertSame('admin', (new RestrictionService())->active((int) $this->sender->id)['source']);
    }

    public function testReviewingExpiredPauseIsHarmless(): void
    {
        $pause = $this->pausedSender();
        db_connect()->table('message_restrictions')->where('id', $pause)->update(['ends_at' => date('Y-m-d H:i:s', time() - 60)]);
        db_connect()->table('audit_logs')->emptyTable();

        $this->assertSame(3, $this->review()->reviewAll($this->admin, (int) $this->sender->id, 'dismiss'));
        $this->assertSame(0, $this->audit('messages.pause_ended'));

        (new RestrictionService())->active((int) $this->sender->id);
        $this->assertSame('expired', $this->pauseRow($pause)['end_reason']);

        // Decided again: nothing more happens.
        $this->assertSame(0, $this->review()->reviewAll($this->admin, (int) $this->sender->id, 'dismiss'));
        $this->assertSame(0, $this->audit('messages.pause_ended'));
        $this->assertSame(1, $this->audit('messages.pause_expired'));
    }

    public function testCasesShowThePauseAndTheRestriction(): void
    {
        $this->pausedSender();

        $case = $this->review()->cases($this->admin)[0];
        $this->assertSame((int) $this->sender->id, $case['user_id']);
        $this->assertNotNull($case['pause']['ends_at']);
        $this->assertNull($case['restriction']);

        (new RestrictionService())->restrict($this->admin, (int) $this->sender->id, 'no_new', null);
        $case = $this->review()->cases($this->admin)[0];
        $this->assertNull($case['pause']);
        $this->assertSame('no_new', $case['restriction']['kind']);
    }
}

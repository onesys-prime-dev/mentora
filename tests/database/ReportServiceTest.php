<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Reporting a message: who may, what is kept, and that the audit entry holds no text.
 *
 * @internal
 */
final class ReportServiceTest extends DatabaseTestCase
{
    use ParentScenario;

    private const SECRET = 'report-secret-4410';

    protected bool $isolateTests = true;

    private int $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('RP');
        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function reports(): ReportService
    {
        return new ReportService($this->parentCipher());
    }

    private function say(User $user, string $text, ?int $conversation = null): int
    {
        return (int) $this->messages()->send($user, $conversation ?? $this->chat, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    private function reportCount(): int
    {
        return db_connect()->table('message_reports')->countAllResults();
    }

    public function testAParticipantReportsSomeoneElsesMessage(): void
    {
        $id = $this->say($this->instructor, self::SECRET);

        $this->assertTrue($this->reports()->report($this->child, $this->chat, $id, 'harassment', 'This was unkind.'));

        $row = db_connect()->table('message_reports')->get()->getRowArray();

        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame($id, (int) $row['message_id']);
        $this->assertSame((int) $this->child->id, (int) $row['reporter_id']);
        $this->assertSame('open', $row['status']);
        $this->assertStringNotContainsString(self::SECRET, (string) $row['snapshot_enc']);
        $this->assertStringNotContainsString('unkind', (string) $row['note_enc']);
    }

    public function testTheAuditEntryHoldsIdsAndTheReasonOnly(): void
    {
        $id = $this->say($this->instructor, self::SECRET);
        $this->reports()->report($this->child, $this->chat, $id, 'spam', 'note ' . self::SECRET);

        $audit = db_connect()->table('audit_logs')->where('action', 'messages.report_created')->get()->getResultArray();

        $this->assertCount(1, $audit);
        $this->assertStringContainsString('"reason":"spam"', (string) $audit[0]['new_values']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($audit));
    }

    public function testEveryRefusalWritesNothing(): void
    {
        $theirs   = $this->say($this->instructor, 'from the instructor');
        $mine     = $this->say($this->child, 'from the child');
        $removed  = $this->say($this->instructor, 'to be removed');
        db_connect()->table('messages')->where('id', $removed)->update(['removed_at' => date('Y-m-d H:i:s')]);
        $stranger = PeopleFactory::person('student', 'Stran', 'Ger', null, $this->institution);
        $direct   = (int) (new ConversationService())->startDirect($this->child, (int) $this->instructor->id)['id'];
        $inDirect = $this->say($this->instructor, 'private', $direct);

        $this->assertFalse($this->reports()->report($this->child, $this->chat, $mine, 'spam', null), 'own message');
        $this->assertFalse($this->reports()->report($this->child, $this->chat, $removed, 'spam', null), 'removed');
        $this->assertFalse($this->reports()->report($stranger, $this->chat, $theirs, 'spam', null), 'not a participant');
        $this->assertFalse($this->reports()->report($this->child, $this->chat, $inDirect, 'spam', null), 'a message of another conversation');
        $this->assertFalse($this->reports()->report($this->child, $this->chat, 99999999, 'spam', null), 'unknown message');
        $this->assertFalse($this->reports()->report($this->child, 99999999, $theirs, 'spam', null), 'unknown conversation');
        $this->assertFalse($this->reports()->report($this->child, $this->chat, $theirs, 'rude', null), 'unknown reason');
        $this->assertFalse($this->reports()->report($this->child, $this->chat, $theirs, 'spam', str_repeat('x', ReportService::NOTE_MAX + 1)), 'long note');

        $this->assertSame(0, $this->reportCount());
        $this->assertSame(0, db_connect()->table('audit_logs')->countAllResults());
    }

    public function testTheSameMessageIsReportedOncePerReporter(): void
    {
        $id = $this->say($this->instructor, 'hello');

        $this->assertTrue($this->reports()->report($this->child, $this->chat, $id, 'spam', null));
        $this->assertFalse($this->reports()->report($this->child, $this->chat, $id, 'other', 'again'));
        $this->assertSame(1, $this->reportCount());
    }

    public function testAMessageFromBeforeTheReporterJoinedCannotBeReported(): void
    {
        $early = $this->say($this->instructor, 'before the newcomer');
        $late  = PeopleFactory::person('student', 'Late', 'Comer', null, $this->institution);
        CourseFactory::enroll($this->offering, $late);
        (new CourseChatSync())->syncOffering($this->offering);

        $this->assertFalse($this->reports()->report($late, $this->chat, $early, 'spam', null));
    }

    public function testAMessageTheReporterHidIsNotReportable(): void
    {
        $id = $this->say($this->instructor, 'hello');
        $this->messages()->hideForMe($this->child, $this->chat, $id);

        $this->assertFalse($this->reports()->report($this->child, $this->chat, $id, 'spam', null));
    }

    public function testAnEmptyNoteIsKeptAsNone(): void
    {
        $id = $this->say($this->instructor, 'hello');

        $this->assertTrue($this->reports()->report($this->child, $this->chat, $id, 'other', "   \n "));
        $this->assertNull(db_connect()->table('message_reports')->get()->getRowArray()['note_enc']);
    }
}

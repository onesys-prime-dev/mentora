<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The administrator's side of a report: who can read it, what the snapshot holds,
 * and the one way an administrator blanks a reported message.
 *
 * @internal
 */
final class ReportReviewServiceTest extends DatabaseTestCase
{
    use ParentScenario;

    private const SECRET = 'review-secret-9021';

    protected bool $isolateTests = true;

    private int $chat;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('RV');
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->chat  = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function review(): ReportReviewService
    {
        return new ReportReviewService($this->parentCipher());
    }

    private function say(User $user, string $text, ?int $conversation = null): int
    {
        return (int) $this->messages()->send($user, $conversation ?? $this->chat, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    private function report(User $reporter, int $conversation, int $message, string $reason = 'harassment', ?string $note = 'See this.'): int
    {
        $this->assertTrue((new ReportService($this->parentCipher()))->report($reporter, $conversation, $message, $reason, $note));

        return (int) db_connect()->table('message_reports')->selectMax('id', 'id')->get()->getRow('id');
    }

    public function testTheAdminOfTheInstitutionReadsTheSnapshotAndTheNote(): void
    {
        $id     = $this->say($this->instructor, self::SECRET);
        $report = $this->report($this->child, $this->chat, $id);

        $view = $this->review()->view($this->admin, $report);

        $this->assertSame([self::SECRET], array_column($view['snapshot'], 'body'));
        $this->assertSame('See this.', $view['note']);
        $this->assertSame('harassment', $view['reason']);
        $this->assertSame($id, $view['reported_id']);
        $this->assertSame('course', $view['conversation_type']);
    }

    public function testTheSnapshotHoldsTheMessageAndFiveBeforeIt(): void
    {
        $ids = [];

        for ($i = 1; $i <= 8; $i++) {
            $ids[] = $this->say($this->instructor, "line {$i}");
        }

        $report = $this->report($this->child, $this->chat, $ids[6]);
        $view   = $this->review()->view($this->admin, $report);

        $this->assertSame(['line 2', 'line 3', 'line 4', 'line 5', 'line 6', 'line 7'], array_column($view['snapshot'], 'body'), 'six lines, oldest first, nothing after');
    }

    public function testNobodyElseCanReadOrReview(): void
    {
        $report   = $this->report($this->child, $this->chat, $this->say($this->instructor, self::SECRET));
        $farAdmin = PeopleFactory::person('institutional_admin', 'Far', 'Admin', null, DeanFactory::department('RV2')['institution']);
        $dean     = DeanFactory::dean($this->institution, $this->department);

        foreach ([$this->child, $this->instructor, $this->parent, $this->chair, $dean, $farAdmin, CourseFactory::user('admin')] as $user) {
            $this->assertNull($this->review()->view($user, $report), (string) $user->first_name);
            $this->assertSame([], $this->review()->list($user), (string) $user->first_name);
            $this->assertFalse($this->review()->review($user, $report, 'dismissed', false), (string) $user->first_name);
        }

        $this->assertSame('open', db_connect()->table('message_reports')->get()->getRowArray()['status']);
    }

    public function testTheListHasNoTextAndFiltersByStatus(): void
    {
        $report = $this->report($this->child, $this->chat, $this->say($this->instructor, self::SECRET), 'spam', 'note ' . self::SECRET);

        $open = $this->review()->list($this->admin);

        $this->assertCount(1, $open);
        $this->assertSame('Course chat', $open[0]['conversation_name']);
        $this->assertSame('Kid Child', $open[0]['reporter_name']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($open));

        $this->review()->review($this->admin, $report, 'dismissed', false);

        $this->assertSame([], $this->review()->list($this->admin));
        $this->assertCount(1, $this->review()->list($this->admin, 'dismissed'));
    }

    public function testRemovingAReportedCourseMessageBlanksItForEveryoneButTheAdminStillSeesTheOriginal(): void
    {
        $id     = $this->say($this->instructor, self::SECRET);
        $report = $this->report($this->child, $this->chat, $id);

        $this->assertTrue($this->review()->view($this->admin, $report)['can_remove']);
        $this->assertTrue($this->review()->review($this->admin, $report, 'actioned', true));

        $row = db_connect()->table('messages')->where('id', $id)->get()->getRowArray();
        $this->assertNotNull($row['removed_at']);
        $this->assertSame((int) $this->admin->id, (int) $row['removed_by']);

        foreach ([$this->child, $this->instructor] as $reader) {
            $shown = array_values(array_filter($this->messages()->page($reader, $this->chat)['messages'], static fn (array $m): bool => $m['id'] === $id))[0];
            $this->assertTrue($shown['removed']);
            $this->assertNull($shown['body']);
        }

        $view = $this->review()->view($this->admin, $report);
        $this->assertSame([self::SECRET], array_column($view['snapshot'], 'body'), 'the evidence survives the removal');
        $this->assertTrue($view['removed']);
        $this->assertFalse($view['can_remove']);
        $this->assertSame('actioned', $view['status']);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertSame(['messages.report_created', 'messages.report_reviewed', 'messages.message_removed'], $actions);
        $this->assertStringNotContainsString(self::SECRET, json_encode(db_connect()->table('audit_logs')->get()->getResultArray()));
    }

    public function testAReportedDirectMessageCanBeDismissedOrActionedButNeverRemoved(): void
    {
        $direct = (int) (new ConversationService())->startDirect($this->child, (int) $this->instructor->id)['id'];
        $id     = $this->say($this->instructor, self::SECRET, $direct);
        $report = $this->report($this->child, $direct, $id);

        $view = $this->review()->view($this->admin, $report);
        $this->assertSame([self::SECRET], array_column($view['snapshot'], 'body'), 'the reported message is shown to the administrator');
        $this->assertFalse($view['can_remove']);

        $this->assertTrue($this->review()->review($this->admin, $report, 'actioned', true));
        $this->assertNull(db_connect()->table('messages')->where('id', $id)->get()->getRowArray()['removed_at']);
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'messages.message_removed')->countAllResults());
    }

    public function testAReportIsReviewedOnceAndOnlyWithADecision(): void
    {
        $report = $this->report($this->child, $this->chat, $this->say($this->instructor, 'hello'));

        $this->assertFalse($this->review()->review($this->admin, $report, 'open', false));
        $this->assertFalse($this->review()->review($this->admin, $report, 'nonsense', false));
        $this->assertTrue($this->review()->review($this->admin, $report, 'dismissed', false));
        $this->assertFalse($this->review()->review($this->admin, $report, 'actioned', true));

        $row = db_connect()->table('message_reports')->where('id', $report)->get()->getRowArray();
        $this->assertSame('dismissed', $row['status']);
        $this->assertSame((int) $this->admin->id, (int) $row['reviewed_by']);
        $this->assertNotNull($row['reviewed_at']);
    }

    public function testASnapshotMovedToAnotherReportersRowCannotBeRead(): void
    {
        $first  = $this->report($this->child, $this->chat, $this->say($this->instructor, 'one'));
        $second = $this->report($this->instructor, $this->chat, $this->say($this->child, self::SECRET));
        $stolen = db_connect()->table('message_reports')->where('id', $second)->get()->getRowArray()['snapshot_enc'];

        db_connect()->table('message_reports')->where('id', $first)->update(['snapshot_enc' => $stolen]);

        $this->assertSame([], $this->review()->view($this->admin, $first)['snapshot']);
    }
}

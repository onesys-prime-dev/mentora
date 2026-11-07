<?php

declare(strict_types=1);

use App\Services\Messaging\BlockService;
use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Blocking in direct threads: who can be blocked, what a block shuts and what it
 * leaves open, and that every change is audited without text.
 *
 * @internal
 */
final class BlockServiceTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private User $ann;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('BK');

        // $this->child is Ann; Ben is a classmate.
        $this->ann = $this->child;
        $this->ben = PeopleFactory::person('student', 'Ben', 'Ng', null, $this->institution);
        CourseFactory::enroll($this->offering, $this->ben);
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function blocks(): BlockService
    {
        return new BlockService();
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function rows(): int
    {
        return db_connect()->table('user_blocks')->countAllResults();
    }

    public function testBlockingAClassmateWritesOneRowAndOneAuditEntryWithoutText(): void
    {
        $this->assertTrue($this->blocks()->block($this->ann, (int) $this->ben->id));
        $this->assertTrue($this->blocks()->block($this->ann, (int) $this->ben->id), 'twice is fine');

        $row = db_connect()->table('user_blocks')->get()->getRowArray();

        $this->assertSame(1, $this->rows());
        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame((int) $this->ann->id, (int) $row['blocker_id']);

        $audit = db_connect()->table('audit_logs')->where('action', 'messages.user_blocked')->get()->getResultArray();

        $this->assertCount(1, $audit);
        $this->assertSame((int) $this->ben->id, (int) $audit[0]['target_id']);
    }

    public function testUnblockingRemovesItOnceAndAuditsOnce(): void
    {
        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertTrue($this->blocks()->unblock($this->ann, (int) $this->ben->id));
        $this->assertTrue($this->blocks()->unblock($this->ann, (int) $this->ben->id), 'not blocked is fine');

        $this->assertSame(0, $this->rows());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.user_unblocked')->countAllResults());
    }

    public function testNobodyWhoCannotBeBlockedIsBlocked(): void
    {
        $admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $outside = PeopleFactory::person('student', 'Out', 'Side', null, DeanFactory::college('BK2')['institution']);

        foreach ([$this->instructor, $admin, $this->ann, $outside, CourseFactory::user('admin')] as $target) {
            $this->assertFalse($this->blocks()->block($this->ann, (int) $target->id), (string) $target->first_name);
        }

        $this->assertFalse($this->blocks()->block($this->ann, 99999999));
        $this->assertSame(0, $this->rows());
        $this->assertSame(0, db_connect()->table('audit_logs')->countAllResults());
    }

    public function testABlockShutsTheDoorOnTheBlockedPersonOnly(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];
        $this->messages()->send($this->ben, $thread, 'Hi Ann', 'uuid-ben-1');
        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertSame('not_allowed', $this->messages()->send($this->ben, $thread, 'Hello?', 'uuid-ben-2')['reason']);
        $this->assertTrue($this->messages()->send($this->ann, $thread, 'Please stop.', 'uuid-ann-1')['ok'], 'the blocker may still write');

        foreach ([$this->ann, $this->ben] as $reader) {
            $this->assertCount(2, $this->messages()->page($reader, $thread)['messages']);
        }
    }

    public function testABlockedPersonCannotStartANewThread(): void
    {
        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertNull((new ConversationService())->startDirect($this->ben, (int) $this->ann->id));
    }

    public function testABlockLeavesTheSharedCourseChatAlone(): void
    {
        $chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertTrue($this->messages()->send($this->ben, $chat, 'Question about the quiz', 'uuid-ben-3')['ok']);
        $this->assertCount(1, $this->messages()->page($this->ann, $chat)['messages']);
    }

    public function testUnblockingOpensTheDoorAgain(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];
        $this->blocks()->block($this->ann, (int) $this->ben->id);
        $this->blocks()->unblock($this->ann, (int) $this->ben->id);

        $this->assertTrue($this->messages()->send($this->ben, $thread, 'Thanks', 'uuid-ben-4')['ok']);
    }

    public function testAnUnrelatedThreadIsUntouched(): void
    {
        $carl = PeopleFactory::person('student', 'Carl', 'Ito', null, $this->institution);
        CourseFactory::enroll($this->offering, $carl);
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $carl->id)['id'];

        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertTrue($this->messages()->send($this->ben, $thread, 'Hi Carl', 'uuid-ben-5')['ok']);
    }

    public function testTheListRowsSayWhetherABlockIsOfferedOrInForce(): void
    {
        $service = new ConversationService();
        (new ConversationService())->startDirect($this->ann, (int) $this->ben->id);
        (new ConversationService())->startDirect($this->ann, (int) $this->instructor->id);

        $rows = fn (): array => array_column((new ConversationPresenter($this->parentCipher()))->present($this->ann, $service->listFor($this->ann, 'direct')), null, 'name');

        $this->assertTrue($rows()['Ben Ng']['can_block']);
        $this->assertFalse($rows()['Ben Ng']['blocked_by_me']);
        $this->assertFalse($rows()['Ins Tructor']['can_block'], 'an instructor cannot be blocked');

        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertFalse($rows()['Ben Ng']['can_block']);
        $this->assertTrue($rows()['Ben Ng']['blocked_by_me']);
        $this->assertSame([['id' => (int) $this->ben->id, 'name' => 'Ben Ng']], $this->blocks()->blockedBy($this->ann));
    }
}

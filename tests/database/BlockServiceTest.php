<?php

declare(strict_types=1);

use App\Services\Messaging\BlockService;
use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingContextResolver;
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

        foreach ([$admin, $this->ann, $outside, CourseFactory::user('admin')] as $target) {
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
        \Tests\Support\Threads::withMessage((int) (new ConversationService())->startDirect($this->ann, (int) $this->ben->id)['id'], (int) $this->ben->id);
        \Tests\Support\Threads::withMessage((int) (new ConversationService())->startDirect($this->ann, (int) $this->instructor->id)['id'], (int) $this->instructor->id);

        $rows = fn (): array => array_column((new ConversationPresenter($this->parentCipher()))->present($this->ann, $service->listFor($this->ann, 'direct')), null, 'name');

        $this->assertTrue($rows()['Ben Ng']['can_block']);
        $this->assertFalse($rows()['Ben Ng']['blocked_by_me']);
        $this->assertTrue($rows()['Ins Tructor']['can_block'], 'offered for an own instructor: the click answers with the protected-link sentence (FR4)');
        $this->assertSame("You can't block your instructor while you share a course.", $this->blocks()->refusal($this->ann, (int) $this->instructor->id));
        $this->assertFalse($this->blocks()->block($this->ann, (int) $this->instructor->id), 'and the server still refuses it');

        $this->blocks()->block($this->ann, (int) $this->ben->id);

        $this->assertFalse($rows()['Ben Ng']['can_block']);
        $this->assertTrue($rows()['Ben Ng']['blocked_by_me']);
        $this->assertSame([['id' => (int) $this->ben->id, 'name' => 'Ben Ng', 'paused' => false]], $this->blocks()->blockedBy($this->ann));
    }

    public function testBlockIsOfferedForAnUnrelatedInstructorButNotAParentsChildInstructorOrAnAdmin(): void
    {
        $other = PeopleFactory::person('instructor', 'Una', 'Related', null, $this->institution);
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $other);
        \Tests\Support\Threads::withMessage((int) (new ConversationService())->startDirect($this->ann, (int) $other->id)['id'], (int) $other->id);
        db_connect()->table('instructor_assignments')->where('instructor_user_id', $other->id)->delete();
        \Tests\Support\Threads::withMessage((int) (new ConversationService())->startDirect($this->parent, (int) $this->instructor->id)['id'], (int) $this->instructor->id);

        $present = fn (User $viewer): array => array_column((new ConversationPresenter($this->parentCipher()))->present($viewer, (new ConversationService())->listFor($viewer, 'direct')), null, 'name');

        $this->assertTrue($present($this->ann)['Una Related']['can_block']);
        $this->assertFalse($present($this->parent)['Ins Tructor']['can_block'], 'a parent cannot block their child instructor');
        $this->assertTrue((new BlockService())->allows($this->ann, $this->institution, $other, $this->institution, false));
        $this->assertFalse((new BlockService())->allows($this->ann, $this->institution, $admin, $this->institution, false));
    }

    public function testAPausedBlockLetsAnExistingThreadPostUntilTheEnrolmentEnds(): void
    {
        $other = PeopleFactory::person('instructor', 'Una', 'Related', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $other);
        $thread = (int) (new ConversationService())->startDirect($other, (int) $this->ann->id)['id'];
        db_connect()->table('instructor_assignments')->where('instructor_user_id', $other->id)->delete();
        $this->assertTrue($this->blocks()->block($this->ann, (int) $other->id));
        $this->assertSame('not_allowed', $this->messages()->send($other, $thread, 'Hello?', 'uuid-post-1')['reason']);

        CourseFactory::assignInstructor($this->offering, $other);
        $this->assertTrue($this->messages()->send($other, $thread, 'About class', 'uuid-post-2')['ok'], 'the protected link pauses the block');

        db_connect()->table('enrollments')->where('student_user_id', $this->ann->id)->update(['status' => 'dropped']);
        $this->assertSame('not_allowed', $this->messages()->send($other, $thread, 'Again', 'uuid-post-3')['reason']);
    }

    private function resolver(): MessagingContextResolver
    {
        return new MessagingContextResolver();
    }

    private function insertBlock(User $blocker, User $blocked): void
    {
        db_connect()->table('user_blocks')->insert([
            'institution_id' => $this->institution, 'blocker_id' => $blocker->id, 'blocked_id' => $blocked->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testCannotBlockOwnInstructor(): void
    {
        $this->assertFalse($this->blocks()->block($this->ann, (int) $this->instructor->id));
        $this->assertSame(0, $this->rows());
    }

    public function testCanBlockUnrelatedInstructor(): void
    {
        $other = PeopleFactory::person('instructor', 'Una', 'Related', null, $this->institution);

        $this->assertTrue($this->blocks()->block($this->ann, (int) $other->id));
        $this->assertSame(1, $this->rows());
    }

    public function testInstructorBlockOnOwnStudentDoesNotBind(): void
    {
        $this->insertBlock($this->instructor, $this->ann);

        $this->assertFalse($this->resolver()->blockBinds((int) $this->instructor->id, (int) $this->ann->id));
        $this->assertFalse($this->resolver()->forStart($this->ann, $this->instructor, null)->blockedByRecipient);
        $this->assertSame(1, $this->rows(), 'pausing never deletes the row');
    }

    public function testAdminBlockNeverBinds(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->insertBlock($admin, $this->ann);

        $this->assertFalse($this->resolver()->blockBinds((int) $admin->id, (int) $this->ann->id));
        $this->assertFalse($this->resolver()->forStart($this->ann, $admin, null)->blockedByRecipient);
    }

    public function testCannotBlockInstitutionalAdmin(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        $this->assertFalse($this->blocks()->block($this->ann, (int) $admin->id));
    }

    public function testParentCannotBlockChildsInstructor(): void
    {
        $this->assertFalse($this->blocks()->block($this->parent, (int) $this->instructor->id));
        $this->assertSame(0, $this->rows());
    }

    public function testBlockResumesWhenCourseEnds(): void
    {
        $other = PeopleFactory::person('instructor', 'Una', 'Related', null, $this->institution);
        $this->assertTrue($this->blocks()->block($this->ann, (int) $other->id));
        $this->assertTrue($this->resolver()->forStart($other, $this->ann, null)->blockedByRecipient);

        CourseFactory::assignInstructor($this->offering, $other);

        $this->assertTrue($this->blocks()->blockedBy($this->ann)[0]['paused']);
        $this->assertFalse($this->resolver()->forStart($other, $this->ann, null)->blockedByRecipient);
        $this->assertNotNull((new ConversationService())->startDirect($other, (int) $this->ann->id));

        db_connect()->table('enrollments')->where('student_user_id', $this->ann->id)->delete();

        $this->assertFalse($this->blocks()->blockedBy($this->ann)[0]['paused']);
        $this->assertTrue($this->resolver()->forStart($other, $this->ann, null)->blockedByRecipient);
    }

    public function testBlockedListShowsPaused(): void
    {
        $this->insertBlock($this->instructor, $this->ann);
        $this->insertBlock($this->instructor, $this->ben);
        db_connect()->table('enrollments')->where('student_user_id', $this->ben->id)->update(['status' => 'dropped']);

        $list = array_column($this->blocks()->blockedBy($this->instructor), 'paused', 'id');

        $this->assertTrue($list[(int) $this->ann->id]);
        $this->assertFalse($list[(int) $this->ben->id], 'ben is not enrolled, so the block binds');
    }
}

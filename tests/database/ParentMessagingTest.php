<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\RecipientSearch;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The parent rules of spec 3.1 and 3.3, end to end through the services: who
 * may start with a parent, what a parent thread is about, and that the child
 * it is about never sees it.
 *
 * @internal
 */
final class ParentMessagingTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('PM');
    }

    private function conversations(): ConversationService
    {
        return new ConversationService();
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function sibling(): User
    {
        $sibling = PeopleFactory::person('student', 'Sib', 'Ling', null, $this->institution);
        CourseFactory::enroll($this->offering, $sibling);
        $this->linkParent($this->parent, $sibling);

        return $sibling;
    }

    public function testAParentStartsWithTheInstructorAndTheChairpersonAboutTheChild(): void
    {
        foreach ([$this->instructor, $this->chair] as $staff) {
            $thread = $this->conversations()->startDirect($this->parent, (int) $staff->id);

            $this->assertNotNull($thread, $staff->first_name ?? '');
            $this->assertSame((int) $this->child->id, (int) $thread['subject_student_id']);
        }
    }

    public function testBothSidesSeeTheChildsNameOnTheThread(): void
    {
        $this->conversations()->startDirect($this->parent, (int) $this->instructor->id);

        foreach ([$this->parent, $this->instructor] as $viewer) {
            $row = (new ConversationPresenter($this->parentCipher()))->present($viewer, $this->conversations()->listFor($viewer))[0];

            $this->assertSame('Kid Child', $row['subtitle']);
        }
    }

    public function testTwoChildrenWithOneInstructorGiveTwoThreads(): void
    {
        $sibling = $this->sibling();
        $service = $this->conversations();

        $this->assertNull($service->startDirect($this->parent, (int) $this->instructor->id), 'with two in common the child must be named');

        $first  = $service->startDirect($this->parent, (int) $this->instructor->id, (int) $this->child->id);
        $second = $service->startDirect($this->parent, (int) $this->instructor->id, (int) $sibling->id);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $service->startDirect($this->instructor, (int) $this->parent->id, (int) $this->child->id)['id'], 'whichever side starts, it is the same thread');
        $this->assertSame($second['id'], $service->startDirect($this->parent, (int) $this->instructor->id, (int) $sibling->id)['id']);
    }

    public function testTheChairpersonCannotStartWithAParentButCanReply(): void
    {
        $service = $this->conversations();

        $this->assertNull($service->startDirect($this->chair, (int) $this->parent->id));

        $thread = $service->startDirect($this->parent, (int) $this->chair->id);

        $this->assertTrue($this->messages()->send($this->parent, (int) $thread['id'], 'I need to escalate this.', 'uuid-parent-1')['ok']);
        $this->assertTrue($this->messages()->send($this->chair, (int) $thread['id'], 'I am looking into it.', 'uuid-chair-1')['ok']);
    }

    public function testTheInstructorStartsWithTheParentOfAStudentOfTheirCourseOnly(): void
    {
        $service  = $this->conversations();
        $stranger = PeopleFactory::person('parent', 'Stran', 'Ger', null, $this->institution);

        $this->assertNotNull($service->startDirect($this->instructor, (int) $this->parent->id));
        $this->assertNull($service->startDirect($this->instructor, (int) $stranger->id));
    }

    /** The institutional administrator is the exception: they may write to anyone (spec 3.1), see AdminMessagingTest. */
    public function testOthersCannotStartWithAParent(): void
    {
        $dean  = DeanFactory::dean($this->institution, $this->department);
        $other = PeopleFactory::person('student', 'Cla', 'Ssmate', null, $this->institution);
        CourseFactory::enroll($this->offering, $other);

        foreach ([$dean, $this->child, $other] as $sender) {
            $this->assertNull($this->conversations()->startDirect($sender, (int) $this->parent->id), $sender->first_name ?? '');
        }
    }

    public function testAParentCannotStartWithAStudentAnotherParentADeanOrAnAdmin(): void
    {
        $dean   = DeanFactory::dean($this->institution, $this->department);
        $admin  = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $parent = PeopleFactory::person('parent', 'Oth', 'Parent', null, $this->institution);

        foreach ([$this->child, $parent, $dean, $admin] as $recipient) {
            $this->assertNull($this->conversations()->startDirect($this->parent, (int) $recipient->id), $recipient->first_name ?? '');
        }
    }

    public function testTheChildNeverSeesTheThread(): void
    {
        $thread = (int) $this->conversations()->startDirect($this->parent, (int) $this->instructor->id)['id'];
        $this->messages()->send($this->instructor, $thread, 'Your child is doing well.', 'uuid-teacher-1');

        $this->assertNull($this->conversations()->find($this->child, $thread));
        $this->assertNull($this->messages()->page($this->child, $thread));
        $this->assertSame('not_found', $this->messages()->send($this->child, $thread, 'hi', 'uuid-child-1')['reason']);
        $this->assertSame([], $this->conversations()->listFor($this->child, 'direct'));
        $this->assertSame(0, $this->conversations()->unreadCount($this->child));
        $this->assertSame(0, db_connect()->table('conversation_participants')->where(['conversation_id' => $thread, 'user_id' => $this->child->id])->countAllResults());
    }

    public function testTheChildIsNeverOfferedAParent(): void
    {
        $this->assertSame([], (new RecipientSearch())->search($this->child, 'Parent'));
    }

    public function testTheParentIsOfferedTheInstructorPerChildAndTheChairpersonOnly(): void
    {
        $this->sibling();

        $found = (new RecipientSearch())->search($this->parent, 'Tructor');
        $this->assertSame(['instructor', 'instructor'], array_column($found, 'role'));
        $this->assertEqualsCanonicalizing(['Kid Child', 'Sib Ling'], array_column($found, 'subject_name'));

        $this->assertSame(['chairperson'], array_column((new RecipientSearch())->search($this->parent, 'Chair'), 'role'));
        $this->assertSame([], (new RecipientSearch())->search($this->parent, 'Child'), 'a student is never offered');
        $this->assertSame([], (new RecipientSearch())->search($this->parent, 'Admin'));
    }

    public function testAParentOfAnotherInstitutionReachesNobodyHere(): void
    {
        $far      = DeanFactory::department('PMX')['institution'];
        $outsider = PeopleFactory::person('parent', 'Far', 'Parent', null, $far);

        $this->assertNull($this->conversations()->startDirect($outsider, (int) $this->instructor->id));
        $this->assertSame([], (new RecipientSearch())->search($outsider, 'Tructor'));
    }
}

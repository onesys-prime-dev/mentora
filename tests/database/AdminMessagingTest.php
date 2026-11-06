<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingPolicy;
use App\Services\Messaging\PostContext;
use App\Services\Messaging\RecipientSearch;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The institutional administrator (spec 3.1): writes to anyone in their
 * institution and gets replies; nobody else starts a thread with them; the
 * system administrator has no Messages at all.
 *
 * @internal
 */
final class AdminMessagingTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private \CodeIgniter\Shield\Entities\User $admin;

    private \CodeIgniter\Shield\Entities\User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('AD');
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->dean  = DeanFactory::dean($this->institution, $this->college);
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    public function testTheAdminStartsWithEveryRoleAndEachRepliesAndAnAdminReachesAnotherAdmin(): void
    {
        $otherAdmin = PeopleFactory::person('institutional_admin', 'Abe', 'Admin', null, $this->institution);

        foreach ([$this->child, $this->instructor, $this->chair, $this->dean, $this->parent, $otherAdmin] as $recipient) {
            $thread = (new ConversationService())->startDirect($this->admin, (int) $recipient->id);

            $this->assertNotNull($thread, (string) $recipient->first_name);
            $this->assertNull($thread['subject_student_id'], 'a thread with the administrator is about nobody in particular');
            $this->assertTrue($this->messages()->send($this->admin, (int) $thread['id'], 'A notice from the office', 'uuid-' . $recipient->id . '-a')['ok']);
            $this->assertTrue($this->messages()->send($recipient, (int) $thread['id'], 'Thank you', 'uuid-' . $recipient->id . '-r')['ok'], (string) $recipient->first_name . ' replies');
        }
    }

    public function testNobodyElseStartsWithTheAdminButEveryoneCanReplyToOneTheAdminStarted(): void
    {
        foreach ([$this->child, $this->instructor, $this->chair, $this->dean, $this->parent] as $person) {
            $this->assertNull((new ConversationService())->startDirect($person, (int) $this->admin->id), (string) $person->first_name);
        }

        $thread = (int) (new ConversationService())->startDirect($this->admin, (int) $this->parent->id)['id'];

        $this->assertTrue($this->messages()->send($this->parent, $thread, 'Yes, I can come in.', 'uuid-parent-1')['ok']);
    }

    public function testTheAdminFindsPeopleOfTheirInstitutionOnly(): void
    {
        $far = PeopleFactory::person('instructor', 'Far', 'Tructor', null, DeanFactory::college('AD2')['institution']);

        $found = (new RecipientSearch())->search($this->admin, 'Tructor');

        $this->assertSame([(int) $this->instructor->id], array_column($found, 'id'));
        $this->assertNotContains((int) $far->id, array_column($found, 'id'));

        $parents = (new RecipientSearch())->search($this->admin, 'Parent');
        $this->assertSame(['parent'], array_column($parents, 'role'));
        $this->assertSame([null], array_column($parents, 'subject_student_id'));
    }

    public function testTheSystemAdministratorHasNoMessagesBothWays(): void
    {
        $system = CourseFactory::user('admin');

        $this->assertNull((new ConversationService())->startDirect($system, (int) $this->instructor->id));
        $this->assertNull((new ConversationService())->startDirect($this->admin, (int) $system->id));
        $this->assertSame([], (new RecipientSearch())->search($system, 'Tructor'));
    }

    public function testTheAdminCannotSeeAThreadBetweenOthers(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->parent, (int) $this->instructor->id)['id'];

        $this->assertNull((new ConversationService())->find($this->admin, $thread));
        $this->assertNull($this->messages()->page($this->admin, $thread));
    }

    public function testAnAdminCannotBeBlockedOutOfAReplyPath(): void
    {
        $policy = new MessagingPolicy();

        $this->assertTrue($policy->canPost(new PostContext('direct', true, true, blocked: true, blockerIsExempt: true)));
        $this->assertFalse($policy->canPost(new PostContext('direct', true, true, blocked: true)));
    }
}

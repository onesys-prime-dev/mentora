<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ParentFeedService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The read-only feed of the instructor's posts that a parent sees for a child's course.
 *
 * @internal
 */
final class ParentFeedServiceTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private int $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('PF');
        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
    }

    private function say(User $user, string $text): int
    {
        return (int) (new MessageService($this->parentCipher()))->send($user, $this->chat, $text, 'u-' . bin2hex(random_bytes(6)))['message']['id'];
    }

    private function feed(?User $parent = null, ?User $child = null, int $limit = 30): ?array
    {
        return (new ParentFeedService($this->parentCipher()))->feed($parent ?? $this->parent, (int) ($child ?? $this->child)->id, $this->offering, $limit);
    }

    /** @return list<string> */
    private function bodies(?array $feed): array
    {
        return array_column($feed['posts'] ?? [], 'body');
    }

    public function testOnlyTheInstructorsPostsComeBackNewestFirst(): void
    {
        $this->say($this->instructor, 'first');
        $this->say($this->child, 'a student speaking');
        $this->say($this->instructor, 'second');

        $this->assertSame(['second', 'first'], $this->bodies($this->feed()));
        $this->assertSame('Ins Tructor', $this->feed()['posts'][0]['sender_name']);
    }

    public function testARemovedPostIsNotShown(): void
    {
        $id = $this->say($this->instructor, 'oops');
        $this->say($this->instructor, 'kept');
        db_connect()->table('messages')->where('id', $id)->update(['removed_at' => date('Y-m-d H:i:s'), 'removed_by' => $this->instructor->id]);

        $this->assertSame(['kept'], $this->bodies($this->feed()));
    }

    public function testNothingFromBeforeTheChildJoinedIsShown(): void
    {
        $late       = PeopleFactory::person('student', 'Late', 'Comer', null, $this->institution);
        $lateParent = PeopleFactory::person('parent', 'Late', 'Parent', null, $this->institution);
        $this->say($this->instructor, 'before');
        CourseFactory::enroll($this->offering, $late);
        $this->linkParent($lateParent, $late);
        (new CourseChatSync())->syncOffering($this->offering);
        $this->say($this->instructor, 'after');

        $this->assertSame(['after'], $this->bodies($this->feed($lateParent, $late)));
        $this->assertSame(['after', 'before'], $this->bodies($this->feed()));
    }

    public function testADroppedChildHasNoFeedButTheInstructorsAreStillListed(): void
    {
        $this->say($this->instructor, 'hello');
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->child->id])->update(['status' => 'dropped']);
        (new CourseChatSync())->syncOffering($this->offering);

        $feed = $this->feed();

        $this->assertSame([], $feed['posts']);
        $this->assertSame([(int) $this->instructor->id], array_column($feed['instructors'], 'id'));
    }

    public function testACompletedChildStillHasTheFeed(): void
    {
        $this->say($this->instructor, 'well done');
        db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $this->child->id])->update(['status' => 'completed']);
        (new CourseChatSync())->syncOffering($this->offering);

        $this->assertSame(['well done'], $this->bodies($this->feed()));
    }

    public function testAnUnlinkedParentAndAnotherFamilyGetNothing(): void
    {
        $stranger = PeopleFactory::person('parent', 'Stran', 'Ger', null, $this->institution);

        $this->assertNull($this->feed($stranger));

        $otherChild = PeopleFactory::person('student', 'Other', 'Kid', null, $this->institution);
        CourseFactory::enroll($this->offering, $otherChild);

        $this->assertNull($this->feed($this->parent, $otherChild), 'a child this parent is not linked to');

        $unenrolled = PeopleFactory::person('student', 'Not', 'Enrolled', null, $this->institution);
        $this->linkParent($this->parent, $unenrolled);

        $this->assertNull($this->feed($this->parent, $unenrolled), 'a linked child who is not in this course');
    }

    public function testEveryInstructorOfTheOfferingIsListed(): void
    {
        $second = PeopleFactory::person('instructor', 'Sec', 'Ond', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $second);
        (new CourseChatSync())->syncOffering($this->offering);
        $this->say($second, 'from the second');

        $feed = $this->feed();

        $this->assertEqualsCanonicalizing([(int) $this->instructor->id, (int) $second->id], array_column($feed['instructors'], 'id'));
        $this->assertSame(['from the second'], $this->bodies($feed));
    }

    public function testAnUnreadableBodyShowsAsUnavailableAndTheLimitIsKept(): void
    {
        $this->say($this->instructor, 'one');
        $id = $this->say($this->instructor, 'two');
        $this->say($this->instructor, 'three');
        db_connect()->table('messages')->where('id', $id)->update(['body_enc' => 'not-a-valid-ciphertext']);

        $this->assertSame(['three', 'Message unavailable', 'one'], $this->bodies($this->feed()));
        $this->assertCount(2, $this->feed(null, null, 2)['posts']);
    }
}

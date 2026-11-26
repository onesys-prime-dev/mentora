<?php

declare(strict_types=1);

use App\Services\Messaging\BlockService;
use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Messaging\Party;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\Threads;

/**
 * FR5 / S2-b: a thread between people who have never shared a course, link or earlier chat is a first contact
 * until the recipient replies or blocks.
 *
 * @internal
 */
final class FirstContactTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('FC');
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function first(User $from, User $to): int
    {
        $result = $this->messages()->sendFirst($from, (int) $to->id, null, 'Hello', bin2hex(random_bytes(12)));
        $this->assertTrue($result['ok']);

        return (int) $result['conversation_id'];
    }

    /** @return array<string, mixed> */
    private function row(User $viewer): array
    {
        $rows = (new ConversationPresenter())->present($viewer, (new ConversationService())->listFor($viewer));
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private function cleared(int $conversationId): bool
    {
        return db_connect()->table('conversations')->where('id', $conversationId)->get()->getRow('first_contact_cleared_at') !== null;
    }

    public function testFirstContactWhenNeverTalked(): void
    {
        $draft = (new ConversationService())->draftFor($this->chair, (int) $this->instructor->id);
        $this->assertTrue($draft['first_contact']);

        $id = $this->first($this->chair, $this->instructor);
        $this->assertSame(1, (int) db_connect()->table('conversations')->where('id', $id)->get()->getRow('first_contact'));
    }

    public function testNoFirstContactForClassmates(): void
    {
        $mate = PeopleFactory::person('student', 'Mate', 'Class', null, $this->institution);
        CourseFactory::enroll($this->offering, $mate);

        $this->assertFalse((new ConversationService())->draftFor($mate, (int) $this->child->id)['first_contact']);
    }

    public function testNoFirstContactForInstructorAndStudent(): void
    {
        $this->assertFalse((new ConversationService())->draftFor($this->instructor, (int) $this->child->id)['first_contact']);
        $this->assertFalse((new ConversationService())->draftFor($this->child, (int) $this->instructor->id)['first_contact']);
    }

    public function testNoFirstContactForParentAndChildsInstructor(): void
    {
        $this->assertFalse((new ConversationService())->draftFor($this->parent, (int) $this->instructor->id)['first_contact']);
    }

    public function testNoFirstContactAfterEarlierThread(): void
    {
        $id = (int) (new ConversationService())->startDirect($this->chair, (int) $this->instructor->id)['id'];
        $this->assertTrue((new ConversationService())->draftFor($this->chair, (int) $this->instructor->id)['first_contact'], 'an empty thread is not a conversation');

        Threads::withMessage($id, (int) $this->chair->id);
        $this->assertFalse((new ConversationService())->draftFor($this->chair, (int) $this->instructor->id)['first_contact']);
    }

    public function testLineShownOnlyToRecipient(): void
    {
        $this->first($this->chair, $this->instructor);

        $recipient = $this->row($this->instructor);
        $this->assertTrue($recipient['first_contact_line']);
        $this->assertFalse($recipient['first_contact_sender']);
        $this->assertSame('chairperson', $recipient['first_contact_role']);

        $sender = $this->row($this->chair);
        $this->assertFalse($sender['first_contact_line']);
        $this->assertTrue($sender['first_contact_sender']);
    }

    public function testLineClearsWhenRecipientReplies(): void
    {
        $id = $this->first($this->chair, $this->instructor);

        $this->messages()->send($this->chair, $id, 'Again', bin2hex(random_bytes(12)));
        $this->assertFalse($this->cleared($id));

        $this->messages()->send($this->instructor, $id, 'Hi', bin2hex(random_bytes(12)));
        $this->assertTrue($this->cleared($id));
        $this->assertFalse($this->row($this->instructor)['first_contact_line']);
        $this->assertFalse($this->row($this->chair)['first_contact_sender']);
    }

    public function testLineClearsWhenRecipientBlocks(): void
    {
        $id = $this->first($this->chair, $this->instructor);

        $this->assertTrue((new BlockService())->block($this->instructor, (int) $this->chair->id));
        $this->assertTrue($this->cleared($id));
    }

    public function testAnEmptyOlderThreadDecidesFirstContactAtItsFirstMessage(): void
    {
        $id = (int) (new ConversationService())->startDirect($this->chair, (int) $this->instructor->id)['id'];
        // As an empty thread opened before Phase 3 stands: the column's default.
        db_connect()->table('conversations')->where('id', $id)->update(['first_contact' => 0]);

        // The other side writes first into the empty thread: they are the starter.
        $this->messages()->send($this->instructor, $id, 'Hello chair', bin2hex(random_bytes(12)));

        $this->assertSame(1, (int) db_connect()->table('conversations')->where('id', $id)->get()->getRow('first_contact'));
        $this->assertSame((int) $this->instructor->id, (int) db_connect()->table('conversations')->where('id', $id)->get()->getRow('created_by'));
        $this->assertTrue($this->row($this->chair)['first_contact_line']);
        $this->assertTrue($this->row($this->instructor)['first_contact_sender']);

        $this->messages()->send($this->chair, $id, 'Hi', bin2hex(random_bytes(12)));
        $this->assertTrue($this->cleared($id));
    }

    /** A resolver that says the given person is a minor, as Phase 5 will. */
    private function minorIs(User $minor): MessagingContextResolver
    {
        return new class ($minor) extends MessagingContextResolver {
            public function __construct(private User $minor)
            {
            }

            public function party(User $user): Party
            {
                $p = parent::party($user);

                return (int) $user->id === (int) $this->minor->id ? new Party($p->id, $p->roles, $p->active, $p->institutionId, true) : $p;
            }
        };
    }

    public function testAMinorRecipientIsInvisibleToTheSender(): void
    {
        $mate = PeopleFactory::person('student', 'Mate', 'Class', null, $this->institution);
        CourseFactory::enroll($this->offering, $mate);
        $resolver = $this->minorIs($this->child);
        $service  = new MessageService($this->parentCipher(), new ConversationService($resolver), $resolver);

        $draft = (new ConversationService($resolver))->draftFor($mate, (int) $this->child->id);
        $this->assertFalse($draft['first_contact'], 'the sender sees no difference for a minor');

        $result = $service->sendFirst($mate, (int) $this->child->id, null, 'Hey', bin2hex(random_bytes(12)));
        $this->assertTrue($result['ok']);
        $id = (int) $result['conversation_id'];
        $this->assertSame(0, (int) db_connect()->table('conversations')->where('id', $id)->get()->getRow('first_contact'));

        $present = static fn (User $v): array => (new ConversationPresenter(null, $resolver))->present($v, (new ConversationService($resolver))->listFor($v))[0];
        $sender  = $present($mate);
        $this->assertFalse($sender['first_contact_sender']);
        $this->assertFalse($sender['first_contact_line']);

        // What the minor sees: the line, until they reply.
        $this->assertTrue($present($this->child)['first_contact_line']);
        $service->send($this->child, $id, 'Hi', bin2hex(random_bytes(12)));
        $this->assertFalse($present($this->child)['first_contact_line']);
    }

    public function testNamingTheStarterCostsTheSameForTwoOrTenFirstContacts(): void
    {
        $cost = function (int $starters): int {
            for ($i = 0; $i < $starters; $i++) {
                $person = PeopleFactory::person('student', 'Cold' . $starters . '_' . $i, 'Caller', null, $this->institution);
                $now    = date('Y-m-d H:i:s');
                db_connect()->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'direct', 'created_by' => $person->id, 'is_locked' => 0, 'first_contact' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $id = (int) db_connect()->insertID();

                foreach ([$person, $this->instructor] as $member) {
                    db_connect()->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $member->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
                }

                Threads::withMessage($id, (int) $person->id);
            }

            // What the open first contacts add to the cost of the list: the same rows, counted unflagged and flagged.
            $measure = function (): int {
                $rows = (new ConversationService())->listFor($this->instructor);
                $n    = 0;
                $l    = static function (Query $q) use (&$n): void {
                    $n++;
                };
                Events::on('DBQuery', $l);
                (new ConversationPresenter())->present($this->instructor, $rows);
                Events::removeListener('DBQuery', $l);

                return $n;
            };

            db_connect()->table('conversations')->update(['first_contact' => 0]);
            $measure(); // warm: per-request caches fill on the first pass
            $plain = $measure();
            db_connect()->table('conversations')->update(['first_contact' => 1]);
            $measure();

            return $measure() - $plain;
        };

        $two = $cost(2);
        $ten = $cost(10);

        $this->assertSame($two, $ten, 'one lookup for the starters, not one each');
    }
}

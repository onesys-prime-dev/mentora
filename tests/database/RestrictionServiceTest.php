<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\MessageActions;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Messaging\RestrictionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Restrictions (FR6): no new conversations, no sending, expiry, replacement and who may place one.
 *
 * @internal
 */
final class RestrictionServiceTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private User $ann;

    private User $ben;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('RS');

        $this->ann   = $this->child;
        $this->ben   = PeopleFactory::person('student', 'Ben', 'Ng', null, $this->institution);
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        CourseFactory::enroll($this->offering, $this->ben);
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function restrictions(): RestrictionService
    {
        return new RestrictionService();
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testNoNewBlocksStartButNotReply(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];
        $this->messages()->send($this->ann, $thread, 'Hi Ben', 'uuid-rs-1');

        $this->assertTrue($this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_new', null));

        $carl = PeopleFactory::person('student', 'Carl', 'Ito', null, $this->institution);
        CourseFactory::enroll($this->offering, $carl);

        $this->assertNull((new ConversationService())->startDirect($this->ben, (int) $carl->id));
        $this->assertTrue($this->messages()->send($this->ben, $thread, 'Hi Ann', 'uuid-rs-2')['ok'], 'a reply still goes');
        $this->assertNotNull((new ConversationService())->startDirect($this->ann, (int) $carl->id), 'others are unaffected');
    }

    public function testNoSendBlocksReplyButNotOwnCourseChat(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $this->instructor->id)['id'];
        $chat   = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];

        $this->restrictions()->restrict($this->admin, (int) $this->instructor->id, 'no_send', null);
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_send', null);

        $this->assertFalse($this->messages()->send($this->instructor, $thread, 'Hello', 'uuid-rs-3')['ok']);
        $this->assertTrue($this->messages()->send($this->instructor, $chat, 'Notice to the class', 'uuid-rs-4')['ok'], 'own course chat stays open');
        $this->assertFalse($this->messages()->send($this->ben, $chat, 'A question', 'uuid-rs-5')['ok'], 'a student is silenced in the chat too');
        $this->assertNull((new ConversationService())->startDirect($this->ben, (int) $this->ann->id), 'no_send also stops new threads');
    }

    public function testRestrictionExpiresAndIsAudited(): void
    {
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_new', new DateTimeImmutable('+2 hours'));

        $this->assertSame('no_new', $this->restrictions()->active((int) $this->ben->id)['kind']);
        $this->assertNull($this->restrictions()->active((int) $this->ben->id, new DateTimeImmutable('+3 hours')));

        $row = db_connect()->table('message_restrictions')->get()->getRowArray();

        $this->assertSame('expired', $row['end_reason']);
        $this->assertNotNull($row['ended_at']);
        $this->assertSame(1, $this->audit('messages.restriction_expired'));
        $this->assertNull($this->restrictions()->active((int) $this->ben->id), 'closed rows stay closed');
        $this->assertSame(1, $this->audit('messages.restriction_expired'), 'audited once');
    }

    public function testNewRestrictionReplacesOld(): void
    {
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_new', null);
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_send', null);

        $this->assertSame('no_send', $this->restrictions()->active((int) $this->ben->id)['kind']);
        $this->assertSame(1, db_connect()->table('message_restrictions')->where('ended_at', null)->countAllResults());
        $this->assertSame(2, $this->audit('messages.restriction_placed'));

        $this->assertTrue($this->restrictions()->lift($this->admin, (int) $this->ben->id));
        $this->assertNull($this->restrictions()->active((int) $this->ben->id));
        $this->assertSame(1, $this->audit('messages.restriction_lifted'));
        $this->assertFalse($this->restrictions()->lift($this->admin, (int) $this->ben->id), 'nothing left to lift');
    }

    public function testRestrictedCannotCreateGroup(): void
    {
        $d       = DeanFactory::college('RS2');
        $dept    = DeanFactory::department($d['college'], 'RSA');
        $term    = DeanFactory::term($d['institution']);
        $offer   = DeanFactory::offering(DeanFactory::program($dept, 'RPA'), $term, 'RA101');
        $chair   = DeanFactory::chair($dept, 'Cara');
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Alpha', null, $d['institution']);
        $admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $d['institution']);
        CourseFactory::assignInstructor($offer, $teacher);

        $this->assertTrue($this->restrictions()->restrict($admin, (int) $chair->id, 'no_new', null));
        $this->assertNull((new GroupService())->create($chair, 'Exam planning', [$teacher->id]));
        $this->assertSame(0, db_connect()->table('conversations')->where('type', 'group')->countAllResults());

        $this->restrictions()->lift($admin, (int) $chair->id);

        $this->assertNotNull((new GroupService())->create($chair, 'Exam planning', [$teacher->id]));
    }

    public function testARestrictedPersonStillOpensAnExistingThreadButNotANewOne(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];
        $carl   = PeopleFactory::person('student', 'Carl', 'Ito', null, $this->institution);
        CourseFactory::enroll($this->offering, $carl);

        foreach (['no_new', 'no_send'] as $kind) {
            $this->restrictions()->restrict($this->admin, (int) $this->ben->id, $kind, null);
            $service = new ConversationService();

            $this->assertSame($thread, $service->draftFor($this->ben, (int) $this->ann->id)['conversation_id'], $kind);
            $this->assertSame($thread, (int) $service->existingDirect($this->ben, (int) $this->ann->id)['id'], $kind);
            $this->assertNull($service->draftFor($this->ben, (int) $carl->id), $kind . ': no new thread');
            $this->assertNull($service->planDirect($this->ben, (int) $carl->id), $kind . ': no new thread');
        }
    }

    public function testTwoRequestsAtTheExpiryWriteOneAudit(): void
    {
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_new', new DateTimeImmutable('+2 hours'));
        db_connect()->table('message_restrictions')->update(['ends_at' => date('Y-m-d H:i:s', time() - 60)]);

        // Both requests read the open row; the other one closes it (and audits) before this one writes.
        $service = new class extends RestrictionService {
            private bool $fired = false;

            protected function openRows(int $userId): array
            {
                $rows = parent::openRows($userId);

                if (! $this->fired) {
                    $this->fired = true;
                    (new RestrictionService())->active($userId);
                }

                return $rows;
            }
        };

        $this->assertNull($service->active((int) $this->ben->id));
        $this->assertSame(1, $this->audit('messages.restriction_expired'));
    }

    public function testLiftEndsEveryLiveAdministratorRow(): void
    {
        // Two administrators placed one at the same moment: each missed the other's uncommitted row.
        $now = date('Y-m-d H:i:s');

        foreach (['no_new', 'no_send'] as $kind) {
            db_connect()->table('message_restrictions')->insert([
                'institution_id' => $this->institution, 'user_id' => (int) $this->ben->id, 'kind' => $kind, 'source' => 'admin',
                'starts_at' => $now, 'ends_at' => null, 'created_by' => (int) $this->admin->id, 'created_at' => $now,
            ]);
        }

        $this->assertTrue($this->restrictions()->lift($this->admin, (int) $this->ben->id));
        $this->assertNull($this->restrictions()->active((int) $this->ben->id), 'nothing older comes back');
        $this->assertSame(2, $this->audit('messages.restriction_lifted'));
    }

    public function testTheViewersRestrictionIsLookedUpOncePerResolver(): void
    {
        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_send', null);
        $now = date('Y-m-d H:i:s');
        db_connect()->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'direct', 'created_by' => (int) $this->ben->id, 'is_locked' => 0, 'direct_key' => 'rs-memo', 'created_at' => $now, 'updated_at' => $now]);
        $row      = db_connect()->table('conversations')->where('id', (int) db_connect()->insertID())->get()->getRowArray();
        $resolver = new MessagingContextResolver();
        $queries  = 0;

        Events::on('DBQuery', static function ($query) use (&$queries): void {
            $queries += str_contains((string) $query->getQuery(), 'message_restrictions') ? 1 : 0;
        });

        foreach (range(1, 5) as $i) {
            $this->assertSame('no_send', $resolver->forPost($this->ben, $row)->restriction);
        }

        $this->assertSame(1, $queries);
    }

    public function testForwardBumpAndReactionsAreRefusedUnderNoSend(): void
    {
        $thread  = (int) (new ConversationService())->startDirect($this->ben, (int) $this->ann->id)['id'];
        $other   = (int) (new ConversationService())->startDirect($this->ben, (int) $this->instructor->id)['id'];
        $mine    = (int) $this->messages()->send($this->ben, $thread, 'Mine', 'uuid-rs-m1')['message']['id'];
        $hers    = (int) $this->messages()->send($this->ann, $thread, 'Hers', 'uuid-rs-m2')['message']['id'];

        $this->assertTrue((new MessageActions($this->messages(), null, null, null, $this->parentCipher()))->react($this->ben, $thread, $hers, MessageActions::QUICK[0])['ok'], 'control: allowed before');

        $this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'no_send', null);
        $actions = new MessageActions($this->messages(), null, null, null, $this->parentCipher()); // a fresh request: the restriction is read once per resolver
        $count = db_connect()->table('messages')->countAllResults();

        $this->assertFalse($actions->react($this->ben, $thread, $hers, MessageActions::QUICK[1])['ok']);
        $this->assertFalse($actions->bump($this->ben, $thread, $mine)['ok']);
        $forward = $actions->forward($this->ben, $thread, $mine, [$other]);
        $this->assertTrue(! $forward['ok'] || ! ($forward['results'][0]['ok'] ?? false));
        $this->assertSame($count, db_connect()->table('messages')->countAllResults(), 'nothing was posted');
    }

    public function testOnlySameInstitutionAdminCanRestrict(): void
    {
        $other      = DeanFactory::college('RS3');
        $outsider   = PeopleFactory::person('institutional_admin', 'Olga', 'Out', null, $other['institution']);
        $instructor = $this->instructor;

        $this->assertFalse($this->restrictions()->restrict($outsider, (int) $this->ben->id, 'no_new', null), 'other institution');
        $this->assertFalse($this->restrictions()->restrict($instructor, (int) $this->ben->id, 'no_new', null), 'not an administrator');
        $this->assertFalse($this->restrictions()->restrict($this->admin, (int) $this->admin->id, 'no_new', null), 'not oneself');
        $this->assertFalse($this->restrictions()->restrict($this->admin, (int) $this->ben->id, 'forever', null), 'unknown kind');
        $this->assertSame(0, db_connect()->table('message_restrictions')->countAllResults());
        $this->assertSame(0, $this->audit('messages.restriction_placed'));
    }
}

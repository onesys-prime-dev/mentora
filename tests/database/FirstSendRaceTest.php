<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\DirectKey;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingContextResolver;
use CodeIgniter\Shield\Entities\User;
use Config\Database;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Two real connections: the other side's first send commits while this one is part-way through its own.
 * Not isolated by a wrapping transaction (a commit on a second connection has to be real), so the tables
 * are emptied again in tearDown.
 *
 * @internal
 */
final class FirstSendRaceTest extends DatabaseTestCase
{
    private User $student;

    private User $instructor;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyTablesOnce();

        $d                 = DeanFactory::college('RC');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'RA'), 'RA');
        $offering          = DeanFactory::offering($program, DeanFactory::term($this->institution), 'RC1');

        $this->student    = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->instructor = PeopleFactory::person('instructor', 'Ins', 'Tructor', null, $this->institution);
        CourseFactory::enroll($offering, $this->student);
        CourseFactory::assignInstructor($offering, $this->instructor);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($db->listTables() as $table) {
            if ($table !== 'migrations') {
                $db->query('TRUNCATE TABLE `' . $table . '`');
            }
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    private function cipher(): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = 'hex2bin:' . str_repeat('61', 32);
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }

    /** The service under test; `$hook` runs once, at the first talkedBefore() (after this send's first reads). */
    private function service(callable $hook): MessageService
    {
        $resolver = new class ($hook) extends MessagingContextResolver {
            private bool $fired = false;

            public function __construct(private $hook)
            {
            }

            public function talkedBefore(int $a, int $b): bool
            {
                if (! $this->fired) {
                    $this->fired = true;
                    ($this->hook)();
                }

                return parent::talkedBefore($a, $b);
            }
        };

        return new MessageService($this->cipher(), new ConversationService($resolver), $resolver);
    }

    /** The student's first send, committed on its own connection. */
    private function otherSideSends(): int
    {
        $c2  = Database::connect('tests', false);
        $now = date('Y-m-d H:i:s');

        $c2->transStart();
        $c2->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'direct', 'created_by' => $this->student->id, 'is_locked' => 0,
            'direct_key' => DirectKey::for($this->institution, (int) $this->student->id, (int) $this->instructor->id, null), 'first_contact' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $c2->insertID();

        foreach ([$this->student, $this->instructor] as $user) {
            $c2->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $user->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $c2->table('messages')->insert(['conversation_id' => $id, 'sender_id' => $this->student->id, 'body_enc' => 'x', 'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => $now]);
        $c2->table('conversations')->where('id', $id)->update(['last_message_id' => $c2->insertID(), 'last_message_at' => $now]);
        $c2->transComplete();
        $c2->close();

        return $id;
    }

    public function testTheSecondSenderEndsInTheSameThread(): void
    {
        $other = 0;
        $b     = $this->service(function () use (&$other): void {
            $other = $this->otherSideSends();
        })->sendFirst($this->instructor, (int) $this->student->id, null, 'Two', bin2hex(random_bytes(12)));

        $this->assertGreaterThan(0, $other);
        $this->assertTrue($b['ok']);
        $this->assertSame($other, $b['conversation_id']);
        $this->assertSame(1, db_connect()->table('conversations')->countAllResults());
        $this->assertSame(2, db_connect()->table('messages')->where('conversation_id', $other)->countAllResults());
    }

    public function testASwallowedQueryFailureRollsBackAndReportsFailure(): void
    {
        // A failed query inside the send that the code catches: only the connection's transaction status remembers it.
        $failing = new class extends MessagingContextResolver {
            private bool $fired = false;

            public function forPost(User $user, array $conversation): \App\Services\Messaging\PostContext
            {
                if (! $this->fired) {
                    $this->fired = true;

                    try {
                        db_connect()->query('SELECT * FROM no_such_table');
                    } catch (Throwable) {
                    }
                }

                return parent::forPost($user, $conversation);
            }
        };
        $service = new MessageService($this->cipher(), new ConversationService($failing), $failing);

        $result = $service->sendFirst($this->instructor, (int) $this->student->id, null, 'Hello', bin2hex(random_bytes(12)));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, db_connect()->table('conversations')->countAllResults());
        $this->assertSame(0, db_connect()->table('messages')->countAllResults());
        $this->assertSame(0, db_connect()->table('notifications')->countAllResults());
    }
}

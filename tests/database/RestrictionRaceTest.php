<?php

declare(strict_types=1);

use App\Services\Messaging\RestrictionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Database;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Two reports at the same moment: the other request's pause commits on its own connection after this one has
 * decided to start one. Not isolated by a wrapping transaction (a commit on a second connection has to be real),
 * so the tables are emptied again in tearDown.
 *
 * @internal
 */
final class RestrictionRaceTest extends DatabaseTestCase
{
    private User $sender;

    private int $institution;

    /** @var list<int> */
    private array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyTablesOnce();
        Services::resetSingle('auditLogger');

        $this->institution = DeanFactory::college('RR')['institution'];
        $this->sender      = PeopleFactory::person('student', 'Sam', 'Sender', null, $this->institution);

        foreach (range(1, 3) as $i) {
            $this->reports[] = $this->strangerReport(PeopleFactory::person('student', 'Stan' . $i, 'Stranger', null, $this->institution));
        }
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

    private function strangerReport(User $reporter): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => 'direct', 'created_by' => (int) $this->sender->id, 'is_locked' => 0,
            'direct_key' => 'rr-' . bin2hex(random_bytes(6)), 'first_contact' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversation = (int) $db->insertID();
        $db->table('messages')->insert(['conversation_id' => $conversation, 'sender_id' => (int) $this->sender->id, 'body_enc' => 'x', 'client_uuid' => 'rr-' . bin2hex(random_bytes(6)), 'created_at' => $now]);
        $db->table('message_reports')->insert([
            'institution_id' => $this->institution, 'message_id' => (int) $db->insertID(), 'reporter_id' => (int) $reporter->id, 'reason' => 'spam',
            'snapshot_enc' => 'x', 'status' => 'open', 'created_at' => $now,
        ]);

        return (int) $db->insertID();
    }

    public function testTwoSimultaneousPausesLeaveOneWithEveryReportOnIt(): void
    {
        // This request sees no pause, then the other request's pause commits before this one writes its own.
        $service = new class ($this->institution) extends RestrictionService {
            public ?int $other = null;

            public function __construct(private int $institution)
            {
            }

            public function active(int $userId, ?DateTimeImmutable $now = null): ?array
            {
                $row = parent::active($userId, $now);

                if ($this->other === null) {
                    $this->other = $this->otherRequestPauses($userId);
                }

                return $row;
            }

            private function otherRequestPauses(int $userId): int
            {
                $c2    = Database::connect('tests', false);
                $stamp = date('Y-m-d H:i:s');
                $c2->transStart();
                $c2->table('message_restrictions')->insert([
                    'institution_id' => $this->institution, 'user_id' => $userId, 'kind' => 'no_new', 'source' => 'pause',
                    'starts_at' => $stamp, 'ends_at' => date('Y-m-d H:i:s', time() + 72 * 3600), 'created_at' => $stamp,
                ]);
                $id = (int) $c2->insertID();
                $c2->table('message_reports')->update(['pause_id' => $id]);
                $c2->transComplete();
                $c2->close();

                return $id;
            }
        };

        $this->assertNull($service->evaluatePause((int) $this->sender->id));
        $this->assertNotNull($service->other);
        $this->assertSame(1, db_connect()->table('message_restrictions')->where('source', 'pause')->countAllResults(), 'one pause');
        $this->assertSame(3, db_connect()->table('message_reports')->where('pause_id', $service->other)->countAllResults(), 'every report still on it');
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'messages.pause_started')->countAllResults());
    }

    public function testASecondEvaluationOnTheSameReportsStartsNoSecondPause(): void
    {
        $service = new RestrictionService();

        $this->assertNotNull($service->evaluatePause((int) $this->sender->id));
        db_connect()->table('message_restrictions')->where('source', 'pause')->update(['ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'expired']);

        $this->assertNull($service->evaluatePause((int) $this->sender->id), 'the same three reports never count twice');
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'messages.pause_started')->countAllResults());
    }
}

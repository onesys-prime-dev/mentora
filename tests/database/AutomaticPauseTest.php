<?php

declare(strict_types=1);

use App\Services\Messaging\ReportService;
use App\Services\Messaging\RestrictionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * The automatic pause (FR6, D8): three different strangers reporting a person's first-contact messages in a day.
 *
 * @internal
 */
final class AutomaticPauseTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private User $sender;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('AP');

        $this->sender = PeopleFactory::person('student', 'Sam', 'Sender', null, $this->institution);
        $this->admin  = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function stranger(): User
    {
        return PeopleFactory::person('student', 'Stan', 'Stranger', null, $this->institution);
    }

    /**
     * A thread started by $from with one message from them.
     *
     * @return array{int, int} conversation id, message id
     */
    private function thread(User $from, User $to, string $type = 'direct', int $firstContact = 1): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('conversations')->insert([
            'institution_id' => $this->institution, 'type' => $type, 'created_by' => (int) $from->id, 'is_locked' => 0,
            'direct_key' => 'ap-' . bin2hex(random_bytes(6)), 'first_contact' => $firstContact, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversation = (int) $db->insertID();

        foreach ([$from, $to] as $user) {
            $db->table('conversation_participants')->insert(['conversation_id' => $conversation, 'user_id' => (int) $user->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $db->table('messages')->insert([
            'conversation_id' => $conversation, 'sender_id' => (int) $from->id,
            'body_enc' => $this->parentCipher()->encrypt('hello there', $conversation, (int) $from->id), 'client_uuid' => 'ap-' . bin2hex(random_bytes(6)), 'created_at' => $now,
        ]);

        return [$conversation, (int) $db->insertID()];
    }

    private function reportFrom(User $reporter, User $from, string $type = 'direct', int $firstContact = 1): void
    {
        [$conversation, $message] = $this->thread($from, $reporter, $type, $firstContact);

        $this->assertTrue((new ReportService($this->parentCipher()))->report($reporter, $conversation, $message, 'harassment', null));
    }

    private function paused(User $user): ?array
    {
        return (new RestrictionService())->active((int) $user->id);
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testThreeStrangersPause(): void
    {
        $this->reportFrom($this->stranger(), $this->sender);
        $this->reportFrom($this->stranger(), $this->sender);

        $this->assertNull($this->paused($this->sender), 'two reports are not enough');

        $this->reportFrom($this->stranger(), $this->sender);
        $row = $this->paused($this->sender);

        $this->assertNotNull($row);
        $this->assertSame('pause', $row['source']);
        $this->assertSame('no_new', $row['kind']);
        $this->assertEqualsWithDelta(time() + 72 * 3600, strtotime((string) $row['ends_at']), 5);
        $this->assertSame(3, db_connect()->table('message_reports')->where('pause_id', (int) $row['id'])->countAllResults());
        $this->assertSame(1, $this->audit('messages.pause_started'));

        $note = db_connect()->table('notifications')->where(['user_id' => (int) $this->admin->id, 'type' => 'message_pause'])->get()->getRowArray();

        $this->assertNotNull($note);
        $this->assertSame('messages/reports', $note['link']);
        $this->assertStringNotContainsString('hello there', (string) $note['title'] . $note['body']);
    }

    public function testClassmatesReportsDoNotCount(): void
    {
        // A class reporting its instructor: they share the offering, so none of it counts.
        foreach (range(1, 3) as $i) {
            $classmate = PeopleFactory::person('student', 'Cl' . $i, 'Mate', null, $this->institution);
            CourseFactory::enroll($this->offering, $classmate);
            $this->reportFrom($classmate, $this->instructor);
        }

        $this->assertNull($this->paused($this->instructor));
        $this->assertSame(0, db_connect()->table('message_restrictions')->countAllResults());
    }

    public function testSameReporterCountsOnce(): void
    {
        $one = $this->stranger();

        foreach (range(1, 3) as $i) {
            $this->reportFrom($one, $this->sender);
        }

        $this->reportFrom($this->stranger(), $this->sender);

        $this->assertNull($this->paused($this->sender));
    }

    public function testGroupOrCourseReportsDoNotCount(): void
    {
        $this->reportFrom($this->stranger(), $this->sender);
        $this->reportFrom($this->stranger(), $this->sender, 'group');
        $this->reportFrom($this->stranger(), $this->sender, 'direct', 0);
        $this->reportFrom($this->stranger(), $this->sender, 'course');

        $this->assertNull($this->paused($this->sender), 'only first-contact direct threads count');
    }

    public function testAdminsNeverPaused(): void
    {
        foreach (range(1, 3) as $i) {
            $this->reportFrom($this->stranger(), $this->admin);
        }

        $this->assertNull($this->paused($this->admin));
        $this->assertSame(0, $this->audit('messages.pause_started'));
    }

    public function testCountedReportsNeverCountAgain(): void
    {
        foreach (range(1, 3) as $i) {
            $this->reportFrom($this->stranger(), $this->sender);
        }

        $first = $this->paused($this->sender);
        $this->assertNotNull($first);

        // The pause ends; two more reports are not enough on top of the three already counted.
        db_connect()->table('message_restrictions')->where('id', $first['id'])->update(['ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'expired']);
        $this->reportFrom($this->stranger(), $this->sender);
        $this->reportFrom($this->stranger(), $this->sender);

        $this->assertNull($this->paused($this->sender));
        $this->assertSame(1, db_connect()->table('message_restrictions')->where('source', 'pause')->countAllResults());
    }

    public function testPauseExpiresAfter72h(): void
    {
        foreach (range(1, 3) as $i) {
            $this->reportFrom($this->stranger(), $this->sender);
        }

        $service = new RestrictionService();

        $this->assertNotNull($service->active((int) $this->sender->id, new DateTimeImmutable('+71 hours')));
        $this->assertNull($service->active((int) $this->sender->id, new DateTimeImmutable('+73 hours')));
        $this->assertSame(1, $this->audit('messages.pause_expired'));
    }
}

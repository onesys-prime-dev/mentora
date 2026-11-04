<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Monitoring\ScheduledJobs;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * messages:notify tells the chats a send did not, once, and counts without telling in a dry run.
 *
 * @internal
 */
final class NotifyMessagesCommandTest extends DatabaseTestCase
{
    use ParentScenario;
    use StreamFilterTrait;

    protected bool $isolateTests = true;

    private int $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        FakeEmail::install();
        $this->buildParentScenario('NC');

        for ($i = 0; $i < 31; $i++) {
            CourseFactory::enroll($this->offering, PeopleFactory::person('student', "Bulk{$i}", 'Student', null, $this->institution));
        }

        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->chat, 'sender_id' => $this->instructor->id, 'body_enc' => $this->parentCipher()->encrypt('Hello class', $this->chat, (int) $this->instructor->id),
            'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function command(string $line): string
    {
        $this->setUpStreamFilterTrait();
        $this->resetStreamFilterBuffer();

        try {
            command($line);

            return $this->getStreamFilterBuffer();
        } finally {
            $this->tearDownStreamFilterTrait();
        }
    }

    private function notices(): int
    {
        return db_connect()->table('notifications')->where('type', 'message')->countAllResults();
    }

    public function testItTellsTheChatsASendLeftAloneAndIsIdempotent(): void
    {
        $output = $this->command('messages:notify');

        $this->assertSame(32, $this->notices(), 'every student and the child, not the instructor who wrote it');
        $this->assertStringContainsString('told 32 person(s)', $output);

        $this->command('messages:notify');
        $this->assertSame(32, $this->notices());
    }

    public function testADryRunCountsAndWritesNothing(): void
    {
        $output = $this->command('messages:notify --dry-run');

        $this->assertSame(0, $this->notices());
        $this->assertStringContainsString('Would look at 1 conversation(s)', $output);
    }

    public function testItReleasesWhatQuietHoursHeld(): void
    {
        (new \App\Services\Messaging\MessagePreferences())->save($this->instructor, '00:00', '23:59', true);
        db_connect()->table('messages')->insert([
            'conversation_id' => $this->chat, 'sender_id' => $this->child->id, 'body_enc' => $this->parentCipher()->encrypt('A question', $this->chat, (int) $this->child->id),
            'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->command('messages:notify');
        $this->assertSame(0, db_connect()->table('notifications')->where(['type' => 'message', 'user_id' => $this->instructor->id])->countAllResults(), 'held inside the hours');

        (new \App\Services\Messaging\MessagePreferences())->save($this->instructor, null, null, true);
        $this->command('messages:notify');

        $this->assertSame(1, db_connect()->table('notifications')->where(['type' => 'message', 'user_id' => $this->instructor->id])->countAllResults());
    }

    public function testARealRunRecordsTheHeartbeatAndADryRunDoesNot(): void
    {
        setting()->forget('Monitoring.messagesNotifyAt');

        $this->command('messages:notify --dry-run');
        $this->assertNull(ScheduledJobs::lastRun('messages:notify'));

        $this->command('messages:notify');
        $this->assertNotNull(ScheduledJobs::lastRun('messages:notify'));
    }
}

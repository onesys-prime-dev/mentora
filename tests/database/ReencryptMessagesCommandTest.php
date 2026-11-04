<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageReencryptor;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\ReportService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Messages;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;

/**
 * Key rotation: everything moves onto the current key, nothing else changes, a
 * dry run only counts, and what nothing can open is named by row and never shown.
 *
 * @internal
 */
final class ReencryptMessagesCommandTest extends DatabaseTestCase
{
    use ParentScenario;
    use StreamFilterTrait;

    private const KEY_A = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const KEY_B = 'hex2bin:6262626262626262626262626262626262626262626262626262626262626262';

    protected bool $isolateTests = true;

    private int $chat;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->buildParentScenario('RE');
        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->chat  = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
    }

    protected function tearDown(): void
    {
        $this->setKeys('', '');

        parent::tearDown();
    }

    private function setKeys(string $key, string $previous): void
    {
        $config                = config(Messages::class);
        $config->encryptionKey = $key;
        $config->previousKeys  = $previous;
    }

    private function cipher(string $key, string $previous = ''): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = $key;
        $config->previousKeys  = $previous;

        return new MessageCipher($config);
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

    /** Messages written under key A, a report of one of them, then the key rotated to B (A kept as previous). */
    private function rotate(): array
    {
        $this->setKeys(self::KEY_A, '');
        $messages = new MessageService($this->cipher(self::KEY_A));
        $first    = (int) $messages->send($this->instructor, $this->chat, 'first body', 'uuid-1-' . bin2hex(random_bytes(4)))['message']['id'];
        $second   = (int) $messages->send($this->instructor, $this->chat, 'second body', 'uuid-2-' . bin2hex(random_bytes(4)))['message']['id'];
        $this->assertTrue((new ReportService($this->cipher(self::KEY_A)))->report($this->child, $this->chat, $first, 'spam', 'a note'));

        $this->setKeys(self::KEY_B, self::KEY_A);

        return [$first, $second];
    }

    private function raw(string $table, int $id, string $column): string
    {
        return (string) db_connect()->table($table)->where('id', $id)->get()->getRowArray()[$column];
    }

    public function testItMovesBodiesSnapshotsAndNotesOntoTheCurrentKey(): void
    {
        [$first, $second] = $this->rotate();
        $report           = (int) db_connect()->table('message_reports')->get()->getRow('id');
        $current          = $this->cipher(self::KEY_B);

        $this->assertFalse($current->usesCurrentKey($this->raw('messages', $first, 'body_enc')));

        $output = $this->command('messages:reencrypt');

        $this->assertStringContainsString('4 value(s) re-encrypted', $output);

        foreach ([[$first, 'body_enc'], [$second, 'body_enc']] as [$id, $column]) {
            $this->assertTrue($current->usesCurrentKey($this->raw('messages', $id, $column)));
        }

        $this->assertTrue($current->usesCurrentKey($this->raw('message_reports', $report, 'snapshot_enc')));
        $this->assertTrue($current->usesCurrentKey($this->raw('message_reports', $report, 'note_enc')));

        // And everything still reads, with the old key now gone.
        $this->setKeys(self::KEY_B, '');
        $reader = new MessageService($this->cipher(self::KEY_B));
        $this->assertSame(['first body', 'second body'], array_column($reader->page($this->child, $this->chat)['messages'], 'body'));
        $view = (new ReportReviewService($this->cipher(self::KEY_B)))->view($this->admin, $report);
        $this->assertSame(['first body'], array_column($view['snapshot'], 'body'));
        $this->assertSame('a note', $view['note']);
    }

    public function testAValueAlreadyOnTheCurrentKeyIsLeftByteForByte(): void
    {
        [$first, $second] = $this->rotate();
        $messages         = new MessageService($this->cipher(self::KEY_B, self::KEY_A));
        $third            = (int) $messages->send($this->instructor, $this->chat, 'third body', 'uuid-3-' . bin2hex(random_bytes(4)))['message']['id'];
        $before           = $this->raw('messages', $third, 'body_enc');

        $this->command('messages:reencrypt');

        $this->assertSame($before, $this->raw('messages', $third, 'body_enc'));
    }

    public function testADryRunChangesNothing(): void
    {
        [$first] = $this->rotate();
        $before  = $this->raw('messages', $first, 'body_enc');

        $output = $this->command('messages:reencrypt --dry-run');

        $this->assertStringContainsString('Would have: 4 value(s)', $output);
        $this->assertSame($before, $this->raw('messages', $first, 'body_enc'));
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'messages.reencrypted')->countAllResults());
    }

    public function testASecondRunChangesNothingAndTheRunIsAuditedWithCountsOnly(): void
    {
        $this->rotate();

        $this->command('messages:reencrypt');
        $output = $this->command('messages:reencrypt');

        $this->assertStringContainsString('0 value(s) re-encrypted', $output);

        $audit = db_connect()->table('audit_logs')->where('action', 'messages.reencrypted')->get()->getResultArray();
        $this->assertCount(2, $audit);
        $this->assertStringNotContainsString('body', json_encode($audit));
    }

    public function testAnUnreadableValueIsNamedByRowAndNeverShown(): void
    {
        [$first, $second] = $this->rotate();
        db_connect()->table('messages')->where('id', $second)->update(['body_enc' => base64_encode('garbage that nothing opens')]);

        $result = (new MessageReencryptor($this->cipher(self::KEY_B, self::KEY_A)))->run(false);

        $this->assertSame(1, $result['unreadable']);
        $this->assertSame(["message {$second}"], $result['unreadable_ids']);
        $this->assertTrue($this->cipher(self::KEY_B)->usesCurrentKey($this->raw('messages', $first, 'body_enc')), 'the others still moved');
    }

    public function testItHandlesMoreRowsThanOneBatch(): void
    {
        $this->setKeys(self::KEY_A, '');
        $old = $this->cipher(self::KEY_A);

        for ($i = 0; $i < MessageReencryptor::BATCH + 5; $i++) {
            db_connect()->table('messages')->insert([
                'conversation_id' => $this->chat, 'sender_id' => $this->instructor->id, 'body_enc' => $old->encrypt("row {$i}", $this->chat, (int) $this->instructor->id),
                'client_uuid' => bin2hex(random_bytes(12)), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $result = (new MessageReencryptor($this->cipher(self::KEY_B, self::KEY_A)))->run(false);

        $this->assertSame(MessageReencryptor::BATCH + 5, $result['rewritten']);
    }

    public function testItRefusesToRunWithoutAUsableKey(): void
    {
        $this->setKeys('', '');

        $this->command('messages:reencrypt');

        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'messages.reencrypted')->countAllResults());
    }
}

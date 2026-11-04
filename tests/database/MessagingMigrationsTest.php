<?php

declare(strict_types=1);

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The tables behind Messages: what they refuse (a second course chat for an
 * offering, a repeated direct key, a double join, a duplicated retried send),
 * that they go with their institution, that messages keep no plaintext, and
 * that the three migrations can be undone and redone.
 *
 * Migrating commits on its own, so this class cannot use the rolled-back
 * transaction the others do: it removes the institutions it made instead
 * (their conversations and messages go with them).
 *
 * @internal
 */
final class MessagingMigrationsTest extends DatabaseTestCase
{
    /** Migration file => [class, the first table it creates]. */
    private const MIGRATIONS = [
        '2026-11-21-000001_CreateConversationTables'  => ['CreateConversationTables', 'conversations'],
        '2026-11-21-000002_CreateMessageTables'       => ['CreateMessageTables', 'messages'],
        '2026-11-21-000003_CreateMessageSafetyTables' => ['CreateMessageSafetyTables', 'user_blocks'],
    ];

    /** First table of a migration => all its tables, parents first. */
    private const MIGRATION_TABLES = [
        'conversations' => ['conversations', 'conversation_participants'],
        'messages'      => ['messages', 'message_hidden'],
        'user_blocks'   => ['user_blocks', 'message_reports', 'message_preferences'],
    ];

    private const TABLES = ['conversations', 'conversation_participants', 'messages', 'message_hidden', 'user_blocks', 'message_reports', 'message_preferences'];

    /** @var list<int> */
    private array $institutionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        db_connect()->resetDataCache();

        if ($this->institutionIds !== []) {
            db_connect()->table('institutions')->whereIn('id', $this->institutionIds)->delete();
        }

        if ($this->userIds !== []) {
            db_connect()->table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    private function migration(string $file, string $class): object
    {
        require_once APPPATH . "Database/Migrations/{$file}.php";
        $fqcn = "App\\Database\\Migrations\\{$class}";

        return new $fqcn();
    }

    private function institution(string $code): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => 'active']);
        $this->institutionIds[] = (int) db_connect()->insertID();

        return end($this->institutionIds);
    }

    private function user(string $name): int
    {
        db_connect()->table('users')->insert(['username' => $name . uniqid(), 'active' => 1]);
        $this->userIds[] = (int) db_connect()->insertID();

        return end($this->userIds);
    }

    /** @param array<string, mixed> $extra */
    private function conversation(int $institutionId, string $type, array $extra = []): int
    {
        db_connect()->table('conversations')->insert(array_merge(['institution_id' => $institutionId, 'type' => $type, 'created_at' => '2026-11-21 00:00:00', 'updated_at' => '2026-11-21 00:00:00'], $extra));

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed> */
    private function message(int $conversationId, int $senderId, string $uuid): array
    {
        return ['conversation_id' => $conversationId, 'sender_id' => $senderId, 'body_enc' => 'cipher', 'client_uuid' => $uuid, 'created_at' => '2026-11-21 00:00:00'];
    }

    private function offeringId(): int
    {
        $id = CourseFactory::offering();

        // The factory makes an institution of its own; remove it in tearDown (the offering goes with it).
        $row = db_connect()->query(
            'SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id
             JOIN sub_departments s ON s.id = p.sub_department_id JOIN departments d ON d.id = s.department_id WHERE o.id = ?',
            [$id]
        )->getRowArray();
        $this->institutionIds[] = (int) $row['institution_id'];

        return $id;
    }

    public function testTheTablesExist(): void
    {
        db_connect()->resetDataCache();

        foreach (self::TABLES as $table) {
            $this->assertTrue(db_connect()->tableExists($table), "table {$table}");
        }
    }

    public function testTheSafetyColumnsAndIndexExist(): void
    {
        db_connect()->resetDataCache();

        $this->assertTrue(db_connect()->fieldExists('last_notified_message_id', 'conversation_participants'));

        $indexed = false;

        foreach (db_connect()->getIndexData('messages') as $index) {
            if ($index->fields === ['sender_id', 'created_at']) {
                $indexed = true;
            }
        }

        $this->assertTrue($indexed, 'messages has an index on (sender_id, created_at) for the send limit');
    }

    public function testTheChatActionColumnsExist(): void
    {
        db_connect()->resetDataCache();

        foreach (['pinned_at', 'archived_at', 'marked_unread', 'cleared_before_message_id'] as $column) {
            $this->assertTrue(db_connect()->fieldExists($column, 'conversation_participants'), "column {$column}");
        }

        $defaults = [];

        foreach (db_connect()->getFieldData('conversation_participants') as $field) {
            $defaults[$field->name] = $field->default;
        }

        $this->assertSame(0, (int) $defaults['marked_unread']);
        $this->assertSame(0, (int) $defaults['cleared_before_message_id']);
    }

    public function testOneCourseChatPerOffering(): void
    {
        $institution = $this->institution('MSG1');
        $offering    = $this->offeringId();
        $this->conversation($institution, 'course', ['offering_id' => $offering]);

        $this->expectException(DatabaseException::class);

        $this->conversation($institution, 'course', ['offering_id' => $offering]);
    }

    public function testManyDirectConversationsCoexistButADirectKeyIsUnique(): void
    {
        $institution = $this->institution('MSG2');
        $first       = $this->conversation($institution, 'direct', ['direct_key' => str_repeat('a', 64)]);
        $second      = $this->conversation($institution, 'direct', ['direct_key' => str_repeat('b', 64)]);

        $this->assertNotSame($first, $second);

        $this->expectException(DatabaseException::class);

        $this->conversation($institution, 'direct', ['direct_key' => str_repeat('a', 64)]);
    }

    public function testAParticipantJoinsAConversationOnce(): void
    {
        $conversation = $this->conversation($this->institution('MSG3'), 'group');
        $user         = $this->user('p');
        $row          = ['conversation_id' => $conversation, 'user_id' => $user, 'joined_at' => '2026-11-21 00:00:00', 'first_visible_message_id' => 0];

        db_connect()->table('conversation_participants')->insert($row);

        $this->expectException(DatabaseException::class);

        db_connect()->table('conversation_participants')->insert($row);
    }

    public function testARetriedSendDoesNotDuplicate(): void
    {
        $conversation = $this->conversation($this->institution('MSG4'), 'group');
        $user         = $this->user('s');
        $uuid         = '11111111-1111-4111-8111-111111111111';

        db_connect()->table('messages')->insert($this->message($conversation, $user, $uuid));

        $this->expectException(DatabaseException::class);

        db_connect()->table('messages')->insert($this->message($conversation, $user, $uuid));
    }

    public function testDeletingAnInstitutionRemovesItsConversationsAndMessages(): void
    {
        $institution  = $this->institution('MSG5');
        $conversation = $this->conversation($institution, 'group');
        $user         = $this->user('d');

        db_connect()->table('messages')->insert($this->message($conversation, $user, '22222222-2222-4222-8222-222222222222'));

        db_connect()->table('institutions')->where('id', $institution)->delete();

        $this->assertSame(0, db_connect()->table('conversations')->where('id', $conversation)->countAllResults());
        $this->assertSame(0, db_connect()->table('messages')->where('conversation_id', $conversation)->countAllResults());
    }

    public function testMessagesHaveNoPlaintextColumn(): void
    {
        db_connect()->resetDataCache();
        $fields = db_connect()->getFieldNames('messages');

        $this->assertContains('body_enc', $fields);

        foreach (['body', 'text', 'preview'] as $column) {
            $this->assertNotContains($column, $fields);
        }
    }

    public function testCompositeKeyModelsRefuseLookupsByOneHalfOfTheKey(): void
    {
        foreach ([new App\Models\Messaging\MessageHiddenModel(), new App\Models\Messaging\UserBlockModel()] as $model) {
            foreach (['find', 'update', 'delete'] as $method) {
                try {
                    $method === 'update' ? $model->update(1, []) : $model->{$method}(1);
                    $this->fail($model::class . "::{$method}(1) should throw");
                } catch (LogicException $e) {
                    $this->assertStringContainsString('composite', $e->getMessage());
                }
            }
        }
    }

    public function testTheMigrationsCanBeUndoneAndRedone(): void
    {
        $migrations = [];

        foreach (self::MIGRATIONS as $file => [$class, $firstTable]) {
            $migrations[$firstTable] = $this->migration($file, $class);
        }

        // The columns migration sits on top of the three: undone first, redone last.
        $columns = $this->migration('2026-11-22-000001_AddChatActionColumns', 'AddChatActionColumns');

        try {
            $columns->down();

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            db_connect()->resetDataCache();

            foreach (self::TABLES as $table) {
                $this->assertFalse(db_connect()->tableExists($table), "down removes {$table}");
            }
        } finally {
            // Whatever happened, leave the database with all seven tables: other tests need them.
            // A migration with any table missing is cleared (children first) and run up again.
            db_connect()->resetDataCache();

            foreach ($migrations as $firstTable => $migration) {
                $tables = self::MIGRATION_TABLES[$firstTable];

                if (array_filter($tables, static fn (string $t): bool => ! db_connect()->tableExists($t)) === []) {
                    continue;
                }

                foreach (array_reverse($tables) as $table) {
                    db_connect()->query('DROP TABLE IF EXISTS ' . db_connect()->escapeIdentifiers($table));
                }

                db_connect()->resetDataCache();
                $migration->up();
                db_connect()->resetDataCache();
            }

            // Put the four columns back whether or not the participants table was rebuilt.
            db_connect()->resetDataCache();

            if (! db_connect()->fieldExists('pinned_at', 'conversation_participants')) {
                $columns->up();
                db_connect()->resetDataCache();
            }
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue(db_connect()->tableExists($table), "up restores {$table}");
        }

        $this->assertTrue(db_connect()->fieldExists('pinned_at', 'conversation_participants'), 'up restores the chat action columns');
    }
}

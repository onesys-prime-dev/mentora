<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * AC9/AC10: data seeded before the institution-wide Messages migrations (2026-12-03-*) survives them and their
 * rollback: conversations, messages, files, reports, preferences and parent preferences keep their counts, and
 * blocks = kept + history (the BC-4 reset moves only non-binding blocks).
 *
 * Migrating commits on its own, so this class removes what it made instead of rolling back, and puts every
 * migration it undid back up whatever happens (other tests need the current schema).
 *
 * @internal
 */
final class MessagingDataIntegrityTest extends DatabaseTestCase
{
    /** In run order; undone in reverse. */
    private const MIGRATIONS = [
        '2026-12-03-000001_CreateUserBlockHistory'     => 'CreateUserBlockHistory',
        '2026-12-03-000002_ResetNonBindingBlocks'      => 'ResetNonBindingBlocks',
        '2026-12-03-000003_AddFirstContactAndEmailScope' => 'AddFirstContactAndEmailScope',
        '2026-12-03-000004_CreateMessageRestrictions'  => 'CreateMessageRestrictions',
        '2026-12-03-000005_AddMinorFlag'               => 'AddMinorFlag',
        '2026-12-03-000006_MapStudentDmSetting'        => 'MapStudentDmSetting',
        '2026-12-03-000007_AddQuietMode'               => 'AddQuietMode',
        '2026-12-03-000008_AddGroupOwnership'          => 'AddGroupOwnership',
    ];

    private const COUNTED = ['conversations', 'conversation_participants', 'messages', 'files', 'message_reports', 'message_preferences', 'parent_child_preferences'];

    /** @var array<string, object> */
    private array $steps = [];

    /** @var array<string, true> migrations currently undone */
    private array $down = [];

    private ?int $institution = null;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        foreach (self::MIGRATIONS as $file => $class) {
            require_once APPPATH . "Database/Migrations/{$file}.php";
            $fqcn                    = "App\\Database\\Migrations\\{$class}";
            $this->steps[$file] = new $fqcn();
        }
    }

    protected function tearDown(): void
    {
        $this->upAll();

        if ($this->institution !== null) {
            db_connect()->table('institutions')->where('id', $this->institution)->delete();
        }

        if ($this->users !== []) {
            db_connect()->table('parent_child_preferences')->whereIn('parent_user_id', $this->users)->delete();
            db_connect()->table('message_preferences')->whereIn('user_id', $this->users)->delete();
            db_connect()->table('files')->whereIn('owner_user_id', $this->users)->delete();
            db_connect()->table('users')->whereIn('id', $this->users)->delete();
        }

        parent::tearDown();
    }

    private function downAll(): void
    {
        foreach (array_reverse(array_keys(self::MIGRATIONS)) as $file) {
            $this->steps[$file]->down();
            $this->down[$file] = true;
            db_connect()->resetDataCache();
        }
    }

    private function upAll(): void
    {
        foreach (array_keys(self::MIGRATIONS) as $file) {
            if (isset($this->down[$file])) {
                $this->steps[$file]->up();
                unset($this->down[$file]);
                db_connect()->resetDataCache();
            }
        }
    }

    private function person(string $role): int
    {
        $id            = (int) PeopleFactory::person($role, 'Int', 'Egrity', null, $this->institution)->id;
        $this->users[] = $id;

        return $id;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];

        foreach (self::COUNTED as $table) {
            $out[$table] = db_connect()->table($table)->countAllResults();
        }

        return $out;
    }

    private function blockCount(): int
    {
        return db_connect()->table('user_blocks')->countAllResults();
    }

    private function historyCount(): int
    {
        return db_connect()->tableExists('user_block_history') ? db_connect()->table('user_block_history')->countAllResults() : 0;
    }

    public function testDataSurvivesTheMigrationsAndTheirRollback(): void
    {
        $this->downAll();

        // Seed in the schema as it was before the track.
        $db                = db_connect();
        $this->institution = PeopleFactory::institution('INTEG');
        $student           = $this->person('student');
        $classmate         = $this->person('student');
        $instructor        = $this->person('instructor');
        $parent            = $this->person('parent');
        $now               = '2026-11-21 00:00:00';

        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'direct', 'created_by' => $student, 'direct_key' => hash('sha256', uniqid('', true)), 'created_at' => $now, 'updated_at' => $now]);
        $direct = (int) $db->insertID();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'group', 'title' => 'Old group', 'created_by' => $instructor, 'created_at' => $now, 'updated_at' => $now]);
        $group = (int) $db->insertID();

        foreach ([[$direct, $student], [$direct, $classmate], [$group, $instructor], [$group, $student]] as [$conversation, $user]) {
            $db->table('conversation_participants')->insert(['conversation_id' => $conversation, 'user_id' => $user, 'joined_at' => $now]);
        }

        $db->table('messages')->insert(['conversation_id' => $direct, 'sender_id' => $student, 'body_enc' => 'cipher', 'client_uuid' => uniqid('a', true), 'created_at' => $now]);
        $message = (int) $db->insertID();
        $db->table('messages')->insert(['conversation_id' => $group, 'sender_id' => $instructor, 'body_enc' => 'cipher', 'client_uuid' => uniqid('b', true), 'created_at' => $now]);

        $db->table('files')->insert(['owner_user_id' => $student, 'original_filename' => 'a.pdf', 'stored_filename' => uniqid('f', true), 'mime_type' => 'application/pdf', 'file_size' => 1, 'storage_key' => 'k', 'status' => 'active']);
        $db->table('message_reports')->insert(['institution_id' => $this->institution, 'message_id' => $message, 'reporter_id' => $classmate, 'reason' => 'spam', 'snapshot_enc' => 'cipher', 'created_at' => $now]);
        $db->table('message_preferences')->insert(['user_id' => $instructor, 'quiet_start' => '22:00:00', 'quiet_end' => '06:00:00', 'email_alerts' => 0]);
        $db->table('message_preferences')->insert(['user_id' => $student, 'email_alerts' => 1]);
        $db->table('parent_child_preferences')->insert(['parent_user_id' => $parent, 'student_user_id' => $student, 'email_enabled' => 0, 'created_at' => $now]);

        // A binding block (a student's) and a non-binding one (an instructor-only blocker's).
        $db->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $student, 'blocked_id' => $classmate, 'created_at' => $now]);
        $db->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $instructor, 'blocked_id' => $student, 'created_at' => $now]);

        $before       = $this->counts();
        $blocksBefore = $this->blockCount();

        $this->upAll();

        $this->assertSame($before, $this->counts(), 'counts unchanged after up');
        $this->assertSame($blocksBefore, $this->blockCount() + $this->historyCount(), 'blocks = kept + history');
        $this->assertSame(1, $db->table('user_blocks')->where('blocker_id', $student)->countAllResults(), 'binding block kept');
        $this->assertSame(1, $db->table('user_block_history')->where(['blocker_id' => $instructor, 'reason' => 'reset_non_binding'])->countAllResults(), 'non-binding block moved');
        $this->assertSame($instructor, (int) $db->table('conversations')->where('id', $group)->get()->getRow('owner_id'));
        $this->assertSame('own', $db->table('message_preferences')->where('user_id', $instructor)->get()->getRow('quiet_mode'));
        $this->assertNull($db->table('message_preferences')->where('user_id', $student)->get()->getRow('quiet_mode'));

        // Rolled back, everything is where it was; then forward again for the tests that follow.
        $this->downAll();
        $this->assertSame($before, $this->counts(), 'counts unchanged after down');
        $this->assertSame($blocksBefore, $this->blockCount(), 'down restores every block');

        $this->upAll();
        $this->assertSame($before, $this->counts(), 'counts unchanged after redo');
        $this->assertSame($blocksBefore, $this->blockCount() + $this->historyCount(), 'blocks = kept + history after redo');
    }
}

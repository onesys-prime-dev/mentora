<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * BC-3: people who set quiet times keep them as "My own"; everyone else (email-only rows included) is "never set"
 * and follows the school default. The test drops the column and runs the migration again over planted rows.
 *
 * @internal
 */
final class AddQuietModeTest extends DatabaseTestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        db_connect()->resetDataCache();

        // A test that failed between down() and up() must not leave the shared schema without the column.
        if (! db_connect()->fieldExists('quiet_mode', 'message_preferences')) {
            $this->migration()->up();
            db_connect()->resetDataCache();
        }

        if ($this->userIds !== []) {
            db_connect()->table('message_preferences')->whereIn('user_id', $this->userIds)->delete();
            db_connect()->table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    private function migration(): object
    {
        require_once APPPATH . 'Database/Migrations/2026-12-03-000007_AddQuietMode.php';

        return new App\Database\Migrations\AddQuietMode();
    }

    private function plant(?string $start, ?string $end, int $emailAlerts): int
    {
        db_connect()->table('users')->insert(['username' => 'qm' . uniqid(), 'active' => 1]);
        $id              = (int) db_connect()->insertID();
        $this->userIds[] = $id;
        db_connect()->table('message_preferences')->insert(['user_id' => $id, 'quiet_start' => $start, 'quiet_end' => $end, 'email_alerts' => $emailAlerts]);

        return $id;
    }

    private function mode(int $userId): ?string
    {
        return db_connect()->table('message_preferences')->where('user_id', $userId)->get()->getRowArray()['quiet_mode'];
    }

    public function testExistingTimesBecomeOwn(): void
    {
        $this->migration()->down();
        db_connect()->resetDataCache();
        $withTimes = [$this->plant('22:00:00', '07:00:00', 1), $this->plant('21:30:00', '06:15:00', 0)];
        $expected  = db_connect()->table('message_preferences')->where('quiet_start IS NOT NULL', null, false)->where('quiet_end IS NOT NULL', null, false)->countAllResults();

        $this->migration()->up();
        db_connect()->resetDataCache();

        foreach ($withTimes as $id) {
            $this->assertSame('own', $this->mode($id));
        }

        $this->assertSame($expected, db_connect()->table('message_preferences')->where('quiet_mode', 'own')->countAllResults());
    }

    public function testEmailOnlyRowsBecomeNeverSet(): void
    {
        $this->migration()->down();
        db_connect()->resetDataCache();
        $emailOnly = [$this->plant(null, null, 0), $this->plant(null, null, 1)];
        $before    = db_connect()->table('message_preferences')->countAllResults();

        $this->migration()->up();
        db_connect()->resetDataCache();

        foreach ($emailOnly as $id) {
            $this->assertNull($this->mode($id));
        }

        $this->assertSame($before, db_connect()->table('message_preferences')->countAllResults(), 'no row added or lost');
    }

    public function testDownThenUpLeavesTheColumnInPlace(): void
    {
        $this->migration()->down();
        db_connect()->resetDataCache();
        $this->assertFalse(db_connect()->fieldExists('quiet_mode', 'message_preferences'));

        $this->migration()->up();
        db_connect()->resetDataCache();
        $this->assertTrue(db_connect()->fieldExists('quiet_mode', 'message_preferences'));
    }
}

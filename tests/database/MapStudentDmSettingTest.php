<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use Config\Platform;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * D3: an institution that switched student messaging off keeps it off under the three-way setting. The
 * migration COPIES the old override into studentMessagingScope = 'off'; the old key stays; down() removes
 * only the new key.
 *
 * @internal
 */
final class MapStudentDmSettingTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $off;

    private int $unset;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->off   = PeopleFactory::institution('DMO');
        $this->unset = PeopleFactory::institution('DMU');
        (new PlatformSettings())->set('studentDmEnabled', false, $this->off);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function migration(): object
    {
        require_once APPPATH . 'Database/Migrations/2026-12-03-000006_MapStudentDmSetting.php';

        return new App\Database\Migrations\MapStudentDmSetting();
    }

    private function rows(string $key): int
    {
        return db_connect()->table('settings')->where(['class' => Platform::class, 'key' => $key])->like('context', 'institution:', 'after')->countAllResults();
    }

    private function scopeOf(int $institution): ?string
    {
        $row = db_connect()->table('settings')->where(['class' => Platform::class, 'key' => 'studentMessagingScope', 'context' => 'institution:' . $institution])->get()->getRowArray();

        return $row['value'] ?? null;
    }

    public function testOffMapsToOff(): void
    {
        $before = $this->rows('studentMessagingScope');

        $this->migration()->up();

        $this->assertSame($before + 1, $this->rows('studentMessagingScope'));
        $this->assertSame('off', $this->scopeOf($this->off));
        Services::resetSingle('settings'); // the migration writes behind the settings cache
        $this->assertSame('off', (new PlatformSettings())->get('studentMessagingScope', $this->off));
    }

    public function testUnsetStaysDefaultInstitution(): void
    {
        $this->migration()->up();

        $this->assertNull($this->scopeOf($this->unset));
        $this->assertSame('institution', (new PlatformSettings())->get('studentMessagingScope', $this->unset));
    }

    public function testUpKeepsTheOldKeyAndIsRepeatable(): void
    {
        $old = $this->rows('studentDmEnabled');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($old, $this->rows('studentDmEnabled'));
        $this->assertSame(1, db_connect()->table('settings')->where(['key' => 'studentMessagingScope', 'context' => 'institution:' . $this->off])->countAllResults());
    }

    public function testDownRemovesOnlyNewKey(): void
    {
        $this->migration()->up();
        $old = $this->rows('studentDmEnabled');
        $this->assertGreaterThan(0, $this->rows('studentMessagingScope'));

        $this->migration()->down();

        $this->assertSame(0, $this->rows('studentMessagingScope'));
        $this->assertSame($old, $this->rows('studentDmEnabled'));
    }
}

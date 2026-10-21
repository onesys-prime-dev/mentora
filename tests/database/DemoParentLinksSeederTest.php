<?php

declare(strict_types=1);

use App\Database\Seeds\DemoParentLinksSeeder;
use App\Database\Seeds\DemoSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The demo parent links: the first demo parent follows two students and the
 * second follows nobody, so every parent state can be tried on the demo data.
 *
 * @internal
 */
final class DemoParentLinksSeederTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $parentId;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeders remember the ids they looked up; each test makes new people.
        $cache = new ReflectionProperty(DemoSeeder::class, 'cache');
        $cache->setValue(null, []);

        $institution = SisFixture::institution('DPL');

        $this->parentId = (int) PeopleFactory::person('parent', 'Roberto', 'Cruz', 'parent1@mentora.test', $institution)->id;
        PeopleFactory::person('institutional_admin', 'Ines', 'Administrador', 'institution.admin@mentora.test', $institution);
        PeopleFactory::person('parent', 'Linda', 'Reyes', 'parent2@mentora.test', $institution);

        foreach (['student1', 'student5', 'student2'] as $name) {
            PeopleFactory::person('student', $name, 'Demo', "{$name}@mentora.test", $institution);
        }
    }

    private function runSeeder(): void
    {
        ob_start();
        (new DemoParentLinksSeeder(config('Database')))->run();
        ob_end_clean();
    }

    private function linksOf(string $email): array
    {
        $db = db_connect();

        return $db->table('parent_student_links l')
            ->select('ai.secret AS email')
            ->join('auth_identities ai', "ai.user_id = l.student_user_id AND ai.type = 'email_password'", 'inner', false)
            ->join('auth_identities pi', "pi.user_id = l.parent_user_id AND pi.type = 'email_password'", 'inner', false)
            ->where('pi.secret', $email)
            ->orderBy('ai.secret')
            ->get()->getResultArray();
    }

    public function testTheFirstDemoParentFollowsStudentsOneAndFiveAndTheSecondNobody(): void
    {
        $this->runSeeder();

        $this->assertSame(['student1@mentora.test', 'student5@mentora.test'], array_column($this->linksOf('parent1@mentora.test'), 'email'));
        $this->assertSame([], $this->linksOf('parent2@mentora.test'));
    }

    public function testRunningItTwiceLinksNobodyAgain(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertCount(2, $this->linksOf('parent1@mentora.test'));
    }
}

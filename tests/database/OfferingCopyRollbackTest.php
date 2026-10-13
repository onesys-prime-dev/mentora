<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use App\Services\Institutions\OfferingCopyService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * "All or nothing" for copying offerings: a copy that fails after writing
 * some of its rows leaves nothing behind.
 *
 * Like OnboardingRollbackTest this class does not run inside a rolled-back
 * test transaction: the copy's own transaction must be the outermost one for
 * its rollback to be real.
 *
 * @internal
 */
final class OfferingCopyRollbackTest extends DatabaseTestCase
{
    protected bool $isolateTests = false;

    /** Touched by these tests; emptied with foreign keys off. */
    private const TABLES = [
        'course_schedules', 'instructor_assignments', 'course_modules', 'course_offerings', 'courses',
        'academic_terms', 'programs', 'sub_departments', 'departments', 'institution_users', 'user_profiles',
        'auth_groups_users', 'auth_identities', 'users', 'institutions', 'audit_logs',
    ];

    private int $institution;

    private int $source;

    private int $target;

    private int $offering;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CPRB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $course = (int) $db->insertID();

        $this->source = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $db->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Next', 'academic_year' => '2027-2028', 'start_date' => '2027-01-10', 'end_date' => '2027-05-30', 'status' => 'upcoming', 'created_at' => date('Y-m-d H:i:s')]);
        $this->target = (int) $db->insertID();

        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $course, 'term_id' => $this->source, 'section_code' => 'A', 'capacity' => '30'], $this->actor);
        $db->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => 'Week 1', 'order_index' => 1, 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    protected function tearDown(): void
    {
        $this->wipe();

        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = db_connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $db->query('TRUNCATE TABLE `' . $table . '`');
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testACopyThatFailsAfterWritingLeavesNothingBehind(): void
    {
        $before = [
            'offerings' => db_connect()->table('course_offerings')->countAllResults(),
            'modules'   => db_connect()->table('course_modules')->countAllResults(),
        ];

        // The audit entry is the last thing a copy writes; making it fail
        // makes the run fail after the offering and its modules were written.
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                if ($action === 'structure.offerings_copied') {
                    throw new RuntimeException('audit down');
                }

                return parent::log($action, $actor, $details);
            }
        });

        try {
            (new OfferingCopyService())->copy($this->institution, $this->source, $this->target, [$this->offering], false, $this->actor);
            $this->fail('The copy must fail.');
        } catch (StructureException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame($before['offerings'], db_connect()->table('course_offerings')->countAllResults(), 'no partial offering');
        $this->assertSame($before['modules'], db_connect()->table('course_modules')->countAllResults(), 'no partial content');

        Services::resetSingle('auditLogger');

        $done = (new OfferingCopyService())->copy($this->institution, $this->source, $this->target, [$this->offering], false, $this->actor);
        $this->assertSame(1, $done['offerings'], 'the next try starts clean');
    }
}

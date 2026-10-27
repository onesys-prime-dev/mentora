<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionExport;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * The export is prepared in the background, one at a time per institution, and only the person who asked is
 * told (audit decisions 12.2–12.4).
 *
 * @internal
 */
final class InstitutionExportQueueTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private string $folder;

    private DateTimeImmutable $now;

    private int $institution;

    private User $ada;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();
        $this->folder        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-export-' . bin2hex(random_bytes(4));
        $_ENV['export.path'] = $this->folder;
        $this->now           = new DateTimeImmutable('2026-10-10 12:00:00');

        $offering          = CourseFactory::offering();
        $this->institution = (int) db_connect()->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$offering])->getRow()->institution_id;
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        $this->ada = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@queue.test', $this->institution);
        $this->ben = PeopleFactory::person('institutional_admin', 'Ben', 'Admin', 'ben@queue.test', $this->institution);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
        unset($_ENV['export.path']);
        Services::resetSingle('email');

        parent::tearDown();
    }

    private function row(int $id): array
    {
        return db_connect()->table('institution_exports')->where('id', $id)->get()->getRowArray();
    }

    private function told(User $admin, string $title): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $admin->id, 'title' => $title])->countAllResults();
    }

    public function testARequestIsQueuedAndBuiltByTheJob(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, $this->now);

        $this->assertSame('queued', $this->row($id)['status'], 'nothing is built inside the request');

        $this->assertSame([$id], (new InstitutionExport())->runQueued($this->now));
        $this->assertSame('ready', $this->row($id)['status']);
        $this->assertNull($this->row($id)['open_marker']);
        $this->assertFileExists($this->folder . DIRECTORY_SEPARATOR . $this->row($id)['file_name']);
    }

    public function testASecondRequestWhileOneIsOpenIsRefused(): void
    {
        (new InstitutionExport())->request($this->institution, $this->ada, $this->now);

        try {
            (new InstitutionExport())->request($this->institution, $this->ben, $this->now);
            $this->fail('one export at a time');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("An export is already being prepared. You'll be told when it's ready.", $e->getMessage());
        }

        $this->assertSame(1, db_connect()->table('institution_exports')->countAllResults());
    }

    public function testTheDatabaseKeepsOneOpenExport(): void
    {
        (new InstitutionExport())->request($this->institution, $this->ada, $this->now);

        try {
            $added = db_connect()->table('institution_exports')->insert(['institution_id' => $this->institution, 'status' => 'queued', 'open_marker' => 1, 'created_at' => '2026-10-10 12:00:00']);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
    }

    public function testOnlyTheRequesterIsToldWhenItIsReady(): void
    {
        (new InstitutionExport())->request($this->institution, $this->ada, $this->now);
        (new InstitutionExport())->runQueued($this->now);

        $this->assertSame(1, $this->told($this->ada, 'Your data export is ready'));
        $this->assertSame(0, $this->told($this->ben, 'Your data export is ready'));
        $this->assertNotNull(db_connect()->table('notifications')->where('user_id', $this->ada->id)->get()->getRow('emailed_at'), 'and by email');
    }

    public function testAFailedBuildTellsTheRequesterAndFreesTheSlot(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, $this->now);
        $_ENV['export.path'] = __FILE__ . DIRECTORY_SEPARATOR . 'cannot-be-a-folder';

        (new InstitutionExport())->runQueued($this->now);

        $_ENV['export.path'] = $this->folder;
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertNull($this->row($id)['open_marker']);
        $this->assertSame(1, $this->told($this->ada, 'Your data export failed'));
        $this->assertIsInt((new InstitutionExport())->request($this->institution, $this->ben, $this->now), 'a new one can be asked for');
    }

    public function testAnExportAnotherRunAlreadyClaimedIsNotTakenAgain(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, $this->now);

        // This run read the queue just before another run (the next minute's cron) claimed the export.
        $racing = new class () extends InstitutionExport {
            protected function nextQueued(): ?array
            {
                $row = parent::nextQueued();

                if ($row !== null && empty($this->raced)) {
                    $this->raced = true;
                    db_connect()->table('institution_exports')->where('id', $row['id'])->update(['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

                    return $row;
                }

                return null;
            }

            public bool $raced = false;
        };

        $this->assertSame([], $racing->runQueued($this->now), 'nothing prepared twice');
        $this->assertSame('running', $this->row($id)['status'], "left to the run that claimed it");
        $this->assertSame([], glob($this->folder . DIRECTORY_SEPARATOR . '*.zip') ?: []);
    }

    public function testAnExportTheStallCheckEndedIsNotMadeReadyAfterwards(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, $this->now);

        // While this run works, another run's stall check ends the export.
        $slow = new class () extends InstitutionExport {
            protected function touch(int $id): void
            {
                db_connect()->table('institution_exports')->where('id', $id)->update(['status' => 'failed', 'open_marker' => null, 'message' => 'The export stopped before it finished. Ask for a new one.']);
            }
        };

        $slow->runQueued($this->now);

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame(0, $this->told($this->ada, 'Your data export is ready'), 'never told both');
        $this->assertSame([], glob($this->folder . DIRECTORY_SEPARATOR . '*.zip') ?: [], 'its file is removed');
    }

    public function testAWorkingExportKeepsItsStartFresh(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, new DateTimeImmutable('2026-01-01 08:00:00'));

        (new InstitutionExport())->runQueued(new DateTimeImmutable('2026-01-01 08:00:00'));

        // Started by the clock, not by when the run began, so a long queue never looks stalled.
        $this->assertGreaterThan('2026-01-02', (string) $this->row($id)['started_at']);
    }

    public function testAStalledRunIsFailedAfterThirtyMinutes(): void
    {
        $id = (new InstitutionExport())->request($this->institution, $this->ada, $this->now);
        db_connect()->table('institution_exports')->where('id', $id)->update(['status' => 'running', 'started_at' => '2026-10-10 11:20:00']);

        (new InstitutionExport())->runQueued($this->now);

        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame('The export stopped before it finished. Ask for a new one.', $this->row($id)['message']);
    }
}

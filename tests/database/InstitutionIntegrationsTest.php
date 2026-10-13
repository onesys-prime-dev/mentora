<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionIntegrations;
use App\Services\Sis\SisConnectors;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * What the institution's administrator is given of the SIS connection: its
 * state and the runs, with connection tests and folder names left out; and
 * the runs themselves, which obey the system administrator's own rules.
 *
 * @internal
 */
final class InstitutionIntegrationsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private InstitutionIntegrations $integrations;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution  = SisFixture::institution('INTA');
        $this->integrations = new InstitutionIntegrations();
    }

    private function record(string $trigger, string $message, string $direction = 'inbound', ?int $institution = null, ?string $details = null): void
    {
        db_connect()->table('sis_runs')->insert([
            'institution_id' => $institution ?? $this->institution, 'direction' => $direction, 'trigger' => $trigger,
            'status' => 'succeeded', 'dry_run' => 0, 'message' => $message, 'details' => $details, 'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testWithNoFeatureOrConnectionItSaysSoAndHasNoRuns(): void
    {
        $view = $this->integrations->view($this->institution);

        $this->assertFalse($view['available']);
        $this->assertFalse($view['configured']);
        $this->assertFalse($view['enabled']);
        $this->assertSame([], $view['runs']);
        $this->assertNull($view['latest']);
    }

    public function testAConnectionTestIsNotAmongTheRuns(): void
    {
        $this->record('test', 'The inbound folder does not exist yet');
        $this->record('manual', 'created 3, updated 0, skipped 0, failed 0.');

        $runs = $this->integrations->view($this->institution)['runs'];

        $this->assertCount(1, $runs);
        $this->assertSame('manual', $runs[0]['trigger']);
    }

    public function testFolderNamesAreTakenOutOfMessagesAndIssues(): void
    {
        $inbound = SisConnectors::folders($this->institution)['inbound'];

        $this->record('scheduled', "Could not read {$inbound}/students.csv", 'inbound', null, json_encode(['issues' => [['dataset' => 'students', 'item' => 'row 4', 'reason' => "Unreadable: {$inbound}"]], 'truncated' => false]));

        $view = $this->integrations->view($this->institution);

        $this->assertSame('Could not read the shared folder/students.csv', $view['runs'][0]['message']);
        $this->assertSame('Unreadable: the shared folder', $view['latest']['issues'][0]['reason']);
        $this->assertStringNotContainsString($inbound, json_encode($view));
    }

    public function testOnlyThisInstitutionsRunsAreListedAndOnlyTheNewestFew(): void
    {
        $other = SisFixture::institution('INTB');
        $this->record('manual', 'Theirs', 'inbound', $other);

        for ($i = 0; $i < InstitutionIntegrations::HISTORY + 5; $i++) {
            $this->record('scheduled', "Run {$i}");
        }

        $runs = $this->integrations->view($this->institution)['runs'];

        $this->assertCount(InstitutionIntegrations::HISTORY, $runs);
        $this->assertNotContains('Theirs', array_column($runs, 'message'));
        $this->assertSame('Run ' . (InstitutionIntegrations::HISTORY + 4), $runs[0]['message'], 'newest first');
    }

    public function testTheLatestImportIsTheNewestInboundRunNotAGradeDelivery(): void
    {
        $this->record('manual', 'import result', 'inbound');
        $this->record('manual', 'grades sent', 'outbound');

        $this->assertSame('import result', $this->integrations->view($this->institution)['latest']['message']);
    }

    public function testRunsObeyTheSystemAdministratorsRules(): void
    {
        $actor = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ([fn () => $this->integrations->import($this->institution, true, $actor), fn () => $this->integrations->grades($this->institution, true, $actor)] as $run) {
            try {
                $run();
                $this->fail('The feature is off, so a run must be refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('sis.sync', $e->getMessage());
            }
        }

        $this->assertSame(0, db_connect()->table('sis_runs')->where('institution_id', $this->institution)->countAllResults());
    }

    public function testAPreviewWithAConnectionReportsItsResult(): void
    {
        $folder = SisFixture::useFolder();
        $root   = PeopleFactory::person('admin', 'Root', 'Admin', 'root@int.example');

        try {
            SisFixture::connect($this->institution, $root);
            SisFixture::dropAll($this->institution);

            $result = $this->integrations->import($this->institution, true, $root);

            $this->assertTrue($result['ok']);
            $this->assertStringStartsWith('Preview, nothing was written', $result['message']);
        } finally {
            SisFixture::cleanup($folder);
        }
    }
}

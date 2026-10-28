<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The uptime workflow posts the scheduler secret to each site. Production must only ever get its own: with
 * `a && b || c`, an empty production secret fell through to the staging one, which was then sent to production.
 *
 * @internal
 */
final class UptimeWorkflowTest extends CIUnitTestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(ROOTPATH . '.github/workflows/uptime.yml');
    }

    public function testProductionNeverFallsBackToTheStagingSecret(): void
    {
        $yaml = $this->workflow();

        $this->assertDoesNotMatchRegularExpression('/SCHEDULER_SECRET_PRODUCTION\s*\|\|/', $yaml);
        $this->assertStringContainsString('SCHEDULER_SECRET_PRODUCTION: ${{ secrets.SCHEDULER_SECRET_PRODUCTION }}', $yaml);
        $this->assertStringContainsString('SCHEDULER_SECRET_STAGING: ${{ secrets.SCHEDULER_SECRET_STAGING }}', $yaml);
        $this->assertMatchesRegularExpression('/if \[ "\$ENV_SLUG" = production \]; then SECRET="\$SCHEDULER_SECRET_PRODUCTION"; else SECRET="\$SCHEDULER_SECRET_STAGING"; fi/', $yaml);
    }
}

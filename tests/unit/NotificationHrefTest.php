<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Stored notification links are sometimes relative ("dean/signoff") and sometimes a full site_url();
 * both must open the same page, never ".../index.php/https://...".
 *
 * @internal
 */
final class NotificationHrefTest extends CIUnitTestCase
{
    public function testRelativeLinkBecomesSiteUrl(): void
    {
        $this->assertSame(site_url('dean/signoff'), NotificationService::href('dean/signoff'));
    }

    public function testAbsoluteLinkIsLeftAlone(): void
    {
        $full = site_url('dean/signoff');

        $this->assertSame($full, NotificationService::href($full));
        $this->assertStringNotContainsString('index.php/http', NotificationService::href($full));
    }
}

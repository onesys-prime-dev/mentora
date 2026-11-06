<?php

declare(strict_types=1);

namespace Tests\Unit;

use Config\Auth;
use PHPUnit\Framework\TestCase;

/**
 * After a login a person goes back to the page they were headed for, never to
 * one of the background data endpoints a page left open keeps asking for.
 *
 * @internal
 */
final class AuthLoginRedirectTest extends TestCase
{
    public function testDataEndpointsAreNotWhereALoginReturns(): void
    {
        foreach ([
            'https://localhost/mentora/public/index.php/notifications/latest',
            'https://localhost/mentora/public/index.php/notifications/feed',
            'https://localhost/mentora/public/index.php/messages/api/unread-count',
            'https://localhost/mentora/public/index.php/student/api/courses/4/assessments',
        ] as $url) {
            $this->assertTrue(Auth::isBackgroundUrl($url), $url);
        }
    }

    public function testPagesAPersonReadsAreReturnedTo(): void
    {
        foreach ([
            'https://localhost/mentora/public/index.php/notifications',
            'https://localhost/mentora/public/index.php/messages',
            'https://localhost/mentora/public/index.php/student/courses/4/introduction',
        ] as $url) {
            $this->assertFalse(Auth::isBackgroundUrl($url), $url);
        }
    }
}

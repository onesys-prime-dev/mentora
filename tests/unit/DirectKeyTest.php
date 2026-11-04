<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Messaging\DirectKey;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DirectKeyTest extends CIUnitTestCase
{
    public function testItIsOrderIndependent(): void
    {
        $this->assertSame(DirectKey::for(1, 5, 9, null), DirectKey::for(1, 9, 5, null));
    }

    public function testItDiffersByInstitution(): void
    {
        $this->assertNotSame(DirectKey::for(1, 5, 9, null), DirectKey::for(2, 5, 9, null));
    }

    public function testItDiffersBySubject(): void
    {
        $this->assertNotSame(DirectKey::for(1, 5, 9, null), DirectKey::for(1, 5, 9, 7));
        $this->assertNotSame(DirectKey::for(1, 5, 9, 7), DirectKey::for(1, 5, 9, 8));
    }

    public function testItIsTheDocumentedHash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', DirectKey::for(1, 9, 5, null));
        $this->assertSame(hash('sha256', '5|9|0|1'), DirectKey::for(1, 9, 5, null));
    }
}

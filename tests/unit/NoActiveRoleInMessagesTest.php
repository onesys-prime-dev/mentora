<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Messages decides on parties (every role held), never on an "active role". Scans the Messages code for any read of it.
 *
 * @internal
 */
final class NoActiveRoleInMessagesTest extends CIUnitTestCase
{
    private const PATHS = [
        'app/Services/Messaging', 'app/Controllers/Messaging', 'app/Filters/MessagesFilter.php',
        'app/Views/pages/messages', 'resources/js/pages/messages',
    ];

    public function testNoActiveRoleReads(): void
    {
        $offenders = [];

        foreach (self::PATHS as $path) {
            $full  = ROOTPATH . $path;
            $this->assertFileExists($full, "Scanned path missing: {$path}");
            $files = is_dir($full)
                ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)))
                : [new \SplFileInfo($full)];

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                foreach (file($file->getPathname()) ?: [] as $n => $line) {
                    if (preg_match('/active_role|ActiveRoleService|usableGroups\(|preferredRole\(|activeRole\(/', $line) === 1) {
                        $offenders[] = $path . ':' . ($n + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Active-role reads in Messages:\n" . implode("\n", $offenders));
    }
}

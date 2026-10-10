<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The inventory of services: every class under app/Services must be named in a
 * test, so a new service cannot be added without tests (or without someone
 * deciding, here and in writing, why it has none). Like the route table check
 * in RouteAccessTest, the list of services is read from the code, so nothing
 * has to be remembered.
 *
 * "Named in a test" is a floor, not proof of good tests: it forces the
 * conversation, and the coverage floor and the changed-lines check judge the
 * depth.
 *
 * @internal
 */
final class ServiceTestInventoryTest extends CIUnitTestCase
{
    /**
     * Services no test names, each with the reason that is true today. An entry
     * that gets a test, or whose class is removed, must be deleted from here
     * (the tests below fail until it is).
     *
     * @var array<string, string>
     */
    private const NOT_NAMED_IN_A_TEST = [
        'AssessmentScopeService' => 'Exercised through the assessmentscope route filter in tests/feature/AssessmentRoutesScopeTest.php.',
        'SimilarityChecker'      => 'Exercised through QuestionGenerationService tests, which use a fake generator.',
        'LocalQuestionBuilderClient' => 'The real HTTP client for the Python question service. Tests never call it by design (see docs/testing.md, "The AI question-generation boundary").',
    ];

    /**
     * Short class names of every concrete class in app/Services.
     *
     * @return list<string>
     */
    private static function services(): array
    {
        $found = [];

        foreach (self::phpFiles(APPPATH . 'Services') as $file) {
            $source = (string) file_get_contents($file);
            $name   = basename($file, '.php');

            // Empty placeholder files, interfaces and exceptions are not services.
            if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+' . preg_quote($name, '/') . '\b/m', $source)) {
                continue;
            }

            if (str_ends_with($name, 'Exception')) {
                continue;
            }

            $found[] = $name;
        }

        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** All test code except this guard, so it cannot satisfy itself. */
    private static function testSource(): string
    {
        $source = '';

        foreach (self::phpFiles(ROOTPATH . 'tests') as $file) {
            if (basename($file) !== basename(__FILE__) && basename($file) !== 'TableTestInventoryTest.php') {
                $source .= file_get_contents($file) . "\n";
            }
        }

        return $source;
    }

    private static function isNamed(string $class, string $source): bool
    {
        return preg_match('/\b' . preg_quote($class, '/') . '\b/', $source) === 1;
    }

    public function testTheServicesWereDiscovered(): void
    {
        $this->assertGreaterThanOrEqual(35, count(self::services()), 'The services were not discovered; check the path.');
    }

    public function testEveryServiceIsNamedInATestOrListedWithAReason(): void
    {
        $source  = self::testSource();
        $missing = [];

        foreach (self::services() as $service) {
            if (! self::isNamed($service, $source) && ! isset(self::NOT_NAMED_IN_A_TEST[$service])) {
                $missing[] = $service;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'A service has no test. Write tests for it in tests/database/ or tests/unit/ (see docs/testing.md); only if that is truly not possible, add it to NOT_NAMED_IN_A_TEST with the reason.'
        );
    }

    public function testTheExceptionListHasNoStaleEntries(): void
    {
        $source   = self::testSource();
        $services = self::services();
        $stale    = [];

        foreach (self::NOT_NAMED_IN_A_TEST as $class => $reason) {
            if (! in_array($class, $services, true)) {
                $stale[] = "{$class}: no such service any more";
            } elseif (self::isNamed($class, $source)) {
                $stale[] = "{$class}: now named in a test";
            } elseif (strlen($reason) < 20) {
                $stale[] = "{$class}: the reason is too short to be useful";
            }
        }

        $this->assertSame([], $stale, 'Remove these from NOT_NAMED_IN_A_TEST (or give a real reason).');
    }
}

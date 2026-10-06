<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * .github/scripts/check-diff-coverage.php: the CI gate that fails a pull
 * request whose added or changed lines are not covered. The script is run as a
 * separate process on small hand-written Clover and diff files, exactly as CI
 * runs it.
 *
 * @internal
 */
final class CheckDiffCoverageScriptTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/diffcov_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param array<string, array<int, int>> $files path from the repo root => [line => hits]
     */
    private function clover(array $files): string
    {
        $xml = '<?xml version="1.0"?><coverage><project>';

        foreach ($files as $path => $lines) {
            $xml .= '<file name="/home/runner/work/mentora/mentora/' . $path . '">';

            foreach ($lines as $num => $count) {
                $xml .= '<line num="' . $num . '" type="stmt" count="' . $count . '"/>';
            }

            // Declarations are type="method" and must never be counted.
            $xml .= '<line num="1" type="method" name="x" count="0"/>';
            $xml .= '</file>';
        }

        return $xml . '</project></coverage>';
    }

    /**
     * @param array<string, string> $hunks path => the "@@ ... @@" header(s) for that file
     */
    private function diff(array $hunks): string
    {
        $diff = '';

        foreach ($hunks as $path => $headers) {
            $diff .= "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n{$headers}\n+x\n";
        }

        return $diff;
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function check(string $clover, string $diff, string $threshold = '80'): array
    {
        file_put_contents($this->dir . '/clover.xml', $clover);
        file_put_contents($this->dir . '/changes.diff', $diff);
        file_put_contents($this->dir . '/threshold', $threshold . "\n");

        $script = ROOTPATH . '.github/scripts/check-diff-coverage.php';
        $spec   = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc   = proc_open([PHP_BINARY, $script, $this->dir . '/clover.xml', $this->dir . '/changes.diff', $this->dir . '/threshold'], $spec, $pipes, null, ['PATH' => (string) getenv('PATH')]);
        $out    = (string) stream_get_contents($pipes[1]);
        $err    = (string) stream_get_contents($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testPassesWhenEveryChangedLineIsCovered(): void
    {
        [$code, $out] = $this->check(
            $this->clover(['app/Services/Foo.php' => [10 => 3, 11 => 1, 12 => 1]]),
            $this->diff(['app/Services/Foo.php' => '@@ -9,0 +10,3 @@']),
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('100.00% (3 of 3', $out);
    }

    public function testFailsAndNamesTheUncoveredLines(): void
    {
        [$code, $out, $err] = $this->check(
            $this->clover(['app/Services/Foo.php' => [10 => 1, 11 => 0, 12 => 0, 13 => 1]]),
            $this->diff(['app/Services/Foo.php' => '@@ -9,0 +10,4 @@']),
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('50.00% (2 of 4', $out);
        $this->assertStringContainsString('not covered: lines 11-12', $out);
        $this->assertStringContainsString('do not lower the threshold', $err);
    }

    public function testExactlyAtTheThresholdPassesAndJustBelowFails(): void
    {
        // 8 of 10 covered.
        $hits = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 0, 10 => 0];
        $diff = $this->diff(['app/A.php' => '@@ -0,0 +1,10 @@']);

        [$atThreshold] = $this->check($this->clover(['app/A.php' => $hits]), $diff, '80');
        [$above]       = $this->check($this->clover(['app/A.php' => $hits]), $diff, '80.01');

        $this->assertSame(0, $atThreshold);
        $this->assertSame(1, $above);
    }

    public function testCommentsBlankLinesAndDeclarationsAreNotCounted(): void
    {
        // Lines 20-22 changed but only line 21 is executable and it is covered.
        [$code, $out] = $this->check(
            $this->clover(['app/Foo.php' => [21 => 5]]),
            $this->diff(['app/Foo.php' => '@@ -19,0 +20,3 @@']),
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('(1 of 1', $out);
    }

    public function testAChangeWithNoExecutableLinesPasses(): void
    {
        [$code, $out] = $this->check(
            $this->clover(['app/Foo.php' => [50 => 0]]),
            $this->diff(['app/Foo.php' => '@@ -1,0 +2,1 @@']),
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to cover', $out);
    }

    public function testAPureDeletionAddsNoLines(): void
    {
        [$code, $out] = $this->check(
            $this->clover(['app/Foo.php' => [10 => 0]]),
            $this->diff(['app/Foo.php' => '@@ -10,3 +9,0 @@']),
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to cover', $out);
    }

    public function testFilesPhpunitDoesNotMeasureAreIgnored(): void
    {
        // A view, a migration and the route table are not in the Clover report.
        [$code, $out] = $this->check(
            $this->clover(['app/Services/Foo.php' => [1 => 1]]),
            $this->diff([
                'app/Views/page.php'                   => '@@ -0,0 +1,5 @@',
                'app/Database/Migrations/2026_x.php'   => '@@ -0,0 +1,40 @@',
                'app/Config/Routes.php'                => '@@ -0,0 +1,9 @@',
            ]),
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to cover', $out);
    }

    public function testFilesOutsideAppAndNonPhpFilesAreIgnored(): void
    {
        [$code] = $this->check(
            $this->clover(['app/Foo.php' => [1 => 0]]),
            $this->diff([
                'tests/unit/FooTest.php'       => '@@ -0,0 +1,5 @@',
                'resources/js/pages/a.js'      => '@@ -0,0 +1,5 @@',
                'docs/testing.md'              => '@@ -0,0 +1,5 @@',
            ]),
        );

        $this->assertSame(0, $code);
    }

    public function testASingleLineHunkWithoutACountMeansOneLine(): void
    {
        // "+10" with no ",count" is one line.
        [$code, $out] = $this->check(
            $this->clover(['app/Foo.php' => [10 => 0, 11 => 1]]),
            $this->diff(['app/Foo.php' => '@@ -10 +10 @@']),
            '100',
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('(0 of 1', $out);
    }

    public function testSeveralFilesAreSummedTogether(): void
    {
        // 2 of 3 lines covered across two files is 66.67%.
        [$code, $out] = $this->check(
            $this->clover(['app/A.php' => [1 => 1, 2 => 1], 'app/B.php' => [1 => 0]]),
            $this->diff(['app/A.php' => '@@ -0,0 +1,2 @@', 'app/B.php' => '@@ -0,0 +1 @@']),
            '66',
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString('66.67% (2 of 3', $out);
        $this->assertStringContainsString('app/B.php: 0 of 1', $out);
    }

    public function testMissingArgumentsExitWithUsageError(): void
    {
        $proc = proc_open([PHP_BINARY, ROOTPATH . '.github/scripts/check-diff-coverage.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        $this->assertSame(2, proc_close($proc));
        $this->assertStringContainsString('Usage:', $err);
    }
}

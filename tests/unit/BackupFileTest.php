<?php

declare(strict_types=1);

use App\Services\Backup\BackupFile;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A dump file is whole only when it reads back to mysqldump's closing line (audit decision 11.1).
 *
 * @internal
 */
final class BackupFileTest extends CIUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup-file-' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function write(string $sql, bool $cut = false): void
    {
        $gz = gzencode($sql, 9);
        file_put_contents($this->path, $cut ? substr($gz, 0, intdiv(strlen($gz), 2)) : $gz);
    }

    public function testAFinishedDumpIsComplete(): void
    {
        $this->write("-- MySQL dump\nINSERT INTO t VALUES (1);\n-- Dump completed on 2026-10-10 12:00:00\n\n");

        $this->assertTrue(BackupFile::isComplete($this->path));
    }

    public function testADumpWithoutItsClosingLineIsNot(): void
    {
        $this->write("-- MySQL dump\nINSERT INTO t VALUES (1);\n");

        $this->assertFalse(BackupFile::isComplete($this->path));
    }

    public function testAGzipCutShortIsNot(): void
    {
        $this->write("-- MySQL dump\n" . str_repeat("INSERT INTO t VALUES (1);\n", 5000) . "-- Dump completed on 2026-10-10 12:00:00\n", true);

        $this->assertFalse(BackupFile::isComplete($this->path));
    }

    /** A dump of $rows varied rows (compressing about as real SQL does), written in pieces; returns its path. */
    private function dump(int $rows): string
    {
        mt_srand(7);
        $gz = gzopen($this->path, 'wb6');

        for ($i = 0; $i < $rows; $i += 1000) {
            $chunk = '';

            for ($j = 0; $j < 1000; $j++) {
                $chunk .= 'INSERT INTO `assessment_submissions` VALUES (' . ($i + $j) . ',' . mt_rand(1, 99999) . ",'Graded'," . mt_rand(0, 100) . ",'answer " . md5((string) mt_rand()) . "');\n";
            }

            gzwrite($gz, $chunk);
        }

        gzwrite($gz, "-- Dump completed on 2026-10-10 12:00:00\n");
        gzclose($gz);

        return $this->path;
    }

    /** Peak memory, in bytes, that checking the file took. */
    private function peakOfChecking(): int
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $this->assertTrue(BackupFile::isComplete($this->path));

        return memory_get_peak_usage() - $before;
    }

    public function testALargeDumpIsCheckedWithoutHoldingItInMemory(): void
    {
        $this->dump(50_000);
        $small = $this->peakOfChecking();

        $this->dump(200_000); // about 20 MB of SQL
        $large = $this->peakOfChecking();

        $this->assertLessThan(2 * 1024 * 1024, $large - $small, sprintf('four times the dump took %.1f MB more', ($large - $small) / 1048576));
        $this->assertLessThan(4 * 1024 * 1024, $large, sprintf('checking held %.1f MB', $large / 1048576));
    }

    public function testAFileThatIsNotGzipOrNotThereIsNot(): void
    {
        file_put_contents($this->path, "-- Dump completed\n");
        $this->assertFalse(BackupFile::isComplete($this->path), 'plain text');

        $this->assertFalse(BackupFile::isComplete($this->path . '.missing'));
    }
}

<?php

declare(strict_types=1);

use App\Services\Users\UserExport;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The users CSV: what a row holds, and that nothing in a name can run as a
 * spreadsheet formula. Pure logic, no database.
 *
 * @internal
 */
final class UserExportTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function user(array $override = []): array
    {
        return array_merge([
            'id' => 7, 'username' => 'maria.santos', 'email' => 'maria@example.com', 'first_name' => 'Maria', 'last_name' => 'Santos',
            'active' => 1, 'deactivated_at' => null, 'last_active' => '2026-10-09 08:00:00', 'created_at' => '2026-09-01 10:00:00',
            'roles' => ['student', 'instructor'], 'institutions' => [['id' => 1, 'name' => 'Acme University'], ['id' => 2, 'name' => 'Beta College']],
        ], $override);
    }

    public function testTheHeaderNamesEveryColumn(): void
    {
        $this->assertSame(['ID', 'Name', 'Email', 'Username', 'Roles', 'Institutions', 'Status', 'Last active', 'Created'], UserExport::header());
    }

    public function testARowHoldsWhatTheListShows(): void
    {
        $this->assertSame(
            ['7', 'Maria Santos', 'maria@example.com', 'maria.santos', 'Student, Instructor', 'Acme University, Beta College', 'Active', '2026-10-09 08:00:00', '2026-09-01 10:00:00'],
            UserExport::row($this->user()),
        );
    }

    public function testADeactivatedAccountSaysSo(): void
    {
        $this->assertSame('Deactivated', UserExport::row($this->user(['active' => 0]))[6]);
        $this->assertSame('Deactivated', UserExport::row($this->user(['deactivated_at' => '2026-10-01 00:00:00']))[6]);
    }

    public function testAPersonWithoutAProfileHasAnEmptyName(): void
    {
        $this->assertSame('', UserExport::row($this->user(['first_name' => null, 'last_name' => null]))[1]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideCells(): iterable
    {
        yield 'a formula' => ['=HYPERLINK("http://evil","x")', "'=HYPERLINK(\"http://evil\",\"x\")"];
        yield 'a plus' => ['+1+1', "'+1+1"];
        yield 'a minus' => ['-2+3', "'-2+3"];
        yield 'an at sign' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'a tab' => ["\tX", "'\tX"];
        yield 'a carriage return' => ["\rX", "'\rX"];
        yield 'ordinary text' => ['Maria', 'Maria'];
        yield 'a hyphen in the middle' => ['Mary-Ann', 'Mary-Ann'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('provideCells')]
    public function testACellThatWouldRunAsAFormulaIsDefused(string $in, string $out): void
    {
        $this->assertSame($out, UserExport::safeCell($in));
    }

    public function testAHostileNameIsDefusedInTheRow(): void
    {
        $row = UserExport::row($this->user(['first_name' => '=cmd|calc', 'last_name' => 'X']));

        $this->assertSame("'=cmd|calc X", $row[1]);
    }

    public function testTheCsvHasAHeaderAndOneLinePerPersonAndCountsThem(): void
    {
        $result = UserExport::csv([$this->user(), $this->user(['id' => 8, 'first_name' => 'Juan'])]);

        $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $result['csv']))));

        $this->assertSame(2, $result['rows']);
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('ID,Name,Email', $lines[0]);
        $this->assertStringContainsString('"Acme University, Beta College"', $lines[1], 'a comma inside a cell is quoted');
    }

    public function testNobodyGivesJustTheHeader(): void
    {
        $result = UserExport::csv([]);

        $this->assertSame(0, $result['rows']);
        $this->assertStringStartsWith('ID,Name', $result['csv']);
    }
}

<?php

declare(strict_types=1);

use App\Services\Users\UserFilters;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How the users list reads its search, filters, sort and paging from a query
 * string. Pure logic, no database.
 *
 * @internal
 */
final class UserFiltersTest extends CIUnitTestCase
{
    public function testAnEmptyQueryGivesTheDefaults(): void
    {
        $f = UserFilters::fromInput([]);

        $this->assertNull($f->q);
        $this->assertNull($f->institutionId);
        $this->assertNull($f->role);
        $this->assertNull($f->status);
        $this->assertSame('name', $f->sort);
        $this->assertSame('asc', $f->dir);
        $this->assertSame(1, $f->page);
        $this->assertSame(UserFilters::DEFAULT_PER_PAGE, $f->perPage);
        $this->assertFalse($f->isFiltered());
        $this->assertSame([], $f->toQuery());
    }

    public function testEverythingValidIsRead(): void
    {
        $f = UserFilters::fromInput(['q' => ' maria ', 'institution' => '12', 'role' => 'student', 'status' => 'deactivated', 'sort' => 'last_active', 'dir' => 'DESC', 'page' => '3', 'per_page' => '50']);

        $this->assertSame('maria', $f->q);
        $this->assertSame(12, $f->institutionId);
        $this->assertSame('student', $f->role);
        $this->assertSame('deactivated', $f->status);
        $this->assertSame('last_active', $f->sort);
        $this->assertSame('desc', $f->dir);
        $this->assertSame(3, $f->page);
        $this->assertSame(50, $f->perPage);
        $this->assertTrue($f->isFiltered());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideHostileInput(): iterable
    {
        yield 'a role that does not exist' => [['role' => 'superuser']];
        yield 'a role that is an array' => [['role' => ['student']]];
        yield 'a status that does not exist' => [['status' => 'banned']];
        yield 'an institution that is not a number' => [['institution' => '1; DROP TABLE users']];
        yield 'an institution of zero' => [['institution' => '0']];
        yield 'a negative institution' => [['institution' => '-4']];
        yield 'an institution that is an array' => [['institution' => ['1']]];
        yield 'a sort column that is not offered' => [['sort' => 'password_hash']];
        yield 'a sort given as an array' => [['sort' => ['email']]];
        yield 'a page of zero' => [['page' => '0']];
        yield 'a page that is text' => [['page' => 'abc']];
        yield 'a search that is an array' => [['q' => ['x']]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('provideHostileInput')]
    public function testNothingInvalidGetsThrough(array $input): void
    {
        $f = UserFilters::fromInput($input);

        $this->assertNull($f->role);
        $this->assertNull($f->status);
        $this->assertNull($f->institutionId);
        $this->assertNull($f->q);
        $this->assertSame('name', $f->sort);
        $this->assertSame(1, $f->page);
    }

    public function testEveryRoleOfTheSystemCanBeFilteredOn(): void
    {
        foreach (['admin', 'institutional_admin', 'chairperson', 'instructor', 'student', 'parent'] as $role) {
            $this->assertSame($role, UserFilters::fromInput(['role' => $role])->role);
        }
    }

    public function testEveryRoleHasALabel(): void
    {
        $this->assertSame(UserFilters::ROLES, array_keys(UserFilters::ROLE_LABELS));
    }

    public function testThePageSizeIsKeptWithinLimits(): void
    {
        $this->assertSame(UserFilters::MAX_PER_PAGE, UserFilters::fromInput(['per_page' => '5000'])->perPage);
        $this->assertSame(UserFilters::DEFAULT_PER_PAGE, UserFilters::fromInput(['per_page' => '0'])->perPage);
    }

    public function testALongSearchIsCut(): void
    {
        $this->assertSame(100, mb_strlen((string) UserFilters::fromInput(['q' => str_repeat('a', 500)])->q));
    }

    public function testTheQueryLeavesOutDefaultsAndAppliesOverrides(): void
    {
        $f = UserFilters::fromInput(['q' => 'maria', 'role' => 'student', 'page' => '2']);

        $this->assertSame(['q' => 'maria', 'role' => 'student', 'page' => 2], $f->toQuery());
        $this->assertSame(['q' => 'maria', 'role' => 'student'], $f->toQuery(['page' => null]));
        $this->assertSame(['q' => 'maria', 'role' => 'student', 'sort' => 'email', 'page' => 5], $f->toQuery(['page' => 5, 'sort' => 'email']));
    }
}

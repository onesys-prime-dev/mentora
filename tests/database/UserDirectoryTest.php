<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use App\Services\Users\UserDirectory;
use App\Services\Users\UserFilters;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The users list's data: every filter, search, sort and page, what a row
 * holds, and who is left out.
 *
 * @internal
 */
final class UserDirectoryTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private UserDirectory $directory;

    private int $acme;

    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new UserDirectory();
        $this->acme      = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->beta      = PeopleFactory::institution('BETA', 'active', 'Beta College');
    }

    /** @return list<string> emails in the order returned */
    private function emails(UserFilters $filters): array
    {
        return array_column($this->directory->search($filters)['rows'], 'email');
    }

    private function filters(array $input = []): UserFilters
    {
        return UserFilters::fromInput($input);
    }

    public function testARowHoldsWhoTheyAreAndWhereTheyBelong(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme, true, '2026-10-09 08:00:00');
        db_connect()->table('auth_groups_users')->insert(['user_id' => $user->id, 'group' => 'instructor']);

        $row = $this->directory->find((int) $user->id);

        $this->assertSame('maria@example.com', $row['email']);
        $this->assertSame('Maria', $row['first_name']);
        $this->assertSame('Santos', $row['last_name']);
        $this->assertSame(['instructor', 'student'], $row['roles']);
        $this->assertSame(['Acme University'], array_column($row['institutions'], 'name'));
        $this->assertSame('2026-10-09 08:00:00', $row['last_active']);
        $this->assertNull($row['deactivated_at']);
    }

    public function testASystemAdministratorHasNoInstitutionAndMayHaveNoProfile(): void
    {
        $admin = PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com');

        $row = $this->directory->find((int) $admin->id);

        $this->assertSame(['admin'], $row['roles']);
        $this->assertSame([], $row['institutions']);
        $this->assertNull($row['first_name']);
    }

    public function testAnUnknownUserIsNull(): void
    {
        $this->assertNull($this->directory->find(999999));
    }

    public function testDeletedAccountsAreLeftOut(): void
    {
        $gone = PeopleFactory::person('student', 'Gone', 'Person', 'gone@example.com', $this->acme);
        PeopleFactory::person('student', 'Here', 'Person', 'here@example.com', $this->acme);
        db_connect()->table('users')->where('id', $gone->id)->update(['deleted_at' => '2026-10-01 00:00:00']);

        $this->assertSame(['here@example.com'], $this->emails($this->filters()));
        $this->assertNull($this->directory->find((int) $gone->id));
        $this->assertSame(1, $this->directory->search($this->filters())['total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function testFilteringByInstitution(): void
    {
        PeopleFactory::person('student', 'Ana', 'Acme', 'ana@example.com', $this->acme);
        PeopleFactory::person('student', 'Ben', 'Beta', 'ben@example.com', $this->beta);
        PeopleFactory::person('student', 'Nobody', 'Anywhere', 'none@example.com');

        $this->assertSame(['ana@example.com'], $this->emails($this->filters(['institution' => (string) $this->acme])));
        $this->assertSame(['ben@example.com'], $this->emails($this->filters(['institution' => (string) $this->beta])));
        $this->assertCount(3, $this->emails($this->filters()));
    }

    public function testFilteringByRole(): void
    {
        PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com', $this->acme);
        PeopleFactory::person('instructor', 'Ina', 'Structor', 'ina@example.com', $this->acme);
        PeopleFactory::person('admin', 'Ada', 'Admin', 'ada@example.com');

        $this->assertSame(['stu@example.com'], $this->emails($this->filters(['role' => 'student'])));
        $this->assertSame(['ina@example.com'], $this->emails($this->filters(['role' => 'instructor'])));
        $this->assertSame(['ada@example.com'], $this->emails($this->filters(['role' => 'admin'])));
    }

    public function testFilteringByStatus(): void
    {
        PeopleFactory::person('student', 'On', 'Line', 'on@example.com', $this->acme);
        $off = PeopleFactory::person('student', 'Off', 'Line', 'off@example.com', $this->acme);
        (new AccountControl())->deactivate((int) $off->id);

        $this->assertSame(['on@example.com'], $this->emails($this->filters(['status' => 'active'])));
        $this->assertSame(['off@example.com'], $this->emails($this->filters(['status' => 'deactivated'])));
    }

    public function testFiltersCombine(): void
    {
        PeopleFactory::person('student', 'Ana', 'Acme', 'ana@example.com', $this->acme);
        PeopleFactory::person('instructor', 'Ivo', 'Acme', 'ivo@example.com', $this->acme);
        PeopleFactory::person('student', 'Ben', 'Beta', 'ben@example.com', $this->beta);

        $this->assertSame(['ana@example.com'], $this->emails($this->filters(['institution' => (string) $this->acme, 'role' => 'student'])));
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    public function testSearchFindsByFirstNameLastNameFullNameEmailAndUsername(): void
    {
        $maria = PeopleFactory::person('student', 'Maria', 'Santos', 'maria.s@school.example', $this->acme);
        PeopleFactory::person('student', 'Juan', 'Dela Cruz', 'juan@other.example', $this->acme);

        foreach (['Maria', 'Santos', 'maria santos', 'school.example', (string) $maria->username] as $term) {
            $this->assertSame(['maria.s@school.example'], $this->emails($this->filters(['q' => $term])), "searching '{$term}'");
        }

        $this->assertSame([], $this->emails($this->filters(['q' => 'nobody-by-this-name'])));
    }

    public function testSearchTreatsPercentSignsAndQuotesAsPlainText(): void
    {
        PeopleFactory::person('student', 'Plain', 'Person', 'plain@example.com', $this->acme);

        $this->assertSame([], $this->emails($this->filters(['q' => '%'])), 'a percent sign is not a wildcard');
        $this->assertSame([], $this->emails($this->filters(['q' => "' OR '1'='1"])));
        $this->assertSame([], $this->emails($this->filters(['q' => '_'])));
    }

    /*
    |--------------------------------------------------------------------------
    | Sorting and paging
    |--------------------------------------------------------------------------
    */

    public function testSortingByNameEmailAndLastActive(): void
    {
        PeopleFactory::person('student', 'Cara', 'Zed', 'c@example.com', $this->acme, true, '2026-10-01 00:00:00');
        PeopleFactory::person('student', 'Abe', 'Yan', 'a@example.com', $this->acme, true, '2026-10-03 00:00:00');
        PeopleFactory::person('student', 'Bea', 'Xu', 'b@example.com', $this->acme, true, '2026-10-02 00:00:00');

        $this->assertSame(['b@example.com', 'a@example.com', 'c@example.com'], $this->emails($this->filters(['sort' => 'name'])), 'by last name');
        $this->assertSame(['c@example.com', 'a@example.com', 'b@example.com'], $this->emails($this->filters(['sort' => 'name', 'dir' => 'desc'])));
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $this->emails($this->filters(['sort' => 'email'])));
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $this->emails($this->filters(['sort' => 'last_active', 'dir' => 'desc'])));
    }

    public function testPagingGivesEveryPersonOnceAndTheTotal(): void
    {
        foreach (range(1, 7) as $i) {
            PeopleFactory::person('student', 'Person', sprintf('N%02d', $i), "p{$i}@example.com", $this->acme);
        }

        $first  = $this->directory->search($this->filters(['per_page' => '3', 'page' => '1']));
        $second = $this->directory->search($this->filters(['per_page' => '3', 'page' => '2']));
        $third  = $this->directory->search($this->filters(['per_page' => '3', 'page' => '3']));

        $this->assertSame(7, $first['total']);
        $this->assertCount(3, $first['rows']);
        $this->assertCount(3, $second['rows']);
        $this->assertCount(1, $third['rows']);

        $all = array_merge(array_column($first['rows'], 'email'), array_column($second['rows'], 'email'), array_column($third['rows'], 'email'));

        $this->assertCount(7, array_unique($all));
        $this->assertSame([], $this->directory->search($this->filters(['per_page' => '3', 'page' => '9']))['rows']);
    }

    public function testTheTotalCountsPeopleNotRoles(): void
    {
        $user = PeopleFactory::person('student', 'Many', 'Things', 'many@example.com', $this->acme);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $user->id, 'group' => 'instructor']);

        $this->assertSame(1, $this->directory->search($this->filters())['total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Export and administrators
    |--------------------------------------------------------------------------
    */

    public function testEachWalksEveryMatchInOrderUpToTheCap(): void
    {
        foreach (range(1, 6) as $i) {
            PeopleFactory::person('student', 'Person', sprintf('N%02d', $i), "p{$i}@example.com", $this->acme);
        }

        $all    = iterator_to_array($this->directory->each($this->filters(), 100), false);
        $capped = iterator_to_array($this->directory->each($this->filters(), 4), false);

        $this->assertSame(['p1@example.com', 'p2@example.com', 'p3@example.com', 'p4@example.com', 'p5@example.com', 'p6@example.com'], array_column($all, 'email'));
        $this->assertCount(4, $capped);
    }

    public function testByIdsReturnsOnlyThoseThatExist(): void
    {
        $one = PeopleFactory::person('student', 'One', 'Person', 'one@example.com', $this->acme);
        $two = PeopleFactory::person('student', 'Two', 'Person', 'two@example.com', $this->acme);
        PeopleFactory::person('student', 'Three', 'Person', 'three@example.com', $this->acme);

        $rows = $this->directory->byIds([(int) $two->id, (int) $one->id, 999999]);

        $this->assertSame(['one@example.com', 'two@example.com'], array_column($rows, 'email'));
        $this->assertSame([], $this->directory->byIds([]));
    }

    public function testActiveAdministratorsAreCountedWithoutTheDeactivatedOrDeleted(): void
    {
        $this->assertSame(0, $this->directory->activeAdministrators());

        $one = PeopleFactory::person('admin', 'One', 'Admin');
        $two = PeopleFactory::person('admin', 'Two', 'Admin');
        $three = PeopleFactory::person('admin', 'Three', 'Admin');
        PeopleFactory::person('student', 'Not', 'Admin', null, $this->acme);

        $this->assertSame(3, $this->directory->activeAdministrators());

        (new AccountControl())->deactivate((int) $two->id);
        db_connect()->table('users')->where('id', $three->id)->update(['deleted_at' => '2026-10-01 00:00:00']);

        $this->assertSame(1, $this->directory->activeAdministrators());
        $this->assertNotNull($one);
    }
}

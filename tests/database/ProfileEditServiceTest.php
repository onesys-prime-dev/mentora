<?php

declare(strict_types=1);

use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\ProfileEditService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/** @internal */
final class ProfileEditServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $inst;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst    = PeopleFactory::institution('PE1');
        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->inst);
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return db_connect()->table('user_profiles')->where('user_id', $u->id)->get()->getRowArray();
    }

    /** @param array<string, mixed> $input */
    private function edit(string $section, array $input, ?User $who = null): void
    {
        (new ProfileEditService())->update($who ?? $this->teacher, $section, $input);
    }

    /** @param array<string, mixed> $input */
    private function refused(string $section, array $input, ?User $who = null): string
    {
        try {
            $this->edit($section, $input, $who);
        } catch (ProfileEditException $e) {
            return $e->getMessage();
        }
        $this->fail('Expected the edit to be refused.');
    }

    public function testNameBoundariesAndRegex(): void
    {
        $this->edit('identity', ['preferred_first_name' => 'Al']);
        $this->assertSame('Al', $this->row($this->teacher)['preferred_first_name']);
        $this->edit('identity', ['preferred_first_name' => str_repeat('a', 50)]);
        $this->assertSame(50, mb_strlen($this->row($this->teacher)['preferred_first_name']));

        $this->refused('identity', ['preferred_first_name' => 'A']);
        $this->refused('identity', ['preferred_first_name' => str_repeat('a', 51)]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideBadNames(): iterable
    {
        foreach (['Mary$', 'Mary 3', '12', '@Mary', '-Mary', ' .Mary', "Mary\u{1F600}", 'Mary_J', 'Mary<b>'] as $n) {
            yield $n => [$n];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadNames')]
    public function testSymbolsEmojiAndDigitsAreRefused(string $name): void
    {
        $this->assertStringContainsString('Preferred first name', $this->refused('identity', ['preferred_first_name' => $name]));
    }

    /** @return iterable<string, array{string}> */
    public static function provideGoodNames(): iterable
    {
        foreach (['Mary-Jane', "O'Neil", 'St. John', 'José', 'Zoë Ann'] as $n) {
            yield $n => [$n];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideGoodNames')]
    public function testRealNamesAreAccepted(string $name): void
    {
        $this->edit('identity', ['preferred_first_name' => "  {$name} "]);
        $this->assertSame($name, $this->row($this->teacher)['preferred_first_name']);
    }

    public function testTheFourthNameChangeInADayIsRefusedWithTheRule(): void
    {
        foreach (['Ann', 'Bea', 'Cat'] as $n) {
            $this->edit('identity', ['preferred_first_name' => $n]);
        }
        $message = $this->refused('identity', ['preferred_first_name' => 'Dee']);
        $this->assertSame('You can change your preferred name 3 times a day. Try again tomorrow.', $message);
        $this->assertSame('Cat', $this->row($this->teacher)['preferred_first_name']);
    }

    public function testOldChangesAndNoOpSavesAreNotCounted(): void
    {
        for ($i = 0; $i < 3; $i++) {
            db_connect()->table('audit_logs')->insert(['actor_user_id' => $this->teacher->id, 'action' => 'profile.updated', 'new_values' => '{"section":"identity","fields":["preferred_first_name"]}', 'created_at' => date('Y-m-d H:i:s', time() - 90000)]);
        }
        $this->edit('identity', ['preferred_first_name' => 'Ann']);
        $this->edit('identity', ['preferred_first_name' => 'Bea']);
        // The same name again, and a pronouns-only change, are not name changes.
        $this->edit('identity', ['preferred_first_name' => 'Bea', 'pronouns' => 'they/them']);
        $this->edit('identity', ['preferred_first_name' => 'Bea', 'pronouns' => 'she/her']);
        $this->edit('identity', ['preferred_first_name' => 'Cat']);
        $this->assertSame('Cat', $this->row($this->teacher)['preferred_first_name']);
        $this->refused('identity', ['preferred_first_name' => 'Dee']);
    }

    public function testAnotherPersonsRecentNameChangesDoNotCountAgainstTheOwner(): void
    {
        $other = PeopleFactory::person('instructor', 'Otto', 'Other', null, $this->inst);
        foreach (['Ann', 'Bea', 'Cat'] as $n) {
            $this->edit('identity', ['preferred_first_name' => $n], $other);
        }
        foreach (['Dan', 'Eve', 'Fay'] as $n) {
            $this->edit('identity', ['preferred_first_name' => $n]);
        }
        $this->assertSame('Fay', $this->row($this->teacher)['preferred_first_name']);
    }

    public function testEmptyClearsEveryField(): void
    {
        $this->edit('identity', ['preferred_first_name' => 'Ann', 'pronouns' => 'she/her']);
        $this->edit('about', ['bio' => 'Hello']);
        $this->edit('staff', ['office_location' => 'R1', 'consultation_hours' => 'Mon', 'work_phone' => '123']);
        $this->edit('identity', ['preferred_first_name' => '  ', 'pronouns' => '']);
        $this->edit('about', ['bio' => '']);
        $this->edit('staff', ['office_location' => '', 'consultation_hours' => ' ', 'work_phone' => '']);
        $r = $this->row($this->teacher);
        foreach (['preferred_first_name', 'pronouns', 'bio', 'office_location', 'consultation_hours', 'work_phone'] as $f) {
            $this->assertNull($r[$f], $f);
        }
    }

    public function testPronounsListAndCustom(): void
    {
        foreach (ProfileEditService::PRONOUNS as $p) {
            $this->edit('identity', ['pronouns' => $p]);
            $this->assertSame($p, $this->row($this->teacher)['pronouns']);
        }
        $this->edit('identity', ['pronouns' => str_repeat('x', 30)]);
        $this->assertSame(30, mb_strlen($this->row($this->teacher)['pronouns']));
        $this->assertStringContainsString('30', $this->refused('identity', ['pronouns' => str_repeat('x', 31)]));
    }

    public function testTextLimits(): void
    {
        $cases = [['about', 'bio', 300], ['staff', 'office_location', 120], ['staff', 'consultation_hours', 200], ['staff', 'work_phone', 40]];
        foreach ($cases as [$section, $field, $max]) {
            $this->edit($section, [$field => str_repeat('é', $max)]);
            $this->assertSame($max, mb_strlen($this->row($this->teacher)[$field]), $field);
            $this->assertStringContainsString((string) $max, $this->refused($section, [$field => str_repeat('é', $max + 1)]), $field);
        }
    }

    public function testOnlyStaffEditStaffDetailsAndUnknownSectionsAreRefused(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->inst);
        $this->refused('staff', ['office_location' => 'R1'], $student);
        $this->assertNull($this->row($student)['office_location']);
        $this->refused('names', ['x' => 'y']);
    }

    public function testTheAuditRowHasFieldNamesAndNoValues(): void
    {
        $this->edit('about', ['bio' => 'Secretive bio text']);
        $this->edit('about', ['bio' => 'Secretive bio text']); // no change, no row
        $rows = db_connect()->table('audit_logs')->where('action', 'profile.updated')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(['section' => 'about', 'fields' => ['bio']], json_decode($rows[0]['new_values'], true));
        $this->assertNull($rows[0]['old_values']);
        $this->assertStringNotContainsString('Secretive', json_encode($rows[0]));
    }
}

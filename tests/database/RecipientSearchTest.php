<?php

declare(strict_types=1);

use App\Services\Messaging\RecipientSearch;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The new-message picker: only people the policy lets this person start with,
 * found by name and by nothing else.
 *
 * @internal
 */
final class RecipientSearchTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $program;

    private int $term;

    private RecipientSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $d                 = DeanFactory::college('Q1');
        $this->institution = $d['institution'];
        $this->program     = DeanFactory::program(DeanFactory::department($d['college'], 'QA'), 'QA');
        $this->term        = DeanFactory::term($this->institution);
        $this->search      = new RecipientSearch();
    }

    private function person(string $role, string $first, string $last, ?int $institution = null, bool $active = true, ?string $email = null): User
    {
        return PeopleFactory::person($role, $first, $last, $email, $institution ?? $this->institution, $active);
    }

    /** @return array{User, User, int} a student and an instructor of one active course, and the offering */
    private function pair(string $code = 'QC1'): array
    {
        $student    = $this->person('student', 'Stella', 'Student');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius');
        $offering   = DeanFactory::offering($this->program, $this->term, $code);
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);

        return [$student, $instructor, $offering];
    }

    /** @return list<int> */
    private function ids(User $sender, string $query): array
    {
        return array_map(static fn (array $r): int => $r['id'], $this->search->search($sender, $query));
    }

    public function testAStudentFindsTheInstructorOfASharedCourseByName(): void
    {
        [$student, $instructor] = $this->pair();

        $found = $this->search->search($student, 'Ignat');

        $this->assertCount(1, $found);
        $this->assertSame((int) $instructor->id, $found[0]['id']);
        $this->assertSame('Ivan Ignatius', $found[0]['name']);
        $this->assertSame('instructor', $found[0]['role']);
        $this->assertNull($found[0]['subject_student_id']);
    }

    public function testAStudentFindsAnyStaffButNoParentAdminOrOutsider(): void
    {
        [$student] = $this->pair();
        $chair = $this->person('chairperson', 'Chad', 'Zed');
        $dean  = $this->person('dean', 'Dana', 'Zed');
        $this->person('parent', 'Pia', 'Zed');
        $ian = $this->person('instructor', 'Ian', 'Zed');         // teaches nothing shared: D1 still reaches them
        $this->person('instructor', 'Ila', 'Zed', null, false);   // deactivated
        $this->person('admin', 'Ada', 'Zed');
        $other = DeanFactory::college('Q2');
        $this->person('instructor', 'Ola', 'Zed', $other['institution']);

        $this->assertSame([(int) $chair->id, (int) $dean->id, (int) $ian->id], $this->ids($student, 'Zed'));
        $this->assertSame([], $this->ids($student, 'Stella'), 'not oneself');
    }

    public function testABlockedRecipientIsNotListedButAnInstructorStillIs(): void
    {
        [$student, , $offering] = $this->pair('QC2');
        $classmate              = $this->person('student', 'Clara', 'Classmate');
        CourseFactory::enroll($offering, $classmate);

        $this->assertNotSame([], $this->ids($student, 'Clara'));

        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $classmate->id, 'blocked_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->ids($student, 'Clara'));
        $this->assertNotSame([], $this->ids($student, 'Ignatius'));
    }

    public function testAQueryUnderTwoCharactersFindsNothing(): void
    {
        [$student] = $this->pair();

        $this->assertSame([], $this->ids($student, 'I'));
        $this->assertSame([], $this->ids($student, ' '));
    }

    public function testEmailSearchFollowsVisibility(): void
    {
        $student    = $this->person('student', 'Stella', 'Student', null, true, 'stella.hidden@example.com');
        $classmate  = $this->person('student', 'Clara', 'Classmate', null, true, 'clara.hidden@example.com');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius', null, true, 'ivan.open@example.com');
        $parent     = $this->person('parent', 'Pat', 'Parent', null, true, 'pat.hidden@example.com');
        $offering   = DeanFactory::offering($this->program, $this->term, 'QC3');
        CourseFactory::enroll($offering, $student);
        CourseFactory::enroll($offering, $classmate);
        CourseFactory::assignInstructor($offering, $instructor);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        // UP D3: staff emails are visible to everyone, student emails only to staff, parent emails to nobody here.
        $this->assertSame([(int) $instructor->id], $this->ids($student, 'ivan.open'));
        $this->assertSame([], $this->ids($student, 'clara.hidden'), 'a classmate cannot find a student by email');
        $this->assertSame([(int) $student->id], $this->ids($instructor, 'stella.hidden'));
        $this->assertSame([], $this->ids($instructor, 'pat.hidden'), 'a parent is found by name only');
        $this->assertNotSame([], $this->ids($instructor, 'Parent'));
    }

    /** @return list<int> ids of $n people holding $role, inserted in bulk (a few queries whatever $n), first names ordered by number */
    private function bulk(int $n, string $role, string $last, string $first = 'P'): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $tag = 'b' . substr(md5(uniqid('', true)), 0, 8) . '_';

        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            $db->table('users')->insertBatch(array_map(static fn (int $i): array => ['username' => $tag . $i, 'active' => 1, 'created_at' => $now], $chunk), null, 500);
        }

        $ids = [];

        foreach ($db->table('users')->select('id, username')->like('username', $tag, 'after')->get()->getResultArray() as $row) {
            $ids[(int) substr($row['username'], strlen($tag))] = (int) $row['id'];
        }

        ksort($ids);

        foreach (array_chunk($ids, 500, true) as $chunk) {
            $db->table('auth_groups_users')->insertBatch(array_map(static fn (int $id): array => ['user_id' => $id, 'group' => $role, 'created_at' => $now], array_values($chunk)), null, 500);
            $db->table('institution_users')->insertBatch(array_map(fn (int $id): array => ['institution_id' => $this->institution, 'user_id' => $id, 'created_at' => $now], array_values($chunk)), null, 500);
            $profiles = [];

            foreach ($chunk as $i => $id) {
                $profiles[] = ['user_id' => $id, 'first_name' => sprintf('%s%05d', $first, $i), 'last_name' => $last, 'created_at' => $now];
            }

            $db->table('user_profiles')->insertBatch($profiles, null, 500);
        }

        return array_values($ids);
    }

    public function testFindsPeopleBeyondTwoHundredCandidates(): void
    {
        [, $instructor] = $this->pair();
        // Sorted first, and minors this instructor does not teach: only the policy refuses them.
        $minors = $this->bulk(230, 'student', 'Zed', 'A');
        db_connect()->table('institution_users')->whereIn('user_id', $minors)->update(['is_minor' => 1]);
        $zoe = $this->person('student', 'Zz', 'Zed');

        $this->assertSame([(int) $zoe->id], $this->ids($instructor, 'Zed'));
    }

    public function testTheNextPageStartsAfterTheCursor(): void
    {
        [$student] = $this->pair();
        $staff     = $this->bulk(7, 'instructor', 'Zed');

        $first  = $this->search->page($student, 'Zed', null, 3);
        $second = $this->search->page($student, 'Zed', $first['next'], 3);
        $last   = $this->search->page($student, 'Zed', $second['next'], 3);

        $this->assertSame(array_slice($staff, 0, 3), array_column($first['recipients'], 'id'));
        $this->assertSame(array_slice($staff, 3, 3), array_column($second['recipients'], 'id'));
        $this->assertSame([$staff[6]], array_column($last['recipients'], 'id'));
        $this->assertNull($last['next'], 'no cursor once the candidates end');
        $this->assertSame([], $this->search->page($student, 'Zed', 999999, 3)['recipients'], 'an unknown cursor finds nothing');
    }

    public function testStudentsFindOtherStudentsOnlyInsideTheScope(): void
    {
        [$student, , $offering] = $this->pair();
        $classmate              = $this->person('student', 'Clara', 'Zed');
        $stranger               = $this->person('student', 'Sara', 'Zed');
        $staff                  = $this->person('instructor', 'Ian', 'Zed');
        CourseFactory::enroll($offering, $classmate);
        $switch = new \App\Services\Messaging\StudentMessaging();

        $this->assertSame([(int) $classmate->id, (int) $staff->id, (int) $stranger->id], $this->ids($student, 'Zed'), 'whole institution');

        $switch->setScope($this->institution, 'course');
        $this->assertSame([(int) $classmate->id, (int) $staff->id], $this->ids($student, 'Zed'));

        $switch->setScope($this->institution, 'off');
        $this->assertSame([(int) $staff->id], $this->ids($student, 'Zed'));
    }

    public function testExcludesMinorsUnreachable(): void
    {
        [$minor, $teacher] = $this->pair();
        db_connect()->table('institution_users')->where('user_id', $minor->id)->update(['is_minor' => 1]);
        $stranger = $this->person('instructor', 'Sam', 'Stranger');
        $admin    = $this->person('institutional_admin', 'Ada', 'Admin');

        $this->assertSame([], $this->ids($stranger, 'Stella'));
        $this->assertSame([(int) $minor->id], $this->ids($teacher, 'Stella'));
        $this->assertSame([(int) $minor->id], $this->ids($admin, 'Stella'));
    }

    public function testExcludesBindingBlockers(): void
    {
        [$student, $instructor, $offering] = $this->pair();
        $classmate                         = $this->person('student', 'Clara', 'Classmate');
        $admin                             = $this->person('institutional_admin', 'Ada', 'Admin');
        CourseFactory::enroll($offering, $classmate);
        $now = date('Y-m-d H:i:s');

        foreach ([$classmate, $admin, $instructor] as $blocker) {
            db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $blocker->id, 'blocked_id' => $student->id, 'created_at' => $now]);
        }

        $this->assertSame([], $this->ids($student, 'Clara'), 'a binding block hides the blocker');
        $this->assertSame([(int) $admin->id], $this->ids($student, 'Admin'), "an institutional admin's block never binds");
        $this->assertSame([(int) $instructor->id], $this->ids($student, 'Ignatius'), 'paused while the instructor teaches the student');
    }

    public function testSearchFirstPageUnder300ms(): void
    {
        $started = microtime(true);
        $student = $this->person('student', 'Stella', 'Searcher');
        $this->bulk(3000, 'student', 'Member', 'S');
        $this->bulk(1000, 'instructor', 'Member', 'I');
        $this->bulk(1000, 'parent', 'Member', 'A');               // sorted first: a student may never start with a parent, so the page reads past them

        if (microtime(true) - $started > 60) {
            $this->markTestSkipped('Seeding 5,000 members took over 60 s on this machine; timing not meaningful.');
        }

        $this->search->search($student, 'Nobody here');            // warm the code paths, not the data

        $t     = microtime(true);
        $found = $this->search->search($student, 'Member');
        $ms    = (microtime(true) - $t) * 1000;

        fwrite(STDERR, sprintf("\n[timing] first page of 5,000 members: %.1f ms\n", $ms));
        $this->assertCount(20, $found);
        $this->assertLessThan(300, $ms);
    }

    /**
     * I1: an all-minor school searched by its teacher. Sorted ahead of the 150 minors they teach are 2,850 they do
     * not, 200 students who blocked them and 200 deactivated staff: the pre-filter must drop all of those in SQL,
     * so the page costs a bounded number of queries instead of several per refused person.
     */
    public function testATeacherSearchingAnAllMinorSchoolReadsOnlyWhoTheyCanReach(): void
    {
        $started  = microtime(true);
        $teacher  = $this->person('instructor', 'Tea', 'Cher');
        $db       = db_connect();
        $blockers = $this->bulk(200, 'student', 'Member', 'A');
        $gone     = $this->bulk(200, 'instructor', 'Member', 'B');
        $minors   = $this->bulk(3000, 'student', 'Member', 'S');
        $offering = DeanFactory::offering($this->program, $this->term, 'QM1');
        CourseFactory::assignInstructor($offering, $teacher);
        $db->table('institution_users')->whereIn('user_id', $minors)->update(['is_minor' => 1]);
        $db->table('users')->whereIn('id', $gone)->update(['active' => 0]);
        $taught = array_slice($minors, 2850);
        $db->table('enrollments')->insertBatch(array_map(static fn (int $id): array => ['offering_id' => $offering, 'student_user_id' => $id, 'status' => 'enrolled', 'enrolled_at' => date('Y-m-d H:i:s')], $taught));
        $db->table('user_blocks')->insertBatch(array_map(fn (int $id): array => ['institution_id' => $this->institution, 'blocker_id' => $id, 'blocked_id' => $teacher->id, 'created_at' => date('Y-m-d H:i:s')], $blockers));

        if (microtime(true) - $started > 60) {
            $this->markTestSkipped('Seeding 3,400 members took over 60 s on this machine; timing not meaningful.');
        }

        $this->search->search($teacher, 'Nobody here');

        $queries = 0;
        $count   = static function () use (&$queries): void {
            $queries++;
        };
        Events::on('DBQuery', $count);
        $t    = microtime(true);
        $page = (new RecipientSearch())->page($teacher, 'Member', null);
        $ms   = (microtime(true) - $t) * 1000;
        Events::removeListener('DBQuery', $count);

        fwrite(STDERR, sprintf("\n[timing] teacher, all-minor school: %.1f ms, %d queries\n", $ms, $queries));
        $this->assertSame(array_slice($taught, 0, 20), array_column($page['recipients'], 'id'));
        $this->assertNotNull($page['next']);
        $this->assertLessThan(300, $queries, 'bounded by the page, not by the people refused');
        $this->assertLessThan(300, $ms);
    }

    /** M1: a cursor naming anyone the searcher could not be offered answers exactly like an unknown one. */
    public function testACursorTheSearcherCouldNotSeeFindsNothing(): void
    {
        [$student] = $this->pair();
        $this->bulk(4, 'instructor', 'Zed');
        $parent = $this->person('parent', 'Pia', 'Zed');
        $minor  = $this->person('student', 'Mia', 'Zed');
        $far    = $this->person('instructor', 'Fay', 'Zed', DeanFactory::college('Q9')['institution']);
        db_connect()->table('institution_users')->where('user_id', $minor->id)->update(['is_minor' => 1]);
        $blocker = $this->person('student', 'Bea', 'Zed');
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $blocker->id, 'blocked_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $none = ['recipients' => [], 'next' => null];

        foreach ([999999, (int) $parent->id, (int) $minor->id, (int) $far->id, (int) $blocker->id, (int) $student->id] as $cursor) {
            $this->assertSame($none, $this->search->page($student, 'Zed', $cursor, 3), "cursor {$cursor}");
        }
    }

    /** M9: a page that ends exactly on the last match gives no cursor, so "Show more" never opens an empty page. */
    public function testAPageEndingOnTheLastMatchHasNoCursor(): void
    {
        [$student] = $this->pair();
        $staff     = $this->bulk(6, 'instructor', 'Zed');

        $first  = $this->search->page($student, 'Zed', null, 3);
        $second = $this->search->page($student, 'Zed', $first['next'], 3);

        $this->assertNotNull($first['next']);
        $this->assertSame(array_slice($staff, 3, 3), array_column($second['recipients'], 'id'));
        $this->assertNull($second['next']);
        $this->assertNull($this->search->page($student, 'Zed', null, 6)['next']);
    }

    public function testAParentGetsOneEntryPerSharedChild(): void
    {
        $parent     = $this->person('parent', 'Pat', 'Parent');
        $instructor = $this->person('instructor', 'Ivan', 'Ignatius');
        $offering   = DeanFactory::offering($this->program, $this->term, 'QC4');
        CourseFactory::assignInstructor($offering, $instructor);

        foreach (['Ann', 'Ben'] as $first) {
            $child = $this->person('student', $first, 'Kid');
            CourseFactory::enroll($offering, $child);
            db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $found = $this->search->search($parent, 'Ignatius');

        $this->assertCount(2, $found);
        $this->assertSame(['Ann Kid', 'Ben Kid'], array_column($found, 'subject_name'));
        $this->assertNotNull($found[0]['subject_student_id']);
    }

    public function testAVisiblePersonShowsTheirPreferredNameAndAnInteractiveAvatar(): void
    {
        [$student, $instructor] = $this->pair();
        db_connect()->table('user_profiles')->where('user_id', $instructor->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);

        $found = $this->search->search($student, 'Dela');

        $this->assertSame('Jun Dela Cruz', $found[0]['name']);
        $this->assertSame((int) $instructor->id, $found[0]['avatar']['id']);
        $this->assertSame('Jun Dela Cruz', $found[0]['avatar']['name']);
        $this->assertTrue($found[0]['avatar']['interactive']);
        $this->assertSame(['id', 'name', 'role', 'subject_student_id', 'subject_name', 'avatar'], array_keys($found[0]));
    }

    public function testAPreferredNameIsNeverSearchable(): void
    {
        [$student, $instructor] = $this->pair();
        db_connect()->table('user_profiles')->where('user_id', $instructor->id)->update(['preferred_first_name' => 'Zedekiah']);

        $this->assertSame([], $this->search->search($student, 'Zedekiah'), 'only the legal name is matched, so a hidden preferred name cannot be found');
    }

    public function testNamesAreLookedUpOnceWhateverTheNumberFound(): void
    {
        [$student, , $offering] = $this->pair();
        $lookups = static function (callable $call): int {
            $n = 0;
            $l = static function (Query $q) use (&$n): void {
                $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
            };
            Events::on('DBQuery', $l);
            $call();
            Events::removeListener('DBQuery', $l);

            return $n;
        };

        $one = $lookups(fn () => $this->search->search($student, 'Ignat'));

        for ($i = 1; $i <= 3; $i++) {
            CourseFactory::assignInstructor($offering, $this->person('instructor', 'Iggy' . $i, 'Ignatius'));
        }

        $this->assertCount(4, $this->search->search($student, 'Ignat'));
        $this->assertSame(1, $one);
        $this->assertSame(1, $lookups(fn () => $this->search->search($student, 'Ignat')));
    }
}

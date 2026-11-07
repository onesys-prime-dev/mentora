<?php

declare(strict_types=1);

use App\Services\Institutions\ClassSectionService;
use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StudentSuggestions;
use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The suggestions behind the "add students" boxes: who is offered while an administrator types an email, a student
 * number or a name. Only students of the administrator's own institution, only those who could be added, never
 * those who already have the place, and nothing for a query too short to mean anything.
 *
 * @internal
 */
final class StudentSuggestionsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $offering;

    private int $section;

    private User $admin;

    private StudentSuggestions $suggest;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SGA');
        $this->other       = SisFixture::institution('SGB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->suggest     = new StudentSuggestions();

        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $course = (int) $db->insertID();

        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'capacity' => '40'], $this->admin);
        $this->section  = (new ClassSectionService())->create($this->institution, ['term_id' => (string) $term, 'program_id' => (string) $program, 'year_level' => '1', 'code' => 'BSIT 1-A', 'capacity' => '40'], $this->admin);
    }

    private function student(string $first, string $last, ?string $number = null, ?string $email = null, ?int $institution = null, bool $active = true): User
    {
        $user = PeopleFactory::person('student', $first, $last, $email ?? strtolower($first . '.' . $last) . '@suggest.test', $institution ?? $this->institution, $active);

        if ($number !== null) {
            db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);
        }

        return $user;
    }

    /** @return list<string> the emails offered */
    private function emails(string $query, ?int $offering = null, ?int $section = null): array
    {
        return array_column($this->suggest->search($this->institution, $query, $offering, $section), 'email');
    }

    public function testAnEmailFragmentFindsTheStudentWithTheFieldsTheListShows(): void
    {
        $this->student('Ann', 'Lee', '2026-001');

        $found = $this->suggest->search($this->institution, 'ann.l');

        $this->assertSame([['name' => 'Ann Lee', 'student_number' => '2026-001', 'email' => 'ann.lee@suggest.test']], $found);
    }

    public function testAStudentNumberFragmentAndANameFindThemToo(): void
    {
        $this->student('Ann', 'Lee', '2026-001');
        $this->student('Ben', 'Ng', '2026-002');

        $this->assertSame(['ben.ng@suggest.test'], $this->emails('2026-002'));
        $this->assertSame(['ann.lee@suggest.test'], $this->emails('lee'));
        $this->assertSame(['ann.lee@suggest.test'], $this->emails('ANN LEE'), 'case does not matter, and a full name works');
    }

    public function testNothingIsOfferedForAQueryTooShortToMeanAnything(): void
    {
        $this->student('Ann', 'Lee', '2026-001');

        $this->assertSame([], $this->emails(''));
        $this->assertSame([], $this->emails(' a '));
        $this->assertSame(['ann.lee@suggest.test'], $this->emails('an'));
    }

    public function testWildcardsInTheQueryAreLiteralNotEverything(): void
    {
        $this->student('Ann', 'Lee', '2026-001');

        $this->assertSame([], $this->emails('%%'));
        $this->assertSame([], $this->emails('__'));
    }

    public function testOnlyStudentsOfTheInstitutionAreOffered(): void
    {
        $this->student('Ann', 'Lee');
        $this->student('Ana', 'Lopez', null, null, $this->other);

        $this->assertSame(['ann.lee@suggest.test'], $this->emails('an'), 'another institution\'s student is never shown');
        $this->assertSame(['ana.lopez@suggest.test'], array_column($this->suggest->search($this->other, 'an'), 'email'), 'and is found from their own');
    }

    public function testOnlyPeopleWhoCouldBeAddedAreOffered(): void
    {
        $this->student('Sam', 'Active');
        $this->student('Sam', 'Inactive', null, null, null, false);
        $off = $this->student('Sam', 'Off');
        (new AccountControl())->deactivate((int) $off->id);
        PeopleFactory::person('instructor', 'Sam', 'Teacher', 'sam.teacher@suggest.test', $this->institution);
        PeopleFactory::person('parent', 'Sam', 'Parent', 'sam.parent@suggest.test', $this->institution);

        $this->assertSame(['sam.active@suggest.test'], $this->emails('sam'), 'not an inactive or switched-off account, and not an instructor or a parent');
    }

    public function testAStudentWhoAlreadyHasThePlaceIsNotOfferedForThatOffering(): void
    {
        $in   = $this->student('Sam', 'Enrolled', '2026-010');
        $done = $this->student('Sam', 'Completed', '2026-011');
        $this->student('Sam', 'Dropped', '2026-012');
        $this->student('Sam', 'Free', '2026-013');

        $enrolments = new EnrolmentService();
        $enrolments->add($this->institution, $this->offering, "2026-010\n2026-011\n2026-012", false, $this->admin);

        db_connect()->table('enrollments')->where('student_user_id', $done->id)->update(['status' => 'completed']);
        db_connect()->table('enrollments')->where('student_user_id', db_connect()->table('user_profiles')->select('user_id')->where('student_number', '2026-012')->get()->getRow('user_id'))->update(['status' => 'dropped']);

        $this->assertSame(
            ['sam.dropped@suggest.test', 'sam.free@suggest.test'],
            $this->emails('sam', $this->offering),
            'enrolled and completed are refused by an add, so they are hidden; a dropped place can be brought back, so it is offered',
        );
        $this->assertCount(4, $this->emails('sam'), 'with no offering named, everyone matches');
        $this->assertNotContains($in->email, $this->emails('sam', $this->offering));
    }

    public function testAStudentAlreadyInTheSectionIsNotOfferedForThatSection(): void
    {
        $this->student('Sam', 'Member', '2026-020');
        $this->student('Sam', 'Free', '2026-021');
        (new ClassSectionService())->addStudents($this->institution, $this->section, '2026-020', false, $this->admin);

        $this->assertSame(['sam.free@suggest.test'], $this->emails('sam', null, $this->section));
    }

    public function testAtMostEightAreOfferedInNameOrder(): void
    {
        foreach (range(1, 12) as $i) {
            $this->student('Kim' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Test');
        }

        $found = $this->emails('kim');

        $this->assertCount(8, $found);
        $this->assertSame('kim01.test@suggest.test', $found[0]);
        $this->assertSame('kim08.test@suggest.test', $found[7]);
    }
}

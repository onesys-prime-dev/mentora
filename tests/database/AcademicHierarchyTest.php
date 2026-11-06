<?php

declare(strict_types=1);

use App\Models\Academic\CollegeModel;
use App\Models\Academic\InstitutionModel;
use App\Models\Academic\ProgramModel;
use App\Models\Academic\DepartmentModel;
use App\Models\Assignments\ChairpersonAssignmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Users\UserProfileModel;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * institutions -> colleges -> departments -> programs: the model helpers that walk it, and
 * where the rest of the app shows it (the profile card's college line, the course queries).
 *
 * @internal
 */
final class AcademicHierarchyTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    /** @return array{institution: int, college: int, sub: int, program: int} */
    private function chain(string $tag, string $programCode = 'BSIT'): array
    {
        $db = db_connect();

        $db->table('institutions')->insert(['name' => "Inst {$tag}", 'code' => "I{$tag}"]);
        $institution = (int) $db->insertID();

        $db->table('colleges')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);
        $college = (int) $db->insertID();

        $db->table('departments')->insert(['college_id' => $college, 'code' => "S{$tag}", 'name' => "College {$tag}"]);
        $department = (int) $db->insertID();

        $db->table('programs')->insert(['department_id' => $department, 'code' => $programCode, 'name' => "Program {$tag}"]);

        return ['institution' => $institution, 'college' => $college, 'department' => $department, 'program' => (int) $db->insertID()];
    }

    private function programOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings')
            ->select('courses.program_id')
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->where('course_offerings.id', $offeringId)
            ->get()->getRowArray()['program_id'];
    }

    private function profile(int $userId, array $extra = []): void
    {
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $userId, 'first_name' => 'Pat', 'last_name' => 'Person'] + $extra);
        $db->table('auth_identities')->insert(['user_id' => $userId, 'type' => 'email_password', 'secret' => "pat{$userId}@example.test", 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
    }

    /*
    |--------------------------------------------------------------------------
    | Model helpers
    |--------------------------------------------------------------------------
    */

    public function testAnInstitutionsCollegesAreListedByName(): void
    {
        $a = $this->chain('1');
        db_connect()->table('colleges')->insert(['institution_id' => $a['institution'], 'code' => 'AAA', 'name' => 'Aardvark College']);
        $this->chain('2');

        $names = array_column((new CollegeModel())->forInstitution($a['institution']), 'name');

        $this->assertSame(['Aardvark College', 'College 1'], $names);
    }

    public function testDepartmentsHideInactiveOnesUnlessAsked(): void
    {
        $a = $this->chain('1');
        db_connect()->table('departments')->insert(['college_id' => $a['college'], 'code' => 'OLD', 'name' => 'Closed College', 'is_active' => 0]);
        $model = new DepartmentModel();

        $this->assertSame(['College 1'], array_column($model->forCollege($a['college']), 'name'));
        $this->assertSame(['Closed College', 'College 1'], array_column($model->forCollege($a['college'], true), 'name'));
    }

    public function testProgramsAreListedPerDepartmentAndPerInstitution(): void
    {
        $a = $this->chain('1');
        $db = db_connect();
        $db->table('colleges')->insert(['institution_id' => $a['institution'], 'code' => 'GEN', 'name' => 'General']);
        $otherCollege = (int) $db->insertID();
        $db->table('departments')->insert(['college_id' => $otherCollege, 'code' => 'GE', 'name' => 'General Subjects']);
        $otherDepartment = (int) $db->insertID();
        $db->table('programs')->insert(['department_id' => $otherDepartment, 'code' => 'BSGE', 'name' => 'Zeta Program']);
        $this->chain('2');

        $this->assertSame(['Program 1'], array_column((new ProgramModel())->forDepartment($a['department']), 'name'));
        $this->assertSame(
            ['Program 1', 'Zeta Program'],
            array_column((new InstitutionModel())->programs($a['institution']), 'name'),
            "an institution's programs include every college's"
        );
    }

    public function testAProgramIsFoundWithEverythingAboveIt(): void
    {
        $a = $this->chain('1');

        $row = (new ProgramModel())->withHierarchy($a['program']);

        $this->assertSame('Program 1', $row['name']);
        $this->assertSame('College 1', $row['department_name']);
        $this->assertSame('S1', $row['department_code']);
        $this->assertSame('College 1', $row['college_name']);
        $this->assertSame('Inst 1', $row['institution_name']);
        $this->assertSame($a['institution'], (int) $row['institution_id']);
        $this->assertNull((new ProgramModel())->withHierarchy(999999));
    }

    public function testProgramsNeedADepartment(): void
    {
        $model = new ProgramModel();

        $this->assertFalse($model->insert(['name' => 'Orphan', 'code' => 'ORP', 'status' => 'active']));
        $this->assertArrayHasKey('department_id', $model->errors());
    }

    /*
    |--------------------------------------------------------------------------
    | Profile (the header and the dashboard's profile card)
    |--------------------------------------------------------------------------
    */

    public function testAStudentsProfileNamesTheCollegeOfTheirProgram(): void
    {
        $a = $this->chain('1');
        $student = CourseFactory::user('student');
        $this->profile($student->id, ['program_id' => $a['program'], 'student_number' => '2026-001']);

        $profile = (new UserProfileModel())->retrieveUserProfile($student);

        $this->assertSame('Program 1', $profile['program_name']);
        $this->assertSame('College 1', $profile['department_name']);
        $this->assertSame('College 1', $profile['college_name']);
        $this->assertSame('D1', $profile['college_code']);
    }

    public function testAnInstructorsCollegeIsThatOfTheFirstCourseTheyWereGiven(): void
    {
        $instructor = CourseFactory::user('instructor');
        $this->profile($instructor->id);
        $first = CourseFactory::offering('A');
        $second = CourseFactory::offering('B');
        CourseFactory::assignInstructor($first, $instructor);
        CourseFactory::assignInstructor($second, $instructor);

        $firstHome = (new ProgramModel())->withHierarchy($this->programOf($first));

        $profile = (new UserProfileModel())->retrieveUserProfile($instructor);

        $this->assertSame($firstHome['department_name'], $profile['teaching_department_name']);
        $this->assertSame($firstHome['college_name'], $profile['teaching_college_name']);
        $this->assertSame($firstHome['college_code'], $profile['teaching_college_code']);
    }

    public function testAnInstructorWhoTeachesNothingHasNoCollege(): void
    {
        $instructor = CourseFactory::user('instructor');
        $this->profile($instructor->id);

        $profile = (new UserProfileModel())->retrieveUserProfile($instructor);

        $this->assertNull($profile['teaching_department_name']);
        $this->assertNull($profile['teaching_college_name']);
    }

    public function testTheProfileCardShowsTheInstructorsCollegeAndNotAMadeUpOne(): void
    {
        $instructor = CourseFactory::user('instructor');
        $this->profile($instructor->id);
        $offering = CourseFactory::offering();
        $home = (new ProgramModel())->withHierarchy($this->programOf($offering));

        $render = static fn (array $data): string => view('pages/dashboard/partials/profile-card', ['user' => $instructor, 'data' => $data]);

        $taught = $render((new UserProfileModel())->retrieveUserProfile($instructor) + ['first_name' => 'Pat']);
        $this->assertStringNotContainsString('Department of Information Technology', $taught, 'no placeholder department');

        CourseFactory::assignInstructor($offering, $instructor);
        $withCourse = $render((new UserProfileModel())->retrieveUserProfile($instructor));
        $this->assertStringContainsString(esc($home['department_name']), $withCourse);
    }

    /*
    |--------------------------------------------------------------------------
    | Course queries
    |--------------------------------------------------------------------------
    */

    public function testCourseListsCarryTheCollegeAboveTheProgram(): void
    {
        $offering = CourseFactory::offering();
        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => 'active']);
        $student = CourseFactory::user('student');
        $instructor = CourseFactory::user('instructor');
        $this->profile($instructor->id);
        CourseFactory::enroll($offering, $student);
        CourseFactory::assignInstructor($offering, $instructor);
        $home = (new ProgramModel())->withHierarchy($this->programOf($offering));

        $rows = [
            (new EnrollmentModel())->retrieveStudentCourseOfferings($student->id)[0],
            (new EnrollmentModel())->retrieveStudentCourseOffering($student->id, $offering),
            (new InstructorAssignmentModel())->retrieveInstructorCourseOfferings($instructor->id)[0],
            (new InstructorAssignmentModel())->retrieveInstructorCourseOffering($instructor->id, $offering),
        ];

        foreach ($rows as $index => $row) {
            $this->assertSame($home['college_name'], $row['college_name'], "query #{$index}");
            $this->assertSame($home['department_name'], $row['department_name'], "query #{$index}");
            $this->assertSame($home['institution_name'], $row['institution_name'], "query #{$index}");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Chairperson assignments
    |--------------------------------------------------------------------------
    */

    public function testADepartmentsActiveChairIsRecognisedAndOthersAreNot(): void
    {
        $department = $this->chain('1')['department'];
        $otherDepartment = $this->chain('2')['department'];
        $chair = CourseFactory::user('chairperson');
        $notChair = CourseFactory::user('chairperson');
        $model = new ChairpersonAssignmentModel();

        $model->insert(['department_id' => $department, 'chairperson_user_id' => $chair->id, 'status' => 'active']);

        $this->assertTrue($model->isChairOf($chair->id, $department));
        $this->assertFalse($model->isChairOf($chair->id, $otherDepartment), 'holds no assignment to the other department');
        $this->assertFalse($model->isChairOf($notChair->id, $department), 'was never assigned at all');
    }

    public function testARevokedChairNoLongerCounts(): void
    {
        $department = $this->chain('1')['department'];
        $chair = CourseFactory::user('chairperson');
        $model = new ChairpersonAssignmentModel();

        $model->insert(['department_id' => $department, 'chairperson_user_id' => $chair->id, 'status' => 'revoked']);

        $this->assertFalse($model->isChairOf($chair->id, $department));
        $this->assertSame([], $model->departmentIdsFor($chair->id));
    }

    public function testADepartmentIdsForListsOnlyActiveAssignments(): void
    {
        $a = $this->chain('1')['department'];
        $b = $this->chain('2')['department'];
        $c = $this->chain('3')['department'];
        $chair = CourseFactory::user('chairperson');
        $model = new ChairpersonAssignmentModel();

        $model->insert(['department_id' => $a, 'chairperson_user_id' => $chair->id, 'status' => 'active']);
        $model->insert(['department_id' => $b, 'chairperson_user_id' => $chair->id, 'status' => 'active']);
        $model->insert(['department_id' => $c, 'chairperson_user_id' => $chair->id, 'status' => 'expired']);

        $this->assertSame([$a, $b], $model->departmentIdsFor($chair->id));
    }
}

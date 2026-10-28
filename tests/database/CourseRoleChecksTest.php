<?php

declare(strict_types=1);

use App\Services\Courses\AnnouncementService;
use App\Services\Courses\CourseService;
use App\Services\Courses\CourseViewer;
use App\Services\Storage\FileAccessService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;

/**
 * Every course check asks the person's role in that course, not the groups they hold (audit decision 9.1).
 * The person here teaches course A and is a student in course B.
 *
 * @internal
 */
final class CourseRoleChecksTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $taught;

    private int $studied;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        session()->destroy();
        service('courseContext')->clear();

        $this->taught  = CourseFactory::offering();
        $this->studied = CourseFactory::offering();

        $this->teacher = CourseFactory::user('instructor');
        $this->teacher->addGroup('student');
        CourseFactory::assignInstructor($this->taught, $this->teacher);
        CourseFactory::enroll($this->studied, $this->teacher);
        // The course lists read the person's profile and sign-in identity too.
        db_connect()->table('user_profiles')->insert(['user_id' => $this->teacher->id, 'first_name' => 'Tess', 'last_name' => 'Teacher']);
        db_connect()->table('auth_identities')->insert(['user_id' => $this->teacher->id, 'type' => 'email_password', 'secret' => 'tess' . $this->teacher->id . '@cr.test', 'secret2' => password_hash('x', PASSWORD_DEFAULT)]);
    }

    protected function tearDown(): void
    {
        // Signed in by one test, so later tests don't run as this person.
        if (auth()->loggedIn()) {
            auth()->logout();
        }

        service('courseContext')->clear();
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /** @return array{id: int, path: string} */
    private function activeFile(User $owner): array
    {
        $file = CourseFactory::file($owner);
        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);

        return $file;
    }

    public function testInTheCourseTheyStudyTheySeeTheStudentView(): void
    {
        $this->assertFalse(CourseViewer::seesInstructorView($this->teacher, $this->studied));
        $this->assertTrue(CourseViewer::seesInstructorView($this->teacher, $this->taught));
    }

    public function testTheCourseOfTheRequestDecidesWhenNoneIsNamed(): void
    {
        service('courseContext')->set($this->studied, 'student');

        $this->assertFalse(CourseViewer::seesInstructorView($this->teacher));
    }

    public function testTheirCourseCardsFollowTheRoleTheyAreActingAs(): void
    {
        auth()->login($this->teacher);
        session()->set('active_role', 'instructor');
        $this->assertSame([$this->taught], array_map('intval', array_column((new CourseService())->getCourseOfferings($this->teacher), 'offering_id')));

        session()->set('active_role', 'student');
        $this->assertSame([$this->studied], array_map('intval', array_column((new CourseService())->getCourseOfferings($this->teacher), 'offering_id')));
    }

    public function testADeanActingAsDeanIsNotAStudentOfACourseTheyLookAt(): void
    {
        // Studies elsewhere, and opens a department course as dean: the dean routes set no course of the request.
        $home = DeanFactory::department('DSTU');
        $dean = DeanFactory::dean($home['institution'], $home['department']);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $dean->id, 'group' => 'student', 'created_at' => date('Y-m-d H:i:s')]);
        $dean = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($dean->id);
        session()->set('active_role', 'dean');
        service('courseContext')->clear();

        $this->assertTrue(CourseViewer::isReadOnlyViewer($dean));
        $this->assertFalse(CourseViewer::isStudentHere($dean));
    }

    public function testTheCourseTheyTeachOpensForThem(): void
    {
        $this->assertNotNull((new CourseService())->getCourseOffering($this->teacher, $this->taught));
        $this->assertNotNull((new CourseService())->getCourseOffering($this->teacher, $this->studied));
    }

    public function testTheyCanOpenAFileTheCourseTheyStudyShares(): void
    {
        $owner = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->studied, $owner);
        $item = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->studied)), [[]])[0];
        $file = $this->activeFile($owner);
        CourseFactory::resource($item, 'file', null, $file['id']);

        $this->assertNotNull((new FileAccessService())->authorize($this->teacher, $file['id']));
    }

    public function testTheyCannotOpenAnotherStudentsWorkInTheCourseTheyStudy(): void
    {
        $owner   = CourseFactory::user('instructor');
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->studied, $student);
        $quiz = CourseFactory::assessment($this->studied, $owner, CourseFactory::module($this->studied), null, 'Assignment');
        $file = $this->activeFile($student);
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $quiz, 'student_id' => $student->id, 'attempt_number' => 1, 'status' => 'Submitted', 'submission_file_id' => $file['id'], 'started_at' => date('Y-m-d H:i:s')]);

        $this->assertNull((new FileAccessService())->authorize($this->teacher, $file['id']));
    }

    public function testAChairpersonStudyingAnotherSubDepartmentsCourseGetsTheStudentOverview(): void
    {
        ['department' => $dept, 'institution' => $institution] = DeanFactory::department('CO');
        $chair = DeanFactory::chair(DeanFactory::subDepartment($dept, 'CO'), 'Maria');
        $chair->addGroup('student');
        $elsewhere = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($dept, 'CP'), 'CP'), DeanFactory::term($institution), 'IT902');
        CourseFactory::enroll($elsewhere, $chair);
        service('courseContext')->set($elsewhere, 'student');

        $course   = (int) db_connect()->table('course_offerings')->where('id', $elsewhere)->get()->getRow('course_id');
        $overview = (new \App\Services\Courses\Assessments\AssessmentOverviewService())->build($chair, $elsewhere, $course);

        $this->assertSame('student', $overview['role']);
    }

    public function testAChairWhoAlsoHoldsTheInstructorRoleCanPostInTheirProgramsCourse(): void
    {
        ['department' => $dept, 'institution' => $institution] = DeanFactory::department('CK');
        $sub      = DeanFactory::subDepartment($dept, 'CK');
        $offering = DeanFactory::offering(DeanFactory::program($sub, 'CK'), DeanFactory::term($institution), 'IT901');
        $chair    = DeanFactory::chair($sub, 'Maria');
        $chair->addGroup('instructor');

        $this->assertTrue((new AnnouncementService())->create($chair, $offering, ['subject' => 'Welcome'])['success']);
    }
}

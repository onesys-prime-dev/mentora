<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * A course route names an offering in its address. Whoever asks must belong
 * to that offering in the role of the area they are in: its instructor, an
 * enrolled student, or the chairperson of its sub-department. Anyone else,
 * with a valid account and the right role, gets a 404 before any controller
 * runs: reads, writes and exports alike.
 *
 * @internal
 */
final class CourseMembershipTest extends RouteTestCase
{
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private int $mine;

    private int $theirs;

    private User $teacher;

    private User $student;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->mine   = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');

        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $this->teacher);

        $this->student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $this->student);

        // The chairperson of the sub-department of offering A only.
        $this->chair = CourseFactory::user('chairperson');
        $this->chairing($this->chair, $this->mine);

        // Someone is teaching and attending the other section, so it has content to leak.
        $otherTeacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->theirs, $otherTeacher);
        CourseFactory::module($this->theirs);
    }

    private function chairing(User $user, int $offeringId): void
    {
        $sub = (int) db_connect()->query(
            'SELECT sd.id FROM course_offerings o JOIN courses c ON c.id = o.course_id
               JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id WHERE o.id = ?',
            [$offeringId],
        )->getRow()->id;

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $sub, 'chairperson_user_id' => $user->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function as(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @return int the status the address answers with */
    private function answer(string $verb, string $uri): int
    {
        try {
            return $this->step($verb, $uri)->response()->getStatusCode();
        } catch (CodeIgniter\Exceptions\PageNotFoundException) {
            return 404;
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideAreas(): iterable
    {
        foreach (['student', 'instructor', 'chairperson'] as $area) {
            yield $area => [$area, $area];
        }
    }

    #[DataProvider('provideAreas')]
    public function testEveryCourseRouteOfTheAreaIsBehindTheMembershipCheck(string $area): void
    {
        $routes  = service('routes')->loadRoutes();
        $pattern = '#^' . $area . '/(api/)?courses/\(\[0-9\]\+\)#';
        $seen    = 0;
        $missing = [];

        foreach (self::VERBS as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                if (preg_match($pattern, $route) !== 1) {
                    continue;
                }

                $seen++;
                $filters = (array) ($routes->getRoutesOptions($route, $verb)['filter'] ?? []);

                if (! in_array('coursemember:' . $area, $filters, true)) {
                    $missing[] = "{$verb} {$route}";
                }
            }
        }

        $this->assertGreaterThan(10, $seen, 'the route table was not read');
        $this->assertSame([], $missing, 'these routes name an offering but do not check the person belongs to it');
    }

    public function testAnInstructorOnlyReachesTheOfferingsTheyTeach(): void
    {
        $this->as($this->teacher);

        foreach (['modules', 'grades', 'grades/scheme', 'grades/roster', 'grades/export', 'grades/report/class', 'grades/report/summary', 'overview', 'announcements', 'resources', 'discussions', 'assessments'] as $tab) {
            $this->assertSame(404, $this->answer('GET', "instructor/api/courses/{$this->theirs}/{$tab}"), "their {$tab}");
        }

        $this->assertSame(200, $this->answer('GET', "instructor/api/courses/{$this->mine}/modules"));
        $this->assertSame(200, $this->answer('GET', "instructor/api/courses/{$this->mine}/grades"));
    }

    public function testAStudentOnlyReachesTheOfferingsTheyAreIn(): void
    {
        $this->as($this->student);

        foreach (['modules', 'grades', 'overview', 'announcements', 'resources', 'discussions', 'assessments'] as $tab) {
            $this->assertSame(404, $this->answer('GET', "student/api/courses/{$this->theirs}/{$tab}"), "their {$tab}");
        }

        $this->assertSame(200, $this->answer('GET', "student/api/courses/{$this->mine}/modules"));
        $this->assertSame(200, $this->answer('GET', "student/api/courses/{$this->mine}/grades"));
    }

    public function testAChairpersonOnlyReachesTheirOwnSubDepartment(): void
    {
        $this->as($this->chair);

        foreach (['modules', 'grades', 'grades/scheme', 'grades/roster', 'grades/export', 'overview'] as $tab) {
            $this->assertSame(404, $this->answer('GET', "chairperson/api/courses/{$this->theirs}/{$tab}"), "their {$tab}");
        }

        $this->assertSame(200, $this->answer('GET', "chairperson/api/courses/{$this->mine}/modules"));
        $this->assertSame(200, $this->answer('GET', "chairperson/api/courses/{$this->mine}/grades/roster"));
    }

    public function testTheAddressesOfTheCoursePagesAreCheckedToo(): void
    {
        $this->as($this->teacher);

        $this->assertSame(404, $this->answer('GET', "instructor/courses/{$this->theirs}/x"));
        $this->assertSame(404, $this->answer('GET', "instructor/courses/{$this->theirs}/x/modules"));
        $this->assertSame(404, $this->answer('GET', "instructor/courses/{$this->theirs}/x/modules/items/1"));
    }

    public function testWritesAreRefusedToo(): void
    {
        $this->as($this->teacher);

        $this->assertSame(404, $this->answer('POST', "instructor/api/courses/{$this->theirs}/modules"));
        $this->assertSame(404, $this->answer('PATCH', "instructor/api/courses/{$this->theirs}/modules/reorder"));
        $this->assertSame(404, $this->answer('DELETE', "instructor/api/courses/{$this->theirs}/modules/1"));
        $this->assertSame(404, $this->answer('POST', "instructor/api/courses/{$this->theirs}/announcements"));
    }

    public function testAStudentWhoDroppedTheCourseIsNoLongerInIt(): void
    {
        db_connect()->table('enrollments')->where(['offering_id' => $this->mine, 'student_user_id' => $this->student->id])->update(['status' => 'dropped']);

        $this->as($this->student);

        $this->assertSame(404, $this->answer('GET', "student/api/courses/{$this->mine}/modules"));
    }

    public function testAnInstructorWhoIsAlsoChairpersonReachesTheProgramAsChairpersonOnly(): void
    {
        $both = CourseFactory::user('instructor');
        db_connect()->table('auth_groups_users')->insert(['user_id' => $both->id, 'group' => 'chairperson', 'created_at' => date('Y-m-d H:i:s')]);
        $both = model(CodeIgniter\Shield\Models\UserModel::class)->findById($both->id);
        $this->chairing($both, $this->mine);

        $this->as($both);

        // As chairperson, the program's course (which they do not teach) opens.
        $this->assertSame(200, $this->answer('GET', "chairperson/api/courses/{$this->mine}/modules"));

        // As instructor it is not theirs to teach (and the instructor area is not the one they are acting in).
        $this->assertNotSame(200, $this->answer('GET', "instructor/api/courses/{$this->mine}/modules"));
    }

    public function testTheLearningItemAddressOpensTheModulesInsteadOfFailing(): void
    {
        $this->as($this->teacher);

        $response = $this->step('GET', "instructor/courses/{$this->mine}/x/modules/items/1")->response();

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }
}

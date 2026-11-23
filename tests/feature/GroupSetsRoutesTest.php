<?php

declare(strict_types=1);

use App\Filters\CoursePermissionFilter;
use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\RouteTestCase;

/**
 * The group-set endpoints of a course: instructor and chairperson change them, the dean only reads, students never reach them.
 *
 * @internal
 */
final class GroupSetsRoutesTest extends RouteTestCase
{
    private int $offering;

    private int $other;

    private User $teacher;

    private User $stranger;

    private User $student;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $this->other    = CourseFactory::offering('B');
        $this->teacher  = CourseFactory::user('instructor');
        $this->stranger = CourseFactory::user('instructor');
        $this->student  = CourseFactory::user('student');
        $this->chair    = CourseFactory::user('chairperson');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::assignInstructor($this->other, $this->stranger);
        CourseFactory::enroll($this->offering, $this->student);

        db_connect()->table('chairperson_assignments')->insert([
            'department_id'       => (int) db_connect()->query('SELECT p.department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$this->offering])->getRow()->department_id,
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{int, array<string, mixed>} status and decoded JSON body */
    private function api(User $user, string $area, string $verb, string $path, array $body = [], ?int $offering = null): array
    {
        $this->loggedInAs($user);

        try {
            $res = $this->withBody(json_encode($body))
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
                ->call($verb, "{$area}/api/courses/" . ($offering ?? $this->offering) . "/group-sets{$path}");
        } catch (PageNotFoundException) {
            return [404, []];
        }

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true) ?? []];
    }

    private function createSet(string $area = 'instructor', ?User $as = null): int
    {
        [$status, $json] = $this->api($as ?? $this->teacher, $area, 'POST', '', ['name' => 'Lab teams', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 3]);
        $this->assertSame(200, $status, (string) json_encode($json));

        return (int) $json['id'];
    }

    public function testInstructorRunsASetFromCreationToArchive(): void
    {
        $set = $this->createSet();

        [, $list] = $this->api($this->teacher, 'instructor', 'GET', '');
        $this->assertSame('Lab teams', $list['sets'][0]['name']);
        $this->assertSame(0, (int) $list['sets'][0]['group_count']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', "/{$set}", ['name' => 'Renamed', 'min_size' => 1, 'max_size' => 2])[0]);

        [$status, $g] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups", ['name' => 'Team 1']);
        $this->assertSame(200, $status);
        $g1 = (int) $g['id'];
        $g2 = (int) $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups", ['name' => 'Team 2'])[1]['id'];

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'PUT', "/{$set}/groups/{$g1}", ['name' => 'Alpha'])[0]);

        $sid = (int) $this->student->id;
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups/{$g1}/members", ['student_user_id' => $sid])[0]);

        [, $detail] = $this->api($this->teacher, 'instructor', 'GET', "/{$set}");
        $this->assertSame('Renamed', $detail['set']['name']);
        $this->assertSame('Alpha', $detail['groups'][0]['name']);
        $this->assertSame($sid, (int) $detail['groups'][0]['members'][0]['student_user_id']);
        $this->assertSame([], $detail['ungrouped']);
        $this->assertSame(30, (int) $detail['limits']['max_members']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$set}/members/move", ['student_user_id' => $sid, 'to_group_id' => $g2])[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/{$set}/members/{$sid}")[0]);

        [, $detail] = $this->api($this->teacher, 'instructor', 'GET', "/{$set}");
        $this->assertSame($sid, (int) $detail['ungrouped'][0]['student_user_id']);

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'DELETE', "/{$set}/groups/{$g2}")[0]);
        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$set}/archive")[0]);
        $this->assertSame('archived', db_connect()->table('group_sets')->where('id', $set)->get()->getRow()->status);
    }

    public function testAFullGroupAnswers422WithTheMessage(): void
    {
        $set = $this->createSet();
        $this->api($this->teacher, 'instructor', 'PUT', "/{$set}", ['name' => 'Lab teams', 'min_size' => 1, 'max_size' => 1]);
        $g      = (int) $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups", ['name' => 'T'])[1]['id'];
        $second = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $second);

        $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups/{$g}/members", ['student_user_id' => (int) $this->student->id]);
        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups/{$g}/members", ['student_user_id' => (int) $second->id]);

        $this->assertSame(422, $status);
        $this->assertNotSame('', (string) ($json['message'] ?? ''));
        $this->assertArrayHasKey('errors', $json);
    }

    public function testAnInvalidSetAnswers422WithFieldErrors(): void
    {
        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', '', ['name' => '']);

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('name', $json['errors']);
    }

    public function testASetOfAnotherOfferingAndBadIdsAre404(): void
    {
        $theirs = (new GroupSetService())->create($this->other, ['name' => 'Theirs'], (int) $this->stranger->id);

        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', "/{$theirs}")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'PUT', "/{$theirs}", ['name' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$theirs}/archive")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$theirs}/groups", ['name' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '/99999')[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'GET', '/abc')[0]);

        // A group of another set, addressed through this offering's set.
        $mine  = $this->createSet();
        $group = (new GroupSetService())->addGroup($theirs, 'G', (int) $this->stranger->id);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'PUT', "/{$mine}/groups/{$group}", ['name' => 'x'])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'DELETE', "/{$mine}/groups/{$group}")[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$mine}/groups/{$group}/members", ['student_user_id' => (int) $this->student->id])[0]);
        $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$mine}/members/move", ['student_user_id' => (int) $this->student->id, 'to_group_id' => $group])[0]);
    }

    public function testAnInstructorOfAnotherCourseIsRefused(): void
    {
        $set = $this->createSet();

        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'GET', '')[0]);
        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'GET', "/{$set}")[0]);
        $this->assertSame(404, $this->api($this->stranger, 'instructor', 'POST', '', ['name' => 'x'])[0]);
    }

    public function testAStudentReachesNoRoute(): void
    {
        $set = $this->createSet();

        foreach ([['GET', ''], ['POST', ''], ['GET', "/{$set}"], ['PUT', "/{$set}"], ['POST', "/{$set}/archive"], ['POST', "/{$set}/groups"], ['DELETE', "/{$set}/members/1"]] as [$verb, $path]) {
            $this->assertContains($this->api($this->student, 'student', $verb, $path)[0], [403, 404], "{$verb} {$path}");
            $this->assertContains($this->api($this->student, 'instructor', $verb, $path)[0], [302, 403, 404], "instructor area {$verb} {$path}");
        }
    }

    public function testTheDeanReadsButCannotChange(): void
    {
        $set = $this->createSet();

        $inst    = (int) db_connect()->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $college = (int) db_connect()->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow()->college_id;
        $dean    = DeanFactory::dean($inst, $college);

        $this->assertSame(200, $this->api($dean, 'dean', 'GET', '')[0]);
        $this->assertSame(200, $this->api($dean, 'dean', 'GET', "/{$set}")[0]);

        foreach ([['POST', ''], ['PUT', "/{$set}"], ['POST', "/{$set}/archive"], ['POST', "/{$set}/groups"], ['POST', "/{$set}/members/move"], ['DELETE', "/{$set}/members/1"]] as [$verb, $path]) {
            $this->assertContains($this->api($dean, 'dean', $verb, $path, ['name' => 'x'])[0], [403, 404, 405], "{$verb} {$path}");
        }
    }

    public function testTheChairpersonOfTheCourseDoesWhatTheInstructorDoes(): void
    {
        $set = $this->createSet('chairperson', $this->chair);

        [$status, $g] = $this->api($this->chair, 'chairperson', 'POST', "/{$set}/groups", ['name' => 'C1']);
        $this->assertSame(200, $status);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/{$set}/groups/{$g['id']}/members", ['student_user_id' => (int) $this->student->id])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'GET', "/{$set}")[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/{$set}/archive")[0]);
    }

    public function testWithoutASessionTheRoutesAreRefused(): void
    {
        auth()->logout();

        try {
            $status = $this->withHeaders(['Accept' => 'application/json'])->call('GET', "instructor/api/courses/{$this->offering}/group-sets")->response()->getStatusCode();
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertContains($status, [302, 401, 403, 404]);
    }

    /** @return list<User> */
    private function moreStudents(int $n): array
    {
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $u = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $u);
            $out[] = $u;
        }

        return $out;
    }

    public function testPreviewWritesNothingAndSaveCreatesTheGroups(): void
    {
        $this->moreStudents(3); // pool of 4
        $set    = $this->createSet();
        $before = [db_connect()->table('student_groups')->countAllResults(), db_connect()->table('student_group_members')->countAllResults()];

        [$status, $p] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate/preview", ['by' => 'count', 'n' => 2, 'seed' => 7]);

        $this->assertSame(200, $status, (string) json_encode($p));
        $this->assertCount(2, $p['groups']);
        $this->assertSame(0, $p['replaces']);
        $this->assertNotSame('', $p['groups'][0]['members'][0]['name']);
        $this->assertSame($before, [db_connect()->table('student_groups')->countAllResults(), db_connect()->table('student_group_members')->countAllResults()]);

        $ids = array_map(static fn ($g) => array_column($g['members'], 'student_user_id'), $p['groups']);
        [$status] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate", ['groups' => $ids, 'replace' => false]);

        $this->assertSame(200, $status);
        $this->assertSame(2, db_connect()->table('student_groups')->where('set_id', $set)->countAllResults());
        $this->assertSame(4, db_connect()->table('student_group_members')->where('set_id', $set)->countAllResults());

        [, $again] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate/preview", ['by' => 'count', 'n' => 2]);
        $this->assertSame(2, $again['replaces']);
    }

    public function testSavingOverExistingGroupsNeedsReplace(): void
    {
        $set = $this->createSet();
        $this->api($this->teacher, 'instructor', 'POST', "/{$set}/groups", ['name' => 'Old']);
        $ids = [[(int) $this->student->id]];

        [$status, $json] = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate", ['groups' => $ids]);
        $this->assertSame(422, $status);
        $this->assertNotSame('', (string) $json['message']);
        $this->assertSame(1, db_connect()->table('student_groups')->where('set_id', $set)->countAllResults());

        $this->assertSame(200, $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate", ['groups' => $ids, 'replace' => true])[0]);
        $this->assertSame('Group 1', db_connect()->table('student_groups')->where('set_id', $set)->get()->getRow()->name);
    }

    public function testPreviewWithBadInputIs422(): void
    {
        $set = $this->createSet();

        $this->assertSame(422, $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate/preview", ['by' => 'size', 'n' => 0])[0]);
    }

    public function testGenerateIsRefusedForTheWrongPeople(): void
    {
        $set    = $this->createSet();
        $theirs = (new GroupSetService())->create($this->other, ['name' => 'Theirs'], (int) $this->stranger->id);
        $body   = ['by' => 'size', 'n' => 1, 'groups' => [[(int) $this->student->id]]];

        foreach (['/generate/preview', '/generate'] as $tail) {
            $this->assertSame(404, $this->api($this->teacher, 'instructor', 'POST', "/{$theirs}{$tail}", $body)[0], 'set of another offering');
            $this->assertSame(404, $this->api($this->stranger, 'instructor', 'POST', "/{$set}{$tail}", $body)[0], 'instructor of another course');
            $this->assertContains($this->api($this->student, 'student', 'POST', "/{$set}{$tail}", $body)[0], [403, 404]);
            $this->assertContains($this->api($this->student, 'instructor', 'POST', "/{$set}{$tail}", $body)[0], [302, 403, 404]);
        }

        $inst    = (int) db_connect()->query('SELECT t.institution_id FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $college = (int) db_connect()->query('SELECT d.college_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments d ON d.id = p.department_id WHERE o.id = ?', [$this->offering])->getRow()->college_id;
        $dean    = DeanFactory::dean($inst, $college);

        foreach (['/generate/preview', '/generate'] as $tail) {
            $this->assertContains($this->api($dean, 'dean', 'POST', "/{$set}{$tail}", $body)[0], [403, 404, 405]);
        }

        $this->assertSame(0, db_connect()->table('student_groups')->where('set_id', $set)->countAllResults());
    }

    public function testSaveIsRefusedOnceGradesAreFinalized(): void
    {
        $set = $this->createSet();
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => $this->teacher->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $status = $this->api($this->teacher, 'instructor', 'POST', "/{$set}/generate", ['groups' => [[(int) $this->student->id]]])[0];

        $this->assertContains($status, [403, 409, 422, 423]);
        $this->assertSame(0, db_connect()->table('student_groups')->where('set_id', $set)->countAllResults());
    }

    public function testTheChairpersonCanGenerate(): void
    {
        $set = $this->createSet('chairperson', $this->chair);

        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/{$set}/generate/preview", ['by' => 'size', 'n' => 1])[0]);
        $this->assertSame(200, $this->api($this->chair, 'chairperson', 'POST', "/{$set}/generate", ['groups' => [[(int) $this->student->id]]])[0]);
    }

    public function testTheFilterAsksTheContentPermissionForGroupWrites(): void
    {
        $this->assertSame('courses.manage', CoursePermissionFilter::required('group-sets/4/groups'));
        $this->assertSame('courses.manage', CoursePermissionFilter::required('group-sets/4/generate'));
        $this->assertSame('courses.manage', CoursePermissionFilter::required('group-sets/4/generate/preview'));
    }
}

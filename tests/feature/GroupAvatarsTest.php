<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupSetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Group member lists (the Groups tab set detail, an activity's groups, a student's own group) give each member an
 * `avatar` from one AvatarData::forViewer lookup per response: interactive only for someone the viewer may see,
 * otherwise the legal name, no photo and no preferred name (FR2, FR3).
 *
 * @internal
 */
final class GroupAvatarsTest extends RouteTestCase
{
    private int $offering;

    private int $set;

    private int $team;

    private int $assignment;

    private int $config;

    private User $teacher;

    private User $me;

    private User $mate;

    private User $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->offering = CourseFactory::offering('A');
        $module         = CourseFactory::module($this->offering);
        $this->teacher  = CourseFactory::user('instructor');
        $this->me       = CourseFactory::user('student');
        $this->mate     = CourseFactory::user('student');
        $this->hidden   = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        foreach ([$this->me, $this->mate, $this->hidden] as $student) {
            CourseFactory::enroll($this->offering, $student);
        }
        CourseFactory::members($this->offering, [$this->teacher, $this->me, $this->mate]);
        // In the course but in no institution: no one here may open this student's card.
        CourseFactory::members($this->offering, [$this->hidden], false);
        db_connect()->table('user_profiles')->where('user_id', $this->hidden->id)->update(['first_name' => 'Hal', 'last_name' => 'Hidden', 'preferred_first_name' => 'Zedekiah']);
        db_connect()->table('user_profiles')->where('user_id', $this->mate->id)->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'preferred_first_name' => 'Jun']);

        $sets      = new GroupSetService();
        $this->set = $sets->create($this->offering, ['name' => 'Teams', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], (int) $this->teacher->id);
        $this->team = $sets->addGroup($this->set, 'Team 1', (int) $this->teacher->id);
        foreach ([$this->me, $this->mate, $this->hidden] as $student) {
            db_connect()->table('student_group_members')->insert(['group_id' => $this->team, 'set_id' => $this->set, 'config_id' => null, 'student_user_id' => $student->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $this->teacher, $module, null, 'Assignment');
        $this->config     = (new ActivityGroupService())->attachAssessment($this->assignment, $this->set, [], (int) $this->teacher->id);
    }

    /** @return array<string, mixed> */
    private function getJson(User $user, string $uri): array
    {
        $this->loggedInAs($user);
        $res = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call('GET', $uri);
        $this->assertSame(200, $res->response()->getStatusCode(), $uri);

        return json_decode((string) $res->response()->getBody(), true);
    }

    /**
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed>       $json
     */
    private function assertAvatars(array $members, string $idKey, array $json): void
    {
        $byId = array_column($members, 'avatar', $idKey);
        // FR5: the member's shown name is the gated one, preferred only for someone the viewer may see.
        $names = array_column($members, 'name', $idKey);
        $this->assertSame('Jun Dela Cruz', $names[(int) $this->mate->id]);
        $this->assertSame('Hal Hidden', $names[(int) $this->hidden->id]);

        $this->assertTrue($byId[(int) $this->mate->id]['interactive']);
        $this->assertSame((int) $this->mate->id, $byId[(int) $this->mate->id]['id']);

        $hidden = $byId[(int) $this->hidden->id];
        $this->assertFalse($hidden['interactive']);
        $this->assertSame('Hal Hidden', $hidden['name']);
        $this->assertNull($hidden['photoUrl']);
        $this->assertNull($hidden['photoUrlLg']);
        $this->assertStringNotContainsString('Zedekiah', (string) json_encode($json), 'no preferred name of a hidden person anywhere');
    }

    public function testTheSetDetailGivesEachMemberTheirAvatar(): void
    {
        $json = $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/group-sets/{$this->set}");

        $this->assertAvatars($json['groups'][0]['members'], 'student_user_id', $json);
    }

    public function testAnActivitysGroupsGiveEachMemberTheirAvatar(): void
    {
        $json = $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/groups");

        $this->assertAvatars($json['groups'][0]['members'], 'student_user_id', $json);
    }

    public function testAStudentsOwnGroupGivesEachMemberTheirAvatar(): void
    {
        $json = $this->getJson($this->me, "student/api/courses/{$this->offering}/assessments/{$this->assignment}/my-group");

        $this->assertAvatars($json['myGroup']['members'], 'id', $json);
    }

    public function testGroupGradingGivesEachMemberTheirAvatarAndName(): void
    {
        $group = (int) db_connect()->table('student_groups')->where('config_id', $this->config)->get()->getRowArray()['id'];
        $json  = $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/group-grading/{$group}");

        $this->assertAvatars($json['members'], 'student_user_id', $json);
    }

    public function testGroupGradingNamesTheSubmitterAsTheViewerMaySeeThem(): void
    {
        $group = (int) db_connect()->table('student_groups')->where('config_id', $this->config)->get()->getRowArray()['id'];
        $url   = "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/group-grading/{$group}";

        \Tests\Support\AssessmentFactory::attempt($this->assignment, $this->mate, 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->mate->id]);
        $this->assertSame('Jun Dela Cruz', $this->getJson($this->teacher, $url)['submission']['submittedBy']['name']);

        db_connect()->table('assessment_submissions')->where('assessment_id', $this->assignment)->delete();
        \Tests\Support\AssessmentFactory::attempt($this->assignment, $this->hidden, 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->hidden->id]);
        $json = $this->getJson($this->teacher, $url);
        $this->assertSame('Hal Hidden', $json['submission']['submittedBy']['name']);
        $this->assertStringNotContainsString('Zedekiah', (string) json_encode($json));
    }

    public function testGroupGradingGivesUngroupedStudentsTheirAvatarAndName(): void
    {
        db_connect()->table('student_group_members')->where('config_id', $this->config)->whereIn('student_user_id', [$this->mate->id, $this->hidden->id])->delete();

        $json = $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/assessments/{$this->assignment}/group-grading");

        $this->assertAvatars($json['ungrouped'], 'student_user_id', $json);
    }

    public function testTheSetDetailLooksAvatarsUpOnceWhateverTheNumberOfMembers(): void
    {
        $lookups = function (): int {
            $n = 0;
            $l = static function (Query $q) use (&$n): void {
                $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
            };
            Events::on('DBQuery', $l);
            $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/group-sets/{$this->set}");
            Events::removeListener('DBQuery', $l);

            return $n;
        };

        $few = $lookups();

        for ($i = 0; $i < 4; $i++) {
            $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
            CourseFactory::members($this->offering, [$student]);
        }

        $this->assertSame(1, $few);
        $this->assertSame($few, $lookups());
    }

    public function testTheSetDetailListsMembersByTheNameItShows(): void
    {
        // Legally "Juan" sorts after "Hal"; shown as "Abe" he comes first.
        db_connect()->table('user_profiles')->where('user_id', $this->mate->id)->update(['preferred_first_name' => 'Abe']);

        $json = $this->getJson($this->teacher, "instructor/api/courses/{$this->offering}/group-sets/{$this->set}");

        $this->assertSame(['Abe Dela Cruz', 'Hal Hidden', 'Member No' . $this->me->id], array_column($json['groups'][0]['members'], 'name'));
    }

    public function testTheGeneratePreviewGivesEachProposedMemberTheirAvatarAndNameFromOneLookup(): void
    {
        $n = 0;
        $l = static function (Query $q) use (&$n): void {
            $n += str_contains($q->getQuery(), 'preferred_first_name') ? 1 : 0;
        };
        $this->loggedInAs($this->teacher);
        Events::on('DBQuery', $l);
        $res = $this->withBody(json_encode(['by' => 'count', 'n' => 1, 'seed' => 7]))
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('POST', "instructor/api/courses/{$this->offering}/group-sets/{$this->set}/generate/preview");
        Events::removeListener('DBQuery', $l);
        $this->assertSame(200, $res->response()->getStatusCode());
        $json = json_decode((string) $res->response()->getBody(), true);

        $this->assertAvatars($json['groups'][0]['members'], 'student_user_id', $json);
        $this->assertSame(['Hal Hidden', 'Jun Dela Cruz', 'Member No' . $this->me->id], array_column($json['groups'][0]['members'], 'name'), 'by the name shown');
        $this->assertSame(1, $n);
    }
}

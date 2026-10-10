<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * R7 of the chairperson plan: same view/edit/grade/announce access as the
 * instructor, for every course in the chairperson's program — and nothing
 * outside it. Every mutation leaves an audit trail.
 *
 * @internal
 */
final class ChairpersonCourseAccessTest extends RouteTestCase
{
    private int $mine;
    private int $elsewhere;
    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = CourseFactory::offering('A');
        $this->elsewhere = CourseFactory::offering('B');

        $this->chair = CourseFactory::user('chairperson');

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'   => $this->subDepartmentOf($this->mine),
            'chairperson_user_id' => $this->chair->id,
            'status'              => 'active',
            'assigned_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function subDepartmentOf(int $offeringId): int
    {
        return (int) db_connect()->table('course_offerings co')
            ->select('programs.sub_department_id')
            ->join('courses', 'courses.id = co.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray()['sub_department_id'];
    }

    private function api(string $verb, int $offering, string $path, array $body = [])
    {
        $this->loggedInAs($this->chair);

        return $this->withBody(json_encode($body))
            ->withHeaders(['Content-Type' => 'application/json'])
            ->call($verb, "chairperson/api/courses/{$offering}{$path}");
    }

    public function testAChairpersonCanViewACourseInTheirProgramTheyDoNotTeach(): void
    {
        $this->loggedInAs($this->chair);

        $result = $this->get("chairperson/courses/{$this->mine}/any-title/overview");

        $result->assertStatus(200);
    }

    public function testAChairpersonIsDeniedACourseOutsideTheirProgram(): void
    {
        $this->loggedInAs($this->chair);

        $this->expectException(PageNotFoundException::class);

        $this->get("chairperson/courses/{$this->elsewhere}/any-title/overview");
    }

    public function testAChairpersonCanAnnounceInTheirProgram(): void
    {
        $response = $this->api('post', $this->mine, '/announcements', ['subject' => 'Heads up', 'message' => '{}']);

        $response->assertStatus(200);
        $this->assertSame(1, db_connect()->table('announcements')->where('offering_id', $this->mine)->countAllResults());
    }

    public function testAChairpersonCannotAnnounceOutsideTheirProgram(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->api('post', $this->elsewhere, '/announcements', ['subject' => 'Heads up', 'message' => '{}']);
    }

    public function testAChairpersonCanEditAnAnnouncementInTheirProgram(): void
    {
        $id = (int) json_decode((string) $this->api('post', $this->mine, '/announcements', ['subject' => 'Original', 'message' => '{}'])->getJSON(), true)['announcement_id'];

        $response = $this->api('patch', $this->mine, "/announcements/{$id}", ['subject' => 'Updated']);

        $response->assertStatus(200);
        $this->assertSame('Updated', db_connect()->table('announcements')->where('id', $id)->get()->getRowArray()['subject']);
    }

    public function testAChairpersonCanGradeASubmissionInTheirProgram(): void
    {
        $module = CourseFactory::module($this->mine);
        $assessment = CourseFactory::assessment($this->mine, $this->chair, $module, null, 'Assignment');
        AssessmentFactory::open($assessment, ['total_points' => 10]);
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $student);
        $submissionId = AssessmentFactory::attempt($assessment, $student, 'Submitted');

        $response = $this->api('patch', $this->mine, "/submissions/{$submissionId}/grade", ['score' => 8]);

        $response->assertStatus(200);
        $row = db_connect()->table('assessment_submissions')->where('id', $submissionId)->get()->getRowArray();
        $this->assertSame('Graded', $row['status']);
        $this->assertSame((string) $this->chair->id, (string) $row['graded_by']);
    }

    public function testAChairpersonsMutationIsAudited(): void
    {
        $this->api('post', $this->mine, '/announcements', ['subject' => 'Audited', 'message' => '{}']);

        $entry = db_connect()->table('audit_logs')
            ->where('actor_user_id', $this->chair->id)
            ->where('action', 'course.chairperson_action')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        $this->assertNotNull($entry, 'a mutation must leave an audit entry');
        $this->assertSame('chairperson', $entry['acting_role']);
        $this->assertSame((string) $this->mine, (string) $entry['target_id']);
    }

    public function testReadingDoesNotCreateAnAuditEntry(): void
    {
        $this->api('get', $this->mine, '/announcements');

        $this->assertSame(
            0,
            db_connect()->table('audit_logs')
                ->where('actor_user_id', $this->chair->id)
                ->where('action', 'course.chairperson_action')
                ->countAllResults(),
            'a read must never be audited'
        );
    }
}

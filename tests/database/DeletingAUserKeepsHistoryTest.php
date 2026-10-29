<?php

declare(strict_types=1);

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Deleting an account keeps the records of what that person did (QA PLN-02):
 * the grade-chain rows they acted on stay, with the actor left empty, and a
 * file that a learning resource still uses cannot be deleted from under it.
 *
 * @internal
 */
final class DeletingAUserKeepsHistoryTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;
    private int $department;
    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        ['institution' => $this->institution, 'department' => $this->department] = DeanFactory::department('H');
        $program        = DeanFactory::program(DeanFactory::subDepartment($this->department, 'H'), 'H');
        $this->offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'HIS101');
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    private function actor(): int
    {
        return (int) PeopleFactory::person('instructor', 'Gone', 'Person', null, $this->institution)->id;
    }

    private function deleteUser(int $id): void
    {
        db_connect()->table('users')->where('id', $id)->delete();
    }

    private function row(string $table, int $id): ?array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    public function testADeanAssignmentStaysWhenTheOneWhoMadeItIsDeleted(): void
    {
        $actor = $this->actor();
        $dean  = (int) PeopleFactory::person('dean', 'Dana', 'Dean', null, $this->institution)->id;
        db_connect()->table('dean_assignments')->insert(['department_id' => $this->department, 'dean_user_id' => $dean, 'assigned_by' => $actor, 'assigned_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'active_marker' => 1]);
        $id = (int) db_connect()->insertID();

        $this->deleteUser($actor);

        $this->assertNotNull($this->row('dean_assignments', $id));
        $this->assertNull($this->row('dean_assignments', $id)['assigned_by']);
    }

    public function testAnOverrideRequestStaysWhenEveryoneWhoActedOnItIsDeleted(): void
    {
        $student = CourseFactory::user('student');
        $actors  = ['requested_by' => $this->actor(), 'endorsed_by' => $this->actor(), 'returned_by' => $this->actor(), 'decided_by' => $this->actor()];
        db_connect()->table('grade_override_requests')->insert($actors + [
            'offering_id' => $this->offering, 'student_id' => $student->id, 'reason' => 'Late work', 'requester_role' => 'instructor', 'status' => 'approved',
        ]);
        $id = (int) db_connect()->insertID();

        foreach ($actors as $actor) {
            $this->deleteUser($actor);
        }

        $row = $this->row('grade_override_requests', $id);
        $this->assertNotNull($row);

        foreach (array_keys($actors) as $column) {
            $this->assertNull($row[$column], $column);
        }
    }

    public function testASignOffStaysWhenItsSignerAndReopenerAreDeleted(): void
    {
        [$signer, $reopener] = [$this->actor(), $this->actor()];
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $this->offering, 'signed_by' => $signer, 'signed_at' => date('Y-m-d H:i:s'), 'reopened_by' => $reopener]);
        $id = (int) db_connect()->insertID();

        $this->deleteUser($signer);
        $this->deleteUser($reopener);

        $row = $this->row('grade_signoffs', $id);
        $this->assertNotNull($row);
        $this->assertNull($row['signed_by']);
        $this->assertNull($row['reopened_by']);
    }

    public function testADepartmentAnnouncementStaysWhenItsAuthorAndWhoEndedItAreDeleted(): void
    {
        [$author, $ender] = [$this->actor(), $this->actor()];
        db_connect()->table('department_announcements')->insert([
            'institution_id' => $this->institution, 'department_id' => $this->department, 'created_by' => $author, 'ended_by' => $ender,
            'scope_type' => 'department', 'scope_id' => $this->department, 'title' => 'Notice', 'message' => 'Read me',
        ]);
        $id = (int) db_connect()->insertID();

        $this->deleteUser($author);
        $this->deleteUser($ender);

        $row = $this->row('department_announcements', $id);
        $this->assertNotNull($row);
        $this->assertNull($row['created_by']);
        $this->assertNull($row['ended_by']);
    }

    public function testAFileALearningResourceUsesCannotBeDeleted(): void
    {
        $owner  = CourseFactory::user('instructor');
        $file   = CourseFactory::file($owner);
        $unit   = CourseFactory::unit(CourseFactory::module($this->offering));
        $item   = CourseFactory::items($unit, [['type' => 'pdf']])[0];
        CourseFactory::resource($item, 'pdf', null, (int) $file['id']);

        try {
            db_connect()->table('files')->where('id', (int) $file['id'])->delete();
        } catch (DatabaseException) {
            // Refused either way: an exception or a false return, depending on DBDebug.
        }

        $this->assertNotNull($this->row('files', (int) $file['id']));
    }
}

<?php

declare(strict_types=1);

use App\Database\Migrations\OneInstitutionPerAccount;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

// A migration's file name starts with its timestamp, so the autoloader can't find it by class name.
require_once APPPATH . 'Database/Migrations/2026-11-13-000001_OneInstitutionPerAccount.php';

/**
 * One account belongs to one institution (decision D1).
 *
 * @internal
 */
final class OneInstitutionPerAccountTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testItKeepsTheOldestMembershipInAnInstitutionThatStillExists(): void
    {
        $drop = OneInstitutionPerAccount::membershipsToDrop([
            ['id' => 5, 'user_id' => 1, 'deleted' => true],
            ['id' => 7, 'user_id' => 1, 'deleted' => false],
            ['id' => 9, 'user_id' => 1, 'deleted' => false],
            ['id' => 3, 'user_id' => 2, 'deleted' => false],
        ]);

        $this->assertSame([5, 9], $drop);
    }

    public function testAnAccountWhoseInstitutionsAreAllDeletedKeepsItsOldest(): void
    {
        $this->assertSame([8], OneInstitutionPerAccount::membershipsToDrop([
            ['id' => 4, 'user_id' => 1, 'deleted' => true],
            ['id' => 8, 'user_id' => 1, 'deleted' => true],
        ]));
    }

    public function testTheRolesInADroppedInstitutionEnd(): void
    {
        $db          = db_connect();
        $offering    = CourseFactory::offering('A');
        $institution = (int) $db->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$offering])->getRow()->institution_id;
        $sub         = (int) $db->query('SELECT p.sub_department_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id WHERE o.id = ?', [$offering])->getRow()->sub_department_id;
        $person      = PeopleFactory::person('instructor', institutionId: PeopleFactory::institution('KEEP'));

        CourseFactory::enroll($offering, $person);
        CourseFactory::assignInstructor($offering, $person);
        $db->table('chairperson_assignments')->insert(['sub_department_id' => $sub, 'chairperson_user_id' => $person->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        OneInstitutionPerAccount::endRolesIn($db, (int) $person->id, $institution);

        $this->assertSame('dropped', $db->table('enrollments')->where(['offering_id' => $offering, 'student_user_id' => $person->id])->get()->getRowArray()['status']);
        $this->assertSame(0, $db->table('instructor_assignments')->where(['offering_id' => $offering, 'instructor_user_id' => $person->id])->countAllResults());
        $this->assertSame('revoked', $db->table('chairperson_assignments')->where(['sub_department_id' => $sub, 'chairperson_user_id' => $person->id])->get()->getRowArray()['status']);
    }

    public function testASecondMembershipIsRefusedByTheDatabase(): void
    {
        $a    = PeopleFactory::institution('ONEA');
        $b    = PeopleFactory::institution('ONEB');
        $user = PeopleFactory::person('student', institutionId: $a);

        try {
            $added = db_connect()->table('institution_users')->insert(['institution_id' => $b, 'user_id' => $user->id, 'created_at' => date('Y-m-d H:i:s')]);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
        $this->assertSame(1, db_connect()->table('institution_users')->where('user_id', $user->id)->countAllResults());
    }
}

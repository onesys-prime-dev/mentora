<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * The hierarchy is institutions -> colleges -> departments -> programs, the way the SIS names it. This pins the
 * names the rename migration left behind: nothing of the old "department / sub-department" pair may survive.
 *
 * @internal
 */
final class CollegeDepartmentSchemaTest extends DatabaseTestCase
{
    public function testTheTablesAreNamedAfterTheSis(): void
    {
        $db = db_connect();

        foreach (['colleges', 'departments', 'college_announcements'] as $table) {
            $this->assertTrue($db->tableExists($table), "{$table} should exist");
        }

        $this->assertFalse($db->tableExists('sub_departments'));
        $this->assertFalse($db->tableExists('department_announcements'));
    }

    public function testEveryLinkPointsOneLevelUp(): void
    {
        $db = db_connect();
        $db->resetDataCache();

        $this->assertTrue($db->fieldExists('college_id', 'departments'));
        $this->assertTrue($db->fieldExists('department_id', 'programs'));
        $this->assertTrue($db->fieldExists('department_id', 'chairperson_assignments'));
        $this->assertTrue($db->fieldExists('college_id', 'dean_assignments'));
        $this->assertTrue($db->fieldExists('college_id', 'college_announcements'));

        $this->assertFalse($db->fieldExists('sub_department_id', 'programs'));
        $this->assertFalse($db->fieldExists('sub_department_id', 'chairperson_assignments'));
        $this->assertFalse($db->fieldExists('department_id', 'dean_assignments'));
    }

    public function testNoKeyOrIndexKeepsTheOldWords(): void
    {
        $names = array_column(db_connect()->query(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND (CONSTRAINT_NAME LIKE '%sub\_department%' OR (TABLE_NAME = 'dean_assignments' AND COLUMN_NAME = 'department_id'))"
        )->getResultArray(), 'name');

        $this->assertSame([], $names);
    }
}

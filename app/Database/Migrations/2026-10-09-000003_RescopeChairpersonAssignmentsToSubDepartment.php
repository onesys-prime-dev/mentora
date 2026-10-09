<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * A chairperson oversees a whole sub-department (every program under it,
 * including ones added later) — not one program at a time. Rescope the
 * assignment to match, now that programs sit under sub_departments.
 */
class RescopeChairpersonAssignmentsToSubDepartment extends Migration
{
    public function up()
    {
        $this->connection()->query('ALTER TABLE chairperson_assignments ADD COLUMN sub_department_id INT(20) UNSIGNED NULL AFTER program_id');

        $this->connection()->query(
            'UPDATE chairperson_assignments ca
             JOIN programs p ON p.id = ca.program_id
             SET ca.sub_department_id = p.sub_department_id'
        );

        $this->dropProgramLink();

        $this->connection()->query('ALTER TABLE chairperson_assignments MODIFY sub_department_id INT(20) UNSIGNED NOT NULL');
        $this->connection()->query('ALTER TABLE chairperson_assignments ADD KEY chairperson_assignments_sub_department_id (sub_department_id)');
        $this->connection()->query('ALTER TABLE chairperson_assignments ADD UNIQUE KEY chairperson_assignments_sub_department_id_chairperson_user_id (sub_department_id, chairperson_user_id)');
        $this->connection()->query(
            'ALTER TABLE chairperson_assignments ADD CONSTRAINT chairperson_assignments_sub_department_id_foreign
             FOREIGN KEY (sub_department_id) REFERENCES sub_departments (id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down()
    {
        $this->connection()->query('ALTER TABLE chairperson_assignments ADD COLUMN program_id INT(20) UNSIGNED NULL AFTER id');

        // Lossy: a sub-department can hold several programs, so this picks
        // the lowest-id program under each sub-department rather than the
        // original one-to-one program it was assigned to.
        $this->connection()->query(
            'UPDATE chairperson_assignments ca
             JOIN (
                 SELECT sub_department_id, MIN(id) AS program_id
                 FROM programs
                 GROUP BY sub_department_id
             ) p ON p.sub_department_id = ca.sub_department_id
             SET ca.program_id = p.program_id'
        );

        $this->dropSubDepartmentLink();

        $this->connection()->query('ALTER TABLE chairperson_assignments MODIFY program_id INT(20) UNSIGNED NOT NULL');
        $this->connection()->query('ALTER TABLE chairperson_assignments ADD UNIQUE KEY program_id (program_id, chairperson_user_id)');
        $this->connection()->query(
            'ALTER TABLE chairperson_assignments ADD CONSTRAINT chairperson_assignments_program_id_foreign
             FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    /** Drops chairperson_assignments.program_id with its foreign key and every index that uses it. */
    private function dropProgramLink(): void
    {
        $this->dropColumnWithKeys('program_id');
    }

    /** Drops chairperson_assignments.sub_department_id with its foreign key and every index that uses it. */
    private function dropSubDepartmentLink(): void
    {
        $this->dropColumnWithKeys('sub_department_id');
    }

    private function dropColumnWithKeys(string $column): void
    {
        $foreignKeys = $this->connection()->query(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->connection()->prefixTable('chairperson_assignments'), $column]
        )->getResultArray();

        foreach ($foreignKeys as $foreignKey) {
            $this->connection()->query('ALTER TABLE chairperson_assignments DROP FOREIGN KEY `' . $foreignKey['name'] . '`');
        }

        $indexes = $this->connection()->query("SHOW INDEX FROM chairperson_assignments WHERE Column_name = '{$column}'")->getResultArray();

        foreach (array_unique(array_column($indexes, 'Key_name')) as $name) {
            $this->connection()->query('ALTER TABLE chairperson_assignments DROP INDEX `' . $name . '`');
        }

        $this->connection()->query("ALTER TABLE chairperson_assignments DROP COLUMN {$column}");
    }

    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}

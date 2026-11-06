<?php

namespace App\Database\Migrations;

use App\Database\LegacyHierarchyRename;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * institutions -> programs becomes institutions -> colleges ->
 * departments -> programs.
 *
 * Programs that already exist keep working: each institution that has any gets
 * one "General" college with one "General" department, and its programs
 * are moved under it (rename or split them from the admin tools afterwards).
 *
 * A database built before the SIS naming is upgraded in place instead, and one
 * that already has the colleges is left alone, so this is safe wherever it is run.
 */
class AddCollegesAndDepartments extends Migration
{
    public function up()
    {
        $legacy = new LegacyHierarchyRename($this->connection());

        if ($legacy->isNeeded()) {
            $legacy->upgrade();

            return;
        }

        if ($this->connection()->tableExists('colleges')) {
            return;
        }

        $this->createColleges();
        $this->createDepartments();

        $this->connection()->query('ALTER TABLE programs ADD COLUMN department_id INT(20) UNSIGNED NULL AFTER id');

        $now = date('Y-m-d H:i:s');

        $institutions = $this->connection()->query('SELECT DISTINCT institution_id FROM programs')->getResultArray();

        foreach ($institutions as $row) {
            $institutionId = (int) $row['institution_id'];

            $this->connection()->table('colleges')->insert([
                'institution_id' => $institutionId,
                'code'           => 'GEN',
                'name'           => 'General',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $collegeId = (int) $this->connection()->insertID();

            $this->connection()->table('departments')->insert([
                'college_id' => $collegeId,
                'code'          => 'GEN',
                'name'          => 'General',
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            $this->connection()->table('programs')
                ->where('institution_id', $institutionId)
                ->update(['department_id' => (int) $this->connection()->insertID()]);
        }

        $this->dropInstitutionLink();

        $this->connection()->query('ALTER TABLE programs MODIFY department_id INT(20) UNSIGNED NOT NULL');
        $this->connection()->query('ALTER TABLE programs ADD KEY programs_department_id (department_id)');
        $this->connection()->query('ALTER TABLE programs ADD UNIQUE KEY programs_department_id_code (department_id, code)');
        $this->connection()->query(
            'ALTER TABLE programs ADD CONSTRAINT programs_department_id_foreign
             FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down()
    {
        $this->connection()->query('ALTER TABLE programs ADD COLUMN institution_id INT(20) UNSIGNED NULL AFTER id');

        $this->connection()->query(
            'UPDATE programs
             JOIN departments ON departments.id = programs.department_id
             JOIN colleges ON colleges.id = departments.college_id
             SET programs.institution_id = colleges.institution_id'
        );

        $this->connection()->query('ALTER TABLE programs DROP FOREIGN KEY programs_department_id_foreign');
        $this->connection()->query('ALTER TABLE programs DROP INDEX programs_department_id_code');
        $this->connection()->query('ALTER TABLE programs DROP INDEX programs_department_id');
        $this->connection()->query('ALTER TABLE programs DROP COLUMN department_id');

        $this->connection()->query('ALTER TABLE programs MODIFY institution_id INT(20) UNSIGNED NOT NULL');
        $this->connection()->query('ALTER TABLE programs ADD KEY institution_id (institution_id)');
        $this->connection()->query('ALTER TABLE programs ADD UNIQUE KEY institution_id_code (institution_id, code)');
        $this->connection()->query(
            'ALTER TABLE programs ADD CONSTRAINT programs_institution_id_foreign
             FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE ON UPDATE CASCADE'
        );

        $this->forge->dropTable('departments');
        $this->forge->dropTable('colleges');
    }

    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }

    /** Drops programs.institution_id with its foreign key and every index that uses it. */
    private function dropInstitutionLink(): void
    {
        $foreignKeys = $this->connection()->query(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->connection()->prefixTable('programs'), 'institution_id']
        )->getResultArray();

        foreach ($foreignKeys as $foreignKey) {
            $this->connection()->query('ALTER TABLE programs DROP FOREIGN KEY `' . $foreignKey['name'] . '`');
        }

        // Dropping the column alone would shrink the composite unique key to (code) alone.
        $indexes = $this->connection()->query("SHOW INDEX FROM programs WHERE Column_name = 'institution_id'")->getResultArray();

        foreach (array_unique(array_column($indexes, 'Key_name')) as $name) {
            $this->connection()->query('ALTER TABLE programs DROP INDEX `' . $name . '`');
        }

        $this->connection()->query('ALTER TABLE programs DROP COLUMN institution_id');
    }

    private function createColleges(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id'   => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'code'             => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'location'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'established_year' => ['type' => 'SMALLINT', 'constraint' => 4, 'unsigned' => true, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('institution_id');
        $this->forge->addUniqueKey(['institution_id', 'code']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('colleges');
    }

    private function createDepartments(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'college_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'code'          => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'is_active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('college_id');
        $this->forge->addUniqueKey(['college_id', 'code']);
        $this->forge->addForeignKey('college_id', 'colleges', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('departments');
    }
}

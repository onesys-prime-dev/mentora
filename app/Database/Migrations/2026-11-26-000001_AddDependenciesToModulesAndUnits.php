<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dependencies for modules and learning units, the way learning items already have them:
 *
 *  - prerequisite_mode on course_modules and learning_units: none | previous | custom
 *      previous: the published module before it (unit: the published unit before it in its module) is completed
 *      custom:   every module (unit) listed in the link table is completed
 *    A module or unit counts as completed when the student has completed every published item in it.
 *  - course_module_prerequisites and learning_unit_prerequisites: the custom links. Both sides cascade.
 *  - learning_items.dependency_min_score_mode / dependency_min_score_percent: a learning item that waits on a quiz or
 *    an assignment can also require a score on it: none | passing (the assessment's own passing score) | custom (a percentage).
 */
class AddDependenciesToModulesAndUnits extends Migration
{
    public function up()
    {
        foreach (['course_modules', 'learning_units'] as $table) {
            $this->forge->addColumn($table, [
                'prerequisite_mode' => [
                    'type'       => 'ENUM',
                    'constraint' => ['none', 'previous', 'custom'],
                    'default'    => 'none',
                    'null'       => false,
                    'after'      => 'status',
                ],
            ]);
        }

        $this->links('course_module_prerequisites', 'module_id', 'prerequisite_module_id', 'course_modules');
        $this->links('learning_unit_prerequisites', 'learning_unit_id', 'prerequisite_unit_id', 'learning_units');

        $this->forge->addColumn('learning_items', [
            'dependency_min_score_mode' => [
                'type'       => 'ENUM',
                'constraint' => ['none', 'passing', 'custom'],
                'default'    => 'none',
                'null'       => false,
                'after'      => 'prerequisite_mode',
            ],
            'dependency_min_score_percent' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'dependency_min_score_mode',
            ],
        ]);
    }

    private function links(string $table, string $own, string $other, string $target): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            $own       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            $other     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey($other);
        $this->forge->addUniqueKey([$own, $other]);
        $this->forge->addForeignKey($own, $target, 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey($other, $target, 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable($table);
    }

    public function down()
    {
        $this->forge->dropColumn('learning_items', ['dependency_min_score_mode', 'dependency_min_score_percent']);
        $this->forge->dropTable('learning_unit_prerequisites', true);
        $this->forge->dropTable('course_module_prerequisites', true);
        $this->forge->dropColumn('learning_units', 'prerequisite_mode');
        $this->forge->dropColumn('course_modules', 'prerequisite_mode');
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Optional grading periods (Prelim / Midterm / Finals, ...) for a scheme.
 *
 * A scheme with `uses_periods = 0` behaves exactly as before — categories
 * roll straight up into the final grade. With `uses_periods = 1`, categories
 * belong to a period instead, each period rolls up its own categories, and
 * the periods themselves roll up (by weight) into the final grade — the
 * same weighted-average rule, one level higher.
 *
 * `grade_categories.period_id` stays nullable and is simply ignored while
 * `uses_periods` is off, so turning periods on/off is fully reversible.
 */
class AddGradingPeriods extends Migration
{
    public function up()
    {
        $this->forge->addColumn('grading_schemes', [
            'uses_periods' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'after' => 'is_system_default',
            ],
        ]);

        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'grading_scheme_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'weight_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['Active', 'Archived'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('grading_scheme_id');
        $this->forge->createTable('grading_periods');

        $this->db->query('
            ALTER TABLE grading_periods
            ADD CONSTRAINT fk_period_scheme
            FOREIGN KEY (grading_scheme_id)
            REFERENCES grading_schemes(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ');

        $this->forge->addColumn('grade_categories', [
            'period_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
                'after' => 'grading_scheme_id',
            ],
        ]);
        $this->db->query('CREATE INDEX idx_category_period ON grade_categories (period_id)');

        $this->db->query('
            ALTER TABLE grade_categories
            ADD CONSTRAINT fk_category_period
            FOREIGN KEY (period_id)
            REFERENCES grading_periods(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE grade_categories DROP FOREIGN KEY fk_category_period');
        $this->db->query('DROP INDEX idx_category_period ON grade_categories');
        $this->forge->dropColumn('grade_categories', 'period_id');

        $this->forge->dropTable('grading_periods', true);

        $this->forge->dropColumn('grading_schemes', 'uses_periods');
    }
}

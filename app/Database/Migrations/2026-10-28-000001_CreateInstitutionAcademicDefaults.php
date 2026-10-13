<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An institution's own academic defaults, over the platform's.
 *
 * `institution_academic_defaults` holds one row per institution that set any:
 * the passing mark and grade scale new offerings start with, and the three
 * thresholds reports and dashboards use (days without activity, the progress
 * a student counts as behind below, days of ungraded work before it is
 * overdue). Every column is optional; a null falls back to the platform value.
 *
 * `grading_schemes.institution_id` lets an institution keep its own default
 * grading scheme (a template of weighted categories, like the system default
 * but only for its offerings). It has no offering and is not the system
 * default, so nothing that looks for the platform template can find it.
 */
class CreateInstitutionAcademicDefaults extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'institution_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'passing_percentage'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'grade_scale_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'inactive_days'         => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
            'behind_below_percent'  => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
            'overdue_grading_days'  => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
            'updated_by_user_id'    => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('institution_id', true);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('institution_academic_defaults');

        $this->forge->addColumn('grading_schemes', [
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
        ]);
        $this->db->query('CREATE INDEX idx_grading_schemes_institution ON grading_schemes (institution_id)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX idx_grading_schemes_institution ON grading_schemes');
        $this->forge->dropColumn('grading_schemes', 'institution_id');
        $this->forge->dropTable('institution_academic_defaults', true);
    }
}

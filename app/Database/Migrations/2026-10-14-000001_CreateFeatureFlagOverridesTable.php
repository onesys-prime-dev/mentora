<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Changes a system administrator has made to a feature flag (SA7).
 *
 * The flags themselves are defined in code (Config\FeatureFlags): their
 * key, description and default. This table holds only what has been changed:
 *
 *   institution_id NULL   a global row. enabled = 0 is the kill switch (the
 *                         feature is off for everyone, whatever else says);
 *                         enabled = 1 makes the feature on by default for
 *                         everyone, instead of the default in code.
 *   institution_id set    that institution's own override, on or off.
 *
 * An override carries the reason it was made (note) and may end on a date
 * (ends_on, the last day it applies), after which it is ignored.
 *
 * "One row per flag and institution" must hold for the global row too, but a
 * unique key treats NULLs as all different. So a global row also carries
 * is_global = 1 (and an institution row leaves it NULL), and the pair
 * (flag_key, is_global) is unique. Plain columns, so it behaves the same on
 * MySQL and MariaDB, and the foreign key can cascade. (The two columns are kept
 * in step by FeatureFlagOverrideModel, not by a CHECK: MySQL refuses a CHECK on a
 * column that has a cascading foreign key.)
 */
class CreateFeatureFlagOverridesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'flag_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'institution_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            // 1 on a global row (institution_id NULL), NULL on an institution's row. See above.
            'is_global' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
            ],

            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
            ],

            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],

            // The last day the override applies. NULL = no end.
            'ends_on' => [
                'type' => 'DATE',
                'null' => true,
            ],

            'created_by_user_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('flag_key');
        $this->forge->addUniqueKey(['flag_key', 'institution_id']);
        $this->forge->addUniqueKey(['flag_key', 'is_global']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('feature_flag_overrides');
    }

    public function down()
    {
        $this->forge->dropTable('feature_flag_overrides');
    }
}

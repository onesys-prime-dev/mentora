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
 * (ends_on, the last day it applies), after which it is ignored. The
 * institution_key column makes "one row per flag and institution" hold for
 * the global row too, since a unique key does not treat NULLs as equal.
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
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('feature_flag_overrides');

        // 0 stands for "global" so the pair (flag, institution) is unique even when institution_id is NULL.
        $this->db->query('ALTER TABLE feature_flag_overrides ADD COLUMN institution_key INT UNSIGNED AS (COALESCE(institution_id, 0)) PERSISTENT AFTER institution_id');
        $this->db->query('ALTER TABLE feature_flag_overrides ADD UNIQUE KEY flag_scope (flag_key, institution_key)');
    }

    public function down()
    {
        $this->forge->dropTable('feature_flag_overrides');
    }
}

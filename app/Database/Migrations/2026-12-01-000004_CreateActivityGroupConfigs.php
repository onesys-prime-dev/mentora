<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The group settings of one activity (an assignment or a learning item) and the source of its group copy.
 * Exactly one of assessment_id / learning_item_id is set; each is unique, so an activity has one config.
 */
class CreateActivityGroupConfigs extends Migration
{
    public function up()
    {
        $id  = ['type' => 'INT', 'constraint' => 20, 'unsigned' => true];
        $int = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];
        $flag = static fn (int $default) => ['type' => 'TINYINT', 'constraint' => 1, 'default' => $default];

        $this->forge->addField([
            'id'                  => $id + ['auto_increment' => true],
            'offering_id'         => $id,
            'assessment_id'       => $id + ['null' => true],
            'learning_item_id'    => $id + ['null' => true],
            'source_set_id'       => $id + ['null' => true],
            'formation_mode'      => ['type' => 'ENUM', 'constraint' => ['manual', 'auto', 'self_signup', 'proposed'], 'default' => 'manual'],
            'min_size'            => $int + ['default' => 1],
            'max_size'            => $int + ['default' => 30],
            'signup_opens_at'     => ['type' => 'DATETIME', 'null' => true],
            'signup_closes_at'    => ['type' => 'DATETIME', 'null' => true],
            'ungrouped_policy'    => ['type' => 'ENUM', 'constraint' => ['block', 'solo'], 'default' => 'block'],
            'submission_style'    => ['type' => 'ENUM', 'constraint' => ['shared', 'leader_only', 'per_member'], 'default' => 'shared'],
            'grading_mode'        => ['type' => 'ENUM', 'constraint' => ['collective', 'collective_adjust', 'individual'], 'default' => 'collective'],
            'use_average'         => $flag(0),
            'nonleader_can_rename' => $flag(0),
            'nonleader_can_vote'  => $flag(0),
            'nonleader_can_leave' => $flag(1),
            'groups_visible'      => $flag(0),
            'completion_mode'     => ['type' => 'ENUM', 'constraint' => ['any', 'each'], 'null' => true],
            'frozen_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_by'          => $id + ['null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('offering_id');
        $this->forge->addUniqueKey('assessment_id', 'uq_group_config_assessment');
        $this->forge->addUniqueKey('learning_item_id', 'uq_group_config_item');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('assessment_id', 'assessments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('learning_item_id', 'learning_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('source_set_id', 'group_sets', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('activity_group_configs');
    }

    public function down()
    {
        $this->forge->dropTable('activity_group_configs', true);
    }
}

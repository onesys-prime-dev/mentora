<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A user asks for a change to a locked identity field; an institution admin (or the platform) decides. */
class CreateProfileCorrectionRequests extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'field'          => ['type' => 'ENUM', 'constraint' => ['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'student_number']],
            'old_value'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'new_value'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'reason'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['pending', 'approved', 'rejected', 'expired'], 'default' => 'pending'],
            'routed_to'      => ['type' => 'ENUM', 'constraint' => ['institution', 'platform']],
            'decided_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'decision_note'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'decided_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'institution_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('decided_by', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('profile_correction_requests');
    }

    public function down()
    {
        $this->forge->dropTable('profile_correction_requests', true);
    }
}

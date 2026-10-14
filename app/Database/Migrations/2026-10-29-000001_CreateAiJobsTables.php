<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The question builder work queue: jobs the Python worker pulls and reports
 * on, the digests it keeps so the same source is never read twice, and the
 * worker heartbeat. PHP owns all of it; the worker never touches the database.
 */
class CreateAiJobsTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id'  => ['type' => 'INT', 'unsigned' => true],
            'course_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'assessment_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'batch_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'kind'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'generate'],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
            'payload'         => ['type' => 'LONGTEXT', 'null' => true],
            'result'          => ['type' => 'LONGTEXT', 'null' => true],
            'checkpoint'      => ['type' => 'LONGTEXT', 'null' => true],
            'stage'           => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'progress_done'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'progress_of'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'progress_note'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'waiting_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'error_code'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'error_message'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'requeues'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'claimed_by'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'claimed_at'      => ['type' => 'DATETIME', 'null' => true],
            'heartbeat_at'    => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'id']);
        $this->forge->addKey(['institution_id', 'created_at']);
        $this->forge->addKey('assessment_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ai_jobs');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'source_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'digest'         => ['type' => 'LONGTEXT'],
            'token_estimate' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'   => ['type' => 'DATETIME', 'null' => true],
            'expires_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // A digest is never shared across institutions: the same file is two rows.
        $this->forge->addUniqueKey(['institution_id', 'source_hash']);
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ai_source_digests');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'worker_id'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'version'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'backend'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'last_seen_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('worker_id');
        $this->forge->createTable('ai_worker_heartbeats');
    }

    public function down()
    {
        $this->forge->dropTable('ai_worker_heartbeats', true);
        $this->forge->dropTable('ai_source_digests', true);
        $this->forge->dropTable('ai_jobs', true);
    }
}

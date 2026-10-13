<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Backup history (SA11): one row per backup asked for or taken.
 *
 * `status`: requested (an administrator asked, the server job has not picked
 * it up), running, succeeded, failed. The dump itself is made by
 * `php spark backup:run`; the app only records and shows. `file_name` is the
 * name of the file in the backup folder, never a path.
 */
class CreateBackupsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['requested', 'running', 'succeeded', 'failed'], 'default' => 'requested'],
            'trigger' => ['type' => 'ENUM', 'constraint' => ['manual', 'scheduled'], 'default' => 'scheduled'],
            'file_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'size_bytes' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'requested_by_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'requested_by_label' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'finished_at']);

        $this->forge->createTable('backups');
    }

    public function down()
    {
        $this->forge->dropTable('backups', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What people are to be told by email, written in the same transaction as the change that makes it true.
 *
 * An upload, an import, an enrolment or an assignment does not send mail itself: it adds a row here. The
 * every-minute job (exports:run) reads the rows that are due, makes one email per person out of all their rows,
 * and marks them sent. So a preview, a refused change or a rolled-back run tells nobody, an upload of five
 * hundred students is not five hundred sends inside one request, and a mail server that is down is retried.
 *
 * A welcome row does not hold the set-password link: that is made when the email is sent, so its time starts then.
 */
class CreateMailOutbox extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'user_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'kind'           => ['type' => 'ENUM', 'constraint' => ['welcome', 'enrolled', 'teaching', 'dean', 'chairperson']],
            'detail'         => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'batch'          => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'send_after'     => ['type' => 'DATETIME'],
            'sent_at'        => ['type' => 'DATETIME', 'null' => true],
            'attempts'       => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'last_error'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['sent_at', 'send_after'], false, false, 'idx_mail_outbox_due');
        $this->forge->addKey('batch');
        $this->forge->addKey(['user_id', 'institution_id'], false, false, 'idx_mail_outbox_person');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mail_outbox');
    }

    public function down()
    {
        $this->forge->dropTable('mail_outbox', true);
    }
}

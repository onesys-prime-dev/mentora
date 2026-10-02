<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Teams-channel-style announcements: one instructor-authored post per row,
 * each with attachments, a flat reply thread, and one reaction per viewer.
 */
class CreateAnnouncements extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'offering_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'author_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'subject' => ['type' => 'VARCHAR', 'constraint' => 255],
            'message' => ['type' => 'LONGTEXT'],
            'is_pinned' => ['type' => 'BOOLEAN', 'default' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('offering_id');
        $this->forge->addKey(['offering_id', 'is_pinned', 'created_at']);
        $this->forge->createTable('announcements');

        $this->db->query("ALTER TABLE announcements ADD CONSTRAINT fk_announcement_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE announcements ADD CONSTRAINT fk_announcement_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'announcement_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'file_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'order_index' => ['type' => 'INT', 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('announcement_id');
        $this->forge->createTable('announcement_attachments');

        $this->db->query("ALTER TABLE announcement_attachments ADD CONSTRAINT fk_announcement_attachment_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE announcement_attachments ADD CONSTRAINT fk_announcement_attachment_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'announcement_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'author_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'message' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('announcement_id');
        $this->forge->addKey(['announcement_id', 'id']);
        $this->forge->createTable('announcement_replies');

        $this->db->query("ALTER TABLE announcement_replies ADD CONSTRAINT fk_announcement_reply_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE announcement_replies ADD CONSTRAINT fk_announcement_reply_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'announcement_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'emoji' => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['announcement_id', 'user_id']);
        $this->forge->createTable('announcement_reactions');

        $this->db->query("ALTER TABLE announcement_reactions ADD CONSTRAINT fk_announcement_reaction_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE announcement_reactions ADD CONSTRAINT fk_announcement_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");
    }

    public function down()
    {
        $this->forge->dropTable('announcement_reactions', true);
        $this->forge->dropTable('announcement_replies', true);
        $this->forge->dropTable('announcement_attachments', true);
        $this->forge->dropTable('announcements', true);
    }
}

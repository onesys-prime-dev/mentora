<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Course discussions: many-to-many threads anyone in the offering can start.
 * A thread may optionally back a `discussion` learning item (learning_item_id);
 * deleting that item clears the link and keeps the thread and its replies.
 */
class CreateDiscussions extends Migration
{
    private const ID = ['type' => 'INT', 'constraint' => 20, 'unsigned' => true];

    public function up()
    {
        // ---- threads
        $this->forge->addField([
            'id' => self::ID + ['auto_increment' => true],
            'offering_id' => self::ID,
            'learning_item_id' => self::ID + ['null' => true],
            'author_id' => self::ID,
            'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            'body' => ['type' => 'LONGTEXT'],
            'is_pinned' => ['type' => 'BOOLEAN', 'default' => false],
            'is_locked' => ['type' => 'BOOLEAN', 'default' => false],
            'answer_reply_id' => self::ID + ['null' => true],
            'reply_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_activity_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['offering_id', 'is_pinned', 'last_activity_at']);
        $this->forge->addUniqueKey('learning_item_id');
        $this->forge->createTable('discussion_threads');

        // Unread is "activity newer than last read", so both need sub-second
        // precision or a reply in the same second as a read goes unnoticed.
        $this->db->query("ALTER TABLE discussion_threads MODIFY last_activity_at DATETIME(6) NULL");

        $this->db->query("ALTER TABLE discussion_threads ADD CONSTRAINT fk_discussion_thread_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_threads ADD CONSTRAINT fk_discussion_thread_item FOREIGN KEY (learning_item_id) REFERENCES learning_items(id) ON DELETE SET NULL ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_threads ADD CONSTRAINT fk_discussion_thread_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");

        // ---- replies (flat, oldest first)
        $this->forge->addField([
            'id' => self::ID + ['auto_increment' => true],
            'thread_id' => self::ID,
            'author_id' => self::ID,
            'body' => ['type' => 'LONGTEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['thread_id', 'id']);
        $this->forge->createTable('discussion_replies');

        $this->db->query("ALTER TABLE discussion_replies ADD CONSTRAINT fk_discussion_reply_thread FOREIGN KEY (thread_id) REFERENCES discussion_threads(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_replies ADD CONSTRAINT fk_discussion_reply_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");

        // ---- reactions: one per person per target (thread itself, or one reply)
        $this->forge->addField([
            'id' => self::ID + ['auto_increment' => true],
            'thread_id' => self::ID,
            'reply_id' => self::ID + ['null' => true],
            'user_id' => self::ID,
            // 0 stands in for "the thread itself" so the unique key below
            // works (MySQL treats NULLs as distinct inside a unique index).
            'target_key' => self::ID + ['default' => 0],
            'emoji' => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('thread_id');
        $this->forge->addUniqueKey(['user_id', 'thread_id', 'target_key']);
        $this->forge->createTable('discussion_reactions');

        $this->db->query("ALTER TABLE discussion_reactions ADD CONSTRAINT fk_discussion_reaction_thread FOREIGN KEY (thread_id) REFERENCES discussion_threads(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_reactions ADD CONSTRAINT fk_discussion_reaction_reply FOREIGN KEY (reply_id) REFERENCES discussion_replies(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_reactions ADD CONSTRAINT fk_discussion_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");

        // ---- attachments on a thread or on one reply
        $this->forge->addField([
            'id' => self::ID + ['auto_increment' => true],
            'thread_id' => self::ID,
            'reply_id' => self::ID + ['null' => true],
            'file_id' => self::ID,
            'order_index' => ['type' => 'INT', 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('thread_id');
        $this->forge->createTable('discussion_attachments');

        $this->db->query("ALTER TABLE discussion_attachments ADD CONSTRAINT fk_discussion_attachment_thread FOREIGN KEY (thread_id) REFERENCES discussion_threads(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_attachments ADD CONSTRAINT fk_discussion_attachment_reply FOREIGN KEY (reply_id) REFERENCES discussion_replies(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_attachments ADD CONSTRAINT fk_discussion_attachment_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE ON UPDATE CASCADE");

        // ---- per-user read marker (thread is unread when activity is newer)
        $this->forge->addField([
            'thread_id' => self::ID,
            'user_id' => self::ID,
            'last_read_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey(['thread_id', 'user_id'], true);
        $this->forge->createTable('discussion_thread_reads');

        $this->db->query("ALTER TABLE discussion_thread_reads MODIFY last_read_at DATETIME(6) NULL");

        $this->db->query("ALTER TABLE discussion_thread_reads ADD CONSTRAINT fk_discussion_read_thread FOREIGN KEY (thread_id) REFERENCES discussion_threads(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE discussion_thread_reads ADD CONSTRAINT fk_discussion_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE");
    }

    public function down()
    {
        $this->forge->dropTable('discussion_thread_reads', true);
        $this->forge->dropTable('discussion_attachments', true);
        $this->forge->dropTable('discussion_reactions', true);
        $this->forge->dropTable('discussion_replies', true);
        $this->forge->dropTable('discussion_threads', true);
    }
}

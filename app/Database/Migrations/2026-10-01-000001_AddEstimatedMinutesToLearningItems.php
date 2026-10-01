<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A module's "duration" badge is the sum of its items' estimated_minutes —
 * real data, not a placeholder. For Quiz items this mirrors the assessment's
 * own `duration` (minutes, already exists, quiz-only) rather than being
 * edited independently, so the two numbers can't drift apart.
 */
class AddEstimatedMinutesToLearningItems extends Migration
{
    public function up()
    {
        $this->forge->addColumn('learning_items', [
            'estimated_minutes' => [
                'type' => 'INT',
                'null' => true,
                'after' => 'content',
            ],
        ]);

        // Backfill from the linked quiz's own duration, where one exists.
        $this->db->query("
            UPDATE learning_items li
            JOIN assessments a ON a.learning_item_id = li.id
            SET li.estimated_minutes = a.duration
            WHERE a.duration IS NOT NULL
        ");
    }

    public function down()
    {
        $this->forge->dropColumn('learning_items', 'estimated_minutes');
    }
}

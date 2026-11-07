<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A module, learning unit or learning item that is a draft can be scheduled to publish itself at a date and time
 * (`scheduled_publish_at`). A scheduled row stays a draft, and hidden, until then; `php spark content:publish-scheduled`
 * (every minute) publishes the ones that are due. Publishing it by hand, or turning it back into a draft, clears the schedule.
 */
class AddScheduledPublishToCourseContent extends Migration
{
    private const TABLES = ['course_modules', 'learning_units', 'learning_items'];

    public function up()
    {
        foreach (self::TABLES as $table) {
            $this->forge->addColumn($table, [
                'scheduled_publish_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'status'],
            ]);
        }
    }

    public function down()
    {
        foreach (self::TABLES as $table) {
            $this->forge->dropColumn($table, 'scheduled_publish_at');
        }
    }
}

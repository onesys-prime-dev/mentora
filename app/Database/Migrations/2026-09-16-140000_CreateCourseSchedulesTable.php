<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Meeting times for a course offering.
 *
 * Kept as its own table rather than columns on course_offerings because a
 * section routinely meets in more than one block — a lecture on MWF plus a
 * Saturday lab in a different room — and those blocks have their own times
 * and venues. One row per block per day.
 */
class CreateCourseSchedulesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],

            'offering_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'day_of_week' => [
                'type' => 'ENUM',
                'constraint' => [
                    'mon',
                    'tue',
                    'wed',
                    'thu',
                    'fri',
                    'sat',
                    'sun',
                ],
            ],

            'start_time' => [
                'type' => 'TIME',
            ],

            'end_time' => [
                'type' => 'TIME',
            ],

            'room' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],

            'delivery_mode' => [
                'type' => 'ENUM',
                'constraint' => [
                    'onsite',
                    'online',
                    'hybrid',
                ],
                'default' => 'onsite',
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

        $this->forge->addKey('offering_id');
        $this->forge->addKey('day_of_week');

        // One offering cannot start two blocks on the same day at the same time.
        $this->forge->addUniqueKey([
            'offering_id',
            'day_of_week',
            'start_time',
        ]);

        $this->forge->addForeignKey(
            'offering_id',
            'course_offerings',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('course_schedules');
    }

    public function down()
    {
        $this->forge->dropTable('course_schedules');
    }
}

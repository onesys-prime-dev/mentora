<?php

namespace App\Database\Seeds;

/**
 * Meeting times for each demo section, so the course Overview and the
 * dashboard's Teaching Load have a real schedule to show.
 *
 * Runs after CourseOfferingsSeeder (it looks offerings up by code + section).
 * One row per meeting day; a section that meets on different days at
 * different times simply has rows with different start/end times.
 */
class CourseSchedulesSeeder extends DemoSeeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // [course code, section, days, start, end, room, delivery mode]
        $schedules = [
            ['IT101', 'A', ['mon', 'wed', 'fri'], '09:00:00', '10:30:00', 'Room 33A', 'onsite'],
            ['IT101', 'B', ['tue', 'thu'],        '13:00:00', '14:30:00', 'Lab 2',    'onsite'],
            ['IT102', 'A', ['tue', 'thu'],        '09:00:00', '10:30:00', 'Lab 1',    'onsite'],
            ['IT201', 'A', ['mon', 'wed'],        '14:00:00', '15:30:00', 'Room 41B', 'hybrid'],
        ];

        foreach ($schedules as [$code, $section, $days, $start, $end, $room, $mode]) {

            $offeringId = $this->offeringId($code, $section);

            foreach ($days as $day) {

                $exists = $this->db->table('course_schedules')
                    ->where('offering_id', $offeringId)
                    ->where('day_of_week', $day)
                    ->where('start_time', $start)
                    ->countAllResults() > 0;

                if ($exists) {
                    continue;
                }

                $this->db->table('course_schedules')->insert([
                    'offering_id' => $offeringId,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'room' => $room,
                    'delivery_mode' => $mode,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}

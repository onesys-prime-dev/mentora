<?php

namespace App\Database\Seeds;

/**
 * Fills IT101-A's calendar with something to look at: a few announcements
 * from the instructor and a personal reminder each for the instructor and
 * a couple of students.
 *
 * Dates are relative to the moment of seeding (via at()), same convention
 * as the rest of DemoSeeder's content — so the demo always has something
 * this week and something already past, instead of drifting stale.
 */
class CalendarDemoSeeder extends DemoSeeder
{
    public function run()
    {
        $instructorId = $this->userId('instructor1@mentora.test');

        $offeringA = $this->offeringId('IT101', 'A');
        $offeringB = $this->offeringId('IT101', 'B');

        $this->seedAnnouncements($instructorId, $offeringA);

        $this->seedReminders($instructorId, $offeringA);
        $this->seedReminders($this->userId('student2@mentora.test'), $offeringA, isStudent: true);
        $this->seedReminders($this->userId('student1@mentora.test'), $offeringA, isStudent: true, variant: 1);

        // A light touch on the second section too, so it isn't empty next
        // to the first.
        $this->seedAnnouncements($instructorId, $offeringB, variant: 1);
    }

    protected function seedAnnouncements(int $authorId, int $offeringId, int $variant = 0): void
    {
        helper('announcement');

        $posts = $variant === 0
            ? [
                ['M1 - Coding Activity now open',     'The first coding activity is live under Module 1. Submit before the deadline shown in Assessments.', '-2 days'],
                ['Lab session moved to Room 33A',      'This week\'s lab session has moved from Room 12 to Room 33A. Same time.', '-1 day'],
                ['Reading list for Module 2 posted',   'The reading list for Module 2 is now posted under Resources. Please go through it before next class.', 'today'],
                ['Midterm coverage announcement',      'The midterm will cover Modules 1-3. A review session will be scheduled closer to the date.', '+2 days'],
            ]
            : [
                ['Section B kickoff',                  'Welcome to IT101 - B. Course materials will follow the same pace as Section A, one week behind.', '-1 day'],
            ];

        foreach ($posts as [$title, $body, $offset]) {

            $exists = $this->db->table('announcements')
                ->where('offering_id', $offeringId)
                ->where('subject', $title)
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            $when = $this->at($offset);

            $this->db->table('announcements')->insert([
                'offering_id' => $offeringId,
                'author_id'   => $authorId,
                'subject'     => $title,
                'message'     => announcement_message_from_text($body),
                'is_pinned'   => 0,
                'created_at'  => $when,
                'updated_at'  => $when,
            ]);

        }
    }

    protected function seedReminders(int $userId, int $offeringId, bool $isStudent = false, int $variant = 0): void
    {
        $reminders = $isStudent
            ? ($variant === 0
                ? [['Study for Quiz 2', 'Review Module 2 notes before Friday.', '+2 days 18:00']]
                : [['Submit reflection journal', null, '+1 day 20:00']])
            : [
                ['Grade Module Assignment submissions', 'A few are already waiting — check the Grades tab.', '+1 day 09:00'],
                ['Prepare rubric for Quiz 2',            null, '+3 days 08:00'],
            ];

        foreach ($reminders as [$title, $note, $offset]) {

            $exists = $this->db->table('reminders')
                ->where('user_id', $userId)
                ->where('title', $title)
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            $now = $this->at('now');

            $this->db->table('reminders')->insert([
                'user_id'     => $userId,
                'offering_id' => $offeringId,
                'title'       => $title,
                'note'        => $note,
                'remind_at'   => $this->at($offset),
                'status'      => 'pending',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        }
    }
}

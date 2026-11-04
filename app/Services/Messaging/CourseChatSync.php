<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Subscriptions\PlanLimits;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Makes an offering's course chat match who is in the course (spec 3.2, 6.4).
 *
 * Members are the assigned instructors and every student whose enrolment is
 * `enrolled` or `completed` (a finished course stays readable). A student who
 * drops or withdraws leaves at once. Everything is idempotent: writers call
 * `syncOffering` after any change, and `messages:sync-course-chats` calls it
 * for every offering to repair what a call missed. It never touches messages.
 */
class CourseChatSync
{
    /**
     * What `syncOffering` would do, writing nothing.
     *
     * @return array{create: bool, join: list<int>, leave: list<int>}
     */
    public function plan(int $offeringId): array
    {
        $institution = $this->institutionOf($offeringId);
        $chat        = $this->chat($offeringId);
        $wanted      = $this->wanted($offeringId);
        $active      = $chat === null ? [] : $this->activeIds((int) $chat['id']);

        $join = array_values(array_filter(
            array_diff($wanted, $active),
            fn (int $userId): bool => (new PlanLimits())->institutionOf($userId) === $institution,
        ));

        return ['create' => $chat === null, 'join' => $join, 'leave' => array_values(array_diff($active, $wanted))];
    }

    /** @return array{conversation_id: int, created: bool, joined: int, left: int} */
    public function syncOffering(int $offeringId): array
    {
        $institution = $this->institutionOf($offeringId);
        $db          = db_connect();
        $now         = date('Y-m-d H:i:s');

        $db->transStart();

        // A failure here is thrown to the caller (a hook swallows it). The transaction must not stay open behind it:
        // a caller inside its own transaction (the SIS import) would never commit, and later writes would be lost.
        try {
            // INSERT IGNORE then read by offering: two syncs at once end up with the one chat either way.
            $db->table('conversations')->ignore(true)->insert([
                'institution_id' => $institution, 'type' => 'course', 'offering_id' => $offeringId,
                'is_locked' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $created = $db->affectedRows() > 0;
            $chatId  = (int) $db->table('conversations')->select('id')->where('offering_id', $offeringId)->get()->getRow('id');

            $plan = $this->plan($offeringId);

            if ($plan['leave'] !== []) {
                $db->table('conversation_participants')->where('conversation_id', $chatId)->whereIn('user_id', $plan['leave'])
                    ->where('left_at', null)->update(['left_at' => $now]);
            }

            if ($plan['join'] !== []) {
                $last = (int) $db->table('messages')->selectMax('id', 'last')->where('conversation_id', $chatId)->get()->getRow('last');
                $from = $last === 0 ? 0 : $last + 1;

                foreach ($plan['join'] as $userId) {
                    // A row left behind by an earlier departure is reused: one row per person, as the unique key wants.
                    $db->query(
                        'INSERT INTO conversation_participants (conversation_id, user_id, joined_at, first_visible_message_id)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = VALUES(joined_at), first_visible_message_id = VALUES(first_visible_message_id)',
                        [$chatId, $userId, $now, $from],
                    );
                }
            }
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The course chat of offering ' . $offeringId . ' could not be synced.');
        }

        return ['conversation_id' => $chatId, 'created' => $created, 'joined' => count($plan['join']), 'left' => count($plan['leave'])];
    }

    private function institutionOf(int $offeringId): int
    {
        $row = db_connect()->table('course_offerings co')->select('t.institution_id')
            ->join('academic_terms t', 't.id = co.term_id')->where('co.id', $offeringId)->get()->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Unknown course offering ' . $offeringId . '.');
        }

        return (int) $row['institution_id'];
    }

    /** @return array<string, mixed>|null */
    private function chat(int $offeringId): ?array
    {
        return db_connect()->table('conversations')->where(['type' => 'course', 'offering_id' => $offeringId])->get()->getRowArray();
    }

    /** @return list<int> */
    private function wanted(int $offeringId): array
    {
        $db       = db_connect();
        $students = $db->table('enrollments')->select('student_user_id AS id')->where('offering_id', $offeringId)
            ->whereIn('status', ['enrolled', 'completed'])->get()->getResultArray();
        $teachers = $db->table('instructor_assignments')->select('instructor_user_id AS id')->where('offering_id', $offeringId)->get()->getResultArray();

        return array_values(array_unique(array_map('intval', array_column([...$students, ...$teachers], 'id'))));
    }

    /** @return list<int> */
    private function activeIds(int $chatId): array
    {
        return array_map('intval', array_column(
            db_connect()->table('conversation_participants')->select('user_id')->where('conversation_id', $chatId)->where('left_at', null)->get()->getResultArray(),
            'user_id',
        ));
    }
}

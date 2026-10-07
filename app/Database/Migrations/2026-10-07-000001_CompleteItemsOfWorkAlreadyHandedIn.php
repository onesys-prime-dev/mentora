<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Handing in a quiz or assignment now completes its item in the module
 * (LearningProgressService::completeOnSubmission). Before that, a student had
 * to press "Mark as Completed" afterwards, so work handed in earlier can have
 * an item that still reads "Not Yet Completed". This catches those up.
 *
 * For every student with a submission that is no longer in progress (and not
 * cancelled) on an assessment tied to a learning item, the item is completed,
 * dated at their first hand-in. A completion that already exists is left as it
 * is; a half-recorded one (not started / in progress) is completed. Safe to run
 * more than once.
 */
class CompleteItemsOfWorkAlreadyHandedIn extends Migration
{
    /** One row per (item, student) who has handed work in, with when they first did. */
    private const HANDED_IN = <<<'SQL'
        SELECT a.learning_item_id AS item_id,
               s.student_id       AS student_id,
               COALESCE(MIN(s.submitted_at), NOW()) AS handed_in_at
          FROM assessment_submissions s
          JOIN assessments a ON a.id = s.assessment_id
         WHERE a.learning_item_id IS NOT NULL
           AND s.status NOT IN ('In Progress', 'Cancelled')
         GROUP BY a.learning_item_id, s.student_id
        SQL;

    public function up()
    {
        // The concrete connection (not $this->db, typed as the interface) has the transaction helpers.
        $db = Database::connect($this->DBGroup);

        $db->transStart();

        // Items with no completion row yet.
        $db->query(
            'INSERT INTO learning_completions (learning_item_id, student_user_id, status, completed_at, created_at, updated_at)
             SELECT h.item_id, h.student_id, \'completed\', h.handed_in_at, NOW(), NOW()
               FROM (' . self::HANDED_IN . ') h
              WHERE NOT EXISTS (
                    SELECT 1 FROM learning_completions lc
                     WHERE lc.learning_item_id = h.item_id AND lc.student_user_id = h.student_id
              )'
        );

        // Items with a row that never reached "completed".
        $db->query(
            'UPDATE learning_completions lc
               JOIN (' . self::HANDED_IN . ') h
                 ON h.item_id = lc.learning_item_id AND h.student_id = lc.student_user_id
                SET lc.status = \'completed\', lc.completed_at = h.handed_in_at, lc.updated_at = NOW()
              WHERE lc.status <> \'completed\''
        );

        $db->transComplete();
    }

    public function down()
    {
        // Nothing to undo: these rows are the same as the ones a student's own
        // "Mark as Completed" would have made, and cannot be told apart from them.
    }
}

<?php

namespace App\Services\Courses;

use App\Models\Assessments\AssessmentModel;
use App\Services\Courses\Assessments\AssessmentService;

/**
 * Publish now, keep as a draft, or keep as a draft that publishes itself at a date and time: for a module, a learning
 * unit and a learning item.
 *
 * - `fields()` reads what the Add / Edit modals send (`status`, `schedule_publish`, `scheduled_publish_at`) and gives
 *   the two columns to store, or says what is wrong with them.
 * - `publishDue()` is the sweep (`php spark content:publish-scheduled`, every minute): it publishes whatever is due.
 *
 * A scheduled row stays a draft, and hidden from students (like any draft, and like anything under a draft parent),
 * until its time. Times are as typed: the same clock the assessments' opening and closing dates use.
 */
class ScheduledPublishing
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: true, fields: array{status: string, scheduled_publish_at: ?string}}|array{ok: false, message: string}
     */
    public static function fields(array $input): array
    {
        $status = $input['status'] ?? 'draft';

        if (! in_array($status, ['draft', 'published'], true)) {
            return ['ok' => false, 'message' => 'Invalid status.'];
        }

        $scheduled = filter_var($input['schedule_publish'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // A published row has nothing left to schedule; a draft with the switch off has no schedule.
        if ($status === 'published' || ! $scheduled) {
            return ['ok' => true, 'fields' => ['status' => $status, 'scheduled_publish_at' => null]];
        }

        $typed = trim((string) ($input['scheduled_publish_at'] ?? ''));

        if ($typed === '') {
            return ['ok' => false, 'message' => 'Pick the date and time to publish.'];
        }

        $when = null;

        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $typed);
            $errors = \DateTime::getLastErrors();

            // A date that does not exist (February 31) is rolled forward by PHP, which would quietly publish on the wrong day.
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $when = $parsed;
                break;
            }
        }

        if ($when === null) {
            return ['ok' => false, 'message' => 'That date and time could not be read.'];
        }

        if ($when->getTimestamp() <= time()) {
            return ['ok' => false, 'message' => 'Pick a time in the future.'];
        }

        return ['ok' => true, 'fields' => ['status' => 'draft', 'scheduled_publish_at' => $when->format('Y-m-d H:i:s')]];
    }

    /**
     * Publishes every module, learning unit and learning item whose time has come.
     *
     * A quiz item with no question yet is left alone (a quiz cannot be published empty); it stays scheduled and
     * goes out on the first sweep after it has a question. A linked quiz or assignment is published with its item.
     *
     * @return array{modules: int, units: int, items: int}
     */
    public static function publishDue(): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $done = ['modules' => 0, 'units' => 0, 'items' => 0];

        foreach (['modules' => 'course_modules', 'units' => 'learning_units'] as $key => $table) {
            $db->table($table)
                ->where('status', 'draft')
                ->where('scheduled_publish_at IS NOT NULL', null, false)
                ->where('scheduled_publish_at <=', $now)
                ->update(['status' => 'published', 'scheduled_publish_at' => null, 'updated_at' => $now]);

            $done[$key] = $db->affectedRows();
        }

        $due = $db->table('learning_items')
            ->select('id')
            ->where('status', 'draft')
            ->where('scheduled_publish_at IS NOT NULL', null, false)
            ->where('scheduled_publish_at <=', $now)
            ->get()->getResultArray();

        if ($due === []) {
            return $done;
        }

        $assessmentModel = new AssessmentModel();
        $assessments     = new AssessmentService();
        $modules         = new ModuleService();

        foreach ($due as $row) {
            $id   = (int) $row['id'];
            $quiz = $assessmentModel->findByLearningItem($id);

            if ($quiz && $assessments->isEmptyQuiz($quiz)) {
                continue;
            }

            $db->table('learning_items')->where('id', $id)->update(['status' => 'published', 'scheduled_publish_at' => null, 'updated_at' => $now]);

            $modules->syncLinkedAssessment($id, ['status' => 'published']);

            $done['items']++;
        }

        return $done;
    }
}

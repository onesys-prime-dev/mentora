<?php

namespace App\Services\Courses\Assessments;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionResponseModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Grades\GradeItemModel;
use App\Models\Grades\GradingSchemeModel;
use App\Services\Courses\Grades\GradebookSyncService;
use App\Services\Courses\Grades\GradingEngineService;

/**
 * Brings stored quiz scores and the gradebook in step after a quiz's
 * questions change (added, removed, re-pointed).
 *
 * A submission's score is the sum of its answers' scores, so when questions
 * go the score goes with them; its percentage follows the quiz's new total;
 * the gradebook item (a snapshot of the total) takes the new total; and the
 * offering's grades are recalculated. Without this, a score could stay above
 * the quiz's maximum and push a category over 100%.
 */
class AssessmentRescoreService
{
    public function afterQuestionsChanged(int $assessmentId): void
    {
        $assessment = (new AssessmentModel())->find($assessmentId);

        if (!$assessment) {
            return;
        }

        $submissions = new AssessmentSubmissionModel();

        $attempts = $submissions
            ->where('assessment_id', $assessmentId)
            ->whereIn('status', AssessmentResultService::FINISHED_STATUSES)
            ->findAll();

        $total = (float) ($assessment['total_points'] ?? 0);

        $responses = new AssessmentQuestionResponseModel();

        foreach ($attempts as $attempt) {

            $score = (float) ($responses
                ->selectSum('score')
                ->where('submission_id', $attempt['id'])
                ->first()['score'] ?? 0);

            $submissions->update($attempt['id'], [
                'score' => $score,
                'percentage' => $total > 0 ? round($score / $total * 100, 2) : 0.0,
            ]);

        }

        $items = new GradeItemModel();

        $item = $items->findForAssessment($assessmentId);

        if (!$item || $item['status'] !== 'Active') {
            return;
        }

        $items->update($item['id'], [
            'max_points' => $total,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new GradebookSyncService())->syncAssessmentScores($assessmentId);

        $schemes = new GradingSchemeModel();

        $scheme = $schemes->find((int) $item['grading_scheme_id']);

        if (!$scheme || empty($scheme['course_offering_id'])) {
            return;
        }

        if ($scheme['status'] === 'Published') {
            (new GradingEngineService())->recalculateOffering((int) $scheme['course_offering_id']);

            return;
        }

        $schemes->markNeedsRecalculation((int) $scheme['id']);
    }
}

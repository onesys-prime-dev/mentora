<?php

namespace App\Services\Courses\Grades;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Grades\GradingSchemeModel;
use App\Models\Grades\GradeItemModel;
use App\Models\Grades\GradebookItemScoreModel;
use App\Services\Courses\Assessments\AssessmentResultService;

/**
 * Mirrors grading activity into the gradebook.
 *
 * The Assessment module stays the source of truth for assessment scores:
 * which attempt counts is decided by AssessmentResultService (best graded
 * quiz attempt, latest graded assignment) — not re-decided here. This
 * service copies that counted score into gradebook_item_scores and, when the
 * scheme is live, refreshes the student's final grade.
 */
class GradebookSyncService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentSubmissionModel $submissionModel;
    protected GradingSchemeModel $schemeModel;
    protected GradeItemModel $itemModel;
    protected GradebookItemScoreModel $scoreModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->schemeModel = new GradingSchemeModel();
        $this->itemModel = new GradeItemModel();
        $this->scoreModel = new GradebookItemScoreModel();
    }

    /**
     * After a student's work on an assessment is graded (or a grade is
     * reopened): update their gradebook score, then their final grade while
     * the scheme is published. A scheme flagged for recalculation is refreshed
     * all the same: the flag only says the configuration changed, and the
     * student's grade is always computed from the configuration as it is now.
     */
    public function syncItemScore(int $assessmentId, int $studentId): void
    {
        $item = $this->itemModel->findForAssessment($assessmentId);

        if (!$item || $item['status'] !== 'Active') {
            return;
        }

        $scheme = $this->schemeModel->find((int) $item['grading_scheme_id']);

        if (!$scheme || empty($scheme['course_offering_id'])) {
            return;
        }

        $this->writeScore($item, $scheme, $studentId);

        if ($scheme['status'] === 'Published') {
            (new GradingEngineService())->recalculateStudent((int) $scheme['course_offering_id'], $studentId);
        }
    }

    /**
     * Every student's counted score on one assessment (no final-grade
     * refresh — callers recalculate the offering). Returns how many scores
     * were written.
     */
    public function syncAssessmentScores(int $assessmentId): int
    {
        $item = $this->itemModel->findForAssessment($assessmentId);

        if (!$item) {
            return 0;
        }

        $scheme = $this->schemeModel->find((int) $item['grading_scheme_id']);

        if (!$scheme || empty($scheme['course_offering_id'])) {
            return 0;
        }

        $studentIds = array_unique(array_map('intval', array_column(
            $this->submissionModel->select('student_id')->where('assessment_id', $assessmentId)->findAll(),
            'student_id'
        )));

        $written = 0;

        foreach ($studentIds as $studentId) {
            $written += $this->writeScore($item, $scheme, $studentId) ? 1 : 0;
        }

        return $written;
    }

    /**
     * An instructor-entered score on a manual item (offline import).
     * Null clears it.
     */
    public function setManualScore(int $offeringId, int $gradeItemId, int $studentId, ?float $score, int $gradedBy): void
    {
        if ($score === null) {
            $this->scoreModel->where('grade_item_id', $gradeItemId)->where('student_id', $studentId)->delete();
            return;
        }

        $item = $this->itemModel->find($gradeItemId);

        $max = (float) ($item['max_points'] ?? 0);

        $this->scoreModel->upsertScore($offeringId, $studentId, $gradeItemId, [
            'assessment_submission_id' => null,
            'raw_score' => $score,
            'raw_percentage' => $max > 0 ? round($score / $max * 100, 2) : null,
            'is_missing' => 0,
            'graded_by' => $gradedBy,
            'graded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Copies the counted submission's score; removes the row when nothing
     * counts any more (e.g. the only graded attempt was reopened).
     */
    private function writeScore(array $item, array $scheme, int $studentId): bool
    {
        $assessment = $this->assessmentModel->find((int) $item['assessment_id']);

        if (!$assessment) {
            return false;
        }

        $attempts = $this->submissionModel
            ->where('assessment_id', $assessment['id'])
            ->where('student_id', $studentId)
            ->orderBy('id', 'ASC')
            ->findAll();

        $result = (new AssessmentResultService())->resultFor($this->isQuiz($assessment), $attempts);

        $counted = $result['counted'];

        if ($counted === null) {
            $this->scoreModel->where('grade_item_id', $item['id'])->where('student_id', $studentId)->delete();
            return false;
        }

        $max = (float) $item['max_points'];
        $score = (float) $counted['score'];

        $this->scoreModel->upsertScore((int) $scheme['course_offering_id'], $studentId, (int) $item['id'], [
            'assessment_submission_id' => $counted['id'],
            'raw_score' => $score,
            'raw_percentage' => $max > 0 ? round($score / $max * 100, 2) : null,
            'is_missing' => 0,
            'graded_by' => $counted['graded_by'] ?? null,
            'graded_at' => $counted['graded_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    private function isQuiz(array $assessment): bool
    {
        $type = db_connect()->table('assessment_types')->select('name')->where('id', $assessment['assessment_type_id'])->get()->getRowArray();

        return strtolower((string) ($type['name'] ?? '')) === 'quiz';
    }
}

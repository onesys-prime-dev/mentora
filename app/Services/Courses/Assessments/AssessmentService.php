<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSettingsModel;
use App\Models\Assessments\AssessmentTypeModel;
use App\Models\Assessments\AssessmentRubricModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentFeedbackModel;

use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\CourseModuleModel;
use App\Models\Courses\CourseOfferingModel;
use App\Services\Courses\ContentDeletionService;
use App\Services\Courses\LearningProgressService;
use App\Services\Courses\Grades\GradeItemSyncService;

class AssessmentService
{

    protected AssessmentModel $assessmentModel;
    protected AssessmentSettingsModel $assessmentSettingsModel;
    protected AssessmentTypeModel $assessmentTypeModel;
    protected AssessmentRubricModel $rubricModel;
    protected AssessmentQuestionModel $questionModel;
    protected AssessmentSubmissionModel $submissionModel;

    protected LearningItemModel $learningItemModel;
    protected LearningUnitModel $learningUnitModel;
    protected CourseModuleModel $courseModuleModel;
    protected CourseOfferingModel $courseOfferingModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->assessmentSettingsModel = new AssessmentSettingsModel();
        $this->assessmentTypeModel = new AssessmentTypeModel();
        $this->rubricModel = new AssessmentRubricModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->submissionModel = new AssessmentSubmissionModel();

        $this->learningItemModel = new LearningItemModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->courseOfferingModel = new CourseOfferingModel();
    }

    public function getAssessmentIndex(
        User $user,
        int $offeringId
    ): array {

        $assessmentTypes = $this->assessmentTypeModel
            ->where('status', 'Active')
            ->orderBy('name', 'ASC')
            ->findAll();

        $modules = $this->courseModuleModel
            ->retrieveCourseModules($offeringId, $user);

        $learningUnits = [];

        foreach ($modules as $module) {

            $learningUnits = array_merge(
                $learningUnits,
                $this->learningUnitModel
                    ->retrieveLearningUnits($module['id'], $user)
            );

        }

        $offering = $this->courseOfferingModel
            ->find($offeringId);

        // Role-specific metrics, list rows and sidebars (students only get
        // what they're allowed to see; instructor numbers are this section's).
        $overview = $offering
            ? (new AssessmentOverviewService($this))->build($user, $offeringId, (int) $offering['course_id'])
            : ['role' => $user->inGroup('instructor') ? 'instructor' : 'student', 'metrics' => [], 'assessments' => []];

        return [
            'html' => view(
                'pages/courses/features/assessments/index',
                [
                    'user' => $user,
                    'assessmentTypes' => $assessmentTypes,
                    'modules' => $modules,
                    'learningUnits' => $learningUnits,
                    'overview' => $overview,
                ]
            ),
        ];
    }

    public function viewAssessment(
        User $user,
        int $assessmentId
    ): array {

        $isStudent = !$user->inGroup('instructor');

        // Students never see drafts, archived work, or anything whose
        // Modules item is hidden — not even through a deep link.
        if ($isStudent) {

            $row = $this->assessmentModel->find($assessmentId);

            if (!$row || !$this->isVisibleToStudent($row)) {
                throw PageNotFoundException::forPageNotFound('Assessment not found.');
            }

        }

        $data = $this->buildAssessmentContext($assessmentId, $isStudent);

        $isAssignment = strtolower($data['assessment']['assessment_type']) === 'assignment';

        // The score shown is the attempt that counts: the best quiz attempt,
        // or the latest graded assignment submission.
        $scoreResult = (new AssessmentResultService())->resultFor(
            !$isAssignment,
            $this->submissionModel
                ->where('assessment_id', $assessmentId)
                ->where('student_id', $user->id)
                ->findAll()
        );

        $submission = $scoreResult['counted'] ?? $scoreResult['latest'];

        if ($submission) {
            $submission['feedback_thread'] = (new AssessmentFeedbackModel())->listForSubmission((int) $submission['id']);
        }

        $rubric = $isAssignment ? $this->rubricModel->findByAssessment($assessmentId) : null;

        $learningItemId = (int) ($data['assessment']['learning_item_id'] ?? 0);

        // The student's completion of the linked Modules item, and whether
        // that item is still locked behind a prerequisite.
        $data['assessment']['learning_completion'] = $learningItemId && !$user->inGroup('instructor')
            ? db_connect()->table('learning_completions')
                ->select(['status', 'completed_at'])
                ->where('learning_item_id', $learningItemId)
                ->where('student_user_id', $user->id)
                ->get()
                ->getRowArray()
            : null;

        $lock = $learningItemId
            ? (new LearningProgressService())->lockFor($user, $learningItemId)
            : null;

        return [
            'html' => view(
                'pages/courses/features/assessments/view',
                [
                    'user' => $user,
                    'currentAssessment' => $data['assessment'],
                    'currentSettings' => $data['settings'],
                    'currentUnit' => $data['currentUnit'],
                    'previousAssessment' => $data['previousAssessment'],
                    'nextAssessment' => $data['nextAssessment'],
                    'submission' => $submission,
                    'scoreResult' => $scoreResult,
                    'currentRubric' => $rubric ? $this->rubricModel->getWithCriteria($rubric['id']) : null,
                    'assignmentState' => $isAssignment
                        ? (new AssignmentSubmissionService())->getState($user, $assessmentId)
                        : null,
                    'quizState' => !$isAssignment && !$user->inGroup('instructor')
                        ? $this->quizState($user, $data['assessment'])
                        : null,
                    'assessmentLock' => $lock,
                ]
            ),
        ];
    }

    public function editAssessment(
        User $user,
        int $assessmentId
    ): array {

        $data = $this->buildAssessmentContext($assessmentId);

        $rubric = $this->rubricModel->findByAssessment($assessmentId);

        $currentRubric = $rubric
            ? $this->rubricModel->getWithCriteria($rubric['id'])
            : null;

        $currentQuestions = $this->questionModel
            ->listForAssessment($assessmentId);

        return [
            'html' => view(
                'pages/courses/features/assessments/editor',
                [
                    'user' => $user,
                    'currentAssessment' => $data['assessment'],
                    'currentSettings' => $data['settings'],
                    'currentUnit' => $data['currentUnit'],
                    'previousAssessment' => $data['previousAssessment'],
                    'nextAssessment' => $data['nextAssessment'],
                    'currentRubric' => $currentRubric,
                    'currentQuestions' => $currentQuestions,
                ]
            ),
        ];
    }

    public function quizBuilder(
        User $user,
        int $assessmentId
    ): array {

        $assessment = $this->assessmentModel
            ->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound(
                'Assessment not found.'
            );
        }

        $currentQuestions = $this->questionModel
            ->listForAssessment($assessmentId);

        return [
            'html' => view(
                'pages/courses/features/assessments/quiz-builder',
                [
                    'user' => $user,
                    'currentAssessment' => $assessment,
                    'currentQuestions' => $currentQuestions,
                    // AI generation's "Learning Unit" source: the unit this
                    // quiz sits under (null when it isn't in one).
                    'sourceUnit' => (new LearningUnitContentExtractor())->unitForAssessment($assessment, $user),
                ]
            ),
        ];
    }

    public function updateAssessmentInstructions(
        User $user,
        int $assessmentId,
        array $instructions
    ): array {

        $assessment = $this->assessmentModel
            ->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $this->assessmentModel->update(
            $assessmentId,
            [
                'instructions' => json_encode(
                    $instructions,
                    JSON_UNESCAPED_UNICODE
                ),
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return [
            'success' => true,
            'message' => 'Instructions saved.',
        ];
    }

    public function updateSubmissionSettings(
        User $user,
        int $assessmentId,
        array $data
    ): array {

        $assessment = $this->assessmentModel
            ->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $settings = $this->assessmentSettingsModel
            ->where('assessment_id', $assessmentId)
            ->first();

        $settingsData = [
            'allow_file_upload' => !empty($data['allow_file_upload']),
            'allow_text_entry' => !empty($data['allow_text_entry']),
            'allow_link_entry' => !empty($data['allow_link_entry']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($settings) {

            $this->assessmentSettingsModel->update(
                $settings['id'],
                $settingsData
            );

        } else {

            $this->assessmentSettingsModel->insert(
                array_merge(
                    $settingsData,
                    [
                        'assessment_id' => $assessmentId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]
                )
            );

        }

        return [
            'success' => true,
            'message' => 'Submission settings saved.',
        ];
    }

    /**
     * Assessments a student may see: Published or Closed, and — when linked
     * to a Modules learning item — only while that item, its unit and its
     * module are all published. Drafts and archived work stay hidden.
     *
     * @param list<array> $assessments assessment rows (need id, status, learning_item_id)
     * @return array<int, true> visible assessment ids
     */
    public function studentVisibleIds(array $assessments): array
    {
        $itemIds = array_values(array_filter(array_map(
            static fn (array $a) => (int) ($a['learning_item_id'] ?? 0),
            $assessments
        )));

        $visibleItems = [];

        if ($itemIds !== []) {

            $rows = db_connect()->table('learning_items')
                ->select('learning_items.id')
                ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
                ->join('course_modules', 'course_modules.id = learning_units.module_id')
                ->whereIn('learning_items.id', $itemIds)
                ->where('learning_items.status', 'published')
                ->where('learning_units.status', 'published')
                ->where('course_modules.status', 'published')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $visibleItems[(int) $row['id']] = true;
            }

        }

        $visible = [];

        foreach ($assessments as $assessment) {

            $itemId = (int) ($assessment['learning_item_id'] ?? 0);

            if (
                in_array($assessment['status'] ?? '', ['Published', 'Closed'], true)
                && ($itemId === 0 || isset($visibleItems[$itemId]))
            ) {
                $visible[(int) $assessment['id']] = true;
            }

        }

        return $visible;
    }

    public function isVisibleToStudent(array $assessment): bool
    {
        return isset($this->studentVisibleIds([$assessment])[(int) $assessment['id']]);
    }

    private function buildAssessmentContext(int $assessmentId, bool $forStudent = false): array
    {

        $assessment = $this->assessmentModel
            ->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound(
                'Assessment not found.'
            );
        }

        $settings = $this->assessmentSettingsModel
            ->where('assessment_id', $assessmentId)
            ->first();

        $assessment = $this->withTypeAndInstructions($assessment);

        /*
        |--------------------------------------------------------------------------
        | Resolve Learning Unit Context
        |--------------------------------------------------------------------------
        */

        $currentUnit = null;

        if ($assessment['learning_item_id']) {

            $learningItem = $this->learningItemModel
                ->find($assessment['learning_item_id']);

            if ($learningItem) {
                $currentUnit = $this->learningUnitModel
                    ->find($learningItem['learning_unit_id']);
            }

        } elseif ($assessment['module_id']) {

            $currentUnit = $this->learningUnitModel
                ->where('module_id', $assessment['module_id'])
                ->orderBy('order_index', 'ASC')
                ->first();

        }

        $currentUnit = $currentUnit ?? ['title' => ''];

        $assessment['learning_completion'] = null;

        /*
        |--------------------------------------------------------------------------
        | Resolve Previous / Next Assessment
        |--------------------------------------------------------------------------
        */

        // Same section only — sections of one course share course_id, so
        // siblings are found through their module's offering.
        $sectionOfferingId = (int) ($this->courseModuleModel->find((int) $assessment['module_id'])['offering_id'] ?? 0);

        $siblings = $this->assessmentModel
            ->select('assessments.*')
            ->join('course_modules', 'course_modules.id = assessments.module_id')
            ->where('assessments.course_id', $assessment['course_id'])
            ->where('course_modules.offering_id', $sectionOfferingId)
            ->orderBy('assessments.due_date', 'ASC')
            ->orderBy('assessments.id', 'ASC')
            ->findAll();

        // Students step only through assessments they're allowed to see.
        if ($forStudent) {

            $visible = $this->studentVisibleIds($siblings);

            $siblings = array_values(array_filter(
                $siblings,
                static fn (array $sibling) => isset($visible[(int) $sibling['id']])
            ));

        }

        $currentIndex = null;

        foreach ($siblings as $index => $sibling) {

            if ((int) $sibling['id'] === (int) $assessment['id']) {
                $currentIndex = $index;
                break;
            }

        }

        $previousAssessment = ($currentIndex !== null && $currentIndex > 0)
            ? $siblings[$currentIndex - 1]
            : null;

        $nextAssessment = ($currentIndex !== null && $currentIndex < count($siblings) - 1)
            ? $siblings[$currentIndex + 1]
            : null;

        return [
            'assessment' => $assessment,
            'settings' => $settings,
            'currentUnit' => $currentUnit,
            'previousAssessment' => $previousAssessment,
            'nextAssessment' => $nextAssessment,
        ];
    }

    /**
     * Adds `assessment_type` (the type's name, e.g. "Quiz") and decodes the
     * `instructions` JSON into the editor's block document.
     */
    private function withTypeAndInstructions(array $assessment): array
    {
        $assessmentType = $this->assessmentTypeModel
            ->find($assessment['assessment_type_id']);

        $assessment['assessment_type'] = $assessmentType['name'] ?? '';

        $decodedInstructions = json_decode(
            $assessment['instructions'] ?? '',
            true
        );

        $assessment['instructions'] = is_array($decodedInstructions)
            ? $decodedInstructions
            : ['version' => 1, 'blocks' => []];

        return $assessment;
    }

    /**
     * Everything a quiz/assignment learning item needs to render its
     * assessment inside Modules. Students get their own progress
     * (`state`); instructors get submission counts (`counts`).
     */
    public function buildLearningItemContext(User $user, int $learningItemId): ?array
    {
        $assessment = $this->assessmentModel->findByLearningItem($learningItemId);

        if (!$assessment) {
            return null;
        }

        $assessment = $this->withTypeAndInstructions($assessment);

        $assessmentId = (int) $assessment['id'];

        $isInstructor = $user->inGroup('instructor');

        $isQuiz = strtolower($assessment['assessment_type']) === 'quiz';

        $rubric = null;

        if (!$isQuiz) {

            $rubricRow = $this->rubricModel->findByAssessment($assessmentId);

            $rubric = $rubricRow ? $this->rubricModel->getWithCriteria($rubricRow['id']) : null;

        }

        $state = null;

        if (!$isInstructor) {

            $state = $isQuiz
                ? $this->quizState($user, $assessment)
                : (new AssignmentSubmissionService())->getState($user, $assessmentId);

        }

        return [
            'assessment' => $assessment,
            'settings' => $this->assessmentSettingsModel->where('assessment_id', $assessmentId)->first(),
            'rubric' => $rubric,
            'questionCount' => $isQuiz
                ? $this->questionModel->where('assessment_id', $assessmentId)->where('status', 'Active')->countAllResults()
                : 0,
            'state' => $state,
            'counts' => $isInstructor ? $this->submissionCounts($assessmentId, $this->offeringIdForItem($learningItemId)) : null,
        ];
    }

    private function quizState(User $user, array $assessment): array
    {
        $attempts = $this->submissionModel
            ->where('assessment_id', $assessment['id'])
            ->where('student_id', $user->id)
            ->orderBy('attempt_number', 'DESC')
            ->findAll();

        $finished = array_values(array_filter(
            $attempts,
            static fn (array $attempt) => $attempt['status'] !== 'In Progress'
        ));

        $inProgress = count($finished) < count($attempts);

        $attemptLimit = max(1, (int) ($assessment['attempt_limit'] ?? 1));

        $exhausted = !$inProgress && count($finished) >= $attemptLimit;

        // An attempt already running can always be resumed; a new one also
        // needs the quiz to be open right now.
        $unavailable = $inProgress ? null : $this->quizAvailabilityReason($assessment);

        $reason = match (true) {
            $exhausted => $attemptLimit === 1 ? "You've already taken this quiz." : "You've used all {$attemptLimit} attempts.",
            $unavailable !== null => $unavailable,
            default => null,
        };

        $result = (new AssessmentResultService())->resultFor(true, $attempts);

        return [
            'attempts' => $attempts,
            'latest' => $finished[0] ?? null,
            'best' => $result['counted'],
            'counted' => $result['counted'],
            'result' => $result,
            'attemptsUsed' => count($finished),
            'attemptLimit' => $attemptLimit,
            'inProgress' => $inProgress,
            'canTake' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Why a student can't start a new attempt at this quiz right now, or
     * null when they can: it must be Published, inside its availability
     * window, and not past due unless late attempts are allowed.
     */
    public function quizAvailabilityReason(array $assessment): ?string
    {
        $now = time();

        if (($assessment['status'] ?? '') === 'Closed') {
            return 'This quiz is closed.';
        }

        if (($assessment['status'] ?? '') !== 'Published') {
            return "This quiz isn't open yet.";
        }

        if (!empty($assessment['available_from']) && strtotime($assessment['available_from']) > $now) {
            return 'This quiz opens ' . date('M j, Y g:i A', strtotime($assessment['available_from'])) . '.';
        }

        if (!empty($assessment['available_until']) && strtotime($assessment['available_until']) < $now) {
            return 'This quiz closed on ' . date('M j, Y g:i A', strtotime($assessment['available_until'])) . '.';
        }

        if (!empty($assessment['due_date']) && strtotime($assessment['due_date']) < $now) {

            $settings = $this->assessmentSettingsModel->where('assessment_id', $assessment['id'])->first();

            if (empty($settings['allow_late_submission'])) {
                return "The due date has passed and late attempts aren't allowed.";
            }

        }

        return null;
    }

    /**
     * Distinct students per state — a student with three attempts counts
     * once, so these read as "N students", not "N attempts".
     */
    private function submissionCounts(int $assessmentId, int $offeringId): array
    {
        // Each student counts once, by their latest finished attempt, and
        // only students of this section — the same numbers Grade Work shows.
        $overview = new AssessmentOverviewService($this);

        $attempts = $overview->latestAttempts([$assessmentId], $overview->sectionStudentIds($offeringId))[$assessmentId] ?? [];

        $assessment = $this->withTypeAndInstructions($this->assessmentModel->find($assessmentId));

        $isQuiz = strtolower($assessment['assessment_type']) === 'quiz';

        $results = new AssessmentResultService();

        $counts = ['submitted' => 0, 'toGrade' => 0, 'graded' => 0];

        foreach ($attempts as $attempt) {

            $result = $results->resultFor($isQuiz, $attempt['attempts']);

            $counts['submitted'] += $result['finished'] !== [] ? 1 : 0;

            $counts['toGrade'] += $result['pending'] !== [] ? 1 : 0;

            $counts['graded'] += $result['counted'] !== null ? 1 : 0;

        }

        return $counts;
    }

    private function offeringIdForItem(int $learningItemId): int
    {
        return (int) (db_connect()->table('learning_items')
            ->select('course_modules.offering_id')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_items.id', $learningItemId)
            ->get()
            ->getRowArray()['offering_id'] ?? 0);
    }

    /**
     * Keeps a quiz/assignment learning item's visibility matching its
     * assessment. Writes the item directly (not through ModuleService) so the
     * two sides' syncs can't call back into each other. Closed keeps the item
     * visible — submission is blocked instead.
     */
    private function syncLearningItemStatus(array $assessment, string $status): void
    {
        if (empty($assessment['learning_item_id'])) {
            return;
        }

        $itemStatus = match ($status) {
            'Published' => 'published',
            'Draft', 'Archived' => 'draft',
            default => null,
        };

        if ($itemStatus !== null) {
            $this->learningItemModel->update($assessment['learning_item_id'], ['status' => $itemStatus]);
        }
    }

    /**
     * Keeps a quiz's learning item showing the same estimated time as the
     * quiz's own duration — the module duration badge sums item minutes,
     * so a quiz's "minutes" must come from here, not be entered twice.
     */
    private function syncLearningItemDuration(array $assessment, ?int $duration): void
    {
        if (empty($assessment['learning_item_id'])) {
            return;
        }

        $this->learningItemModel->update($assessment['learning_item_id'], [
            'estimated_minutes' => $duration,
        ]);
    }

    public function createAssessment(User $user, int $moduleId, int $learningUnitId, array $data): array {
        $db = db_connect();

        try {

            /*
            |--------------------------------------------------------------------------
            | Resolve Course Hierarchy
            |--------------------------------------------------------------------------
            */

            $module = $this->courseModuleModel
                ->find($moduleId);

            if (!$module) {
                return [
                    'success' => false,
                    'message' => 'Course module not found.'
                ];
            }

            $learningUnit = $this->learningUnitModel
                ->find($learningUnitId);

            if (!$learningUnit || (int) $learningUnit['module_id'] !== $moduleId) {
                return [
                    'success' => false,
                    'message' => 'Learning unit not found.'
                ];
            }

            $offering = $this->courseOfferingModel
                ->find($module['offering_id']);

            if (!$offering) {
                return [
                    'success' => false,
                    'message' => 'Course offering not found.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Transaction
            |--------------------------------------------------------------------------
            */

            $db->transBegin();

            /*
            |--------------------------------------------------------------------------
            | Create Learning Item
            |--------------------------------------------------------------------------
            */

            $assessmentType = $this->assessmentTypeModel
                ->find($data['assessment_type_id']);

            $lastItem = $this->learningItemModel
                ->where('learning_unit_id', $learningUnitId)
                ->orderBy('order_index', 'DESC')
                ->first();

            $this->learningItemModel->insert([

                'learning_unit_id' => $learningUnitId,

                'title'      => $data['title'],

                'item_type'  => strtolower($assessmentType['name'] ?? 'assignment'),

                'order_index' => $lastItem ? $lastItem['order_index'] + 1 : 1,

                'status'     => 'draft',

            ]);

            $learningItemId = $this->learningItemModel->getInsertID();

            /*
            |--------------------------------------------------------------------------
            | Create Assessment + Default Settings
            |--------------------------------------------------------------------------
            */

            $assessmentId = $this->insertAssessmentWithSettings(
                $user,
                $offering['course_id'],
                $module['id'],
                $learningItemId,
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            if ($db->transStatus() === false) {

                $db->transRollback();

                return [
                    'success' => false,
                    'message' => 'Unable to create assessment.'
                ];
            }

            $db->transCommit();

            return [

                'success' => true,

                'message' => 'Assessment created successfully.',

                // The modal opens the new assessment's editor next.
                'assessment_id' => (int) $assessmentId,

                'learning_item_id' => (int) $learningItemId,

            ];

        } catch (\Throwable $e) {

            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Create an assessment (+ default settings) for a learning item that
     * already exists (e.g. created via the learning item create modal).
     */
    public function createAssessmentForLearningItem(
        User $user,
        int $moduleId,
        int $learningItemId,
        int $assessmentTypeId,
        string $title
    ): array {
        $db = db_connect();

        try {

            $module = $this->courseModuleModel
                ->find($moduleId);

            if (!$module) {
                return [
                    'success' => false,
                    'message' => 'Course module not found.'
                ];
            }

            $offering = $this->courseOfferingModel
                ->find($module['offering_id']);

            if (!$offering) {
                return [
                    'success' => false,
                    'message' => 'Course offering not found.'
                ];
            }

            $db->transBegin();

            $assessmentId = $this->insertAssessmentWithSettings(
                $user,
                $offering['course_id'],
                $module['id'],
                $learningItemId,
                [
                    'assessment_type_id' => $assessmentTypeId,
                    'title' => $title,
                ]
            );

            if ($db->transStatus() === false) {

                $db->transRollback();

                return [
                    'success' => false,
                    'message' => 'Unable to create assessment.'
                ];
            }

            $db->transCommit();

            return [
                'success' => true,
                'message' => 'Assessment created successfully.',
                'assessment_id' => $assessmentId,
            ];

        } catch (\Throwable $e) {

            $db->transRollback();

            throw $e;
        }
    }

    private function insertAssessmentWithSettings(
        User $user,
        int $courseId,
        int $moduleId,
        ?int $learningItemId,
        array $data
    ): int {

        $assessment = [

            'course_id'            => $courseId,
            'module_id'            => $moduleId,
            'learning_item_id'     => $learningItemId,

            'assessment_type_id'   => $data['assessment_type_id'],

            'title'                => $data['title'],
            'description'          => $data['description'] ?? null,
            'instructions'         => $data['instructions'] ?? null,

            'available_from'       => $data['available_from'] ?? date('Y-m-d H:i:s'),
            'due_date'             => $data['due_date'] ?? date('Y-m-d H:i:s', strtotime('+7 days')),

            'duration'             => $data['duration'] ?? null,

            'total_points'         => $data['total_points'] ?? 0,

            'weight_percentage'    => $data['weight_percentage'] ?? 0,

            'attempt_limit'        => $data['attempt_limit'] ?? 1,

            'passing_score'        => $data['passing_score'] ?? null,

            'created_by'           => $user->id,

            'created_at'           => date('Y-m-d H:i:s'),

            'status'               => 'Draft'
        ];

        $this->assessmentModel->insert($assessment);

        $assessmentId = $this->assessmentModel->getInsertID();

        $settings = [

            'assessment_id' => $assessmentId,

            'allow_file_upload'
                => $data['allow_file_upload'] ?? true,

            'allow_text_entry'
                => $data['allow_text_entry'] ?? false,

            'allow_link_entry'
                => $data['allow_link_entry'] ?? false,

            'allow_late_submission'
                => $data['allow_late_submission'] ?? false,

            'show_results_immediately'
                => $data['show_results_immediately'] ?? false,

            'show_correct_answers'
                => $data['show_correct_answers'] ?? false,

            'allow_review_after_submission'
                => $data['allow_review_after_submission'] ?? true,

            'require_password'
                => $data['require_password'] ?? false,

            'password'
                => $data['password'] ?? null,

            'randomize_questions'
                => $data['randomize_questions'] ?? false,

            'randomize_choices'
                => $data['randomize_choices'] ?? false,

            'browser_lockdown'
                => $data['browser_lockdown'] ?? false,

            'allow_multiple_attempts'
                => $data['allow_multiple_attempts'] ?? false,

            'created_at'
                => date('Y-m-d H:i:s')
        ];

        $this->assessmentSettingsModel->insert($settings);

        return $assessmentId;
    }

    public function updateAssessment(User $user, int $assessmentId, array $data): array {
        $db = db_connect();

        try {

            /*
            |--------------------------------------------------------------------------
            | Retrieve Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = $this->assessmentModel->find($assessmentId);

            if (!$assessment) {
                return [
                    'success' => false,
                    'message' => 'Assessment not found.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve Assessment Settings
            |--------------------------------------------------------------------------
            */

            $settings = $this->assessmentSettingsModel
                ->where('assessment_id', $assessmentId)
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Transaction
            |--------------------------------------------------------------------------
            */

            $db->transBegin();

            /*
            |--------------------------------------------------------------------------
            | Update Assessment
            |--------------------------------------------------------------------------
            */

            $totalPoints = $data['total_points'] ?? $assessment['total_points'];

            $passingPercentage = $data['passing_percentage'] ?? $assessment['passing_percentage'];

            $passingScore = $passingPercentage !== null
                ? round(((float) $totalPoints) * ((float) $passingPercentage) / 100, 2)
                : null;

            $allowMultipleAttempts = array_key_exists('allow_multiple_attempts', $data)
                ? (bool) $data['allow_multiple_attempts']
                : (bool) ($settings['allow_multiple_attempts'] ?? false);

            $attemptLimit = $allowMultipleAttempts
                ? ($data['attempt_limit'] ?? $assessment['attempt_limit'])
                : 1;

            $assessmentData = [

                'assessment_type_id' => $data['assessment_type_id'] ?? $assessment['assessment_type_id'],

                'title'              => $data['title'] ?? $assessment['title'],

                'description'        => $data['description'] ?? $assessment['description'],

                'instructions'       => $data['instructions'] ?? $assessment['instructions'],

                'available_from'     => $data['available_from'] ?? $assessment['available_from'],

                'due_date'           => $data['due_date'] ?? $assessment['due_date'],

                'available_until'    => $data['available_until'] ?? $assessment['available_until'],

                'duration'           => $data['duration'] ?? $assessment['duration'],

                'total_points'       => $totalPoints,

                'weight_percentage'  => $data['weight_percentage'] ?? $assessment['weight_percentage'],

                'attempt_limit'      => $attemptLimit,

                'passing_percentage' => $passingPercentage,

                'passing_score'      => $passingScore,

                'status'             => $data['status'] ?? $assessment['status'],

                'updated_by'         => $user->id,

                'updated_at'         => date('Y-m-d H:i:s')

            ];

            $this->assessmentModel->update(
                $assessmentId,
                $assessmentData
            );

            if (array_key_exists('status', $data)) {
                $this->syncLearningItemStatus($assessment, $data['status']);

                (new GradeItemSyncService())->syncFromAssessment($assessmentId);
            }

            if (array_key_exists('duration', $data)) {
                $this->syncLearningItemDuration($assessment, $assessmentData['duration'] !== null ? (int) $assessmentData['duration'] : null);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Assessment Settings
            |--------------------------------------------------------------------------
            */

            if ($settings) {

                $settingsData = [

                    'allow_late_submission' =>
                        $data['allow_late_submission'] ?? $settings['allow_late_submission'],

                    'show_results_immediately' =>
                        $data['show_results_immediately'] ?? $settings['show_results_immediately'],

                    'show_correct_answers' =>
                        $data['show_correct_answers'] ?? $settings['show_correct_answers'],

                    'allow_review_after_submission' =>
                        $data['allow_review_after_submission'] ?? $settings['allow_review_after_submission'],

                    'require_password' =>
                        $data['require_password'] ?? $settings['require_password'],

                    'password' =>
                        $data['password'] ?? $settings['password'],

                    'randomize_questions' =>
                        $data['randomize_questions'] ?? $settings['randomize_questions'],

                    'randomize_choices' =>
                        $data['randomize_choices'] ?? $settings['randomize_choices'],

                    'browser_lockdown' =>
                        $data['browser_lockdown'] ?? $settings['browser_lockdown'],

                    'allow_multiple_attempts' =>
                        $allowMultipleAttempts,

                    'updated_at' => date('Y-m-d H:i:s')

                ];

                $this->assessmentSettingsModel->update(
                    $settings['id'],
                    $settingsData
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Commit Transaction
            |--------------------------------------------------------------------------
            */

            if ($db->transStatus() === false) {

                $db->transRollback();

                return [
                    'success' => false,
                    'message' => 'Unable to update assessment.'
                ];
            }

            $db->transCommit();

            return [

                'success' => true,

                'message' => 'Assessment updated successfully.',

                'assessment' => $this->assessmentModel->find($assessmentId)

            ];

        } catch (\Throwable $e) {

            $db->transRollback();

            throw $e;
        }
    } 
    
    public function deleteAssessment(int $assessmentId): array {

        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        // Also deletes the linked learning item (so Modules never shows a
        // dead quiz/assignment) and purges uploaded files nothing else uses.
        if (!(new ContentDeletionService())->deleteAssessment($assessmentId)) {
            return [
                'success' => false,
                'message' => 'Unable to delete assessment.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Assessment deleted successfully.'
        ];
    }

    public function archiveAssessment(User $user, int $assessmentId): array {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $this->assessmentModel->update(
            $assessmentId,
            [
                'status'     => 'Archived',
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );

        return [
            'success' => true,
            'message' => 'Assessment archived successfully.',
            'assessment' => $this->assessmentModel->find($assessmentId)
        ];
    } 
    
    public function publishedAssessment(User $user, int $assessmentId): array { 
         $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $this->assessmentModel->update(
            $assessmentId,
            [
                'status'     => 'Published',
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );

        // Published work counts toward the grade: add it to the gradebook.
        (new GradeItemSyncService())->syncFromAssessment($assessmentId);

        return [
            'success' => true,
            'message' => 'Assessment published successfully.',
            'assessment' => $this->assessmentModel->find($assessmentId)
        ];
    } 
    
    public function unpublishedAssessment(User $user, int $assessmentId): array { 
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $this->assessmentModel->update(
            $assessmentId,
            [
                'status'     => 'Draft',
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );

        return [
            'success' => true,
            'message' => 'Assessment unpublished successfully.',
            'assessment' => $this->assessmentModel->find($assessmentId)
        ];
    } 
    
    public function duplicateAssessment(User $user, int $assessmentId): array { 
        $db = db_connect();

        try {

            /*
            |--------------------------------------------------------------------------
            | Retrieve Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = $this->assessmentModel->find($assessmentId);

            if (!$assessment) {
                return [
                    'success' => false,
                    'message' => 'Assessment not found.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve Assessment Settings
            |--------------------------------------------------------------------------
            */

            $settings = $this->assessmentSettingsModel
                ->where('assessment_id', $assessmentId)
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Transaction
            |--------------------------------------------------------------------------
            */

            $db->transBegin();

            /*
            |--------------------------------------------------------------------------
            | Duplicate Assessment
            |--------------------------------------------------------------------------
            */

            unset(
                $assessment['id'],
                $assessment['created_at'],
                $assessment['updated_at']
            );

            $assessment['title'] .= ' (Copy)';
            $assessment['status'] = 'Draft';
            $assessment['created_by'] = $user->id;
            $assessment['created_at'] = date('Y-m-d H:i:s');
            $assessment['updated_by'] = null;
            $assessment['updated_at'] = null;

            $this->assessmentModel->insert($assessment);

            $newAssessmentId = $this->assessmentModel->getInsertID();

            /*
            |--------------------------------------------------------------------------
            | Duplicate Assessment Settings
            |--------------------------------------------------------------------------
            */

            if ($settings) {

                unset(
                    $settings['id'],
                    $settings['created_at'],
                    $settings['updated_at']
                );

                $settings['assessment_id'] = $newAssessmentId;
                $settings['created_at'] = date('Y-m-d H:i:s');
                $settings['updated_at'] = null;

                $this->assessmentSettingsModel->insert($settings);
            }

            /*
            |--------------------------------------------------------------------------
            | Commit Transaction
            |--------------------------------------------------------------------------
            */

            if ($db->transStatus() === false) {

                $db->transRollback();

                return [
                    'success' => false,
                    'message' => 'Unable to duplicate assessment.'
                ];
            }

            $db->transCommit();

            return [
                'success' => true,
                'message' => 'Assessment duplicated successfully.',
                'assessment' => $this->assessmentModel->find($newAssessmentId)
            ];

        } catch (\Throwable $e) {

            $db->transRollback();

            throw $e;
        }
    } 
    
    public function restoreAssessment(User $user, int $assessmentId): array { 
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $this->assessmentModel->update(
            $assessmentId,
            [
                'status'     => 'Draft',
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );

        return [
            'success' => true,
            'message' => 'Assessment restored successfully.',
            'assessment' => $this->assessmentModel->find($assessmentId)
        ];
    } 
    
    public function getAssessment(User $user, int $assessmentId): array {
        /*
        |--------------------------------------------------------------------------
        | Retrieve Assessment
        |--------------------------------------------------------------------------
        */

        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Retrieve Assessment Settings
        |--------------------------------------------------------------------------
        */

        $settings = $this->assessmentSettingsModel
            ->where('assessment_id', $assessmentId)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Return Assessment
        |--------------------------------------------------------------------------
        */

        return [
            'success'    => true,
            'assessment' => $assessment,
            'settings'   => $settings
        ];
    }
    
    public function getAssessmentStatistics(User $user, int $assessmentId): array { 
        /*
        |--------------------------------------------------------------------------
        | Retrieve Assessment
        |--------------------------------------------------------------------------
        */

        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Retrieve Statistics
        |--------------------------------------------------------------------------
        */

        $statistics = [

            'total_points'      => $assessment['total_points'],
            'attempt_limit'     => $assessment['attempt_limit'],
            'passing_score'     => $assessment['passing_score'],
            'status'            => $assessment['status'],
            'available_from'    => $assessment['available_from'],
            'due_date'          => $assessment['due_date'],
            'duration'          => $assessment['duration'],
            'weight_percentage' => $assessment['weight_percentage']

        ];

        /*
        |--------------------------------------------------------------------------
        | Return Statistics
        |--------------------------------------------------------------------------
        */

        return [
            'success' => true,
            'statistics' => $statistics
        ];
    }
}
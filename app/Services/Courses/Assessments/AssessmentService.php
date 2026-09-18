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

use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\CourseModuleModel;
use App\Models\Courses\CourseOfferingModel;

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

        $assessments = $offering
            ? $this->assessmentModel
                ->getAssessmentsWithSubmissions($offering['course_id'], $user->id)
            : [];

        return [
            'html' => view(
                'pages/courses/features/assessments/index',
                [
                    'user' => $user,
                    'assessmentTypes' => $assessmentTypes,
                    'modules' => $modules,
                    'learningUnits' => $learningUnits,
                    'assessments' => $assessments,
                ]
            ),
        ];
    }

    public function viewAssessment(
        User $user,
        int $assessmentId
    ): array {

        $data = $this->buildAssessmentContext($assessmentId);

        $submission = $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $user->id)
            ->orderBy('attempt_number', 'DESC')
            ->first();

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

    private function buildAssessmentContext(int $assessmentId): array
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

        /*
        |--------------------------------------------------------------------------
        | Resolve Assessment Type
        |--------------------------------------------------------------------------
        */

        $assessmentType = $this->assessmentTypeModel
            ->find($assessment['assessment_type_id']);

        $assessment['assessment_type'] = $assessmentType['name'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Decode Instructions JSON
        |--------------------------------------------------------------------------
        */

        $decodedInstructions = json_decode(
            $assessment['instructions'] ?? '',
            true
        );

        $assessment['instructions'] = is_array($decodedInstructions)
            ? $decodedInstructions
            : ['version' => 1, 'blocks' => []];

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

        $siblings = $this->assessmentModel
            ->where('course_id', $assessment['course_id'])
            ->orderBy('due_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

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

                'assessment_id' =>  $this->assessmentModel->find($assessmentId)

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

        $this->assessmentModel->delete($assessmentId);

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
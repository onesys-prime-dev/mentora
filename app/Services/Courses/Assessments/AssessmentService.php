<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Courses\Assessments\AssessmentModel;
use App\Models\Courses\Assessments\AssessmentSettingsModel;

use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Courses\CourseModuleModel;
use App\Models\Courses\CourseOfferingModel;

class AssessmentService
{

    protected AssessmentModel $assessmentModel;
    protected AssessmentSettingsModel $assessmentSettingsModel;

    protected LearningItemModel $learningItemModel;
    protected LearningUnitModel $learningUnitModel;
    protected CourseModuleModel $courseModuleModel;
    protected CourseOfferingModel $courseOfferingModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->assessmentSettingsModel = new AssessmentSettingsModel();

        $this->learningItemModel = new LearningItemModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->courseOfferingModel = new CourseOfferingModel();
    }

    public function getAssessmentIndex(
        User $user,
        int $offeringId
    ): array {

        return [
            'html' => view(
                'pages/courses/features/assessments/index'
            ),
        ];
    }

    public function createAssessment(User $user, int $learningItemId, array $data): array { 
        $db = db_connect();

        try {

            /*
            |--------------------------------------------------------------------------
            | Resolve Course Hierarchy
            |--------------------------------------------------------------------------
            */

            $learningItem = $this->learningItemModel
                ->find($learningItemId);

            if (!$learningItem) {
                return [
                    'success' => false,
                    'message' => 'Learning item not found.'
                ];
            }

            $learningUnit = $this->learningUnitModel
                ->find($learningItem['learning_unit_id']);

            if (!$learningUnit) {
                return [
                    'success' => false,
                    'message' => 'Learning unit not found.'
                ];
            }

            $module = $this->courseModuleModel
                ->find($learningUnit['module_id']);

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

            /*
            |--------------------------------------------------------------------------
            | Transaction
            |--------------------------------------------------------------------------
            */

            $db->transBegin();

            /*
            |--------------------------------------------------------------------------
            | Create Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = [

                'course_id'            => $offering['course_id'],
                'module_id'            => $module['id'],
                'learning_item_id'     => $learningItemId,

                'assessment_type_id'   => $data['assessment_type_id'],

                'title'                => $data['title'],
                'description'          => $data['description'] ?? null,
                'instructions'         => $data['instructions'] ?? null,

                'available_from'       => $data['available_from'],
                'due_date'             => $data['due_date'],

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

            /*
            |--------------------------------------------------------------------------
            | Default Settings
            |--------------------------------------------------------------------------
            */

            $settings = [

                'assessment_id' => $assessmentId,

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

            $assessmentData = [

                'assessment_type_id' => $data['assessment_type_id'] ?? $assessment['assessment_type_id'],

                'title'              => $data['title'] ?? $assessment['title'],

                'description'        => $data['description'] ?? $assessment['description'],

                'instructions'       => $data['instructions'] ?? $assessment['instructions'],

                'available_from'     => $data['available_from'] ?? $assessment['available_from'],

                'due_date'           => $data['due_date'] ?? $assessment['due_date'],

                'duration'           => $data['duration'] ?? $assessment['duration'],

                'total_points'       => $data['total_points'] ?? $assessment['total_points'],

                'weight_percentage'  => $data['weight_percentage'] ?? $assessment['weight_percentage'],

                'attempt_limit'      => $data['attempt_limit'] ?? $assessment['attempt_limit'],

                'passing_score'      => $data['passing_score'] ?? $assessment['passing_score'],

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
                        $data['allow_multiple_attempts'] ?? $settings['allow_multiple_attempts'],

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
    
    public function getAssessmentsByLearningItem(User $user, int $learningItemId): array { 
        /*
        |--------------------------------------------------------------------------
        | Verify Learning Item
        |--------------------------------------------------------------------------
        */

        $learningItem = $this->learningItemModel->find($learningItemId);

        if (!$learningItem) {
            return [
                'success' => false,
                'message' => 'Learning item not found.'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Retrieve Assessments
        |--------------------------------------------------------------------------
        */

        $assessments = $this->assessmentModel
            ->where('learning_item_id', $learningItemId)
            ->orderBy('created_at', 'DESC')
            ->findAll();

        /*
        |--------------------------------------------------------------------------
        | Return Assessments
        |--------------------------------------------------------------------------
        */

        return [
            'success' => true,
            'assessments' => $assessments
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
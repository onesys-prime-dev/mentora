<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningCompletionModel;

use CodeIgniter\Exceptions\PageNotFoundException;

class ModuleService
{
    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;
    protected LearningItemModel $learningItemModel;
    protected LearningCompletionModel $learningCompletionModel;

    public function __construct()
    {
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningItemModel = new LearningItemModel();
        $this->learningCompletionModel = new LearningCompletionModel();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

    $modules = $this->courseModuleModel->retrieveCourseModules($offeringId);

    foreach ($modules as &$module) {

        $module['learning_units'] =
            $this->learningUnitModel->retrieveLearningUnits($module['id']);

        $totalItems = 0;
        $completedItems = 0;

        foreach ($module['learning_units'] as &$unit) {

            $unit['learning_items'] =
                $this->learningItemModel->retrieveLearningItems($unit['id']);

            foreach ($unit['learning_items'] as &$item) {

                $item['learning_completion'] =
                    $this->learningCompletionModel
                        ->retrieveLearningCompletion($item['id'], $user->id);

                $totalItems++;

                if (
                    $item['learning_completion'] !== null &&
                    $item['learning_completion']['status'] === 'completed'
                ) {
                    $completedItems++;
                }
            }
        }

        $module['progress'] = $totalItems > 0
            ? round(($completedItems / $totalItems) * 100)
            : 0;

        $module['completed_items'] = $completedItems;
        $module['total_items'] = $totalItems;
    }

        return [

            'html' => view(
                'pages/courses/features/modules/index',
                [
                    'modules' => $modules,
                ]
            ),

        ];

    }

    public function viewLearningItem(
        User $user,
        int $offeringId,
        int $learningItemId
    ): array {

        return [

            'html' => view(
                'pages/courses/features/modules/learning-item-view',
                [
                    'offeringId'     => $offeringId,
                    'learningItemId' => $learningItemId,
                ]
            ),

        ];

    }

    public function markCompleted()
    {

    }

    public function nextLearningItem()
    {

    }

    public function previousLearningItem()
    {

    }
}
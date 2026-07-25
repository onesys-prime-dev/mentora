<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningCompletionModel;
use App\Models\Learning\LearningResourceModel;
use App\Models\Files\FileModel;

use App\Services\Storage\StorageService;

use CodeIgniter\Exceptions\PageNotFoundException;

class ModuleService
{
    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;
    protected LearningItemModel $learningItemModel;
    protected LearningCompletionModel $learningCompletionModel;
    protected LearningResourceModel $learningResourceModel;
    protected FileModel $fileModel;

    protected StorageService $storageService;

    public function __construct()
    {
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningItemModel = new LearningItemModel();
        $this->learningCompletionModel = new LearningCompletionModel();
        $this->learningResourceModel = new LearningResourceModel();
        $this->fileModel = new FileModel();

        $this->storageService = new StorageService();
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

                $item = $this->hydrateLearningItem($item);

                $item['learning_completion'] =
                    $this->learningCompletionModel
                        ->retrieveLearningCompletion($item['id'], $user->id);

                $item['is_completed'] =
                    ($item['learning_completion']['status'] ?? null) === 'completed';

                $item['is_started'] =
                    in_array(
                        $item['learning_completion']['status'] ?? null,
                        ['in_progress', 'completed'],
                        true
                    );

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

        $context =
            $this->buildLearningItemContext(
                $user,
                $learningItemId
            );

        return [

            'html' => view(
                'pages/courses/features/modules/learning-item-view',
                $context
            ),

        ];

    }

    private function buildModuleTree(
        User $user,
        int $moduleId
    ): array
    {
        $module =
            $this->courseModuleModel
                ->retrieveCourseModule($moduleId);

        if (!$module) {
            throw PageNotFoundException::forPageNotFound();
        }

        $module['learning_units'] =
            $this->learningUnitModel
                ->retrieveLearningUnits($moduleId);

        $totalItems = 0;
        $completedItems = 0;

        foreach ($module['learning_units'] as &$unit) {

            $unit['learning_items'] =
                $this->learningItemModel
                    ->retrieveLearningItems($unit['id']);

            foreach ($unit['learning_items'] as &$item) {

                $item = $this->hydrateLearningItem($item);

                $item['learning_completion'] =
                    $this->learningCompletionModel
                        ->retrieveLearningCompletion(
                            $item['id'],
                            $user->id
                        );

                $item['is_completed'] =
                    ($item['learning_completion']['status'] ?? null) === 'completed';

                $item['is_started'] =
                    in_array(
                        $item['learning_completion']['status'] ?? null,
                        ['in_progress', 'completed'],
                        true
                    );

                $totalItems++;

                if (
                    $item['learning_completion'] &&
                    $item['learning_completion']['status'] === 'completed'
                ) {
                    $completedItems++;
                }
            }
        }

        $module['total_items'] = $totalItems;

        $module['completed_items'] = $completedItems;

        $module['progress'] = $totalItems
            ? round(($completedItems / $totalItems) * 100)
            : 0;

        return $module;
    }

    private function buildNavigation(
        array $module,
        int $currentLearningItemId
    ): array
    {
        $items = [];

        foreach ($module['learning_units'] as $unit) {

            foreach ($unit['learning_items'] as $item) {

                $items[] = $item;

            }
        }

        $currentIndex = null;

        foreach ($items as $index => $item) {

            if ((int) $item['id'] === (int) $currentLearningItemId) {

                $currentIndex = $index;
                break;
            }

        }

        if ($currentIndex === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return [

            'previous' =>

                $currentIndex > 0
                    ? $items[$currentIndex - 1]
                    : null,

            'next' =>

                $currentIndex < count($items) - 1
                    ? $items[$currentIndex + 1]
                    : null,

        ];
    }

    private function buildLearningItemContext(
        User $user,
        int $learningItemId
    ): array
    {
        $currentItem =
            $this->learningItemModel
                ->retrieveLearningItem(
                    $learningItemId
                );

        if (!$currentItem) {
            throw PageNotFoundException::forPageNotFound();
        }

        $currentUnit =
            $this->learningUnitModel
                ->retrieveLearningUnit(
                    $currentItem['learning_unit_id']
                );

        if (!$currentUnit) {
            throw PageNotFoundException::forPageNotFound();
        }

        $module =
            $this->buildModuleTree(
                $user,
                $currentUnit['module_id']
            );

        $currentItem = null;

        foreach ($module['learning_units'] as $unit) {

            foreach ($unit['learning_items'] as $item) {

                if ((int) $item['id'] === (int) $learningItemId) {

                    $currentItem = $item;
                    break 2;
                }
            }
        }

        if (!$currentItem) {
            throw PageNotFoundException::forPageNotFound();
        }

        $navigation =
            $this->buildNavigation(
                $module,
                $learningItemId
            );

        return [

            'module' => $module,

            'currentUnit' => $currentUnit,

            'currentItem' => $currentItem,

            'previousItem' => $navigation['previous'],

            'nextItem' => $navigation['next'],

        ];
    }

    private function hydrateLearningItem(array $item): array
    {
        /*
        |--------------------------------------------------------------------------
        | Decode JSON content
        |--------------------------------------------------------------------------
        */

        $item['content'] = json_decode(
            $item['content'],
            true
        );

        /*
        |--------------------------------------------------------------------------
        | Retrieve attached learning resource
        |--------------------------------------------------------------------------
        */

        $resource = $this->learningResourceModel
            ->retrieveLearningResource($item['id']);

        if ($resource) {

            /*
            |--------------------------------------------------------------------------
            | Load uploaded file
            |--------------------------------------------------------------------------
            */

            if (! empty($resource['file_id'])) {

                $resource['file'] = $this->fileModel
                    ->retrieveFile($resource['file_id']);

                if ($resource['file']) {

                    $resource['file']['url'] =
                        $this->storageService
                            ->getUrl($resource['file']);

                }

            } else {

                $resource['file'] = null;

            }

            /*
            |--------------------------------------------------------------------------
            | Prepare external video URL
            |--------------------------------------------------------------------------
            */

            if (
                $resource['resource_type'] === 'video' &&
                ! empty($resource['resource_url'])
            ) {
                $resource['embed_url'] = $this->buildVideoEmbedUrl(
                    $resource['resource_url']
                );
            }

        }

        $item['resource'] = $resource;

        return $item;
    }

    private function buildVideoEmbedUrl(string $url): string
    {
        /*
        |--------------------------------------------------------------------------
        | YouTube
        |--------------------------------------------------------------------------
        */

        if (str_contains($url, 'youtube.com/watch')) {

            return preg_replace(
                '/watch\?v=([a-zA-Z0-9_-]+)/',
                'embed/$1',
                $url
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Short YouTube URL
        |--------------------------------------------------------------------------
        */

        if (str_contains($url, 'youtu.be/')) {

            $videoId = basename(parse_url($url, PHP_URL_PATH));

            return "https://www.youtube.com/embed/{$videoId}";
        }

        /*
        |--------------------------------------------------------------------------
        | Already embeddable
        |--------------------------------------------------------------------------
        */

        return $url;
    }
}
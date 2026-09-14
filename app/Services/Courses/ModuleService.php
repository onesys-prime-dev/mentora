<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningCompletionModel;
use App\Models\Learning\LearningResourceModel;
use App\Models\Files\FileModel;
use App\Models\Assessments\AssessmentTypeModel;

use App\Services\Storage\StorageService;
use App\Services\Courses\Assessments\AssessmentService;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;

class ModuleService
{
    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;
    protected LearningItemModel $learningItemModel;
    protected LearningCompletionModel $learningCompletionModel;
    protected LearningResourceModel $learningResourceModel;
    protected FileModel $fileModel;
    protected AssessmentTypeModel $assessmentTypeModel;

    protected StorageService $storageService;
    protected AssessmentService $assessmentService;

    public function __construct()
    {
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningItemModel = new LearningItemModel();
        $this->learningCompletionModel = new LearningCompletionModel();
        $this->learningResourceModel = new LearningResourceModel();
        $this->fileModel = new FileModel();
        $this->assessmentTypeModel = new AssessmentTypeModel();

        $this->assessmentService = new AssessmentService();

        $this->storageService = new StorageService();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

    $modules = $this->courseModuleModel->retrieveCourseModules($offeringId, $user);

    foreach ($modules as &$module) {

        $module['learning_units'] =
            $this->learningUnitModel->retrieveLearningUnits($module['id'], $user);

        $totalItems = 0;
        $completedItems = 0;

        foreach ($module['learning_units'] as &$unit) {

            $unit['learning_items'] =
                $this->learningItemModel->retrieveLearningItems($unit['id'], $user);

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
                    'user' => $user,
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

    public function viewLearningItemEditor(
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
                'pages/courses/features/modules/learning-item-editor',
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
                ->retrieveCourseModule($moduleId, $user);

        if (!$module) {
            throw PageNotFoundException::forPageNotFound();
        }

        $module['learning_units'] =
            $this->learningUnitModel
                ->retrieveLearningUnits($moduleId, $user);

        $totalItems = 0;
        $completedItems = 0;

        foreach ($module['learning_units'] as &$unit) {

            $unit['learning_items'] =
                $this->learningItemModel
                    ->retrieveLearningItems($unit['id'], $user);

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
                    $learningItemId,
                    $user
                );

        if (!$currentItem) {
            throw PageNotFoundException::forPageNotFound();
        }

        $currentUnit =
            $this->learningUnitModel
                ->retrieveLearningUnit(
                    $currentItem['learning_unit_id'],
                    $user
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

            'user' => $user,

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

    public function storeModule(
        User $user,
        int $offeringId,
        IncomingRequest $request
    ): array
    {
        $title = trim(
            $request->getPost('title')
        );

        $description = trim(
            $request->getPost('description')
        );

        if ($title === '') {

            return [

                'success' => false,

                'message' => 'Module title is required.',

            ];

        }

        $lastModule =
            $this->courseModuleModel
                ->where(
                    'offering_id',
                    $offeringId
                )
                ->orderBy(
                    'order_index',
                    'DESC'
                )
                ->first();

        $orderIndex =
            $lastModule
                ? $lastModule['order_index'] + 1
                : 1;

        $this->courseModuleModel->insert([

            'offering_id' => $offeringId,

            'title' => $title,

            'description' => $description,

            'order_index' => $orderIndex,

            'status' => 'draft',

        ]);

        return [

            'success' => true,

            'message' => 'Module created successfully.',

        ];
    }

    public function updateModule(
        User $user,
        int $offeringId,
        int $moduleId,
        IncomingRequest $request
    ): array {

        $module =
            $this->courseModuleModel
                ->find($moduleId);

        if (! $module) {

            return [

                'success' => false,

                'message' => 'Module not found.',

            ];

        }

        if (
            (int) $module['offering_id'] !== $offeringId
        ) {

            return [

                'success' => false,

                'message' => 'Invalid module.',

            ];

        }

        $title = trim(
            $request->getPost('title')
        );

        $description = trim(
            $request->getPost('description')
        );

        if ($title === '') {

            return [

                'success' => false,

                'message' => 'Module title is required.',

            ];

        }

        $this->courseModuleModel->update(
            $moduleId,
            [

                'title' => $title,

                'description' => $description,

            ]
        );

        return [

            'success' => true,

            'message' => 'Module updated successfully.',

        ];

    }

    public function updateModuleStatus(
        User $user,
        int $offeringId,
        int $moduleId,
        string $status
    ): array {

        $module =
            $this->courseModuleModel
                ->find($moduleId);

        if (! $module) {

            return [

                'success' => false,

                'message' => 'Module not found.',

            ];

        }

        if (
            (int) $module['offering_id'] !==
            $offeringId
        ) {

            return [

                'success' => false,

                'message' => 'Invalid module.',

            ];

        }

        $this->courseModuleModel
            ->update(

                $moduleId,

                [

                    'status' => $status,

                ]

            );

        return [

            'success' => true,

            'message' => 'Module status updated.',

        ];

    }

    public function reorderModules(

        User $user,

        int $offeringId,

        array $order

    ) {

        foreach ($order as $module) {

            $this->courseModuleModel->update(

                $module['id'],

                [

                    'order_index' =>

                        $module['order_index'],

                ]

            );

        }

        return [

            'success' => true,

        ];

    }

    public function storeLearningUnit(
        User $user,
        int $offeringId,
        int $moduleId,
        IncomingRequest $request
    ): array
    {
        $title = trim(
            $request->getPost('title')
        );

        $description = trim(
            $request->getPost('description')
        );

        if ($title === '') {

            return [

                'success' => false,

                'message' => 'Learning unit title is required.',

            ];

        }

        $lastUnit =
            $this->learningUnitModel
                ->where(
                    'module_id',
                    $moduleId
                )
                ->orderBy(
                    'order_index',
                    'DESC'
                )
                ->first();

        $orderIndex =
            $lastUnit
                ? $lastUnit['order_index'] + 1
                : 1;

        $this->learningUnitModel->insert([

            'module_id' => $moduleId,

            'title' => $title,

            'description' => $description,

            'order_index' => $orderIndex,

            'status' => 'draft',

        ]);

        return [

            'success' => true,

            'message' => 'Learning unit created successfully.',

        ];
    }

    public function updateLearningUnit(
        User $user,
        int $offeringId,
        int $moduleId,
        int $learningUnitId,
        IncomingRequest $request
    ): array {

        $unit =
            $this->learningUnitModel
                ->find($learningUnitId);

        if (! $unit) {

            return [

                'success' => false,

                'message' => 'Learning unit not found.',

            ];

        }

        $title = trim(
            $request->getPost('title')
        );

        $description = trim(
            $request->getPost('description')
        );

        if ($title === '') {

            return [

                'success' => false,

                'message' => 'Learning unit title is required.',

            ];

        }

        $this->learningUnitModel->update(
            $learningUnitId,
            [

                'title' => $title,

                'description' => $description,

            ]
        );

        return [

            'success' => true,

            'message' => 'Learning unit updated successfully.',

        ];

    }

    public function updateLearningUnitStatus(

        User $user,

        int $offeringId,

        int $learningUnitId,

        array $payload

    ) {

        $learningUnit =

            $this->learningUnitModel
                ->find($learningUnitId);

        if (!$learningUnit) {

            throw new PageNotFoundException();

        }

        $this->learningUnitModel->update(

            $learningUnitId,

            [

                'status' => $payload['status'],

            ]

        );

        return [

            'success' => true,

            'message' =>
                'Learning unit status updated.',

        ];

    }

    public function reorderLearningUnits(

        User $user,

        int $offeringId,

        array $learningUnits

    ) {

        /*
        |--------------------------------------------------------------------------
        | Validate
        |--------------------------------------------------------------------------
        */

        foreach ($learningUnits as $learningUnit) {

            if (
                ! isset($learningUnit['id']) ||
                ! isset($learningUnit['order_index'])
            ) {

                continue;

            }

            /*
            |--------------------------------------------------------------------------
            | Ensure the learning unit belongs to this course offering
            |--------------------------------------------------------------------------
            */

            $existing =

                $this->learningUnitModel

                    ->select('learning_units.id')

                    ->join(
                        'course_modules',
                        'course_modules.id = learning_units.module_id'
                    )

                    ->where(
                        'learning_units.id',
                        $learningUnit['id']
                    )

                    ->where(
                        'course_modules.offering_id',
                        $offeringId
                    )

                    ->first();

            if (! $existing) {

                continue;

            }

            /*
            |--------------------------------------------------------------------------
            | Update order
            |--------------------------------------------------------------------------
            */

            $this->learningUnitModel->update(

                $learningUnit['id'],

                [

                    'order_index' =>
                        $learningUnit['order_index'],

                ]

            );

        }

        return [

            'success' => true,

            'message' => 'Learning units reordered.',

        ];

    }

    public function storeLearningItem(
        User $user,
        int $offeringId,
        int $moduleId,
        int $learningUnitId,
        IncomingRequest $request
    ): array {

        $module =
            $this->courseModuleModel
                ->find($moduleId);

        if (! $module) {

            return [

                'success' => false,

                'message' => 'Module not found.',

            ];

        }

        if ((int) $module['offering_id'] !== $offeringId) {

            return [

                'success' => false,

                'message' => 'Invalid module.',

            ];

        }

        $learningUnit =
            $this->learningUnitModel
                ->find($learningUnitId);

        if (! $learningUnit) {

            return [

                'success' => false,

                'message' => 'Learning unit not found.',

            ];

        }

        if ((int) $learningUnit['module_id'] !== $moduleId) {

            return [

                'success' => false,

                'message' => 'Invalid learning unit.',

            ];

        }

        $title = trim(
            $request->getPost('title')
        );

        $itemType = trim(
            $request->getPost('item_type')
        );

        if ($title === '') {

            return [

                'success' => false,

                'message' => 'Learning item title is required.',

            ];

        }

        $lastItem =
            $this->learningItemModel
                ->where(
                    'learning_unit_id',
                    $learningUnitId
                )
                ->orderBy(
                    'order_index',
                    'DESC'
                )
                ->first();

        $orderIndex =
            $lastItem
                ? $lastItem['order_index'] + 1
                : 1;

        $this->learningItemModel->insert([

            'learning_unit_id' => $learningUnitId,

            'title' => $title,

            'item_type' => $itemType,

            'order_index' => $orderIndex,

            'status' => 'draft',

        ]);

        $learningItemId =
            $this->learningItemModel->getInsertID();

        /*
        |--------------------------------------------------------------------------
        | Create matching Assessment (Quiz / Assignment items only)
        |--------------------------------------------------------------------------
        */

        if (in_array($itemType, ['quiz', 'assignment'], true)) {

            $assessmentType =
                $this->assessmentTypeModel
                    ->where('name', ucfirst($itemType))
                    ->first();

            if ($assessmentType) {

                $this->assessmentService
                    ->createAssessmentForLearningItem(
                        $user,
                        $moduleId,
                        $learningItemId,
                        $assessmentType['id'],
                        $title
                    );

            }

        }

        return [

            'success' => true,

            'message' => 'Learning item created successfully.',

        ];

    }

    public function updateLearningItem(

        User $user,

        int $offeringId,

        int $learningItemId,

        array $payload

    ) {

        $learningItem =

            $this->learningItemModel

                ->find($learningItemId);

        if (!$learningItem) {

            throw new PageNotFoundException();

        }

        $update = [];

        if (array_key_exists(
            'content',
            $payload
        )) {

            $update['content'] =

                json_encode(

                    $payload['content'],

                    JSON_UNESCAPED_UNICODE

                );

        }

        if (array_key_exists(
            'title',
            $payload
        )) {

            $update['title'] =
                trim($payload['title']);

        }

        if (array_key_exists(
            'status',
            $payload
        )) {

            $update['status'] =
                $payload['status'];

        }

        $this->learningItemModel
            ->update(

                $learningItemId,

                $update

            );

        return [

            'success' => true,

            'message' =>
                'Learning item updated.',

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Delete Learning Item
    |--------------------------------------------------------------------------
    */

    public function deleteLearningItem(
        User $user,
        int $offeringId,
        int $learningItemId
    ): array {

        $learningItem =
            $this->learningItemModel
                ->find($learningItemId);

        if (!$learningItem) {

            throw new PageNotFoundException();

        }

        $this->learningItemModel
            ->delete($learningItemId);

        return [

            'success' => true,

            'message' =>
                'Learning item deleted.',

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Update Video Learning Item
    |--------------------------------------------------------------------------
    */

    public function updateVideoItem(
        User $user,
        int $offeringId,
        int $learningItemId,
        IncomingRequest $request
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Retrieve Learning Item
        |--------------------------------------------------------------------------
        */

        $learningItem =
            $this->learningItemModel
                ->find($learningItemId);

        if (! $learningItem) {

            throw PageNotFoundException::forPageNotFound();

        }

        /*
        |--------------------------------------------------------------------------
        | Video Settings
        |--------------------------------------------------------------------------
        */

        $content = [

            'source' => $request->getPost('source'),

            'autoplay' => filter_var(
                $request->getPost('autoplay'),
                FILTER_VALIDATE_BOOLEAN
            ),

            'show_controls' => filter_var(
                $request->getPost('show_controls'),
                FILTER_VALIDATE_BOOLEAN
            ),

        ];

        /*
        |--------------------------------------------------------------------------
        | Update Learning Item
        |--------------------------------------------------------------------------
        */

        $this->learningItemModel->update(

            $learningItemId,

            [
                'content' => json_encode(
                    $content,
                    JSON_UNESCAPED_UNICODE
                ),
            ]

        );

        /*
        |--------------------------------------------------------------------------
        | Learning Resource
        |--------------------------------------------------------------------------
        */

        $source =
            $request->getPost(
                'source'
            );

        $resource =

            $this->learningResourceModel
                ->retrieveLearningResource(
                    $learningItemId
                );

        if (! $resource) {

            $resource = [

                'learning_item_id' =>
                    $learningItemId,

                'resource_type' =>
                    'video',

                'resource_url' =>
                    null,

                'file_id' =>
                    null,

                'title' => 
                    $learningItem['title'],

            ];

            $resourceId =

                $this->learningResourceModel
                    ->insert(
                        $resource,
                        true
                    );

        }

        else {

            $resourceId =
                $resource['id'];

        }

        /*
        |--------------------------------------------------------------------------
        | External Video Link
        |--------------------------------------------------------------------------
        */

        if ($source === 'link') {

            $this->learningResourceModel->update(

                $resourceId,

                [

                    'resource_type' =>
                        'video',

                    'resource_url' =>
                        trim(
                            $request->getPost(
                                'resource_url'
                            )
                        ),

                    'file_id' =>
                        null,

                ]

            );

        }

        /*
        |--------------------------------------------------------------------------
        | Uploaded Video
        |--------------------------------------------------------------------------
        */

        if ($source === 'upload') {

            $video =
                $request->getFile(
                    'video'
                );

            if (

                $video &&
                $video->isValid() &&
                ! $video->hasMoved()

            ) {

                /*
                |--------------------------------------------------------------------------
                | Store Video
                |--------------------------------------------------------------------------
                */

                $stored =

                    $this->storageService->upload(

                        $video,

                        'learning/videos'

                    );

                /*
                |--------------------------------------------------------------------------
                | Save File Record
                |--------------------------------------------------------------------------
                */

                $fileId =

                    $this->fileModel->insert(

                        [

                            'owner_user_id' =>

                                $user->id,

                            'original_filename' =>

                                $stored['original_name'],

                            'stored_filename' =>

                                $stored['filename'],

                            'mime_type' =>

                                $stored['mime_type'],

                            'file_size' =>

                                $stored['size'],

                            'storage_key' =>

                                $stored['storage_key'],

                            'status' =>

                                'active',

                        ],

                        true

                    );

                /*
                |--------------------------------------------------------------------------
                | Update Learning Resource
                |--------------------------------------------------------------------------
                */

                $this->learningResourceModel->update(

                    $resourceId,

                    [

                        'resource_type' =>
                            'video',

                        'resource_url' =>
                            null,

                        'file_id' =>
                            $fileId,

                    ]

                );

            }

        }

        return [

            'success' => true,

            'message' => 'Video updated.',

        ];

    }

    public function reorderLearningItems(

        User $user,

        int $offeringId,

        array $items

    ) {

        foreach ($items as $item) {

            $this->learningItemModel->update(

                $item['id'],

                [

                    'order_index' =>

                        $item['order_index'],

                ]

            );

        }

        return [

            'success' => true,

            'message' =>
                'Learning items reordered.',

        ];

    }
}
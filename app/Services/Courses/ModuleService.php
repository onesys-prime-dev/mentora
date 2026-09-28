<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\LearningItemModel;
use App\Models\Learning\LearningCompletionModel;
use App\Models\Learning\LearningResourceModel;
use App\Models\Files\FileModel;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentTypeModel;

use App\Services\Storage\StorageService;
use App\Services\Courses\Assessments\AssessmentService;
use App\Services\Courses\LearningProgressService;

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
    protected AssessmentModel $assessmentModel;
    protected AssessmentTypeModel $assessmentTypeModel;

    protected StorageService $storageService;
    protected AssessmentService $assessmentService;
    protected LearningProgressService $progressService;

    public function __construct()
    {
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningItemModel = new LearningItemModel();
        $this->learningCompletionModel = new LearningCompletionModel();
        $this->learningResourceModel = new LearningResourceModel();
        $this->fileModel = new FileModel();
        $this->assessmentModel = new AssessmentModel();
        $this->assessmentTypeModel = new AssessmentTypeModel();

        $this->assessmentService = new AssessmentService();

        $this->storageService = new StorageService();

        $this->progressService = new LearningProgressService();
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

        // Locks, "can complete" and the student's real progress.
        $this->progressService->annotateTree($user, $module);
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

        // A student can't enter an item behind incomplete prerequisites —
        // nothing of it is rendered; the client returns to the Modules list
        // with this notice (learning-item.js). Instructors are never locked.
        if (!empty($context['currentItem']['is_locked'])) {
            return $this->lockedItemRefusal($context['currentItem']);
        }

        return [

            'html' => view(
                'pages/courses/features/modules/learning-item-view',
                $context
            ),

        ];

    }

    /**
     * What a student gets instead of a locked item: the reason, and the
     * first missing prerequisite to open instead.
     */
    public function lockedItemRefusal(array $item): array
    {
        $lockedBy = $item['locked_by'] ?? [];

        $message = preg_replace('/ first\.$/u', '', $this->progressService->lockMessage($lockedBy))
            . ' to unlock “' . $item['title'] . '”.';

        return [
            'success' => false,
            'locked' => true,
            'message' => $message,
            'item_title' => $item['title'],
            'unlock_item_id' => isset($lockedBy[0]['id']) ? (int) $lockedBy[0]['id'] : null,
            'unlock_item_title' => $lockedBy[0]['title'] ?? null,
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

        // Locks, "can complete" and the student's real progress.
        $this->progressService->annotateTree($user, $module);

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

        $assessmentContext =
            in_array($currentItem['item_type'], ['quiz', 'assignment'], true)
                ? $this->assessmentService->buildLearningItemContext($user, $learningItemId)
                : null;

        return [

            'user' => $user,

            'module' => $module,

            'currentUnit' => $currentUnit,

            'currentItem' => $currentItem,

            'previousItem' => $navigation['previous'],

            'nextItem' => $navigation['next'],

            // Why Continue is disabled while the next item is still locked.
            'nextLockHint' => !empty($navigation['next']['is_locked'])
                ? preg_replace('/ first\.$/u', ' to continue.', $this->progressService->lockMessage($navigation['next']['locked_by'] ?? []))
                : '',

            'currentAssessment' => $assessmentContext['assessment'] ?? null,

            'currentSettings' => $assessmentContext['settings'] ?? null,

            'currentRubric' => $assessmentContext['rubric'] ?? null,

            'assessmentContext' => $assessmentContext,

            // Same names the Assessments tab uses, so the shared partials
            // (assignment-work, score-panel) render identically in both.
            'assignmentState' => $currentItem['item_type'] === 'assignment'
                ? ($assessmentContext['state'] ?? null)
                : null,

            // The attempt that counts (best quiz attempt / latest graded
            // submission), falling back to the latest for "no score yet".
            'submission' => $assessmentContext['state']['counted'] ?? $assessmentContext['state']['latest'] ?? null,

            'scoreResult' => $assessmentContext['state']['result'] ?? null,

            // Set for a student whose item is behind incomplete prerequisites;
            // the view then shows the locked notice instead of the content.
            'currentLock' => !empty($currentItem['is_locked'])
                ? [
                    'message' => $this->progressService->lockMessage($currentItem['locked_by']),
                    'prerequisites' => $currentItem['locked_by'],
                ]
                : null,

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
                            ->url((int) $resource['file']['id']);

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
                $resource['youtube_id'] = $this->progressService->youtubeId(
                    $resource['resource_url']
                );

                // YouTube embeds get the IFrame API enabled so watch
                // progress can be tracked for completion.
                $resource['embed_url'] = $resource['youtube_id']
                    ? "https://www.youtube.com/embed/{$resource['youtube_id']}?enablejsapi=1&rel=0"
                    : $this->buildVideoEmbedUrl($resource['resource_url']);
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

            // The modal opens this item's editor next.
            'learning_item_id' => (int) $learningItemId,

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

        // Link items store {url, description}; only http(s) links are
        // accepted so the "Open link" button can't run a javascript: URL.
        if (
            $learningItem['item_type'] === 'link'
            && array_key_exists('content', $payload)
        ) {

            $url = trim((string) ($payload['content']['url'] ?? ''));

            if (
                $url !== ''
                && (
                    filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                )
            ) {

                return [
                    'success' => false,
                    'message' => 'Enter a full link starting with http:// or https://.',
                ];

            }

            $payload['content'] = [
                'url' => $url,
                'description' => trim((string) ($payload['content']['description'] ?? '')),
            ];

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

        $this->syncLinkedAssessment($learningItemId, $update);

        return [

            'success' => true,

            'message' =>
                'Learning item updated.',

        ];

    }

    /**
     * A quiz/assignment item and its assessment are one thing to the
     * instructor, so publishing, drafting or renaming the item applies to the
     * assessment too. Written directly to the assessments table (not through
     * AssessmentService::updateAssessment()) so the two sides' syncs can't
     * call back into each other.
     */
    private function syncLinkedAssessment(int $learningItemId, array $itemUpdate): void
    {
        $assessment = $this->assessmentModel->findByLearningItem($learningItemId);

        if (!$assessment) {
            return;
        }

        $assessmentUpdate = [];

        if (array_key_exists('status', $itemUpdate)) {
            $assessmentUpdate['status'] = $itemUpdate['status'] === 'published' ? 'Published' : 'Draft';
        }

        if (array_key_exists('title', $itemUpdate) && $itemUpdate['title'] !== '') {
            $assessmentUpdate['title'] = $itemUpdate['title'];
        }

        if ($assessmentUpdate !== []) {
            $assessmentUpdate['updated_at'] = date('Y-m-d H:i:s');

            $this->assessmentModel->update($assessment['id'], $assessmentUpdate);
        }
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

        if (!$learningItem || !$this->itemBelongsToOffering($learningItem, $offeringId)) {

            throw new PageNotFoundException();

        }

        // Also removes the linked quiz/assignment (its FK would only be
        // SET NULL, orphaning it) and the files nothing else uses.
        if (!(new ContentDeletionService())->deleteLearningItem($learningItemId)) {

            return [

                'success' => false,

                'message' => 'Unable to delete learning item.',

            ];

        }

        return [

            'success' => true,

            'message' =>
                'Learning item deleted.',

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Delete Module / Learning Unit
    |--------------------------------------------------------------------------
    */

    public function deleteModule(
        User $user,
        int $offeringId,
        int $moduleId
    ): array {

        $module = $this->courseModuleModel->find($moduleId);

        if (!$module || (int) $module['offering_id'] !== $offeringId) {

            return [
                'success' => false,
                'message' => 'Module not found.',
            ];

        }

        if (!(new ContentDeletionService())->deleteModule($moduleId)) {

            return [
                'success' => false,
                'message' => 'Unable to delete module.',
            ];

        }

        return [
            'success' => true,
            'message' => 'Module deleted.',
        ];

    }

    public function deleteLearningUnit(
        User $user,
        int $offeringId,
        int $moduleId,
        int $learningUnitId
    ): array {

        $module = $this->courseModuleModel->find($moduleId);

        $unit = $this->learningUnitModel->find($learningUnitId);

        if (
            !$module || !$unit
            || (int) $module['offering_id'] !== $offeringId
            || (int) $unit['module_id'] !== $moduleId
        ) {

            return [
                'success' => false,
                'message' => 'Learning unit not found.',
            ];

        }

        if (!(new ContentDeletionService())->deleteLearningUnit($learningUnitId)) {

            return [
                'success' => false,
                'message' => 'Unable to delete learning unit.',
            ];

        }

        return [
            'success' => true,
            'message' => 'Learning unit deleted.',
        ];

    }

    private function itemBelongsToOffering(array $learningItem, int $offeringId): bool
    {
        return $this->learningUnitModel
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_units.id', $learningItem['learning_unit_id'])
            ->where('course_modules.offering_id', $offeringId)
            ->countAllResults() > 0;
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

        $previousFileId = $resource['file_id'] ?? null;

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

        // A new upload or a switch to a link leaves the old video unused.
        $this->storageService->purgeFiles([$previousFileId]);

        return [

            'success' => true,

            'message' => 'Video updated.',

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Update PDF Learning Item
    |--------------------------------------------------------------------------
    |
    | Multipart: optional `pdf` (upload/replace) and optional `allow_download`.
    | The replaced file is purged once nothing points at it.
    |
    */

    private const MAX_PDF_BYTES = 25 * 1024 * 1024;

    public function updatePdfItem(
        User $user,
        int $offeringId,
        int $learningItemId,
        IncomingRequest $request
    ): array {

        $learningItem = $this->learningItemModel->find($learningItemId);

        if (
            !$learningItem
            || $learningItem['item_type'] !== 'pdf'
            || !$this->itemBelongsToOffering($learningItem, $offeringId)
        ) {

            return [
                'success' => false,
                'message' => 'PDF item not found.',
            ];

        }

        $content = json_decode($learningItem['content'] ?? '', true);

        $content = is_array($content) ? $content : [];

        $content['display_mode'] ??= 'embed';

        if ($request->getPost('allow_download') !== null) {

            $content['allow_download'] = filter_var(
                $request->getPost('allow_download'),
                FILTER_VALIDATE_BOOLEAN
            );

        }

        $pdf = $request->getFile('pdf');

        $previousFileId = null;

        if ($pdf && $pdf->getError() !== UPLOAD_ERR_NO_FILE) {

            if (!$pdf->isValid()) {

                return [
                    'success' => false,
                    'message' => 'The PDF could not be uploaded: ' . $pdf->getErrorString(),
                ];

            }

            if ($pdf->getSize() > self::MAX_PDF_BYTES) {

                return [
                    'success' => false,
                    'message' => 'The PDF is larger than the 25 MB limit.',
                ];

            }

            if ($pdf->getMimeType() !== 'application/pdf') {

                return [
                    'success' => false,
                    'message' => 'Only PDF files can be uploaded here.',
                ];

            }

            $stored = $this->storageService->upload($pdf, 'learning/documents');

            $fileId = $this->fileModel->insert([
                'owner_user_id' => $user->id,
                'original_filename' => $stored['original_name'],
                'stored_filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['size'],
                'storage_key' => $stored['storage_key'],
                'status' => 'active',
            ], true);

            $resource = $this->learningResourceModel->retrieveLearningResource($learningItemId);

            if ($resource) {

                $previousFileId = $resource['file_id'];

                $this->learningResourceModel->update($resource['id'], [
                    'resource_type' => 'pdf',
                    'resource_url' => null,
                    'file_id' => $fileId,
                ]);

            } else {

                $this->learningResourceModel->insert([
                    'learning_item_id' => $learningItemId,
                    'resource_type' => 'pdf',
                    'resource_url' => null,
                    'file_id' => $fileId,
                    'title' => $learningItem['title'],
                ]);

            }

        }

        $this->learningItemModel->update($learningItemId, [
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);

        $this->storageService->purgeFiles([$previousFileId]);

        $resource = $this->learningResourceModel->retrieveLearningResource($learningItemId);

        $file = !empty($resource['file_id'])
            ? $this->fileModel->retrieveFile((int) $resource['file_id'])
            : null;

        return [
            'success' => true,
            'message' => 'PDF saved.',
            'allowDownload' => (bool) ($content['allow_download'] ?? false),
            'file' => $file ? [
                'id' => (int) $file['id'],
                'name' => $file['original_filename'],
                'size' => (int) $file['file_size'],
                'url' => $this->storageService->url((int) $file['id']),
            ] : null,
        ];

    }

    public function reorderLearningItems(

        User $user,

        int $offeringId,

        array $items

    ) {

        $unitIds = [];

        foreach ($items as $item) {

            $this->learningItemModel->update(

                $item['id'],

                [

                    'order_index' =>

                        $item['order_index'],

                ]

            );

            $unitIds[] = $this->learningItemModel->find($item['id'])['learning_unit_id'] ?? null;

        }

        // A custom prerequisite that now comes after its item no longer
        // counts; drop it so the dependency settings stay truthful.
        foreach (array_unique(array_filter($unitIds)) as $unitId) {
            $this->progressService->pruneDependencies((int) $unitId);
        }

        return [

            'success' => true,

            'message' =>
                'Learning items reordered.',

        ];

    }
}
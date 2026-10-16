<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Learning\LearningResourceModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Services\Storage\StorageService;

/**
 * A read-only, Google-Drive-style browse over the existing Modules
 * hierarchy: Module > Learning Unit > file/link resources. There is no
 * separate "resources" table of its own — a resource is just a
 * learning_item (pdf/link/video/reading) that carries a learning_resources
 * row, so this is a filtered view over data Modules already owns and
 * already enforces visibility on (draft/published by role).
 */
class ResourceService
{
    /** file-type filter bucket => [mime prefixes/exact matches] */
    private const TYPE_BUCKETS = [
        'pdf' => ['application/pdf'],
        'word' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'powerpoint' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'excel' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'image' => ['image/'],
        'video' => ['video/'],
    ];

    private const TYPE_LABELS = [
        'pdf' => 'PDF',
        'word' => 'Word Document',
        'powerpoint' => 'PowerPoint',
        'excel' => 'Excel',
        'image' => 'Image',
        'video' => 'Video',
        'link' => 'Link',
        'other' => 'File',
    ];

    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;
    protected LearningResourceModel $learningResourceModel;
    protected EnrollmentModel $enrollmentModel;
    protected InstructorAssignmentModel $instructorAssignmentModel;
    protected StorageService $storageService;

    public function __construct()
    {
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningResourceModel = new LearningResourceModel();
        $this->enrollmentModel = new EnrollmentModel();
        $this->instructorAssignmentModel = new InstructorAssignmentModel();
        $this->storageService = new StorageService();
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    /** Instructor here, or the chairperson of the program it belongs to — same access either way. */
    private function isInstructorHere(User $user, int $offeringId): bool
    {
        if ($user->inGroup('instructor')) {
            return (bool) $this->instructorAssignmentModel->retrieveInstructorCourseOffering($user->id, $offeringId);
        }

        if ($user->inGroup('chairperson')) {
            return (new ChairpersonAccessService())->canAccess($user, $offeringId);
        }

        return false;
    }

    private function canViewOffering(User $user, int $offeringId): bool
    {
        // The institution's administrator may look, never post: access only, no posting rights.
        if (\App\Services\Courses\CourseViewer::isReadOnlyViewer($user)) {
            return (new \App\Services\Courses\CourseReadAccess())->canAccess($user, $offeringId);
        }

        if ($this->isInstructorHere($user, $offeringId)) {
            return true;
        }

        return (bool) $this->enrollmentModel->retrieveStudentCourseOffering($user->id, $offeringId);
    }

    /*
    |--------------------------------------------------------------------------
    | Browse
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{module?: int, unit?: int, search?: string, sort?: string, type?: string, view?: string} $params
     */
    public function show(User $user, int $offeringId, array $params = []): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isInstructor = $this->isInstructorHere($user, $offeringId);

        $moduleId = (int) ($params['module'] ?? 0) ?: null;
        $unitId = (int) ($params['unit'] ?? 0) ?: null;

        $view = ($params['view'] ?? 'list') === 'grid' ? 'grid' : 'list';
        $search = trim((string) ($params['search'] ?? ''));
        $sort = (string) ($params['sort'] ?? 'name');
        $type = (string) ($params['type'] ?? '');

        $data = [
            'user' => $user,
            'isInstructor' => $isInstructor,
            'offeringId' => $offeringId,
            'view' => $view,
            'filters' => ['search' => $search, 'sort' => $sort, 'type' => $type],
        ];

        if ($unitId) {

            $unit = $this->learningUnitModel->retrieveLearningUnit($unitId, $user);

            if (!$unit) {
                throw PageNotFoundException::forPageNotFound();
            }

            $module = $this->courseModuleModel->retrieveCourseModule((int) $unit['module_id'], $user);

            if (!$module || (int) $module['offering_id'] !== $offeringId) {
                throw PageNotFoundException::forPageNotFound();
            }

            $resources = $this->learningResourceModel->retrieveForLearningUnit($unitId, $isInstructor, $search);
            $resources = array_map($this->decorate(...), $resources);
            $resources = $this->filterByType($resources, $type);
            $resources = $this->sortResources($resources, $sort);

            $data += ['level' => 'unit', 'module' => $module, 'unit' => $unit, 'resources' => $resources];

        } elseif ($moduleId) {

            $module = $this->courseModuleModel->retrieveCourseModule($moduleId, $user);

            if (!$module || (int) $module['offering_id'] !== $offeringId) {
                throw PageNotFoundException::forPageNotFound();
            }

            $units = $this->learningUnitModel->retrieveLearningUnits($moduleId, $user);

            if ($search !== '') {
                $units = array_values(array_filter(
                    $units,
                    static fn (array $u) => stripos($u['title'], $search) !== false
                ));
            }

            $units = $this->sortFolders($units, $sort);

            $data += ['level' => 'module', 'module' => $module, 'units' => $units];

        } else {

            $modules = $this->courseModuleModel->retrieveCourseModules($offeringId, $user);

            if ($search !== '') {
                $modules = array_values(array_filter(
                    $modules,
                    static fn (array $m) => stripos($m['title'], $search) !== false
                ));
            }

            $modules = $this->sortFolders($modules, $sort);

            $data += ['level' => 'offering', 'modules' => $modules];

        }

        return ['html' => view('pages/courses/features/resources/index', $data)];
    }

    /*
    |--------------------------------------------------------------------------
    | Decoration / filtering / sorting
    |--------------------------------------------------------------------------
    */

    /** Adds view/download URLs, a display title, a type bucket+label, and a human file size. */
    private function decorate(array $resource): array
    {
        $isLink = $resource['resource_type'] === 'link' || (!$resource['file_id'] && $resource['resource_url']);

        $resource['display_title'] = $resource['resource_title'] !== '' && $resource['resource_title'] !== null
            ? $resource['resource_title']
            : $resource['item_title'];

        if ($isLink) {

            $resource['type_bucket'] = 'link';
            $resource['view_url'] = $resource['resource_url'];
            $resource['download_url'] = null;
            $resource['size_label'] = null;

        } else {

            $fileId = (int) $resource['file_id'];

            $resource['type_bucket'] = $this->typeBucketFromMime((string) ($resource['mime_type'] ?? ''), (string) $resource['item_type']);
            $resource['view_url'] = $fileId ? $this->storageService->url($fileId) : null;
            $resource['download_url'] = $fileId ? $this->storageService->url($fileId, true) : null;
            $resource['size_label'] = $resource['file_size'] ? $this->humanFileSize((int) $resource['file_size']) : null;

        }

        $resource['type_label'] = self::TYPE_LABELS[$resource['type_bucket']] ?? self::TYPE_LABELS['other'];
        $resource['added_label'] = $resource['created_at'] ? date('M j, Y', strtotime($resource['created_at'])) : '';

        return $resource;
    }

    private function typeBucketFromMime(string $mime, string $itemType): string
    {
        foreach (self::TYPE_BUCKETS as $bucket => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    return $bucket;
                }
            }
        }

        if ($itemType === 'video') {
            return 'video';
        }

        return 'other';
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    private function filterByType(array $resources, string $type): array
    {
        if ($type === '' || $type === 'all') {
            return $resources;
        }

        return array_values(array_filter(
            $resources,
            static fn (array $r) => $r['type_bucket'] === $type
        ));
    }

    private function sortResources(array $resources, string $sort): array
    {
        usort($resources, static function (array $a, array $b) use ($sort) {

            return match ($sort) {
                'name_desc' => strcasecmp($b['display_title'], $a['display_title']),
                'oldest' => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''),
                'newest' => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''),
                default => strcasecmp($a['display_title'], $b['display_title']),
            };

        });

        return $resources;
    }

    /** Same sort vocabulary applied to the module/unit "folder" lists. */
    private function sortFolders(array $folders, string $sort): array
    {
        usort($folders, static function (array $a, array $b) use ($sort) {

            return match ($sort) {
                'name_desc' => strcasecmp($b['title'], $a['title']),
                'oldest' => ($a['order_index'] ?? 0) <=> ($b['order_index'] ?? 0),
                'newest' => ($b['order_index'] ?? 0) <=> ($a['order_index'] ?? 0),
                default => strcasecmp($a['title'], $b['title']),
            };

        });

        return $folders;
    }
}

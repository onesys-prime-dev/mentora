<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Services\Groups\ActivityGroupService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The learning item editor's "Group activity" card (D12, D13, screen 2A): the same endpoints as an assignment's
 * groups, for an ungraded item. Only grouping and completion: no leader, grading, extension or freeze routes,
 * and only the item settings (ActivityGroupService::ITEM_SETTINGS) are accepted. An item not of the offering,
 * or a group not of this item, is "not found".
 */
class LearningItemGroupsController extends ActivityGroupsController
{
    public function attach(int $offeringId, int $itemId): ResponseInterface
    {
        $this->findConfig($offeringId, $itemId);
        $body  = $this->body();
        $setId = isset($body['set_id']) && $body['set_id'] !== '' ? (int) $body['set_id'] : null;

        return $this->run(fn () => ['id' => (new ActivityGroupService())->attachLearningItem($itemId, $setId, (string) ($body['completion_mode'] ?? ''), $this->actor(), (array) ($body['settings'] ?? []))]);
    }

    public function copyFrom(int $offeringId, int $itemId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $itemId);
        $from     = (new ActivityGroupService())->findByLearningItem((int) ($this->body()['from_learning_item_id'] ?? 0));

        return $this->run(function () use ($configId, $from) {
            if ($from === null) {
                throw StructureException::field('from_learning_item_id', 'Choose another item of this course that uses groups.');
            }

            (new ActivityGroupService())->copyFromActivity($configId, (int) $from['id'], $this->actor());
        });
    }

    protected function findConfig(int $offeringId, int $itemId): ?array
    {
        self::assertItemOf($offeringId, $itemId);

        return (new ActivityGroupService())->findByLearningItem($itemId);
    }

    protected function extensions(int $itemId): array
    {
        return [];
    }

    protected function dueDate(int $itemId): ?string
    {
        return null;
    }

    protected function activities(int $offeringId, int $itemId): array
    {
        $rows = db_connect()->table('activity_group_configs c')->select('c.learning_item_id, i.title')
            ->join('learning_items i', 'i.id = c.learning_item_id')
            ->where('c.offering_id', $offeringId)->where('c.learning_item_id !=', $itemId)->orderBy('i.title', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $a) => ['learning_item_id' => (int) $a['learning_item_id'], 'title' => $a['title']], $rows);
    }

    /** The item must belong to this offering (item, unit, module), else 404. Shared with the student controller. */
    public static function assertItemOf(int $offeringId, int $itemId): void
    {
        $found = db_connect()->table('learning_items i')->join('learning_units u', 'u.id = i.learning_unit_id')->join('course_modules m', 'm.id = u.module_id')
            ->where('i.id', $itemId)->where('m.offering_id', $offeringId)->countAllResults();

        if ($found === 0) {
            throw PageNotFoundException::forPageNotFound('Learning item not found.');
        }
    }
}

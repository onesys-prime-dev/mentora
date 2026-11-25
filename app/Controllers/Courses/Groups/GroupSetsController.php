<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Controllers\BaseController;
use App\Models\Groups\GroupSetModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Groups\GroupGeneratorService;
use App\Services\Groups\GroupLimits;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use App\Services\Profiles\AvatarData;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON for the Groups tab: the group sets of a course offering, their groups and members.
 * A set or group that is not of the offering in the address is "not found".
 */
class GroupSetsController extends BaseController
{
    public function index(int $offeringId): ResponseInterface
    {
        $sets = db_connect()->query(
            'SELECT s.id, s.name, s.status, s.formation_mode, s.min_size, s.max_size,
                (SELECT COUNT(*) FROM student_groups g WHERE g.set_id = s.id) AS group_count,
                (SELECT COUNT(*) FROM student_group_members m WHERE m.set_id = s.id) AS member_count
             FROM group_sets s WHERE s.offering_id = ? ORDER BY s.status ASC, s.id DESC',
            [$offeringId],
        )->getResultArray();

        return $this->response->setJSON(['success' => true, 'sets' => $sets]);
    }

    public function show(int $offeringId, int $setId): ResponseInterface
    {
        $set = $this->set($offeringId, $setId);

        $groups = (new StudentGroupModel())->where('set_id', $setId)->orderBy('id', 'ASC')->findAll();

        $members = db_connect()->table('student_group_members m')
            ->select("m.group_id, m.student_user_id, COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS name", false)
            ->join('users u', 'u.id = m.student_user_id')
            ->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->where('m.set_id', $setId)->orderBy('name', 'ASC')->get()->getResultArray();

        $byGroup = [];
        $placed  = [];

        foreach ($members as $m) {
            $placed[(int) $m['student_user_id']] = true;
        }

        $pool = (new GroupMembershipService())->poolForOffering($offeringId);
        $free = array_values(array_filter($pool, static fn (int $id) => ! isset($placed[$id])));

        $ungrouped = $free === [] ? [] : db_connect()->table('users u')
            ->select("u.id AS student_user_id, COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS name", false)
            ->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->whereIn('u.id', $free)->orderBy('name', 'ASC')->get()->getResultArray();

        // One lookup for the set: every member's avatar as this viewer may see them (FR2).
        $avatars = (new AvatarData())->forViewerJson(auth()->user(), [...array_keys($placed), ...array_map('intval', array_column($ungrouped, 'student_user_id'))]);
        $person  = static fn (array $s): array => ['student_user_id' => (int) $s['student_user_id'], 'name' => $avatars[(int) $s['student_user_id']]['name'] ?? (string) $s['name'], 'avatar' => $avatars[(int) $s['student_user_id']] ?? null];

        foreach ($members as $m) {
            $byGroup[(int) $m['group_id']][] = $person($m);
        }

        return $this->response->setJSON([
            'success'   => true,
            'set'       => $set,
            'groups'    => array_map(static fn (array $g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'members' => AvatarData::sortByName($byGroup[(int) $g['id']] ?? [])], $groups),
            'ungrouped' => AvatarData::sortByName(array_map($person, $ungrouped)),
            'limits'    => ['max_members' => GroupLimits::MAX_MEMBERS, 'max_groups' => GroupLimits::MAX_GROUPS_PER_ACTIVITY, 'max_sets' => GroupLimits::MAX_SETS_PER_OFFERING],
        ]);
    }

    public function create(int $offeringId): ResponseInterface
    {
        return $this->run(fn () => ['id' => (new GroupSetService())->create($offeringId, $this->body(), $this->actor())]);
    }

    public function update(int $offeringId, int $setId): ResponseInterface
    {
        $this->set($offeringId, $setId);

        return $this->run(fn () => (new GroupSetService())->update($setId, $this->body(), $this->actor()));
    }

    public function archive(int $offeringId, int $setId): ResponseInterface
    {
        $this->set($offeringId, $setId);

        return $this->run(fn () => (new GroupSetService())->archive($setId, $this->actor()));
    }

    public function addGroup(int $offeringId, int $setId): ResponseInterface
    {
        $this->set($offeringId, $setId);

        return $this->run(fn () => ['id' => (new GroupSetService())->addGroup($setId, (string) ($this->body()['name'] ?? ''), $this->actor())]);
    }

    public function renameGroup(int $offeringId, int $setId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $setId, $groupId);

        return $this->run(fn () => (new GroupSetService())->renameGroup($groupId, (string) ($this->body()['name'] ?? ''), $this->actor()));
    }

    public function deleteGroup(int $offeringId, int $setId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $setId, $groupId);

        return $this->run(fn () => (new GroupSetService())->deleteGroup($groupId, $this->actor()));
    }

    public function addMember(int $offeringId, int $setId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $setId, $groupId);

        return $this->run(fn () => (new GroupMembershipService())->addToSet($groupId, (int) ($this->body()['student_user_id'] ?? 0), $this->actor()));
    }

    public function moveMember(int $offeringId, int $setId): ResponseInterface
    {
        $body = $this->body();
        $this->group($offeringId, $setId, (int) ($body['to_group_id'] ?? 0));

        return $this->run(fn () => (new GroupMembershipService())->moveInSet((int) ($body['student_user_id'] ?? 0), (int) $body['to_group_id'], $this->actor()));
    }

    public function removeMember(int $offeringId, int $setId, int $studentId): ResponseInterface
    {
        $this->set($offeringId, $setId);

        if (db_connect()->table('student_group_members')->where('set_id', $setId)->where('student_user_id', $studentId)->countAllResults() === 0) {
            throw PageNotFoundException::forPageNotFound('Student not found in this set.');
        }

        return $this->run(fn () => (new GroupMembershipService())->removeFromSet($studentId, $setId, $this->actor()));
    }

    /** Proposes groups for the set's pool. Writes nothing; the instructor edits and saves it with generateSave. */
    public function generatePreview(int $offeringId, int $setId): ResponseInterface
    {
        $set  = $this->set($offeringId, $setId);
        $body = $this->body();
        $pair = static fn ($list): array => array_values(array_filter(
            array_map(static fn ($p) => is_array($p) && count($p) === 2 ? [(int) $p[0], (int) $p[1]] : null, (array) $list),
        ));

        return $this->run(function () use ($offeringId, $setId, $set, $body, $pair) {
            $result = (new GroupGeneratorService())->preview($offeringId, (new GroupMembershipService())->poolForOffering($offeringId), [
                'by'       => ($body['by'] ?? '') === 'count' ? 'count' : 'size',
                'n'        => (int) ($body['n'] ?? 0),
                'together' => $pair($body['together'] ?? []),
                'apart'    => $pair($body['apart'] ?? []),
                'max'      => (int) $set['max_size'],
                'seed'     => isset($body['seed']) ? (int) $body['seed'] : null,
            ]);

            $ids   = array_merge($result['ungrouped'], ...$result['groups']);
            $names = $ids === [] ? [] : array_column(db_connect()->table('users u')
                ->select("u.id, COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username) AS name", false)
                ->join('user_profiles p', 'p.user_id = u.id', 'left')
                ->whereIn('u.id', $ids)->get()->getResultArray(), 'name', 'id');
            // One lookup for the preview: each proposed member's avatar and name as this viewer may see them (FR2).
            $avatars = (new AvatarData())->forViewerJson(auth()->user(), array_map('intval', $ids));
            $person  = static fn (int $id): array => ['student_user_id' => $id, 'name' => $avatars[$id]['name'] ?? (string) ($names[$id] ?? ''), 'avatar' => $avatars[$id] ?? null];

            return [
                'groups'    => array_map(static fn (array $g) => ['members' => AvatarData::sortByName(array_map($person, $g))], $result['groups']),
                'ungrouped' => AvatarData::sortByName(array_map($person, $result['ungrouped'])),
                'conflicts' => $result['conflicts'],
                'replaces'  => (new StudentGroupModel())->where('set_id', $setId)->countAllResults(),
            ];
        });
    }

    /** Stores a previewed (possibly edited) grouping; replacing existing groups needs replace=true. */
    public function generateSave(int $offeringId, int $setId): ResponseInterface
    {
        $this->set($offeringId, $setId);
        $body = $this->body();

        return $this->run(function () use ($setId, $body) {
            (new GroupSetService())->saveGenerated($setId, ['groups' => (array) ($body['groups'] ?? [])], ($body['replace'] ?? false) === true, $this->actor());

            return ['count' => count((array) ($body['groups'] ?? []))];
        });
    }

    /** Runs a change: success (with what it returned), or 422 with the rule that was broken. */
    private function run(callable $change): ResponseInterface
    {
        try {
            $result = $change();
        } catch (StructureException $e) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()]);
        }

        return $this->response->setJSON(['success' => true] + (is_array($result) ? $result : []));
    }

    /** @return array<string, mixed> */
    private function set(int $offeringId, int $setId): array
    {
        $set = (new GroupSetModel())->find($setId);

        if ($set === null || (int) $set['offering_id'] !== $offeringId) {
            throw PageNotFoundException::forPageNotFound('Group set not found.');
        }

        return $set;
    }

    private function group(int $offeringId, int $setId, int $groupId): void
    {
        $this->set($offeringId, $setId);

        if ((new StudentGroupModel())->where('id', $groupId)->where('set_id', $setId)->countAllResults() === 0) {
            throw PageNotFoundException::forPageNotFound('Group not found.');
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    private function actor(): int
    {
        return (int) auth()->id();
    }
}

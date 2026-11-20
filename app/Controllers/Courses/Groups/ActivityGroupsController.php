<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Controllers\BaseController;
use App\Models\Assessments\AssessmentModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON for the assignment editor's "Group activity" card (screen 2A): the activity's settings, its own groups
 * (copied from a set, D2) and who is not in one. An assessment not of the offering, or a group not of this
 * activity, is "not found"; a broken rule is a 422 with { message, errors }.
 */
class ActivityGroupsController extends BaseController
{
    private const NAME = "COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username)";

    public function show(int $offeringId, int $assessmentId): ResponseInterface
    {
        $this->assessment($offeringId, $assessmentId);
        $service = new ActivityGroupService();
        $config  = $service->findByAssessment($assessmentId);
        $groups  = [];
        $free    = [];

        if ($config !== null) {
            $configId = (int) $config['id'];
            $members  = db_connect()->table('student_group_members m')
                ->select('m.group_id, m.student_user_id, ' . self::NAME . ' AS name', false)
                ->join('users u', 'u.id = m.student_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
                ->where('m.config_id', $configId)->orderBy('name', 'ASC')->get()->getResultArray();

            $byGroup = [];

            foreach ($members as $m) {
                $byGroup[(int) $m['group_id']][] = ['student_user_id' => (int) $m['student_user_id'], 'name' => (string) $m['name']];
            }

            $groups = array_map(static fn (array $g) => [
                'id'      => (int) $g['id'],
                'name'    => $g['name'],
                'frozen'  => $g['frozen_at'] !== null,
                'members' => $byGroup[(int) $g['id']] ?? [],
            ], (new StudentGroupModel())->where('config_id', $configId)->orderBy('id', 'ASC')->findAll());

            $free = array_values(array_diff($service->pool($configId), array_map('intval', array_column($members, 'student_user_id'))));

            $config['source_set_name'] = $config['source_set_id'] === null ? null
                : db_connect()->table('group_sets')->select('name')->where('id', $config['source_set_id'])->get()->getRow()?->name;
        }

        $ungrouped = $free === [] ? [] : db_connect()->table('users u')
            ->select('u.id AS student_user_id, ' . self::NAME . ' AS name', false)
            ->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->whereIn('u.id', $free)->orderBy('name', 'ASC')->get()->getResultArray();

        $sets = db_connect()->query(
            'SELECT s.id, s.name, (SELECT COUNT(*) FROM student_groups g WHERE g.set_id = s.id) AS group_count
             FROM group_sets s WHERE s.offering_id = ? AND s.status = ? ORDER BY s.name ASC',
            [$offeringId, 'active'],
        )->getResultArray();

        // Other group activities of this course, to copy groups from.
        $activities = db_connect()->table('activity_group_configs c')->select('c.assessment_id, a.title')
            ->join('assessments a', 'a.id = c.assessment_id')
            ->where('c.offering_id', $offeringId)->where('c.assessment_id !=', $assessmentId)->orderBy('a.title', 'ASC')->get()->getResultArray();

        $access = GroupsTabController::access($offeringId);

        return $this->response->setJSON([
            'success'    => true,
            'config'     => $config,
            'groups'     => $groups,
            'ungrouped'  => array_map(static fn (array $s) => ['student_user_id' => (int) $s['student_user_id'], 'name' => (string) $s['name']], $ungrouped),
            'sets'       => array_map(static fn (array $s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'group_count' => (int) $s['group_count']], $sets),
            'activities' => array_map(static fn (array $a) => ['assessment_id' => (int) $a['assessment_id'], 'title' => $a['title']], $activities),
            'flags'      => [
                'frozenGroups'      => in_array(true, array_column($groups, 'frozen'), true),
                'canWrite'          => $access['canEdit'],
                'offeringFinalized' => $access['finalized'],
            ],
            'lockReason' => $access['lockReason'],
        ]);
    }

    public function attach(int $offeringId, int $assessmentId): ResponseInterface
    {
        $this->assessment($offeringId, $assessmentId);
        $body  = $this->body();
        $setId = isset($body['set_id']) && $body['set_id'] !== '' ? (int) $body['set_id'] : null;

        return $this->run(fn () => ['id' => (new ActivityGroupService())->attachAssessment($assessmentId, $setId, (array) ($body['settings'] ?? []), $this->actor())]);
    }

    public function settings(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $body     = $this->body();

        return $this->run(fn () => (new ActivityGroupMembershipService())->changeSettings($configId, (array) ($body['changes'] ?? []), ($body['confirmed'] ?? false) === true, $this->actor()));
    }

    public function detach(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);

        return $this->run(fn () => (new ActivityGroupService())->detach($configId, $this->actor()));
    }

    public function recopy(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);

        return $this->run(fn () => (new ActivityGroupService())->recopy($configId, $this->actor()));
    }

    public function copyFrom(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $from     = (new ActivityGroupService())->findByAssessment((int) ($this->body()['from_assessment_id'] ?? 0));

        return $this->run(function () use ($configId, $from) {
            if ($from === null) {
                throw StructureException::field('from_assessment_id', 'Choose another group activity of this course to copy from.');
            }

            (new ActivityGroupService())->copyFromActivity($configId, (int) $from['id'], $this->actor());
        });
    }

    public function addGroup(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);

        return $this->run(fn () => ['id' => (new ActivityGroupService())->addGroup($configId, (string) ($this->body()['name'] ?? ''), $this->actor())]);
    }

    public function renameGroup(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $assessmentId, $groupId);

        return $this->run(fn () => (new ActivityGroupService())->renameGroup($groupId, (string) ($this->body()['name'] ?? ''), $this->actor()));
    }

    public function deleteGroup(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $assessmentId, $groupId);

        return $this->run(fn () => (new ActivityGroupService())->deleteGroup($groupId, $this->actor()));
    }

    public function addMember(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $assessmentId, $groupId);

        return $this->run(fn () => (new ActivityGroupMembershipService())->addMember($groupId, (int) ($this->body()['student_user_id'] ?? 0), $this->actor()));
    }

    public function moveMember(int $offeringId, int $assessmentId): ResponseInterface
    {
        $body = $this->body();
        $this->group($offeringId, $assessmentId, (int) ($body['to_group_id'] ?? 0));

        return $this->run(fn () => (new ActivityGroupMembershipService())->moveMember((int) ($body['student_user_id'] ?? 0), (int) $body['to_group_id'], $this->actor()));
    }

    public function removeMember(int $offeringId, int $assessmentId, int $studentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);

        return $this->run(fn () => (new ActivityGroupMembershipService())->removeMember($studentId, $configId, $this->actor()));
    }

    public function unlock(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $assessmentId, $groupId);

        return $this->run(fn () => (new ActivityGroupMembershipService())->unlock($groupId, (string) ($this->body()['reason'] ?? ''), $this->actor()));
    }

    public function addLateMember(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        $this->group($offeringId, $assessmentId, $groupId);
        $body = $this->body();

        return $this->run(fn () => (new ActivityGroupMembershipService())->addLateEnrollee($groupId, (int) ($body['student_user_id'] ?? 0), (string) ($body['reason'] ?? ''), $this->actor()));
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

    /** The assessmentscope filter already checks this; kept so the controller is safe on its own. */
    private function assessment(int $offeringId, int $assessmentId): void
    {
        if ((new AssessmentModel())->offeringIdOf($assessmentId) !== $offeringId) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }
    }

    private function configId(int $offeringId, int $assessmentId): int
    {
        $this->assessment($offeringId, $assessmentId);
        $config = (new ActivityGroupService())->findByAssessment($assessmentId);

        if ($config === null) {
            throw PageNotFoundException::forPageNotFound('This assignment does not use groups.');
        }

        return (int) $config['id'];
    }

    private function group(int $offeringId, int $assessmentId, int $groupId): void
    {
        if ((new StudentGroupModel())->where('id', $groupId)->where('config_id', $this->configId($offeringId, $assessmentId))->countAllResults() === 0) {
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

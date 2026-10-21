<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * Which students a parent is linked to, as the institutional administrator
 * keeps it.
 *
 * Rules:
 *  - Both people belong to the administrator's institution (anyone else is a
 *    404), the parent holds the parent role and the student the student role.
 *  - A student is named by student number or email, like an enrolment, and
 *    several can be linked at once; the ones that cannot be are skipped and
 *    named with the reason.
 *  - A link is made once (the same pair is skipped) and removed, never edited.
 *  - Removing a link changes nothing else about either person.
 *  - Every change is audited.
 */
class ParentLinkService
{
    public const PER_PAGE = 25;

    /**
     * The institution's parents with their linked students.
     *
     * @param array<string, mixed> $filters q, page
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function list(int $institutionId, array $filters = []): array
    {
        $db = db_connect();

        $base = static function () use ($db, $institutionId, $filters) {
            $builder = $db->table('institution_users iu')
                ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL', 'inner', false)
                ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'parent'", 'inner', false)
                ->join('user_profiles up', 'up.user_id = u.id', 'left')
                ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left', false)
                ->where('iu.institution_id', $institutionId);

            $q = trim((string) ($filters['q'] ?? ''));

            if ($q !== '') {
                $like = $db->escapeLikeString($q);
                $builder->groupStart()
                    ->where("CONCAT(COALESCE(up.first_name, ''), ' ', COALESCE(up.last_name, '')) LIKE '%{$like}%'", null, false)
                    ->orWhere("ai.secret LIKE '%{$like}%'", null, false)
                    ->groupEnd();
            }

            return $builder;
        };

        $total = (int) $base()->select('COUNT(DISTINCT u.id) AS total', false)->get()->getRowArray()['total'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $parents = $base()
            ->select('u.id, u.active, up.first_name, up.last_name, ai.secret AS email')
            ->orderBy('up.last_name')->orderBy('up.first_name')->orderBy('u.id')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        $ids      = array_map(static fn (array $r): int => (int) $r['id'], $parents);
        $students = $this->studentsOf($institutionId, $ids);

        $rows = [];

        foreach ($parents as $p) {
            $id     = (int) $p['id'];
            $rows[] = [
                'id'       => $id,
                'name'     => trim($p['first_name'] . ' ' . $p['last_name']) ?: (string) $p['email'],
                'email'    => (string) $p['email'],
                'active'   => (int) $p['active'] === 1,
                'students' => $students[$id] ?? [],
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Links the named students to the parent.
     *
     * @return array{linked: int, skipped: list<array{identifier: string, reason: string}>, warnings: list<string>}
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function link(int $institutionId, int $parentId, string $text, ?User $actor = null): array
    {
        $scope = InstitutionScope::forInstitution($institutionId);
        $scope->require('person', $parentId);

        $db = db_connect();

        if ($db->table('auth_groups_users')->where(['user_id' => $parentId, 'group' => 'parent'])->countAllResults() === 0) {
            throw PageNotFoundException::forPageNotFound();
        }

        $found   = $this->resolve($institutionId, $text);
        $skipped = $found['skipped'];
        $toLink  = [];

        foreach ($found['students'] as $studentId) {
            $name = $found['names'][$studentId];

            if ($studentId === $parentId) {
                $skipped[] = ['identifier' => $name, 'reason' => 'is the parent themselves'];
            } elseif ($db->table('parent_student_links')->where(['institution_id' => $institutionId, 'parent_user_id' => $parentId, 'student_user_id' => $studentId])->countAllResults() > 0) {
                $skipped[] = ['identifier' => $name, 'reason' => 'is already linked'];
            } else {
                $toLink[] = $studentId;
            }
        }

        if ($toLink === []) {
            throw StructureException::field('students', $this->skippedText($skipped, 'Nobody was linked.'));
        }

        $made = $this->createLinks($institutionId, $parentId, $toLink, $actor);

        return [
            'linked'   => $made['linked'],
            'skipped'  => $skipped,
            'warnings' => $skipped === [] ? [] : [$this->skippedText($skipped, count($skipped) === 1 ? '1 was skipped.' : count($skipped) . ' were skipped.')],
        ];
    }

    /**
     * Finds the students a pasted list names (a student number or an email per
     * line), without linking anyone. A student named twice counts once. Anyone
     * who cannot be used is listed with the reason; the rest are returned.
     *
     * @return array{students: list<int>, names: array<int, string>, skipped: list<array{identifier: string, reason: string}>}
     *
     * @throws StructureException an empty list, or more lines than EnrolmentList::MAX
     */
    public function resolve(int $institutionId, string $text): array
    {
        $names = EnrolmentList::parse($text);

        if ($names === []) {
            throw StructureException::field('students', 'Enter at least one student number or email address.');
        }

        if (count($names) > EnrolmentList::MAX) {
            throw StructureException::field('students', 'That is more than ' . EnrolmentList::MAX . ' at once. Add them in smaller groups.');
        }

        $skipped = [];
        $found   = [];

        foreach ($names as $name) {
            $student = $this->findStudent($institutionId, $name);

            if (is_string($student)) {
                $skipped[] = ['identifier' => $name, 'reason' => $student];

                continue;
            }

            $found[(int) $student['id']] ??= $name;
        }

        return ['students' => array_keys($found), 'names' => $found, 'skipped' => $skipped];
    }

    /**
     * Links the parent to these students, all or none. Each id is checked again
     * (a student of this institution, not the parent, not already linked), so a
     * list prepared earlier, such as the one an invitation carries, is safe to
     * use later: whoever is no longer valid is skipped. One `parent.linked`
     * audit entry per link, with $audit merged into what it records.
     *
     * @param list<int>            $studentIds
     * @param array<string, mixed> $audit      extra keys for the audit entry (for example how the link came about)
     *
     * @return array{linked: int, skipped: list<int>} the ids that were skipped
     *
     * @throws StructureException when the links could not be saved (nothing is then changed)
     */
    public function createLinks(int $institutionId, int $parentId, array $studentIds, ?User $actor = null, array $audit = []): array
    {
        $db      = db_connect();
        $now     = date('Y-m-d H:i:s');
        $linked  = 0;
        $skipped = [];

        $db->transStart();

        foreach (array_values(array_unique(array_map('intval', $studentIds))) as $studentId) {
            if (
                $studentId === $parentId
                || ! $this->isStudentOf($institutionId, $studentId)
                || $db->table('parent_student_links')->where(['institution_id' => $institutionId, 'parent_user_id' => $parentId, 'student_user_id' => $studentId])->countAllResults() > 0
            ) {
                $skipped[] = $studentId;

                continue;
            }

            $db->table('parent_student_links')->insert([
                'institution_id'     => $institutionId,
                'parent_user_id'     => $parentId,
                'student_user_id'    => $studentId,
                'created_by_user_id' => ($actor ?? auth()->user())?->id,
                'created_at'         => $now,
            ]);

            $this->audit('parent.linked', $actor, $institutionId, (int) $db->insertID(), null, ['parent_user_id' => $parentId, 'student_user_id' => $studentId] + $audit);

            $linked++;
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new StructureException('The students could not be linked. Nothing was changed.');
        }

        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /** Whether this id is a student of the institution right now (member, not deleted, holds the student role). */
    private function isStudentOf(int $institutionId, int $userId): bool
    {
        return db_connect()->table('users u')
            ->join('institution_users iu', 'iu.user_id = u.id')
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'student'", 'inner', false)
            ->where('iu.institution_id', $institutionId)
            ->where('u.id', $userId)
            ->where('u.deleted_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    /**
     * @throws PageNotFoundException when the link is not this institution's
     */
    public function unlink(int $institutionId, int $linkId, ?User $actor = null): void
    {
        $db   = db_connect();
        $link = $db->table('parent_student_links')->where(['id' => $linkId, 'institution_id' => $institutionId])->get()->getRowArray();

        if ($link === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $db->table('parent_student_links')->where('id', $linkId)->delete();

        $this->audit('parent.unlinked', $actor, $institutionId, $linkId, ['parent_user_id' => (int) $link['parent_user_id'], 'student_user_id' => (int) $link['student_user_id']], null);
    }

    /**
     * @param list<int> $parentIds
     *
     * @return array<int, list<array{link_id: int, student_id: int, name: string}>>
     */
    private function studentsOf(int $institutionId, array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = db_connect()->table('parent_student_links l')
            ->select('l.id, l.parent_user_id, l.student_user_id, up.first_name, up.last_name, ai.secret AS email')
            ->join('user_profiles up', 'up.user_id = l.student_user_id', 'left')
            ->join('auth_identities ai', "ai.user_id = l.student_user_id AND ai.type = 'email_password'", 'left', false)
            ->where('l.institution_id', $institutionId)
            ->whereIn('l.parent_user_id', $parentIds)
            ->orderBy('up.last_name')->orderBy('up.first_name')
            ->get()->getResultArray();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['parent_user_id']][] = [
                'link_id'    => (int) $row['id'],
                'student_id' => (int) $row['student_user_id'],
                'name'       => trim($row['first_name'] . ' ' . $row['last_name']) ?: (string) $row['email'],
            ];
        }

        return $grouped;
    }

    /**
     * The student a number or an email names, or why there is none.
     *
     * @return array<string, mixed>|string
     */
    private function findStudent(int $institutionId, string $name): array|string
    {
        $builder = db_connect()->table('users u')
            ->select('u.id, u.active')
            ->join('institution_users iu', 'iu.user_id = u.id')
            ->where('iu.institution_id', $institutionId)
            ->where('u.deleted_at IS NULL', null, false);

        if (str_contains($name, '@')) {
            $builder->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'inner', false)
                ->where('LOWER(ai.secret)', mb_strtolower($name));
        } else {
            $builder->join('user_profiles up', 'up.user_id = u.id', 'inner')->where('up.student_number', $name);
        }

        $found = $builder->get()->getResultArray();

        if ($found === []) {
            return 'is not a student number or email in your institution';
        }

        if (count($found) > 1) {
            return 'matches more than one person; use their email address';
        }

        $isStudent = db_connect()->table('auth_groups_users')->where(['user_id' => (int) $found[0]['id'], 'group' => 'student'])->countAllResults() > 0;

        return $isStudent ? $found[0] : 'does not hold the student role';
    }

    /**
     * @param list<array{identifier: string, reason: string}> $skipped
     */
    private function skippedText(array $skipped, string $lead): string
    {
        $parts = array_map(static fn (array $s): string => $s['identifier'] . ' ' . $s['reason'], array_slice($skipped, 0, 5));
        $more  = count($skipped) > 5 ? ' and ' . (count($skipped) - 5) . ' more' : '';

        return $skipped === [] ? $lead : $lead . ' Skipped: ' . implode('; ', $parts) . $more . '.';
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'parent_link',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

/**
 * One account, one institution (audit decision D1); a system administrator belongs to none.
 *
 * An account with more than one membership keeps its oldest one in an institution that still exists, and
 * the others are removed; the unique key then keeps it that way. An account whose institutions are all
 * deleted keeps its oldest row, so purging that institution still finds the account.
 */
class OneInstitutionPerAccount extends Migration
{
    public function up(): void
    {
        $rows = $this->db->table('institution_users iu')
            ->select('iu.id, iu.user_id, iu.institution_id, i.deleted_at')
            ->join('institutions i', 'i.id = iu.institution_id')
            ->get()->getResultArray();

        $drop = self::membershipsToDrop(array_map(static fn (array $r): array => [
            'id'      => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'deleted' => $r['deleted_at'] !== null,
        ], $rows));

        foreach ($rows as $row) {
            if (! in_array((int) $row['id'], $drop, true)) {
                continue;
            }

            // Their place in that institution goes with the membership, so they can't keep working in its courses.
            self::endRolesIn($this->db, (int) $row['user_id'], (int) $row['institution_id']);

            log_message('warning', 'One institution per account: user {user} was removed from institution {institution}, and their roles there ended.', [
                'user' => $row['user_id'], 'institution' => $row['institution_id'],
            ]);
        }

        if ($drop !== []) {
            $this->db->table('institution_users')->whereIn('id', $drop)->delete();
        }

        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('institution_users') . ' ADD UNIQUE KEY institution_users_user_id_unique (user_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('institution_users') . ' DROP INDEX institution_users_user_id_unique');
    }

    /**
     * Ends a person's place in an institution they no longer belong to: enrolments in its sections are
     * dropped (work and grades kept), teaching assignments removed, chairperson assignments revoked and
     * deanships ended, as the app itself does when someone leaves a role.
     */
    public static function endRolesIn(BaseConnection $db, int $userId, int $institutionId): void
    {
        $now = date('Y-m-d H:i:s');

        $offerings = array_map('intval', array_column($db->query(
            'SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id'
            . ' JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?',
            [$institutionId]
        )->getResultArray(), 'id'));

        $subDepartments = array_map('intval', array_column($db->query(
            'SELECT sd.id FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?',
            [$institutionId]
        )->getResultArray(), 'id'));

        $departments = array_map('intval', array_column($db->table('departments')->select('id')->where('institution_id', $institutionId)->get()->getResultArray(), 'id'));

        if ($offerings !== []) {
            $db->table('enrollments')->where('student_user_id', $userId)->whereIn('offering_id', $offerings)->whereIn('status', ['enrolled'])->update(['status' => 'dropped', 'updated_at' => $now]);
            $db->table('instructor_assignments')->where('instructor_user_id', $userId)->whereIn('offering_id', $offerings)->delete();
        }

        if ($subDepartments !== []) {
            $db->table('chairperson_assignments')->where(['chairperson_user_id' => $userId, 'status' => 'active'])->whereIn('sub_department_id', $subDepartments)->update(['status' => 'revoked', 'updated_at' => $now]);
        }

        if ($departments !== []) {
            $db->table('dean_assignments')->where(['dean_user_id' => $userId, 'status' => 'active'])->whereIn('department_id', $departments)
                ->update(['status' => 'ended', 'active_marker' => null, 'ended_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * The memberships to remove so each account keeps one: its oldest in an institution that still
     * exists, or its oldest when every one of them is deleted.
     *
     * @param list<array{id: int, user_id: int, deleted: bool}> $rows
     *
     * @return list<int>
     */
    public static function membershipsToDrop(array $rows): array
    {
        $byUser = [];

        foreach ($rows as $row) {
            $byUser[$row['user_id']][] = $row;
        }

        $drop = [];

        foreach ($byUser as $memberships) {
            usort($memberships, static fn (array $a, array $b): int => [$a['deleted'], $a['id']] <=> [$b['deleted'], $b['id']]);

            foreach (array_slice($memberships, 1) as $extra) {
                $drop[] = $extra['id'];
            }
        }

        sort($drop);

        return $drop;
    }
}

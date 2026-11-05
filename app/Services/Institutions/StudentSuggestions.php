<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * The suggestions behind the "add students" boxes: who to offer while an administrator types an email, a student
 * number or a name.
 *
 * What is offered is what an add would accept:
 *  - students of the given institution only (the caller takes the institution from the signed-in administrator,
 *    never from the request), with an active account that has not been switched off;
 *  - for an offering, not those who already have the place (enrolled, or completed: an add refuses both). A
 *    student who dropped or withdrew is offered, since adding them brings the place back;
 *  - for a block section, not those already in it;
 *  - nothing for a query shorter than MIN_QUERY, and at most LIMIT people, in name order.
 *
 * It reads only: nothing is enrolled here, and the add keeps every rule of its own.
 */
class StudentSuggestions
{
    public const MIN_QUERY = 2;

    public const LIMIT = 8;

    /**
     * @return list<array{name: string, student_number: string, email: string}>
     */
    public function search(int $institutionId, string $query, ?int $offeringId = null, ?int $sectionId = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        $db   = db_connect();
        $like = $db->escape('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%');

        $builder = $db->table('institution_users iu')
            ->select('up.first_name, up.last_name, up.student_number, ai.secret AS email')
            ->join('users u', 'u.id = iu.user_id')
            ->join('user_profiles up', 'up.user_id = u.id')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'inner', false)
            ->join('user_account_controls uac', 'uac.user_id = u.id', 'left')
            ->where('iu.institution_id', $institutionId)
            ->where('u.active', 1)
            ->where('u.deleted_at IS NULL', null, false)
            ->where('uac.deactivated_at IS NULL', null, false)
            ->where("EXISTS (SELECT 1 FROM auth_groups_users g WHERE g.user_id = u.id AND g.`group` = 'student')", null, false)
            ->where("(ai.secret LIKE {$like} OR up.student_number LIKE {$like} OR up.first_name LIKE {$like} OR up.last_name LIKE {$like} OR CONCAT(up.first_name, ' ', up.last_name) LIKE {$like})", null, false);

        if ($offeringId !== null) {
            $builder->where("NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.offering_id = {$offeringId} AND e.student_user_id = u.id AND e.status IN ('enrolled', 'completed'))", null, false);
        }

        if ($sectionId !== null) {
            $builder->where("NOT EXISTS (SELECT 1 FROM class_section_students m WHERE m.class_section_id = {$sectionId} AND m.student_user_id = u.id)", null, false);
        }

        $rows = $builder->orderBy('up.last_name')->orderBy('up.first_name')->orderBy('u.id')->limit(self::LIMIT)->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'name'           => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['email'],
            'student_number' => (string) ($r['student_number'] ?? ''),
            'email'          => (string) $r['email'],
        ], $rows);
    }
}

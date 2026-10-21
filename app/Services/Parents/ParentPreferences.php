<?php

declare(strict_types=1);

namespace App\Services\Parents;

/**
 * A parent's email choice for each of their children. The app's own
 * notification is always on; this only decides whether Mentora also emails.
 * On unless turned off, and only ever kept for a child the parent is linked to.
 */
class ParentPreferences
{
    public function emailEnabled(int $parentId, int $studentId): bool
    {
        $row = db_connect()->table('parent_child_preferences')
            ->select('email_enabled')
            ->where(['parent_user_id' => $parentId, 'student_user_id' => $studentId])
            ->get()->getRowArray();

        return $row === null || (int) $row['email_enabled'] === 1;
    }

    /**
     * Saves the parent's choices.
     *
     * @param array<int, bool> $emailByChild student id => whether to email; a student the parent is not linked to is ignored
     */
    public function save(int $parentId, array $emailByChild): void
    {
        $db     = db_connect();
        $linked = array_map('intval', array_column(
            $db->table('parent_student_links')->select('student_user_id')->where('parent_user_id', $parentId)->get()->getResultArray(),
            'student_user_id',
        ));
        $now = date('Y-m-d H:i:s');

        foreach ($emailByChild as $studentId => $enabled) {
            if (! in_array((int) $studentId, $linked, true)) {
                continue;
            }

            $keys     = ['parent_user_id' => $parentId, 'student_user_id' => (int) $studentId];
            $existing = $db->table('parent_child_preferences')->where($keys)->countAllResults() > 0;

            $existing
                ? $db->table('parent_child_preferences')->where($keys)->update(['email_enabled' => $enabled ? 1 : 0, 'updated_at' => $now])
                : $db->table('parent_child_preferences')->insert($keys + ['email_enabled' => $enabled ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}

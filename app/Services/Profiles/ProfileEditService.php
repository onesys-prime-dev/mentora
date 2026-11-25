<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use CodeIgniter\Shield\Entities\User;

/** The owner's own edits of the public parts of their profile. Never reads the active role. */
class ProfileEditService
{
    public const NAME_CHANGES_PER_DAY = 3;

    public const PRONOUNS = ['she/her', 'he/him', 'they/them', 'prefer not to say'];

    private const STAFF_ROLES = ['dean', 'chairperson', 'instructor', 'institutional_admin'];

    /** section => [field => [label, max length]] */
    private const LIMITS = [
        'identity' => ['pronouns' => ['Pronouns', 30]],
        'about'    => ['bio' => ['About', 300]],
        'staff'    => ['office_location' => ['Office', 120], 'consultation_hours' => ['Consultation hours', 200], 'work_phone' => ['Work phone', 40]],
    ];

    /**
     * @param array<string, mixed> $input
     *
     * @throws ProfileEditException
     */
    public function update(User $owner, string $section, array $input): void
    {
        if (! isset(self::LIMITS[$section])) {
            throw new ProfileEditException('That part of your profile cannot be edited.');
        }

        $db = db_connect();
        $id = (int) $owner->id;

        if ($section === 'staff') {
            $groups = array_column($db->table('auth_groups_users')->select('group')->where('user_id', $id)->get()->getResultArray(), 'group');
            if (array_intersect($groups, self::STAFF_ROLES) === []) {
                throw new ProfileEditException('Office and consultation details are for staff only.');
            }
        }

        $row = $db->table('user_profiles')->where('user_id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new ProfileEditException('You have no profile to edit.');
        }

        $new = [];
        if ($section === 'identity') {
            $new['preferred_first_name'] = $this->preferredName((string) ($input['preferred_first_name'] ?? ''));
        }
        foreach (self::LIMITS[$section] as $field => [$label, $max]) {
            $value = $this->clean((string) ($input[$field] ?? ''));
            if ($value !== null && mb_strlen($value) > $max) {
                throw new ProfileEditException("{$label} can be at most {$max} characters.", $field);
            }
            $new[$field] = $value;
        }

        $changed = array_keys(array_filter($new, static fn ($v, $k): bool => ($row[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }

        if (in_array('preferred_first_name', $changed, true) && $this->nameChangesToday($id) >= self::NAME_CHANGES_PER_DAY) {
            throw new ProfileEditException('You can change your preferred name ' . self::NAME_CHANGES_PER_DAY . ' times a day. Try again tomorrow.', 'preferred_first_name');
        }

        $db->table('user_profiles')->where('user_id', $id)->update(array_intersect_key($new, array_flip($changed)));

        // Field names only: what someone wrote about themselves is not copied into the log (D16).
        service('auditLogger')->log('profile.updated', $owner, ['target_type' => 'user', 'target_id' => $id, 'new' => ['section' => $section, 'fields' => $changed]]);
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function preferredName(string $raw): ?string
    {
        $name = $this->clean($raw);
        if ($name === null) {
            return null;
        }
        $length = mb_strlen($name);
        if ($length < 2 || $length > 50) {
            throw new ProfileEditException('Preferred first name must be 2 to 50 characters.', 'preferred_first_name');
        }
        if (preg_match('/^[\p{L}][\p{L} .\'\-]*$/u', $name) !== 1) {
            throw new ProfileEditException('Preferred first name can use letters, spaces, periods, apostrophes and hyphens, and must start with a letter.', 'preferred_first_name');
        }

        return $name;
    }

    private function nameChangesToday(int $userId): int
    {
        return db_connect()->table('audit_logs')
            ->where('action', 'profile.updated')->where('actor_user_id', $userId)
            ->where('created_at >=', date('Y-m-d H:i:s', time() - 86400))
            ->like('new_values', '"preferred_first_name"')
            ->countAllResults();
    }
}

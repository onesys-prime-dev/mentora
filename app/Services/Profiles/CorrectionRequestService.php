<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Concerns\InTransaction;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\NotificationService;
use App\Services\Sis\SisOwnership;
use App\Services\Sis\StudentNumbers;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use App\Services\Users\AccountControl;

/**
 * Requests to correct an official identity field (FR7, D17): the owner asks, an administrator decides. A request
 * goes to the owner's institutional admins, or to the platform's system admins when the owner has no institution or
 * no other active institutional admin. Nobody sees or decides their own request. An approved email change waits
 * for the link sent to the new address (EmailChangeService). Audit entries carry the field, never the values.
 */
class CorrectionRequestService
{
    use InTransaction;

    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'student_number'];

    public const LABELS = ['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'suffix' => 'Suffix', 'email' => 'Email', 'student_number' => 'Student number'];

    public const TEXT_MAX = 500;

    public const ALREADY_DECIDED = 'This request was already decided.';

    /** field => [min length, max length]; a minimum of 0 means the value may be cleared */
    private const NAME_LENGTHS = ['first_name' => [2, 100], 'middle_name' => [0, 100], 'last_name' => [2, 100], 'suffix' => [0, 20]];

    /** @throws ProfileEditException */
    public function submit(User $owner, string $field, string $newValue, string $reason): int
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new ProfileEditException('That detail cannot be corrected here.', 'field');
        }

        $ownerId     = (int) $owner->id;
        $institution = $this->institutionOf($ownerId);

        if ($field === 'student_number' && ! $this->inGroup($ownerId, 'student')) {
            throw new ProfileEditException('Only a student has a student number to correct.', 'field');
        }

        $value  = $this->checked($field, $newValue, $ownerId, $institution);
        $reason = $this->text($reason, 'reason', 'Give a reason for the correction.');
        $old    = $this->current($ownerId, $field);

        if ($value === ($field === 'email' ? strtolower((string) $old) : (string) $old)) {
            throw new ProfileEditException('That is already the current value.', 'new_value');
        }

        $db = db_connect();

        if ($db->table('profile_correction_requests')->where(['user_id' => $ownerId, 'field' => $field, 'status' => 'pending'])->countAllResults() > 0) {
            throw new ProfileEditException('You already have a request open for this. Wait for a decision before asking again.', 'field');
        }

        $route = $this->routeOf($owner);

        $id = $this->inTransaction(function () use ($db, $owner, $ownerId, $institution, $field, $value, $reason, $old, $route): int {
            $stamp = date('Y-m-d H:i:s');

            $db->table('profile_correction_requests')->insert([
                'user_id'        => $ownerId,
                'institution_id' => $institution,
                'field'          => $field,
                'old_value'      => $old,
                'new_value'      => $value,
                'reason'         => $reason,
                'status'         => 'pending',
                'routed_to'      => $route,
                'created_at'     => $stamp,
                'updated_at'     => $stamp,
            ]);

            $id = (int) $db->insertID();

            service('auditLogger')->log('profile.correction_requested', $owner, ['target_type' => 'user', 'target_id' => $ownerId, 'institution_id' => $institution, 'new' => ['field' => $field, 'request_id' => $id]]);

            return $id;
        });

        (new NotificationService())->sendToMany(
            $this->deciders($route, $institution, $ownerId),
            'profile.correction_requested',
            'New correction request',
            $this->nameOf($ownerId) . ' asked to correct their ' . strtolower(self::LABELS[$field]) . '.',
            "correction:{$id}:new",
            $institution,
            $route === 'platform' ? 'admin/users' : 'institution/people/requests',
        );

        return $id;
    }

    /** 'institution', or 'platform' when the owner has no institution or no other active institutional admin there. */
    public function routeOf(User $owner): string
    {
        $institution = $this->institutionOf((int) $owner->id);

        return $institution === null || $this->deciders('institution', $institution, (int) $owner->id) === [] ? 'platform' : 'institution';
    }

    /**
     * The owner's own open requests, for "Waiting for ..." beside each field.
     *
     * @return array<string, string> field => 'institution' or 'platform'
     */
    public function openFor(User $owner): array
    {
        $rows = db_connect()->table('profile_correction_requests')->select('field, routed_to')->where(['user_id' => (int) $owner->id, 'status' => 'pending'])->get()->getResultArray();

        return array_column($rows, 'routed_to', 'field');
    }

    /** How many open requests this admin may decide (the tab badge): one COUNT on pendingFor's scope. */
    public function countFor(User $admin): int
    {
        $query = db_connect()->table('profile_correction_requests r')->where('r.status', 'pending')->where('r.user_id !=', (int) $admin->id);

        return $this->scope($query, $admin) ? $query->countAllResults() : 0;
    }

    /**
     * The open requests this admin may decide, oldest first. Never their own.
     *
     * @return list<array{id: int, user_id: int, requester: string, role: ?string, student_number: ?string, field: string, old_value: ?string, new_value: string, reason: ?string, created_at: string, sis_linked: bool}>
     */
    public function pendingFor(User $admin): array
    {
        $query = db_connect()->table('profile_correction_requests r')
            ->select('r.id, r.user_id, r.field, r.old_value, r.new_value, r.reason, r.created_at, r.routed_to, r.institution_id, p.student_number')
            ->join('user_profiles p', 'p.user_id = r.user_id', 'left')
            ->where('r.status', 'pending')
            ->where('r.user_id !=', (int) $admin->id)
            ->orderBy('r.created_at')->orderBy('r.id');

        if (! $this->scope($query, $admin)) {
            return [];
        }

        $sis  = new SisOwnership();
        $rows = $query->get()->getResultArray();
        $ids  = array_map('intval', array_column($rows, 'user_id'));

        $roles = [];
        foreach ($ids === [] ? [] : db_connect()->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', $ids)->get()->getResultArray() as $g) {
            $roles[(int) $g['user_id']][] = $g['group'];
        }

        // shortcut: one SIS lookup per row; fine for a review queue, batch it if queues grow to hundreds.
        return array_map(fn (array $r): array => [
            'id'         => (int) $r['id'],
            'user_id'    => (int) $r['user_id'],
            'requester'  => $this->nameOf((int) $r['user_id']),
            'role'       => RoleRank::highest($roles[(int) $r['user_id']] ?? []),
            'student_number' => $r['student_number'] === null || $r['student_number'] === '' ? null : (string) $r['student_number'],
            'field'      => $r['field'],
            'old_value'  => $r['old_value'],
            'new_value'  => $r['new_value'],
            'reason'     => $r['reason'],
            'created_at' => $r['created_at'],
            'sis_linked' => $sis->managesPerson((int) $r['user_id']),
        ], $rows);
    }

    /**
     * @throws PageNotFoundException when this admin may not decide this request (or it is not open)
     * @throws ProfileEditException  when the value cannot be written now
     */
    public function approve(int $id, User $admin, ?string $note): void
    {
        $request = $this->decidable($id, $admin);
        $note    = trim((string) $note) === '' ? null : $this->text((string) $note, 'note', '');
        $userId  = (int) $request['user_id'];
        $field   = $request['field'];
        $value   = $request['new_value'];

        if ($field === 'student_number' && StudentNumbers::taken($value, $this->institutionOf($userId), $userId)) {
            throw new ProfileEditException('That student number belongs to someone else now.', 'new_value');
        }

        if ($field === 'email' && EmailChangeService::taken($value, $userId)) {
            throw new ProfileEditException('An account with that email already exists now.', 'new_value');
        }

        // An update of a missing row "succeeds" with nothing written, so refuse before anything is decided.
        if ($field !== 'email' && db_connect()->table('user_profiles')->where('user_id', $userId)->countAllResults() === 0) {
            throw new ProfileEditException('This person has no profile to correct. Add their details from People first.', 'new_value');
        }

        $this->inTransaction(function () use ($id, $admin, $note, $request, $userId, $field, $value): void {
            $this->decide($id, $admin, 'approved', $note);
            $this->audit('profile.correction_approved', $admin, $request);

            // Last, because it emails: nothing after it can roll the request back.
            if ($field === 'email') {
                (new EmailChangeService())->start($userId, $value, $id);
            } else {
                $set = [$field => $value === '' ? null : $value, 'updated_at' => date('Y-m-d H:i:s')];

                if ($field === 'student_number') {
                    $set['student_number_institution_id'] = $this->institutionOf($userId);
                }

                db_connect()->table('user_profiles')->where('user_id', $userId)->update($set);
            }
        });

        $body = $field === 'email'
            ? 'We sent a link to ' . $value . '. Open it within ' . EmailChangeService::HOURS . ' hours to finish the change.'
            : 'Your ' . strtolower(self::LABELS[$field]) . ' is now updated.';

        $this->tell($userId, $id, 'approved', 'Your correction was approved', $body . ($note === null ? '' : "\n\nNote: {$note}"));
    }

    /**
     * @throws PageNotFoundException when this admin may not decide this request (or it is not open)
     * @throws ProfileEditException  when the note is missing or too long
     */
    public function reject(int $id, User $admin, string $note): void
    {
        $request = $this->decidable($id, $admin);
        $note    = $this->text($note, 'note', 'Give a reason for rejecting the request.');

        $this->inTransaction(function () use ($id, $admin, $note, $request): void {
            $this->decide($id, $admin, 'rejected', $note);
            $this->audit('profile.correction_rejected', $admin, $request);
        });

        $this->tell((int) $request['user_id'], $id, 'rejected', 'Your correction was not approved', 'Your request to correct your ' . strtolower(self::LABELS[$request['field']]) . " was not approved.\n\nReason: {$note}");
    }

    /**
     * A system administrator's own official details, edited directly (FR7.6): names are written now, a new email
     * waits for the link sent to it. Audited with the field names and the owner is emailed. Anyone else: not found.
     *
     * @param array<string, mixed> $input first_name, middle_name, last_name, suffix, email
     *
     * @throws PageNotFoundException when the user is not a system administrator
     * @throws ProfileEditException  when a value is refused (nothing is written)
     */
    public function updateOwnOfficial(User $admin, array $input): void
    {
        $id = (int) $admin->id;

        if (! $this->inGroup($id, 'admin')) {
            throw PageNotFoundException::forPageNotFound();
        }

        // The same rules as a correction; the error is named by the dialog's own field.
        $check = function (string $field) use ($input, $id): string {
            try {
                return $this->checked($field, (string) ($input[$field] ?? ''), $id, null);
            } catch (ProfileEditException $e) {
                throw new ProfileEditException($e->getMessage(), $field);
            }
        };

        $names = [];
        foreach (array_keys(self::NAME_LENGTHS) as $field) {
            $names[$field] = $check($field);
        }
        $email = $check('email');

        $changed = array_keys(array_filter($names, fn (string $v, string $f): bool => $v !== (string) $this->current($id, $f), ARRAY_FILTER_USE_BOTH));
        $newMail = $email !== strtolower((string) $this->current($id, 'email'));

        $this->inTransaction(function () use ($admin, $id, $names, $changed, $newMail): void {
            if ($changed !== []) {
                $db  = db_connect();
                $set = array_map(static fn (string $v): ?string => $v === '' ? null : $v, $names) + ['updated_at' => date('Y-m-d H:i:s')];

                // A system administrator may have no profile row yet: the first edit makes it.
                $db->table('user_profiles')->where('user_id', $id)->countAllResults() > 0
                    ? $db->table('user_profiles')->where('user_id', $id)->update($set)
                    : $db->table('user_profiles')->insert($set + ['user_id' => $id, 'created_at' => $set['updated_at']]);
            }

            if ($changed !== [] || $newMail) {
                service('auditLogger')->log('profile.official_updated', $admin, ['target_type' => 'user', 'target_id' => $id, 'new' => ['fields' => $newMail ? [...$changed, 'email'] : $changed]]);
            }
        });

        if ($changed !== []) {
            $labels = implode(', ', array_map(static fn (string $f): string => strtolower(self::LABELS[$f]), $changed));
            SecurityNotice::send($admin, 'Your official details were changed', "Your {$labels} on Mentora were changed.\n\n" . SecurityNotice::ifNotYou());
        }

        if ($newMail) {
            (new EmailChangeService())->start($id, $email, null); // emails both addresses itself
        }
    }

    /**
     * The open request, when this admin may decide it; otherwise not found, so ids cannot be probed. A request
     * the admin decides but that is no longer open is refused as already decided.
     *
     * @return array<string, mixed>
     *
     * @throws PageNotFoundException
     * @throws ProfileEditException
     */
    private function decidable(int $id, User $admin): array
    {
        $query = db_connect()->table('profile_correction_requests r')
            ->where('r.id', $id)->where('r.user_id !=', (int) $admin->id);

        $row = $this->scope($query, $admin) ? $query->get()->getRowArray() : null;

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($row['status'] !== 'pending') {
            throw new ProfileEditException(self::ALREADY_DECIDED, 'status');
        }

        return $row;
    }

    /** Limits a request query to what this admin decides; false when they decide nothing. */
    private function scope(\CodeIgniter\Database\BaseBuilder $query, User $admin): bool
    {
        $adminId = (int) $admin->id;

        // A deactivated or deleted admin decides nothing, even with a session left over.
        $active = db_connect()->table('users')->where(['id' => $adminId, 'active' => 1])->where('deleted_at IS NULL', null, false)->countAllResults() > 0;

        if (! $active || (new AccountControl())->isDeactivated($adminId)) {
            return false;
        }

        $system  = $this->inGroup($adminId, 'admin');
        $schools = $this->inGroup($adminId, 'institutional_admin')
            ? array_map('intval', array_column(db_connect()->table('institution_users')->select('institution_id')->where('user_id', $adminId)->get()->getResultArray(), 'institution_id'))
            : [];

        if (! $system && $schools === []) {
            return false;
        }

        $query->groupStart();

        if ($system) {
            $query->where('r.routed_to', 'platform');
        }

        if ($schools !== []) {
            $query->orGroupStart()->where('r.routed_to', 'institution')->whereIn('r.institution_id', $schools)->groupEnd();
        }

        $query->groupEnd();

        return true;
    }

    private function decide(int $id, User $admin, string $status, ?string $note): void
    {
        $stamp = date('Y-m-d H:i:s');

        db_connect()->table('profile_correction_requests')->where('id', $id)->where('status', 'pending')
            ->update(['status' => $status, 'decided_by' => (int) $admin->id, 'decision_note' => $note, 'decided_at' => $stamp, 'updated_at' => $stamp]);

        if (db_connect()->affectedRows() !== 1) {
            throw new ProfileEditException(self::ALREADY_DECIDED, 'status'); // another admin decided it a moment ago
        }
    }

    /** @param array<string, mixed> $request */
    private function audit(string $action, User $admin, array $request): void
    {
        service('auditLogger')->log($action, $admin, [
            'target_type'    => 'user',
            'target_id'      => (int) $request['user_id'],
            'institution_id' => $request['institution_id'] === null ? null : (int) $request['institution_id'],
            'new'            => ['field' => $request['field'], 'request_id' => (int) $request['id']],
        ]);
    }

    /** The requester's bell, sent urgent so it is emailed too. */
    private function tell(int $userId, int $id, string $outcome, string $title, string $body): void
    {
        (new NotificationService())->send($userId, 'profile.correction_' . $outcome, $title, $body, true, "correction:{$id}:{$outcome}", $this->institutionOf($userId), 'profile');
    }

    /** @return list<int> the active admins who decide a request on this route, without the requester */
    private function deciders(string $route, ?int $institution, int $ownerId): array
    {
        $recipients = new NotificationRecipients();
        $ids        = $route === 'platform' || $institution === null ? $recipients->administrators() : $recipients->institutionAdministrators($institution);

        return array_values(array_diff($ids, [$ownerId]));
    }

    /** @throws ProfileEditException */
    private function checked(string $field, string $raw, int $ownerId, ?int $institution): string
    {
        $value = trim($raw);

        if ($field === 'email') {
            $value = strtolower($value);

            if ($value === '' || mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new ProfileEditException('Enter a valid email address.', 'new_value');
            }

            if (EmailChangeService::taken($value, $ownerId)) {
                throw new ProfileEditException('An account with that email already exists.', 'new_value');
            }

            return $value;
        }

        if ($field === 'student_number') {
            if ($value === '' || mb_strlen($value) > 20 || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                throw new ProfileEditException('Student number must be 1 to 20 characters.', 'new_value');
            }

            if (StudentNumbers::taken($value, $institution, $ownerId)) {
                throw new ProfileEditException('That student number belongs to someone else.', 'new_value');
            }

            return $value;
        }

        [$min, $max] = self::NAME_LENGTHS[$field];
        $label       = self::LABELS[$field];
        $length      = mb_strlen($value);

        if ($length === 0 && $min === 0) {
            return '';
        }

        if ($length < max(1, $min) || $length > $max) {
            throw new ProfileEditException("{$label} must be " . max(1, $min) . " to {$max} characters.", 'new_value');
        }

        // The admin rule for legal names (InvitationInput): real names vary too much for a letter whitelist; only control and format characters are refused.
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new ProfileEditException("{$label} contains characters that are not allowed.", 'new_value');
        }

        return $value;
    }

    /** A required free-text answer (reason or note), trimmed, at most 500 characters. */
    private function text(string $raw, string $field, string $missing): string
    {
        $text = trim($raw);

        if ($text === '') {
            throw new ProfileEditException($missing, $field);
        }

        if (mb_strlen($text) > self::TEXT_MAX) {
            throw new ProfileEditException('Keep it to ' . self::TEXT_MAX . ' characters.', $field);
        }

        return $text;
    }

    private function current(int $userId, string $field): ?string
    {
        $db = db_connect();

        $value = $field === 'email'
            ? $db->table('auth_identities')->select('secret')->where(['user_id' => $userId, 'type' => 'email_password'])->get()->getRow('secret')
            : $db->table('user_profiles')->select($field)->where('user_id', $userId)->get()->getRow($field);

        return $value === null ? null : (string) $value;
    }

    private function institutionOf(int $userId): ?int
    {
        $id = db_connect()->table('institution_users')->select('institution_id')->where('user_id', $userId)->orderBy('id')->get()->getRow('institution_id');

        return $id === null ? null : (int) $id;
    }

    private function inGroup(int $userId, string $group): bool
    {
        return db_connect()->table('auth_groups_users')->where(['user_id' => $userId, 'group' => $group])->countAllResults() > 0;
    }

    private function nameOf(int $userId): string
    {
        $row = db_connect()->table('users u')->select('u.username, p.first_name, p.last_name')->join('user_profiles p', 'p.user_id = u.id', 'left')->where('u.id', $userId)->get()->getRowArray();

        return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) ($row['username'] ?? '');
    }
}

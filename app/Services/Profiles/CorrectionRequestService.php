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
use RuntimeException;

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

        if ($value === (string) $old) {
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
            $route === 'platform' ? 'admin/users' : 'institution/people',
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
     * The open requests this admin may decide, oldest first. Never their own.
     *
     * @return list<array{id: int, user_id: int, requester: string, field: string, old_value: ?string, new_value: string, reason: ?string, created_at: string, sis_linked: bool}>
     */
    public function pendingFor(User $admin): array
    {
        $query = db_connect()->table('profile_correction_requests r')
            ->select('r.id, r.user_id, r.field, r.old_value, r.new_value, r.reason, r.created_at, r.routed_to, r.institution_id')
            ->where('r.status', 'pending')
            ->where('r.user_id !=', (int) $admin->id)
            ->orderBy('r.created_at')->orderBy('r.id');

        if (! $this->scope($query, $admin)) {
            return [];
        }

        $sis = new SisOwnership();

        // shortcut: one SIS lookup per row; fine for a review queue, batch it if queues grow to hundreds.
        return array_map(fn (array $r): array => [
            'id'         => (int) $r['id'],
            'user_id'    => (int) $r['user_id'],
            'requester'  => $this->nameOf((int) $r['user_id']),
            'field'      => $r['field'],
            'old_value'  => $r['old_value'],
            'new_value'  => $r['new_value'],
            'reason'     => $r['reason'],
            'created_at' => $r['created_at'],
            'sis_linked' => $sis->managesPerson((int) $r['user_id']),
        ], $query->get()->getResultArray());
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
     * The open request, when this admin may decide it; otherwise not found, so ids cannot be probed.
     *
     * @return array<string, mixed>
     */
    private function decidable(int $id, User $admin): array
    {
        $query = db_connect()->table('profile_correction_requests r')
            ->where('r.id', $id)->where('r.status', 'pending')->where('r.user_id !=', (int) $admin->id);

        $row = $this->scope($query, $admin) ? $query->get()->getRowArray() : null;

        return $row ?? throw PageNotFoundException::forPageNotFound();
    }

    /** Limits a request query to what this admin decides; false when they decide nothing. */
    private function scope(\CodeIgniter\Database\BaseBuilder $query, User $admin): bool
    {
        $adminId = (int) $admin->id;
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
            throw new RuntimeException('The correction request was decided twice at once.');
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

        if (preg_match('/^[\p{L}][\p{L} .\'\-]*$/u', $value) !== 1) {
            throw new ProfileEditException("{$label} can use letters, spaces, periods, apostrophes and hyphens, and must start with a letter.", 'new_value');
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

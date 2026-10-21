<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionInvitationModel;
use App\Models\Academic\InstitutionModel;
use App\Models\Users\UserProfileModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Concerns\InTransaction;
use App\Services\Security\PasswordPolicy;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\UserFilters;
use App\Services\Users\UsernameGenerator;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Email as EmailConfig;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Invitations to administer an institution (SA4).
 *
 * The first institutional administrator does not get an account made for
 * them: they get an emailed link, valid for a limited time and usable once,
 * and set their own password on it. Only then does the account exist.
 *
 * Only one invitation per institution is open at a time. Sending a new one
 * (a re-send, or an invitation to a corrected address) revokes the open one,
 * so a link mailed to a mistyped address cannot be used later.
 *
 * Every refusal is an InvalidArgumentException whose message is fit to show.
 * A failed email is NOT an exception: the invitation exists, and the result
 * says the email did not go so it can be sent again.
 */
class InvitationService
{
    use InTransaction;

    /** The group a person invited here ends up in. */
    public const ROLE = 'institutional_admin';

    public const LINK_INVALID = 'This invitation link is not valid. Check that the whole link was copied, or ask for a new invitation.';

    public const LINK_USED = 'This invitation has already been used. If you set your password, sign in with it.';

    public const LINK_REPLACED = 'This invitation was cancelled, or replaced by a newer one. Use the link in the most recent email, or ask for a new one.';

    public const LINK_EXPIRED = 'This invitation has expired. Ask your Mentora contact to send a new one.';

    public const LINK_INSTITUTION_GONE = 'This institution is no longer available. Ask your Mentora contact for help.';

    /** What each other role is called in a sentence ("as a student"). */
    private const ROLE_NAMES = [
        'chairperson' => 'a chairperson',
        'instructor'  => 'an instructor',
        'student'     => 'a student',
        'parent'      => 'a parent',
    ];

    public const SEND_FAILED = 'The invitation was saved, but the email could not be sent. Check the mail settings, then use "Resend invitation".';

    /**
     * An institution's invitations, newest first, each with its `status`.
     * `$role` narrows them to one role (the administrator's, say).
     *
     * @return list<array<string, mixed>>
     */
    public function forInstitution(int $institutionId, ?DateTimeImmutable $now = null, int $limit = 20, ?string $role = null): array
    {
        $now ??= new DateTimeImmutable();

        $model = (new InstitutionInvitationModel())->where('institution_id', $institutionId);

        if ($role !== null) {
            $model->where('role', $role);
        }

        $rows = $model->orderBy('id', 'desc')->findAll($limit);

        foreach ($rows as &$row) {
            $row['status'] = InvitationStatus::of($row, $now);
        }

        return $rows;
    }

    /**
     * Invites someone to an institution that has finished onboarding, and
     * emails them the link. Replaces any open invitation.
     *
     * @param array<string, mixed> $input first_name, last_name, email
     *
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is saved then
     */
    public function invite(int $institutionId, array $input, ?User $actor = null, ?DateTimeImmutable $now = null, string $role = self::ROLE): array
    {
        $now ??= new DateTimeImmutable();

        if ($role === 'admin' || ! in_array($role, UserFilters::ROLES, true)) {
            throw new InvalidArgumentException('That role cannot be invited. System administrators are created with php spark admin:create.');
        }

        $institution = (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('That institution does not exist.');

        if ($institution['status'] === InstitutionModel::PENDING) {
            throw new InvalidArgumentException('Finish onboarding this institution first: its administrator is invited as the last step.');
        }

        $person = InvitationInput::person($input);

        if ($role === 'student' && ($blocked = (new PlanLimits())->seatBlock($institutionId)) !== null) {
            throw new InvalidArgumentException($blocked);
        }

        $studentIds = $this->studentsToLink($institutionId, $role, (string) ($input['students'] ?? ''));

        $issued = $this->inTransaction(fn (): array => $this->issue($institutionId, $person, $actor, $now, 'invitation.created', $role, $studentIds));

        return $this->delivered($issued, $person['email'], $now);
    }

    /**
     * Cancels an invitation that has not been used: its link stops working.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function revoke(int $institutionId, int $invitationId, ?User $actor = null, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        $invitation = (new InstitutionInvitationModel())->where('institution_id', $institutionId)->find($invitationId)
            ?? throw new InvalidArgumentException('That invitation does not exist.');

        if (InvitationStatus::of($invitation, $now) !== InvitationStatus::PENDING) {
            throw new InvalidArgumentException('That invitation is no longer open, so there is nothing to cancel.');
        }

        $this->inTransaction(function () use ($invitation, $actor, $now): void {
            $stamp = $now->format('Y-m-d H:i:s');

            db_connect()->table('institution_invitations')->where('id', $invitation['id'])->update(['revoked_at' => $stamp, 'updated_at' => $stamp]);

            service('auditLogger')->log('invitation.revoked', $actor ?? auth()->user(), [
                'target_type'    => 'invitation',
                'target_id'      => (int) $invitation['id'],
                'institution_id' => (int) $invitation['institution_id'],
                'old'            => ['email' => $invitation['email'], 'role' => $invitation['role']],
            ]);
        });
    }

    /**
     * Sends a person a new link, in place of the one they have (lost, expired
     * or never delivered). The old link stops working.
     *
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function resend(int $institutionId, int $invitationId, ?User $actor = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $old = (new InstitutionInvitationModel())->where('institution_id', $institutionId)->find($invitationId)
            ?? throw new InvalidArgumentException('That invitation does not exist.');

        if ($old['accepted_at'] !== null) {
            throw new InvalidArgumentException('That invitation has already been accepted: the account exists.');
        }

        $person = ['first_name' => $old['first_name'], 'last_name' => $old['last_name'], 'email' => $old['email']];

        // The students named when it was first sent come along with the new link.
        $studentIds = $old['student_ids'] === null ? null : json_decode((string) $old['student_ids'], true);

        $issued = $this->inTransaction(fn (): array => $this->issue($institutionId, $person, $actor, $now, 'invitation.resent', (string) $old['role'], is_array($studentIds) ? $studentIds : null));

        return $this->delivered($issued, $person['email'], $now);
    }

    /**
     * Writes a new invitation and revokes the open one, and records it in the
     * audit log. Sends nothing: call deliver() once the surrounding change is
     * saved. Meant to run inside a transaction (see invite() and onboarding),
     * so that a failure here leaves nothing behind.
     *
     * @param array{first_name: string, last_name: string, email: string} $person     already checked by InvitationInput
     * @param ?list<int>                                                  $studentIds the students a parent will be linked to on accepting (already checked), or null
     *
     * @return array{id: int, token: string, expires_at: string}
     *
     * @throws InvalidArgumentException
     */
    public function issue(int $institutionId, array $person, ?User $actor, DateTimeImmutable $now, string $auditAction = 'invitation.created', string $role = self::ROLE, ?array $studentIds = null): array
    {
        if (model(UserModel::class)->findByCredentials(['email' => $person['email']]) !== null) {
            throw new InvalidArgumentException("An account with {$person['email']} already exists. Invite a different address.");
        }

        $secret  = InvitationToken::generate();
        $stamp   = $now->format('Y-m-d H:i:s');
        $days    = (int) (new PlatformSettings())->get('invitationValidityDays');
        $expires = $now->modify("+{$days} days")->format('Y-m-d H:i:s');
        $by      = $actor ?? auth()->user();

        // Sending again to the same person replaces their open invitation. For the
        // administrator's role only one invitation is open per institution, so a link
        // mailed to a mistyped address cannot be used later.
        $replaced = db_connect()->table('institution_invitations')
            ->where('institution_id', $institutionId)
            ->where('accepted_at IS NULL', null, false)
            ->where('revoked_at IS NULL', null, false);

        if ($role === self::ROLE) {
            $replaced->where('role', self::ROLE);
        } else {
            $replaced->where('email', $person['email']);
        }

        $replaced->update(['revoked_at' => $stamp, 'updated_at' => $stamp]);

        $model = new InstitutionInvitationModel();
        $id    = $model->insert([
            'institution_id'     => $institutionId,
            'role'               => $role,
            'email'              => $person['email'],
            'first_name'         => $person['first_name'],
            'last_name'          => $person['last_name'],
            'student_ids'        => $studentIds === null || $studentIds === [] ? null : json_encode(array_values($studentIds)),
            'token_hash'         => $secret['hash'],
            'expires_at'         => $expires,
            'invited_by_user_id' => $by?->id,
            'invited_by_label'   => $by?->email ?: $by?->username,
        ]);

        if ($id === false) {
            throw new RuntimeException('The invitation could not be saved.');
        }

        service('auditLogger')->log($auditAction, $by, [
            'target_type'    => 'invitation',
            'target_id'      => (int) $id,
            'institution_id' => $institutionId,
            'new'            => ['email' => $person['email'], 'role' => $role, 'expires_at' => $expires] + ($studentIds ? ['students' => count($studentIds)] : []),
        ]);

        return ['id' => (int) $id, 'token' => $secret['token'], 'expires_at' => $expires];
    }

    /**
     * Emails the link and notes when that happened.
     *
     * @return string|null why it could not be sent, or null when it was
     */
    public function deliver(int $invitationId, string $token, ?DateTimeImmutable $now = null): ?string
    {
        $now ??= new DateTimeImmutable();

        $invitation = $this->withInstitution((new InstitutionInvitationModel())->find($invitationId));

        if ($invitation === null) {
            return self::SEND_FAILED;
        }

        $link = site_url('invitation/' . $token);

        if ($this->isDevelopment()) {
            log_message('warning', 'DEVELOPMENT ONLY - invitation link for {email}: {link}', ['email' => $invitation['email'], 'link' => $link]);
        }

        try {
            $config = config(EmailConfig::class);
            $email  = service('email');

            $email->clear();
            $email->setFrom($config->fromEmail, $config->fromName);
            $email->setTo($invitation['email']);
            $email->setSubject('Set up your Mentora account for ' . $invitation['institution_name']);
            $email->setMessage($this->message($invitation, $link));

            $sent = $email->send();
        } catch (Throwable $e) {
            log_message('error', 'Invitation email to {email} failed: {message}', ['email' => $invitation['email'], 'message' => $e->getMessage()]);

            return self::SEND_FAILED;
        }

        if ($sent !== true) {
            log_message('error', 'Invitation email to {email} was not accepted by the mail server.', ['email' => $invitation['email']]);

            return self::SEND_FAILED;
        }

        $stamp = $now->format('Y-m-d H:i:s');

        (new InstitutionInvitationModel())->update($invitationId, ['sent_at' => $stamp]);

        return null;
    }

    /**
     * The invitation behind a link that can be used now.
     *
     * @return array<string, mixed> the invitation with `institution_name`
     *
     * @throws InvalidArgumentException saying why the link cannot be used
     */
    public function open(string $token, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $invitation = InvitationToken::looksValid($token)
            ? $this->withInstitution((new InstitutionInvitationModel())->where('token_hash', InvitationToken::hash($token))->first())
            : null;

        if ($invitation === null) {
            throw new InvalidArgumentException(self::LINK_INVALID);
        }

        if ($invitation['institution_deleted_at'] !== null) {
            throw new InvalidArgumentException(self::LINK_INSTITUTION_GONE);
        }

        return match (InvitationStatus::of($invitation, $now)) {
            InvitationStatus::ACCEPTED => throw new InvalidArgumentException(self::LINK_USED),
            InvitationStatus::REVOKED  => throw new InvalidArgumentException(self::LINK_REPLACED),
            InvitationStatus::EXPIRED  => throw new InvalidArgumentException(self::LINK_EXPIRED),
            default                    => $invitation,
        };
    }

    /**
     * Uses the link: the person chooses their password and the account is
     * made. All or nothing, and a link can only ever do this once. They are
     * not signed in; they sign in with the new password.
     *
     * @throws InvalidArgumentException a bad link, or a password that is not accepted (the link stays usable then)
     * @throws Throwable                whatever else stopped it; nothing is saved then
     */
    public function accept(string $token, string $password, ?DateTimeImmutable $now = null): User
    {
        $now ??= new DateTimeImmutable();

        $invitation = $this->open($token, $now);

        $problem = (new PasswordPolicy())->problem($password, new User(['username' => $this->username($invitation['first_name'], $invitation['last_name']), 'email' => $invitation['email']]), (int) $invitation['institution_id']);

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        if (model(UserModel::class)->findByCredentials(['email' => $invitation['email']]) !== null) {
            throw new InvalidArgumentException("An account with {$invitation['email']} already exists. Ask your Mentora contact for help.");
        }

        // The seats may have filled up since the invitation was sent.
        if ($invitation['role'] === 'student' && ($blocked = (new PlanLimits())->seatBlock((int) $invitation['institution_id'])) !== null) {
            throw new InvalidArgumentException($blocked);
        }

        return $this->inTransaction(function () use ($invitation, $password, $now): User {
            $stamp = $now->format('Y-m-d H:i:s');
            $db    = db_connect();

            // Claim the invitation first, in one statement: of two people opening the
            // same link at once, exactly one gets here, and the other is told it was used.
            $db->table('institution_invitations')
                ->where('id', $invitation['id'])
                ->where('accepted_at IS NULL', null, false)
                ->where('revoked_at IS NULL', null, false)
                ->where('expires_at >', $stamp)
                ->update(['accepted_at' => $stamp, 'updated_at' => $stamp]);

            if ($db->affectedRows() !== 1) {
                throw new InvalidArgumentException(self::LINK_USED);
            }

            $users = model(UserModel::class);

            if (! $users->save(new User([
                'username' => $this->username($invitation['first_name'], $invitation['last_name']),
                'email'    => $invitation['email'],
                'password' => $password,
                'active'   => true,
            ]))) {
                throw new RuntimeException('The account could not be created: ' . implode(' ', $users->errors()));
            }

            /** @var User $user */
            $user = $users->findById($users->getInsertID());
            $user->addGroup($invitation['role']);

            $profile = new UserProfileModel();

            if ($profile->insert(['user_id' => $user->id, 'first_name' => $invitation['first_name'], 'last_name' => $invitation['last_name']]) === false) {
                throw new RuntimeException('The profile could not be saved: ' . implode(' ', $profile->errors()));
            }

            $linked = $db->table('institution_users')->insert([
                'institution_id' => $invitation['institution_id'],
                'user_id'        => $user->id,
                'created_at'     => $stamp,
                'updated_at'     => $stamp,
            ]);

            if (! $linked) {
                throw new RuntimeException('The account could not be linked to the institution.');
            }

            $db->table('institution_invitations')->where('id', $invitation['id'])->update(['accepted_user_id' => $user->id]);

            // A parent invited with students is linked to them now that there is an account. The
            // administrator who sent the invitation is the one who linked them; anyone who is no
            // longer a student here is skipped, not a reason to refuse the account.
            $linkedStudents = 0;
            $studentIds     = $invitation['role'] === 'parent' && $invitation['student_ids'] !== null ? json_decode((string) $invitation['student_ids'], true) : null;

            if (is_array($studentIds) && $studentIds !== []) {
                $inviter = $invitation['invited_by_user_id'] !== null ? model(UserModel::class)->findById((int) $invitation['invited_by_user_id']) : null;

                $linkedStudents = (new ParentLinkService())->createLinks((int) $invitation['institution_id'], (int) $user->id, array_map('intval', $studentIds), $inviter, ['via' => 'invitation', 'invitation_id' => (int) $invitation['id']])['linked'];
            }

            service('auditLogger')->log('invitation.accepted', $user, [
                'target_type'    => 'invitation',
                'target_id'      => (int) $invitation['id'],
                'institution_id' => (int) $invitation['institution_id'],
                'new'            => ['email' => $invitation['email'], 'role' => $invitation['role'], 'user_id' => (int) $user->id, 'linked_students' => $linkedStudents],
            ]);

            return $user;
        });
    }

    /**
     * The students a parent invitation names, as ids, checked now so a typo is
     * fixed before anything is sent. Every name must be a student of this
     * institution: one that is not stops the invitation and is listed with its
     * reason. Only a parent can be given students.
     *
     * @return ?list<int> null when none were named
     *
     * @throws StructureException on the `students` field
     */
    private function studentsToLink(int $institutionId, string $role, string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        if ($role !== 'parent') {
            throw StructureException::field('students', 'Students can only be named for a parent.');
        }

        $found = (new ParentLinkService())->resolve($institutionId, $text);

        if ($found['skipped'] !== []) {
            $parts = array_map(static fn (array $s): string => $s['identifier'] . ' ' . $s['reason'], array_slice($found['skipped'], 0, 5));
            $more  = count($found['skipped']) > 5 ? ' and ' . (count($found['skipped']) - 5) . ' more' : '';

            throw StructureException::field('students', implode('; ', $parts) . $more . '. Nothing was sent.');
        }

        return $found['students'];
    }

    /** Whether the development conveniences apply (the link is also logged). Never true in production or staging. */
    protected function isDevelopment(): bool
    {
        return ENVIRONMENT === 'development';
    }

    /**
     * Sends an invitation that was just issued and describes the outcome.
     *
     * @param array{id: int, token: string, expires_at: string} $issued
     *
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     */
    private function delivered(array $issued, string $email, DateTimeImmutable $now): array
    {
        return [
            'invitation_id' => $issued['id'],
            'email'         => $email,
            'expires_at'    => $issued['expires_at'],
            'email_error'   => $this->deliver($issued['id'], $issued['token'], $now),
        ];
    }

    /**
     * Adds the institution's name, and whether it has been deleted, to an invitation.
     *
     * @param array<string, mixed>|null $invitation
     *
     * @return array<string, mixed>|null
     */
    private function withInstitution(?array $invitation): ?array
    {
        if ($invitation === null) {
            return null;
        }

        $institution = (new InstitutionModel())->withDeleted()->find((int) $invitation['institution_id']);

        $invitation['institution_name']       = $institution['name'] ?? 'your institution';
        $invitation['institution_deleted_at'] = $institution === null ? 'unknown' : $institution['deleted_at'];

        return $invitation;
    }

    /** @param array<string, mixed> $invitation */
    private function message(array $invitation, string $link): string
    {
        return "Hello {$invitation['first_name']},\n\n"
            . "You have been invited to {$this->purpose($invitation)} on Mentora.\n\n"
            . "Set your password and finish setting up your account here:\n{$link}\n\n"
            . 'The link works once and expires on ' . substr((string) $invitation['expires_at'], 0, 16) . ".\n\n"
            . "If you were not expecting this, you can ignore this email: nothing happens unless the link is used.\n";
    }

    /**
     * "administer Acme University" or "join Acme University as a student".
     *
     * @param array<string, mixed> $invitation
     */
    private function purpose(array $invitation): string
    {
        $role = self::ROLE_NAMES[$invitation['role']] ?? null;

        return $invitation['role'] === self::ROLE || $role === null
            ? "administer {$invitation['institution_name']}"
            : "join {$invitation['institution_name']} as {$role}";
    }

    /**
     * A username for the new account: first.last without accents or symbols,
     * at most 30 characters, made unique with a number if it is taken. The
     * person signs in with their email; the username is only an identifier.
     */
    private function username(string $first, string $last): string
    {
        return (new UsernameGenerator())->from($first, $last);
    }
}

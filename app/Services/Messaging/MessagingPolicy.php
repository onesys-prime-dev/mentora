<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Every Messages permission decision lives here (spec section 3). Pure logic:
 * the caller passes in the facts, this class never reads the database.
 */
final class MessagingPolicy
{
    /**
     * Requirements FR1, in order; the first refusal wins. The resolver gives no context for a bare system `admin`
     * or someone with no institution; the system admin is refused here too.
     */
    public function canStart(Party $sender, Party $recipient, ?int $subjectStudentId, StartContext $c): bool
    {
        // 1. Hard refusals.
        if ($c->isSelf || ! $c->sameInstitution || ! $c->senderActive || ! $c->recipientActive
            || $sender->isSystemAdmin() || $recipient->isSystemAdmin() || $c->impersonating
            || ! $c->senderInstitutionWritable || $c->senderRestriction !== 'none') {
            return false;
        }

        // 2. Capacity (D4). A thread naming a child is a parent thread: one side a parent linked to that child, the
        // other staff. Otherwise both act as staff or student; a parent-only person has no such capacity.
        if ($subjectStudentId !== null) {
            $capacityOk = ($sender->isParent() && $c->linkedChildAsSenderParent && $recipient->isStaff())
                || ($recipient->isParent() && $c->linkedChildAsRecipientParent && $sender->isStaff());
        } else {
            $capacityOk = $sender->capacity(false) !== null && $recipient->capacity(false) !== null;
        }

        if (! $capacityOk) {
            return false;
        }

        // 3. Pair rules. Parent <-> student and parent <-> parent cannot pass step 2 (D2).
        if ($subjectStudentId === null && $sender->capacity(false) === 'student' && $recipient->capacity(false) === 'student') {
            $inScope = match ($c->studentScope) {
                'institution' => true,
                'course'      => $c->sharedCourse,
                default       => false,
            };

            if (! $inScope) {
                return false;
            }
        }

        // A minor recipient, even one holding a staff role (S2-f): own instructor, chair or dean, classmate, or the institution's admin (D9).
        if ($c->recipientMinor && ! $this->reachesMinor($sender, $c)) {
            return false;
        }

        // 4. Blocks (D5): only a binding one; the resolver already leaves out paused blocks and institutional admins' blocks.
        return ! $c->blockedByRecipient;
    }

    /** D9: the sender teaches the minor, chairs or deans them, is a classmate, or is the institution's admin. */
    public function reachesMinor(Party $sender, StartContext $c): bool
    {
        return $c->senderTeachesRecipient || $c->senderLeadsRecipient
            || (in_array('student', $sender->roles, true) && $c->sharedCourse)
            || in_array('institutional_admin', $sender->roles, true);
    }

    /**
     * Takes no role: the resolver must refuse the system `admin` and
     * impersonation sessions before calling this.
     */
    public function canPost(PostContext $c): bool
    {
        if (! $c->activeParticipant || ! $c->accountActive || $c->institutionReadOnly || $c->offeringClosed || $c->subjectLinkLost || $c->closed) {
            return false;
        }

        if ($c->type === 'course' && $c->locked && ! $c->isCourseInstructor) {
            return false;
        }

        if ($c->restriction === 'no_send' && ! ($c->type === 'course' && $c->isCourseInstructor)) {
            return false;
        }

        if ($c->type === 'direct' && ($c->blocked || $c->scopeLost || $c->minorLost || $c->deactivatedOther)) {
            return false;
        }

        return true;
    }

    /**
     * Takes no role: the resolver must refuse the system `admin` and
     * impersonation sessions before calling this.
     */
    public function canView(bool $activeParticipant, bool $sameInstitution): bool
    {
        return $activeParticipant && $sameInstitution;
    }

    /**
     * $inScope is "instructor of that course" for a course chat, "owns that group"
     * for a group (an institutional admin moderates every group and course chat). Direct threads are never moderated.
     * Any held role counts (the caller adds `instructor` for someone who teaches the course); the
     * system admin never moderates.
     */
    public function canModerate(Party $actor, string $conversationType, bool $inScope, bool $sameInstitution = true): bool
    {
        if (! $sameInstitution || $actor->isSystemAdmin()) {
            return false;
        }

        if (! in_array($conversationType, ['course', 'group'], true)) {
            return false;
        }

        if (in_array('institutional_admin', $actor->roles, true)) {
            return true;
        }

        return match ($conversationType) {
            'course' => in_array('instructor', $actor->roles, true) && $inScope,
            'group'  => $inScope,
        };
    }

    /**
     * Who may change a chat's controls (reactions, replies, forwarding, bump, delete for everyone, the lock).
     * Only the institution's administrator, for a course chat or staff group of their own institution; direct
     * threads have no controls. The other parameters stay so a caller states every fact; none of them matters.
     */
    public function canManageControls(
        string $role,
        string $type,
        bool $teachesCourse,
        bool $inScope,
        bool $createdGroup,
        bool $sameInstitution,
    ): bool {
        if (! $sameInstitution || ! in_array($type, ['course', 'group'], true)) {
            return false;
        }

        return $role === 'institutional_admin';
    }

    /** A course chat pins by moderators only; direct and group chats by anyone in them; a closed group by nobody. */
    public function canPinMessage(string $type, bool $isModerator, bool $closed = false): bool
    {
        return ! $closed && ($type === 'course' ? $isModerator : true);
    }

    /** Like canPost, but a lock does not stop a reaction (it is not a post). */
    public function canReact(PostContext $c): bool
    {
        if (! $c->activeParticipant || ! $c->accountActive || $c->institutionReadOnly || $c->offeringClosed || $c->subjectLinkLost || $c->closed) {
            return false;
        }

        if ($c->type === 'direct' && ($c->blocked || $c->scopeLost || $c->minorLost || $c->deactivatedOther)) {
            return false;
        }

        return $c->restriction !== 'no_send' || ($c->type === 'course' && $c->isCourseInstructor);
    }

    public function canSeeParentFeed(bool $linked, bool $childEnrolled, string $senderRole): bool
    {
        return $linked && $childEnrolled && $senderRole === 'instructor';
    }
}

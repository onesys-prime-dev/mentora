<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The facts MessagingPolicy needs to decide whether one person may start a
 * thread with another. Filled in by the resolver from the real access
 * services; the policy itself never touches the database.
 *
 * sharedCourse: both are active in one non-closed offering.
 * inScope: the sender's department (chairperson) or college (dean)
 *   covers the recipient.
 * linkedChildAsSenderParent / linkedChildAsRecipientParent: that side, as a
 *   parent, has a linked child taught by the other side's instructor role or
 *   chaired by its chairperson role. One fact per direction, so a person never
 *   speaks as the parent of a child only the other side is linked to.
 * blockedByRecipient: the recipient has blocked the sender and the block binds.
 * isSelf: sender and recipient are the same person; always refused.
 * senderRestriction: 'none', 'no_new' or 'no_send' (an administrator's restriction or the pause); anything but 'none' refuses.
 *
 * The resolver builds it from every role each account holds, and must never build a
 * context for an `admin` sender or recipient except to have it refused.
 */
final class StartContext
{
    public function __construct(
        public readonly bool $sameInstitution = true,
        public readonly bool $senderActive = true,
        public readonly bool $recipientActive = true,
        public readonly bool $sharedCourse = false,
        public readonly bool $sameCollege = false,
        public readonly bool $inScope = false,
        public readonly bool $linkedChildAsSenderParent = false,
        public readonly bool $linkedChildAsRecipientParent = false,
        public readonly bool $studentDmEnabled = true,
        public readonly bool $blockedByRecipient = false,
        public readonly bool $isSelf = false,
        public readonly string $senderRestriction = 'none',
    ) {
    }

    /** The same facts without the sender's restriction: for opening a thread that already exists, which a restriction does not stop. */
    public function withoutRestriction(): self
    {
        return new self(
            $this->sameInstitution, $this->senderActive, $this->recipientActive, $this->sharedCourse, $this->sameCollege, $this->inScope,
            $this->linkedChildAsSenderParent, $this->linkedChildAsRecipientParent, $this->studentDmEnabled, $this->blockedByRecipient, $this->isSelf,
        );
    }

    /** A child links the pair in either direction. */
    public function linkedChild(): bool
    {
        return $this->linkedChildAsSenderParent || $this->linkedChildAsRecipientParent;
    }
}

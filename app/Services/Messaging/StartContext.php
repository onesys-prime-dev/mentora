<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The facts MessagingPolicy needs to decide whether one person may start a
 * thread with another (requirements FR1). Filled in by the resolver from the
 * real access services; the policy itself never touches the database.
 *
 * senderInstitutionWritable: the sender's institution is not read-only or past (D11).
 * sharedCourse: both are enrolled as students in one active offering (classmates).
 * senderTeachesRecipient: the sender is assigned to an active offering the recipient is enrolled in.
 * senderLeadsRecipient: the sender chairs the recipient's department or is dean of their college.
 *   The resolver fills the two only for a minor recipient, the one rule that reads them (false otherwise).
 * linkedChildAsSenderParent / linkedChildAsRecipientParent: that side is a parent linked to the
 *   child the thread names. One fact per direction, so a person never speaks as the parent of a
 *   child only the other side is linked to.
 * studentScope: the institution's D3 setting, 'off', 'course' or 'institution'.
 * blockedByRecipient: the recipient has blocked the sender and the block binds.
 * senderRestriction: 'none', 'no_new' or 'no_send' (an administrator's restriction or the pause); anything but 'none' refuses.
 * recipientMinor: the recipient carries the institution's minor flag (D9).
 *
 * The resolver builds it from every role each account holds, and never builds a
 * context for a bare system `admin` or someone with no institution.
 */
final class StartContext
{
    public function __construct(
        public readonly bool $sameInstitution = true,
        public readonly bool $senderActive = true,
        public readonly bool $recipientActive = true,
        public readonly bool $senderInstitutionWritable = true,
        public readonly bool $impersonating = false,
        public readonly bool $isSelf = false,
        public readonly bool $sharedCourse = false,
        public readonly bool $senderTeachesRecipient = false,
        public readonly bool $senderLeadsRecipient = false,
        public readonly bool $linkedChildAsSenderParent = false,
        public readonly bool $linkedChildAsRecipientParent = false,
        public readonly string $studentScope = 'institution',
        public readonly bool $blockedByRecipient = false,
        public readonly string $senderRestriction = 'none',
        public readonly bool $recipientMinor = false,
    ) {
    }

    /**
     * The same facts for opening a thread that already exists: a restriction (FR6) and a read-only institution
     * (FR11) stop creating threads, not reading one. The caller must check that the thread exists.
     */
    public function forOpening(): self
    {
        return $this->with(['senderRestriction' => 'none', 'senderInstitutionWritable' => true]);
    }

    /** Only the relationship between the pair (D26): both accounts count as active and writable, no impersonation, restriction or block. */
    public function relationshipOnly(): self
    {
        return $this->with(['senderActive' => true, 'recipientActive' => true, 'senderInstitutionWritable' => true, 'impersonating' => false, 'blockedByRecipient' => false, 'senderRestriction' => 'none']);
    }

    /** A child links the pair in either direction. */
    public function linkedChild(): bool
    {
        return $this->linkedChildAsSenderParent || $this->linkedChildAsRecipientParent;
    }

    /** @param array<string, bool|string> $changes */
    private function with(array $changes): self
    {
        return new self(...$changes + get_object_vars($this));
    }
}

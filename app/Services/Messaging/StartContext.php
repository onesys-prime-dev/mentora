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
 * linkedChild: the parent's linked child is taught by that instructor or
 *   chaired by that chairperson.
 * isSelf: sender and recipient are the same person; always refused.
 *
 * The resolver must pass the account's active role, and must never build a
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
        public readonly bool $linkedChild = false,
        public readonly bool $studentDmEnabled = true,
        public readonly bool $blockedByRecipient = false,
        public readonly bool $isSelf = false,
    ) {
    }
}

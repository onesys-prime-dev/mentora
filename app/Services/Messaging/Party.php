<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * One person as MessagingPolicy sees them: every role they hold, not one
 * active role. capacity() picks the hat for a thread: a child-labelled
 * thread uses the parent capacity; otherwise staff if any staff role, else
 * student. Null means the person cannot act on that thread (e.g. a
 * parent-only person outside a child thread).
 */
final class Party
{
    public const STAFF_ROLES = ['instructor', 'chairperson', 'dean', 'institutional_admin'];

    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly int $id,
        public readonly array $roles,
        public readonly bool $active,
        public readonly ?int $institutionId,
        public readonly bool $minor = false,
    ) {
    }

    public function withRole(string $role): self
    {
        return new self($this->id, array_values(array_unique([...$this->roles, $role])), $this->active, $this->institutionId, $this->minor);
    }

    public function isStaff(): bool
    {
        return array_intersect(self::STAFF_ROLES, $this->roles) !== [];
    }

    public function isParent(): bool
    {
        return in_array('parent', $this->roles, true);
    }

    public function isStudent(): bool
    {
        return in_array('student', $this->roles, true) && ! $this->isStaff();
    }

    /** The highest role by the profile rank order (RoleRank); empty when none. */
    public function topRole(): string
    {
        return \App\Services\Profiles\RoleRank::highest($this->roles) ?? '';
    }

    /** Only the bare system admin: beside institution roles `admin` is ignored inside Messages (ruling R10). */
    public function isSystemAdmin(): bool
    {
        return $this->roles === ['admin'];
    }

    /**
     * @return 'parent'|'staff'|'student'|null
     */
    public function capacity(bool $aboutChild): ?string
    {
        if ($aboutChild && $this->isParent()) {
            return 'parent';
        }
        if ($this->isStaff()) {
            return 'staff';
        }

        return $this->isStudent() ? 'student' : null;
    }
}

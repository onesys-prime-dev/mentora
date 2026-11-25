<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/** Everything ProfilePolicy needs to know about one (viewer, subject) pair. */
final readonly class ViewContext
{
    /**
     * @param list<string> $subjectRoles
     */
    public function __construct(
        public bool $isSelf,
        public bool $sameInstitution,
        public ?string $viewerRank,
        public array $subjectRoles,
        public bool $subjectActive,
        public bool $viewerIsParentOfSubject,
        public bool $viewerIsChildOfSubject,
        public bool $viewerIsInstitutionAdmin,
        public bool $subjectIsSystemAdmin,
    ) {
    }
}

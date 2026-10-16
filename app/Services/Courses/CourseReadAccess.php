<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Courses\OfferingDetail;
use App\Services\Institutions\InstitutionCourseAccess;
use CodeIgniter\Shield\Entities\User;

/**
 * Who may look at a course without being its instructor, and which courses.
 *
 * Two kinds of person have a read-only view of a course (CourseViewer):
 * the dean, of every course in their department, while acting as dean; and
 * the institution's administrator, of every course in their institution.
 * Every course service that lets them in asks here, so a course outside their
 * reach answers the same way in all of them and the rule is written once.
 *
 * Reading only: nothing that changes a course asks this.
 */
class CourseReadAccess
{
    public function __construct(
        private readonly InstitutionCourseAccess $institution = new InstitutionCourseAccess(),
        private readonly DeanAccessService $dean = new DeanAccessService(),
    ) {
    }

    /** May this person look at the offering? */
    public function canAccess(User $user, int $offeringId): bool
    {
        if (CourseViewer::isDeanViewer($user)) {
            return $this->dean->canAccessOffering($user, $offeringId);
        }

        return $this->institution->canAccess($user, $offeringId);
    }

    /**
     * The offering in the row shape the course pages read, or null when it is
     * not this person's to look at.
     *
     * @return array<string, mixed>|null
     */
    public function offering(User $user, int $offeringId): ?array
    {
        return $this->canAccess($user, $offeringId) ? OfferingDetail::find($offeringId) : null;
    }

    /**
     * May this person open a file the course shares (a resource or an
     * announcement attachment)? A student's work is never among them.
     */
    public function canReadFile(User $user, int $fileId): bool
    {
        foreach ($this->institution->offeringsSharing($fileId) as $offeringId) {
            if ($this->canAccess($user, $offeringId)) {
                return true;
            }
        }

        return false;
    }
}

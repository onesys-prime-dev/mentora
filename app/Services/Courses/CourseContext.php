<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * The course the current request is about, and the person's role in it (9.1).
 *
 * CourseMembershipFilter sets it once it has checked the person belongs to the
 * offering in the address in that area's role (a student in the student area,
 * an instructor in the instructor area, a chairperson in the chairperson area),
 * so a check deep in a page (a module list, a view partial) answers for this
 * course without being handed the offering. Shared per request (service
 * `courseContext`); empty outside a course.
 */
class CourseContext
{
    private ?int $offeringId = null;

    private ?string $role = null;

    public function set(int $offeringId, string $role): void
    {
        $this->offeringId = $offeringId;
        $this->role       = $role;
    }

    public function clear(): void
    {
        $this->offeringId = null;
        $this->role       = null;
    }

    public function offeringId(): ?int
    {
        return $this->offeringId;
    }

    /** 'student', 'instructor' or 'chairperson'; null outside a course. */
    public function role(): ?string
    {
        return $this->role;
    }
}

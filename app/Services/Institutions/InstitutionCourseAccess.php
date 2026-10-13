<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Courses\OfferingDetail;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;

/**
 * The institutional administrator's read-only reach into courses: every
 * offering of their own institution (any status), and nothing outside it.
 * The institution is the person's, worked out on the server; an offering of
 * another institution is simply not reachable.
 */
class InstitutionCourseAccess
{
    /** May this person look at the offering? */
    public function canAccess(User $user, int $offeringId): bool
    {
        if (! $user->inGroup('institutional_admin')) {
            return false;
        }

        $institutionId = (new PlanLimits())->institutionOf((int) $user->id);

        return $institutionId !== null && InstitutionScope::forInstitution($institutionId)->ownsOffering($offeringId);
    }

    /**
     * May this person open a file the course shares: a resource of one of
     * its learning items, or an attachment of one of its announcements? A
     * student's work is never among them.
     */
    public function canReadFile(User $user, int $fileId): bool
    {
        $db = db_connect();

        $offerings = array_merge(
            array_column($db->table('learning_resources lr')
                ->select('cm.offering_id')
                ->join('learning_items li', 'li.id = lr.learning_item_id')
                ->join('learning_units lu', 'lu.id = li.learning_unit_id')
                ->join('course_modules cm', 'cm.id = lu.module_id')
                ->where('lr.file_id', $fileId)->get()->getResultArray(), 'offering_id'),
            array_column($db->table('announcement_attachments aa')
                ->select('a.offering_id')
                ->join('announcements a', 'a.id = aa.announcement_id')
                ->where('aa.file_id', $fileId)->get()->getResultArray(), 'offering_id'),
        );

        foreach ($offerings as $offeringId) {
            if ($this->canAccess($user, (int) $offeringId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The offering in the row shape the course pages read, or null when it is
     * not this person's to look at.
     *
     * @return array<string, mixed>|null
     */
    public function offering(User $user, int $offeringId): ?array
    {
        if (! $this->canAccess($user, $offeringId)) {
            return null;
        }

        $programId = (int) db_connect()->table('course_offerings o')->select('c.program_id')->join('courses c', 'c.id = o.course_id')->where('o.id', $offeringId)->get()->getRow('program_id');

        return OfferingDetail::inPrograms([$programId])->where('course_offerings.id', $offeringId)->get()->getRowArray();
    }
}

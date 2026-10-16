<?php

namespace App\Services\Storage;

use App\Models\Files\FileModel;
use App\Services\Courses\ChairpersonAccessService;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\LearningProgressService;
use CodeIgniter\Shield\Entities\User;

/**
 * Decides whether a user may fetch an uploaded file. Access comes from
 * whatever references the file, so there's one rule per kind of owner:
 *
 *  - learning resource (video/PDF on a learning item): the offering's
 *    instructors; enrolled students once the item is published, visible
 *    and not locked behind an incomplete prerequisite
 *  - assignment submission / quiz response upload: the student who
 *    submitted it, and instructors teaching that section
 *  - AI generation source: instructors teaching the course
 *  - announcement / discussion attachment: the course section's instructors
 *    and enrolled students
 *  - anything: the user who uploaded it
 */
class FileAccessService
{
    protected FileModel $fileModel;

    public function __construct()
    {
        $this->fileModel = new FileModel();
    }

    /**
     * @return array|null the file row when allowed, otherwise null
     */
    public function authorize(User $user, int $fileId): ?array
    {
        $file = $this->fileModel->retrieveFile($fileId);

        if (! $file || ($file['status'] ?? 'active') !== 'active') {
            return null;
        }

        if (! is_file((new StorageService())->path($file['storage_key']))) {
            return null;
        }

        return $this->isAllowed($user, $fileId, (int) $file['owner_user_id'])
            ? $file
            : null;
    }

    private function isAllowed(User $user, int $fileId, int $ownerId): bool
    {
        $userId = (int) $user->id;

        if ($ownerId === $userId) {
            return true;
        }

        $db = db_connect();

        // An institution's logo is shown to everyone who belongs to it.
        $logo = $db->table('institutions')
            ->join('institution_users', 'institution_users.institution_id = institutions.id')
            ->where('institutions.logo_file_id', $fileId)
            ->where('institution_users.user_id', $userId)
            ->countAllResults();

        if ($logo > 0) {
            return true;
        }

        // The institutional administrator's read-only view: what the course shares (its
        // resources and announcement attachments), never a student's work.
        if (\App\Services\Courses\CourseViewer::isReadOnlyViewer($user) && (new \App\Services\Courses\CourseReadAccess())->canReadFile($user, $fileId)) {
            return true;
        }

        // Staff: the people who teach the section, the chairperson of its
        // sub-department (full instructor rights on it) and the dean of its
        // department (looking, never changing). Everyone else is treated as
        // a student of the section.
        $isInstructor = $user->inGroup('instructor')
            || $user->inGroup('chairperson')
            || $user->inGroup('dean');

        /*
        |--------------------------------------------------------------------------
        | Learning resources
        |--------------------------------------------------------------------------
        */

        $resources = $db->table('learning_resources')
            ->select([
                'learning_items.id AS item_id',
                'learning_items.status AS item_status',
                'learning_units.status AS unit_status',
                'course_modules.status AS module_status',
                'course_modules.offering_id',
            ])
            ->join('learning_items', 'learning_items.id = learning_resources.learning_item_id')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_resources.file_id', $fileId)
            ->get()
            ->getResultArray();

        foreach ($resources as $resource) {

            $offeringId = (int) $resource['offering_id'];

            if ($isInstructor) {

                if ($this->teachesOffering($user, $offeringId)) {
                    return true;
                }

                continue;

            }

            $visible = $resource['item_status'] === 'published'
                && $resource['unit_status'] === 'published'
                && $resource['module_status'] === 'published';

            if (
                $visible
                && $this->enrolledInOffering($userId, $offeringId)
                && (new LearningProgressService())->lockFor($user, (int) $resource['item_id']) === null
            ) {
                return true;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Student work (assignment submissions, quiz file answers)
        |--------------------------------------------------------------------------
        */

        $work = array_merge(

            $db->table('assessment_submissions')
                ->select(['assessment_submissions.student_id', 'assessments.course_id', 'course_modules.offering_id'])
                ->join('assessments', 'assessments.id = assessment_submissions.assessment_id')
                ->join('course_modules', 'course_modules.id = assessments.module_id', 'left')
                ->where('assessment_submissions.submission_file_id', $fileId)
                ->get()
                ->getResultArray(),

            $db->table('assessment_question_responses')
                ->select(['assessment_submissions.student_id', 'assessments.course_id', 'course_modules.offering_id'])
                ->join('assessment_submissions', 'assessment_submissions.id = assessment_question_responses.submission_id')
                ->join('assessments', 'assessments.id = assessment_submissions.assessment_id')
                ->join('course_modules', 'course_modules.id = assessments.module_id', 'left')
                ->where('assessment_question_responses.uploaded_file_id', $fileId)
                ->get()
                ->getResultArray()

        );

        foreach ($work as $row) {

            if (! $isInstructor && (int) $row['student_id'] === $userId) {
                return true;
            }

            // The section the work belongs to; only an assessment that sits
            // in no module falls back to any section of the course.
            if ($isInstructor && (
                $row['offering_id'] !== null
                    ? $this->teachesOffering($user, (int) $row['offering_id'])
                    : $this->teachesCourse($user, (int) $row['course_id'])
            )) {
                return true;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | AI generation sources
        |--------------------------------------------------------------------------
        */

        if ($isInstructor) {

            $batches = $db->table('ai_generation_batches')
                ->select('course_id')
                ->where('source_file_id', $fileId)
                ->get()
                ->getResultArray();

            foreach ($batches as $batch) {
                if ($this->teachesCourse($user, (int) $batch['course_id'])) {
                    return true;
                }
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Announcement and discussion attachments
        |--------------------------------------------------------------------------
        */

        $offerings = array_merge(

            $db->table('announcement_attachments')
                ->select('announcements.offering_id')
                ->join('announcements', 'announcements.id = announcement_attachments.announcement_id')
                ->where('announcement_attachments.file_id', $fileId)
                ->get()
                ->getResultArray(),

            $db->table('discussion_attachments')
                ->select('discussion_threads.offering_id')
                ->join('discussion_threads', 'discussion_threads.id = discussion_attachments.thread_id')
                ->where('discussion_attachments.file_id', $fileId)
                ->get()
                ->getResultArray()

        );

        foreach ($offerings as $row) {

            $offeringId = (int) $row['offering_id'];

            if ($isInstructor ? $this->teachesOffering($user, $offeringId) : $this->enrolledInOffering($userId, $offeringId)) {
                return true;
            }

        }

        return false;
    }

    /** Does this staff member reach the section: teaches it, chairs its sub-department, or is its department's dean? */
    private function teachesOffering(User $staff, int $offeringId): bool
    {
        if (db_connect()->table('instructor_assignments')
            ->where('instructor_user_id', (int) $staff->id)
            ->where('offering_id', $offeringId)
            ->countAllResults() > 0) {
            return true;
        }

        return ($staff->inGroup('chairperson') && (new ChairpersonAccessService())->canAccess($staff, $offeringId))
            || ($staff->inGroup('dean') && (new DeanAccessService())->canAccessOffering($staff, $offeringId));
    }

    /** The same for a course: any section of it. */
    private function teachesCourse(User $staff, int $courseId): bool
    {
        $sections = db_connect()->table('course_offerings')->select('id')->where('course_id', $courseId)->get()->getResultArray();

        foreach ($sections as $section) {
            if ($this->teachesOffering($staff, (int) $section['id'])) {
                return true;
            }
        }

        return false;
    }

    private function enrolledInOffering(int $studentId, int $offeringId): bool
    {
        return db_connect()->table('enrollments')
            ->where('student_user_id', $studentId)
            ->where('offering_id', $offeringId)
            ->whereIn('status', ['enrolled', 'completed'])
            ->countAllResults() > 0;
    }
}

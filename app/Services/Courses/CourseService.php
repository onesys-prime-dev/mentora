<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Courses\EnrollmentModel;
use App\Models\Courses\CourseScheduleModel;
use App\Models\Learning\CourseModuleModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Assignments\ChairpersonAssignmentModel;

use CodeIgniter\Exceptions\PageNotFoundException;

class CourseService
{
    protected EnrollmentModel $enrollmentModel;

    protected InstructorAssignmentModel $instructorAssignmentModel;

    protected ChairpersonAssignmentModel $chairpersonAssignmentModel;

    protected CourseScheduleModel $courseScheduleModel;

    protected CourseModuleModel $courseModuleModel;

    public function __construct()
    {
        $this->enrollmentModel = new EnrollmentModel();

        $this->instructorAssignmentModel = new InstructorAssignmentModel();

        $this->chairpersonAssignmentModel = new ChairpersonAssignmentModel();

        $this->courseScheduleModel = new CourseScheduleModel();

        $this->courseModuleModel = new CourseModuleModel();
    }

    /*
    |--------------------------------------------------------------------------
    | Course List
    |--------------------------------------------------------------------------
    */

    /**
     * The cards for a person's courses: the current ones, or with $past the ones
     * whose term has been closed (they can be looked at, not changed).
     */
    public function getCourseOfferings(User $user, bool $past = false): array
    {
        if ($user->inGroup('student')) {

            return $this->withCardDetails(
                $this->enrollmentModel->retrieveStudentCourseOfferings($user->id, $past),
                fn (array $courses) => $this->studentProgress($user, array_column($courses, 'offering_id'))
            );

        }

        if ($user->inGroup('instructor')) {

            return $this->withCardDetails(
                $this->instructorAssignmentModel->retrieveInstructorCourseOfferings($user->id, $past),
                fn (array $courses) => $this->classProgress($courses)
            );
        }

        if ($user->inGroup('chairperson')) {

            return $this->withCardDetails(
                $this->chairpersonAssignmentModel->retrieveChairedCourseOfferings($user->id, $past),
                fn (array $courses) => $this->classProgress($courses)
            );
        }

        return [];
    }

    /**
     * Folds in the schedule/room every card shows, and the progress bar —
     * whose meaning depends on role, so the caller supplies how to compute
     * it — without each branch re-querying schedules on its own.
     */
    protected function withCardDetails(array $courses, callable $progressFor): array
    {
        if ($courses === []) {
            return [];
        }

        $offeringIds = array_column($courses, 'offering_id');

        $blocks = $this->courseScheduleModel->groupByOffering(
            $this->courseScheduleModel->retrieveForOfferings($offeringIds)
        );

        $progress = $progressFor($courses);

        foreach ($courses as &$course) {

            $offeringId = (int) $course['offering_id'];
            $block = $blocks[$offeringId] ?? null;

            $course['schedule'] = $block['schedule'] ?? 'No schedule set';
            $course['room'] = $block['room'] ?? '--';
            $course['progress'] = $progress[$offeringId] ?? 0;

        }

        unset($course);

        return $courses;
    }

    /** A student's own completion: same math as the dashboard's My Courses widget. */
    protected function studentProgress(User $user, array $offeringIds): array
    {
        $completed = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($offeringIds) as $row) {
            if ((int) $row['student_user_id'] === (int) $user->id) {
                $completed[(int) $row['offering_id']] = (int) $row['completed_count'];
            }
        }

        $itemTotals = $this->itemTotalsByOffering($offeringIds);

        $progress = [];

        foreach ($itemTotals as $offeringId => $total) {
            $progress[$offeringId] = $total > 0
                ? (int) round(($completed[$offeringId] ?? 0) / $total * 100)
                : 0;
        }

        return $progress;
    }

    /**
     * An instructor has no personal completion, so the card shows the whole
     * class's average instead — same aggregate the dashboard's Class
     * Progress widget uses, collapsed to one number per offering. Reuses
     * the 'enrolled' count already fetched by retrieveInstructorCourseOfferings()
     * rather than re-querying it.
     */
    protected function classProgress(array $courses): array
    {
        $offeringIds = array_column($courses, 'offering_id');

        $rosters = [];

        foreach ($courses as $section) {
            $rosters[(int) $section['offering_id']] = (int) ($section['enrolled'] ?? 0);
        }

        $completed = [];
        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];
            $completed[$offeringId] = ($completed[$offeringId] ?? 0) + (int) $module['completed_count'];
            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0) + (int) $module['item_count'];
        }

        $progress = [];

        foreach ($offeringIds as $offeringId) {
            $offeringId = (int) $offeringId;
            $denominator = ($rosters[$offeringId] ?? 0) * ($itemTotals[$offeringId] ?? 0);
            $progress[$offeringId] = $denominator > 0
                ? (int) round(($completed[$offeringId] ?? 0) / $denominator * 100)
                : 0;
        }

        return $progress;
    }

    protected function itemTotalsByOffering(array $offeringIds): array
    {
        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];
            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0) + (int) $module['item_count'];
        }

        return $itemTotals;
    }

    /*
    |--------------------------------------------------------------------------
    | Single Course
    |--------------------------------------------------------------------------
    */

    public function getCourseOffering(
        User $user,
        int $offeringId
    ): ?array
    {
        if ($user->inGroup('student')) {

            return $this->enrollmentModel
                ->retrieveStudentCourseOffering(
                    $user->id,
                    $offeringId
                );

        }

        if ($user->inGroup('instructor')) {
            return $this->instructorAssignmentModel
                ->retrieveInstructorCourseOffering(
                    $user->id,
                    $offeringId
                );
        }

        if ($user->inGroup('chairperson')) {
            return $this->chairpersonAssignmentModel
                ->retrieveChairedCourseOffering(
                    $user->id,
                    $offeringId
                );
        }

        return null;
    }
}
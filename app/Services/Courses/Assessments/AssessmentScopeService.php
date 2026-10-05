<?php

namespace App\Services\Courses\Assessments;

use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Courses\EnrollmentModel;
use CodeIgniter\Shield\Entities\User;

/**
 * Which course offering (section) an id taken from an assessment URL belongs
 * to, and whether a user may work inside an offering at all.
 *
 * The routes carry the offering as one URL segment and an assessment,
 * submission, question, rubric, criterion or question-generation id as
 * another; nothing ties the two together unless it is checked here. The
 * AssessmentScope filter asks this class before any controller runs.
 */
class AssessmentScopeService
{
    /**
     * URL segment that introduces an id → how to find that thing's offering.
     * The queries return the offering id (null when it has none) and the
     * course id (for things that can belong to a whole course).
     */
    private const OWNERS = [
        'assessments' => 'assessment',
        'submissions' => 'submission',
        'questions' => 'question',
        'rubrics' => 'rubric',
        'rubric-criteria' => 'criterion',
        'generation-batches' => 'batch',
        'generated-questions' => 'generated',
        'modules' => 'module',
        'learning-units' => 'unit',
    ];

    /** Segments that only matter in a path that also names an assessment (the create route). */
    private const ONLY_WITH_ASSESSMENTS = ['modules', 'learning-units'];

    /** Does this path touch assessment work at all? */
    public function appliesTo(array $segments): bool
    {
        return array_intersect(['announcements', 'assessments', 'submissions', 'questions', 'rubrics', 'rubric-criteria', 'generation-batches', 'generated-questions'], $segments) !== [];
    }

    /**
     * @param list<string> $rest URL segments after /courses/{offering}
     *
     * @return bool false when something in the path is not this offering's
     */
    public function pathBelongsTo(array $rest, int $offeringId): bool
    {
        $hasAssessments = in_array('assessments', $rest, true);

        foreach ($rest as $index => $segment) {

            $owner = self::OWNERS[$segment] ?? null;

            if ($owner === null || ! isset($rest[$index + 1]) || ! ctype_digit($rest[$index + 1])) {
                continue;
            }

            if (in_array($segment, self::ONLY_WITH_ASSESSMENTS, true) && ! $hasAssessments) {
                continue;
            }

            $id = (int) $rest[$index + 1];

            $found = $this->locate($owner, $id);

            if ($found === null) {
                return false;
            }

            // Picking an existing rubric to copy is a course-wide library:
            // any section of the same course will do.
            $copying = $segment === 'rubrics' && ($rest[$index + 2] ?? null) === 'select';

            if ($copying) {

                if ($found['course_id'] !== $this->courseOf($offeringId)) {
                    return false;
                }

                continue;
            }

            // A generation batch started without an assessment belongs to the course.
            if ($found['offering_id'] === null) {

                if ($found['course_id'] !== $this->courseOf($offeringId)) {
                    return false;
                }

                continue;
            }

            if ($found['offering_id'] !== $offeringId) {
                return false;
            }
        }

        return true;
    }

    /** May this user work inside the offering: its assigned instructor, or an enrolled student? */
    public function canAccess(User $user, int $offeringId): bool
    {
        if ($user->inGroup('instructor')) {
            return (bool) (new InstructorAssignmentModel())->retrieveInstructorCourseOffering((int) $user->id, $offeringId);
        }

        return (bool) (new EnrollmentModel())->retrieveStudentCourseOffering((int) $user->id, $offeringId);
    }

    /** True when an assessment id belongs to the offering. */
    public function assessmentBelongsTo(int $assessmentId, int $offeringId): bool
    {
        $found = $this->locate('assessment', $assessmentId);

        return $found !== null && $found['offering_id'] === $offeringId;
    }

    /** @return array{offering_id: ?int, course_id: int}|null */
    private function locate(string $owner, int $id): ?array
    {
        $db = db_connect();

        $row = match ($owner) {
            'assessment' => $db->query(
                'SELECT cm.offering_id AS offering_id, a.course_id AS course_id
                   FROM assessments a JOIN course_modules cm ON cm.id = a.module_id
                  WHERE a.id = ?',
                [$id]
            )->getRowArray(),

            'submission' => $db->query(
                'SELECT cm.offering_id AS offering_id, a.course_id AS course_id
                   FROM assessment_submissions s
                   JOIN assessments a ON a.id = s.assessment_id
                   JOIN course_modules cm ON cm.id = a.module_id
                  WHERE s.id = ?',
                [$id]
            )->getRowArray(),

            'question' => $db->query(
                'SELECT cm.offering_id AS offering_id, a.course_id AS course_id
                   FROM assessment_questions q
                   JOIN assessments a ON a.id = q.assessment_id
                   JOIN course_modules cm ON cm.id = a.module_id
                  WHERE q.id = ?',
                [$id]
            )->getRowArray(),

            'rubric' => $db->query(
                'SELECT cm.offering_id AS offering_id, a.course_id AS course_id
                   FROM assessment_rubrics r
                   LEFT JOIN assessment_questions q ON q.id = r.question_id
                   JOIN assessments a ON a.id = COALESCE(r.assessment_id, q.assessment_id)
                   JOIN course_modules cm ON cm.id = a.module_id
                  WHERE r.id = ?',
                [$id]
            )->getRowArray(),

            'criterion' => $db->query(
                'SELECT cm.offering_id AS offering_id, a.course_id AS course_id
                   FROM assessment_rubric_criteria c
                   JOIN assessment_rubrics r ON r.id = c.rubric_id
                   LEFT JOIN assessment_questions q ON q.id = r.question_id
                   JOIN assessments a ON a.id = COALESCE(r.assessment_id, q.assessment_id)
                   JOIN course_modules cm ON cm.id = a.module_id
                  WHERE c.id = ?',
                [$id]
            )->getRowArray(),

            'batch' => $db->query(
                'SELECT cm.offering_id AS offering_id, b.course_id AS course_id
                   FROM ai_generation_batches b
                   LEFT JOIN assessments a ON a.id = b.assessment_id
                   LEFT JOIN course_modules cm ON cm.id = a.module_id
                  WHERE b.id = ?',
                [$id]
            )->getRowArray(),

            'generated' => $db->query(
                'SELECT cm.offering_id AS offering_id, b.course_id AS course_id
                   FROM ai_generated_questions g
                   JOIN ai_generation_batches b ON b.id = g.batch_id
                   LEFT JOIN assessments a ON a.id = b.assessment_id
                   LEFT JOIN course_modules cm ON cm.id = a.module_id
                  WHERE g.id = ?',
                [$id]
            )->getRowArray(),

            'module' => $db->query(
                'SELECT cm.offering_id AS offering_id, co.course_id AS course_id
                   FROM course_modules cm JOIN course_offerings co ON co.id = cm.offering_id
                  WHERE cm.id = ?',
                [$id]
            )->getRowArray(),

            'unit' => $db->query(
                'SELECT cm.offering_id AS offering_id, co.course_id AS course_id
                   FROM learning_units lu
                   JOIN course_modules cm ON cm.id = lu.module_id
                   JOIN course_offerings co ON co.id = cm.offering_id
                  WHERE lu.id = ?',
                [$id]
            )->getRowArray(),

            default => null,
        };

        if ($row === null || $row === []) {
            return null;
        }

        return [
            'offering_id' => $row['offering_id'] === null ? null : (int) $row['offering_id'],
            'course_id' => (int) $row['course_id'],
        ];
    }

    private function courseOf(int $offeringId): ?int
    {
        $row = db_connect()->table('course_offerings')->select('course_id')->where('id', $offeringId)->get()->getRowArray();

        return $row ? (int) $row['course_id'] : null;
    }
}

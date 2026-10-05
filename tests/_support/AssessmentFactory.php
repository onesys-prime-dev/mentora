<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * Questions, answers and attempts for assessment tests. Pairs with
 * CourseFactory::assessment() (which provides the assessment itself).
 */
final class AssessmentFactory
{
    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Opens an assessment for students: published, available now, due in a week. */
    public static function open(int $assessmentId, array $extra = []): void
    {
        db_connect()->table('assessments')->where('id', $assessmentId)->update($extra + [
            'status' => 'Published',
            'available_from' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'due_date' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
    }

    /** @return int question id */
    public static function question(int $assessmentId, User $creator, string $type, float $points = 1.0, array $extra = []): int
    {
        $db = db_connect();

        $db->table('assessment_questions')->insert($extra + [
            'assessment_id' => $assessmentId,
            'question_text' => $type . ' question',
            'question_type' => $type,
            'points' => $points,
            'order_index' => (int) $db->table('assessment_questions')->where('assessment_id', $assessmentId)->countAllResults() + 1,
            'created_by' => $creator->id,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    /**
     * A multiple choice question with the given choices.
     *
     * @param list<bool> $correct which choices are right, in order
     *
     * @return array{question: int, choices: list<int>}
     */
    public static function multipleChoice(int $assessmentId, User $creator, array $correct, float $points = 1.0): array
    {
        $db = db_connect();

        $question = self::question($assessmentId, $creator, 'Multiple Choice', $points, [
            'allow_multiple_answers' => count(array_filter($correct)) > 1 ? 1 : 0,
        ]);

        $choices = [];

        foreach ($correct as $index => $isCorrect) {
            $db->table('assessment_question_choices')->insert([
                'question_id' => $question,
                'choice_text' => 'Choice ' . ($index + 1),
                'is_correct' => $isCorrect ? 1 : 0,
                'order_index' => $index + 1,
                'created_at' => self::now(),
            ]);
            $choices[] = (int) $db->insertID();
        }

        return ['question' => $question, 'choices' => $choices];
    }

    /** @param list<string>|null $accepted accepted answers (null = no answer key) */
    public static function shortAnswer(int $assessmentId, User $creator, ?array $accepted, float $points = 1.0, bool $caseSensitive = false): int
    {
        return self::question($assessmentId, $creator, 'Short Answer', $points, [
            'accepted_answers' => $accepted === null ? null : json_encode($accepted),
            'case_sensitive' => $caseSensitive ? 1 : 0,
        ]);
    }

    /** An attempt. @return int submission id */
    public static function attempt(int $assessmentId, User $student, string $status = 'Graded', float $score = 0.0, array $extra = []): int
    {
        $db = db_connect();
        $graded = in_array($status, ['Graded', 'Returned'], true);
        $number = (int) $db->table('assessment_submissions')
            ->where('assessment_id', $assessmentId)->where('student_id', $student->id)->countAllResults() + 1;

        $db->table('assessment_submissions')->insert($extra + [
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_number' => $number,
            'started_at' => self::now(),
            'submitted_at' => $status === 'In Progress' ? null : self::now(),
            'score' => $score,
            'is_graded' => $graded ? 1 : 0,
            'graded_at' => $graded ? self::now() : null,
            'status' => $status,
        ]);

        return (int) $db->insertID();
    }

    /** A saved answer. */
    public static function response(int $submissionId, int $questionId, array $values): int
    {
        $db = db_connect();

        $db->table('assessment_question_responses')->insert($values + [
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }
}

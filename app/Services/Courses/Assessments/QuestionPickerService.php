<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assignments\ChairpersonAssignmentModel;
use App\Services\Audit\AuditLogger;
use App\Services\Concerns\InTransaction;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;
use Throwable;

/**
 * Reusing questions between quizzes: which quizzes a person may take
 * questions from, what is in them, and copying chosen questions into another
 * quiz.
 *
 * A person may take from the quizzes of the sections they teach and, for a
 * chairperson, the sections of the sub-departments they chair, in any term.
 * Everything is checked here against that list, so an id in a request that is
 * not one of theirs is simply not found.
 */
class QuestionPickerService
{
    use InTransaction;

    /** Most quizzes offered in the picker. */
    private const MAX_SOURCES = 300;

    protected AssessmentModel $assessmentModel;
    protected AssessmentQuestionModel $questionModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->questionModel = new AssessmentQuestionModel();
    }

    /**
     * The quizzes with questions that this person may take from, newest
     * first, grouped by what the teacher recognises: course, section, term.
     *
     * @return list<array{id: int, title: string, course: string, section: string, term: string, questions: int}>
     */
    public function sources(User $user, int $exceptAssessmentId): array
    {
        $offerings = $this->offeringIdsFor($user);

        if ($offerings === []) {
            return [];
        }

        $rows = db_connect()->table('assessments a')
            ->select('a.id, a.title, c.course_code, c.course_title, co.section_code, t.name AS term_name')
            ->select('(SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.id AND q.status = \'Active\') AS question_count', false)
            ->join('assessment_types aty', 'aty.id = a.assessment_type_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->join('course_offerings co', 'co.id = cm.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('academic_terms t', 't.id = co.term_id')
            ->whereIn('cm.offering_id', $offerings)
            ->where('LOWER(aty.name)', 'quiz')
            ->where('a.status !=', 'Archived')
            ->where('a.id !=', $exceptAssessmentId)
            ->having('question_count >', 0)
            ->orderBy('t.start_date', 'DESC')
            ->orderBy('c.course_code', 'ASC')
            ->orderBy('a.id', 'DESC')
            ->limit(self::MAX_SOURCES)
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'course'    => $row['course_code'] . ' ' . $row['course_title'],
            'section'   => (string) $row['section_code'],
            'term'      => (string) $row['term_name'],
            'questions' => (int) $row['question_count'],
        ], $rows);
    }

    /**
     * The questions of one source quiz, or null when it is not one this
     * person may take from. Each carries whether the target quiz already
     * has a question that looks the same.
     *
     * @return list<array<string, mixed>>|null
     */
    public function questionsOf(User $user, int $sourceAssessmentId, int $targetAssessmentId): ?array
    {
        if (! $this->mayTakeFrom($user, $sourceAssessmentId)) {
            return null;
        }

        $existing = array_map(
            static fn (array $q): string => (string) $q['question_text'],
            $this->questionModel->listForAssessment($targetAssessmentId)
        );

        $out = [];

        foreach ($this->questionModel->listForAssessment($sourceAssessmentId) as $question) {
            $out[] = [
                'id'                     => (int) $question['id'],
                'question_text'          => (string) $question['question_text'],
                'question_type'          => (string) $question['question_type'],
                'points'                 => (float) $question['points'],
                'allow_multiple_answers' => (bool) $question['allow_multiple_answers'],
                'choices'                => array_map(
                    static fn (array $c): array => ['text' => (string) $c['choice_text'], 'is_correct' => (bool) $c['is_correct']],
                    $question['choices'] ?? []
                ),
                'accepted_answers'       => $question['accepted_answers'] ?? [],
                'already_in_quiz'        => in_array($this->normalise((string) $question['question_text']), array_map([$this, 'normalise'], $existing), true),
            ];
        }

        return $out;
    }

    /**
     * Copies the chosen questions of a source quiz onto the end of the target
     * quiz, with their choices, keys and settings. All or nothing.
     *
     * @param list<int> $questionIds
     *
     * @return array<string, mixed>
     */
    public function copy(User $user, int $targetAssessmentId, int $sourceAssessmentId, array $questionIds): array
    {
        if ($targetAssessmentId === $sourceAssessmentId) {
            return ['success' => false, 'message' => 'Choose a different quiz. To repeat a question in this quiz, use its duplicate button.'];
        }

        if (! $this->assessmentModel->find($targetAssessmentId)) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        if (! $this->mayTakeFrom($user, $sourceAssessmentId)) {
            return ['success' => false, 'message' => 'That quiz was not found.'];
        }

        $wanted = array_flip(array_map('intval', $questionIds));

        if ($wanted === []) {
            return ['success' => false, 'message' => 'Choose at least one question to add.'];
        }

        // In the source's own order, and only questions that really belong to it.
        $chosen = array_values(array_filter(
            $this->questionModel->listForAssessment($sourceAssessmentId),
            static fn (array $q): bool => isset($wanted[(int) $q['id']])
        ));

        if ($chosen === []) {
            return ['success' => false, 'message' => 'Those questions were not found.'];
        }

        $service = new QuestionService();
        $created = [];

        try {
            $this->inTransaction(function () use ($user, $targetAssessmentId, $chosen, $service, &$created): void {
                foreach ($chosen as $question) {
                    $result = $service->createQuestion($user, $targetAssessmentId, [
                        'question_text'          => $question['question_text'],
                        'question_type'          => $question['question_type'],
                        'points'                 => $question['points'],
                        'is_required'            => (bool) $question['is_required'],
                        'allow_multiple_answers' => (bool) $question['allow_multiple_answers'],
                        'answers_count'          => $question['answers_count'],
                        'accepted_answers'       => $question['accepted_answers'] ?? [],
                        'case_sensitive'         => (bool) ($question['case_sensitive'] ?? false),
                        'choices'                => array_map(
                            static fn (array $c): array => ['text' => $c['choice_text'], 'is_correct' => (bool) $c['is_correct']],
                            $question['choices'] ?? []
                        ),
                    ]);

                    if (empty($result['success'])) {
                        throw new RuntimeException((string) ($result['message'] ?? 'A question could not be copied.'));
                    }

                    $created[] = (int) $result['question']['id'];
                }
            });
        } catch (Throwable $e) {
            log_message('error', 'Copying questions failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Nothing was added: the questions could not be copied. Please try again.'];
        }

        try {
            (new AuditLogger())->log('quiz.questions.copied', $user, [
                'target_type' => 'assessment',
                'target_id'   => $targetAssessmentId,
                'new'         => ['from_assessment_id' => $sourceAssessmentId, 'count' => count($created)],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Copy audit entry failed: ' . $e->getMessage());
        }

        $count = count($created);

        return [
            'success'      => true,
            'added'        => $count,
            'question_ids' => $created,
            'message'      => $count === 1 ? '1 question was added.' : "{$count} questions were added.",
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Who may take from what
    |--------------------------------------------------------------------------
    */

    private function mayTakeFrom(User $user, int $assessmentId): bool
    {
        $offerings = $this->offeringIdsFor($user);

        if ($offerings === []) {
            return false;
        }

        $row = db_connect()->table('assessments a')
            ->select('cm.offering_id')
            ->join('assessment_types aty', 'aty.id = a.assessment_type_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->where('a.id', $assessmentId)
            ->where('LOWER(aty.name)', 'quiz')
            ->get()->getRowArray();

        return $row !== null && in_array((int) $row['offering_id'], $offerings, true);
    }

    /**
     * Every section this person teaches or chairs, in any term.
     *
     * @return list<int>
     */
    private function offeringIdsFor(User $user): array
    {
        $db = db_connect();
        $userId = (int) $user->id;

        $taught = $db->table('instructor_assignments')->select('offering_id')->where('instructor_user_id', $userId)->get()->getResultArray();

        $ids = array_map(static fn (array $r): int => (int) $r['offering_id'], $taught);

        if ($user->inGroup('chairperson')) {
            $chaired = (new ChairpersonAssignmentModel())
                ->chairedOfferingsBuilder($userId, null)
                ->select('course_offerings.id')
                ->get()->getResultArray();

            foreach ($chaired as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}

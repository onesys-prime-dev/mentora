<?php

namespace App\Database\Seeds;

/**
 * Demo student activity: quiz attempts (with per-question responses whose
 * scores add up to the attempt's score), assignment submissions, learning
 * progress and gradebook rows — spread so every state shows somewhere:
 *
 *   IT101-A  student1  ahead of the class, best-of-two pre-test, all graded
 *            student2  quiz Partially Graded, assignment resubmitted after a grade
 *            student3  quiz being graded, assignment turned in late
 *            student4  quiz In Progress, assignment missing
 *            student5  enrolled, nothing started (overdue pre-test)
 */
class DemoActivitySeeder extends DemoSeeder
{
    private const WRONG_TEXT = "I'm not sure";

    public function run()
    {
        $this->seedEnrollments();
        $this->seedIntroToComputingA();
        $this->seedProgramming1();
        $this->seedDatabaseSystems();
        $this->seedGradebook();

        echo "Demo activity seeded.\n";
    }

    /*
    |--------------------------------------------------------------------------
    | Enrollments
    |--------------------------------------------------------------------------
    */

    private function seedEnrollments(): void
    {
        $rosters = [
            ['IT101', 'A', ['student1', 'student2', 'student3', 'student4', 'student5']],
            ['IT102', 'A', ['student1', 'student2', 'student3']],
            ['IT201', 'A', ['student2', 'student4', 'student5']],
            // IT101-B has no students yet — an instructor's empty section.
        ];

        foreach ($rosters as [$code, $section, $students]) {
            foreach ($students as $student) {
                $this->db->table('enrollments')->insert([
                    'offering_id' => $this->offeringId($code, $section),
                    'student_user_id' => $this->student($student),
                    'status' => 'enrolled',
                    'enrolled_at' => $this->at('-40 days'),
                    'updated_at' => $this->at('-40 days'),
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | IT101-A — the showcase
    |--------------------------------------------------------------------------
    */

    private function seedIntroToComputingA(): void
    {
        $offering = ['IT101', 'A'];
        $grader = 'instructor1@mentora.test';

        $moduleOne = [
            'Welcome to Introduction to Computing', 'Course Orientation Video', 'Course Syllabus and Class Policies',
            'Course Pre-Test', 'Hardware and Software', 'Parts of a Computer', 'How Computers Represent Data',
        ];

        $variables = ['What Is a Variable?', 'Data Types Cheat Sheet'];
        $controlFlow = ['Making Decisions with If Statements', 'Repeating with Loops', 'Assignment 1: Grade Calculator'];

        // Course Pre-Test: MC, MC, SA, SA, multi-MC (2 pts each), 2 attempts, best counts.
        $preTest = [...$offering, 'Course Pre-Test'];
        $this->quiz($preTest, 'student1', 1, [true, false, true, false, true], 'Graded', '-19 days');
        $this->quiz($preTest, 'student1', 2, [true, true, true, true, false], 'Graded', '-18 days');
        $this->quiz($preTest, 'student2', 1, [true, true, true, true, true], 'Graded', '-18 days');
        $this->quiz($preTest, 'student3', 1, [true, true, false, true, true], 'Graded', '-16 days');
        $this->quiz($preTest, 'student4', 1, [false, true, false, true, false], 'Graded', '-15 days');

        // Quiz 1: MC 3, multi-MC 4, SA 3, SA 3, Essay 7.
        $quizOne = [...$offering, 'Quiz 1: Variables and Data Types'];
        $this->quiz($quizOne, 'student1', 1, [true, true, true, true, ['text' => 'The type decides what you can do with a value. If an age is stored as the text "18", adding 1 joins the text into "181" instead of giving 19, so a number type avoids that bug.', 'score' => 6]], 'Graded', '-4 days', [
            'feedback' => 'Clear explanation and a great example. Mention memory use next time for full marks.',
            'grader' => $grader,
        ]);
        $this->quiz($quizOne, 'student2', 1, [true, false, true, true, ['text' => 'Data types matter because a program can crash or give wrong answers if you add text to numbers.']], 'Partially Graded', '-2 days');
        $this->quiz($quizOne, 'student3', 1, [true, true, false, true, ['text' => 'If you pick the wrong type the computer does not know how to handle the value.']], 'Grading', '-1 day');
        $this->quiz($quizOne, 'student4', 1, [true, null, null, null, null], 'In Progress', '-30 minutes');

        // Assignment 1 (rubric: Correctness 12, Readability 10, Testing 8), due 2 days ago.
        $assignmentOne = [...$offering, 'Assignment 1: Grade Calculator'];
        $this->assignment($assignmentOne, 'student1', 1, 'Graded', '-4 days', [
            'rubric' => [11, 9, 7],
            'feedback' => 'Correct results and tidy code. Add a check for scores above 100.',
            'grader' => $grader,
        ]);
        $this->assignment($assignmentOne, 'student2', 1, 'Graded', '-6 days', [
            'rubric' => [7, 6, 5],
            'feedback' => 'The average divides by 4 instead of 5 — fix that and resubmit.',
            'grader' => $grader,
        ]);
        $this->assignment($assignmentOne, 'student2', 2, 'Submitted', '-1 day', ['late' => true, 'text' => 'Fixed the average to divide by 5 and added input validation.']);
        $this->assignment($assignmentOne, 'student3', 1, 'Submitted', '-1 day', ['late' => true]);
        // student4 and student5: missing (overdue).

        // Lab 1, due tomorrow.
        $this->assignment([...$offering, 'Lab 1: Designing a Table'], 'student1', 1, 'Submitted', '-5 hours', [
            'text' => "Students(student_number VARCHAR(20) PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100), email VARCHAR(255), year_level INT).\nstudent_number is the key because the school guarantees it is unique and it never changes.",
        ]);

        // Learning progress, in unlock order (after the work it depends on).
        $this->complete($offering, 'student1', [...$moduleOne, ...$variables, 'Quiz 1: Variables and Data Types', ...$controlFlow, 'What Is a Database?', 'Lab 1: Designing a Table'], '-25 days');
        $this->complete($offering, 'student2', [...$moduleOne, ...$variables, 'Quiz 1: Variables and Data Types', ...$controlFlow], '-24 days');
        $this->complete($offering, 'student3', [...$moduleOne, ...$variables, ...$controlFlow], '-22 days');
        $this->complete($offering, 'student4', [...$moduleOne, ...$variables], '-20 days');
    }

    private function seedProgramming1(): void
    {
        $offering = ['IT102', 'A'];

        $quiz = [...$offering, 'Quiz: PHP Setup'];
        $this->quiz($quiz, 'student1', 1, [true, true], 'Graded', '-3 days');
        $this->quiz($quiz, 'student2', 1, [true, false], 'Graded', '-4 days');

        $this->assignment([...$offering, 'Exercise: Hello, PHP'], 'student2', 1, 'Submitted', '-1 day', [
            'text' => "<?php\n\$name = 'Pedro';\n\$course = 'Computer Programming 1';\necho \"Hello, {\$name}! Welcome to {\$course}.\";",
        ]);

        // Learning progress, in unlock order (after the work it depends on).
        $this->complete($offering, 'student1', ['XAMPP Installation Guide', 'Your First PHP Script', 'Quiz: PHP Setup'], '-9 days');
        $this->complete($offering, 'student2', ['XAMPP Installation Guide', 'Your First PHP Script', 'Quiz: PHP Setup', 'PHP Variables Reference', 'PHP Variables Explained (video)', 'Exercise: Hello, PHP'], '-9 days');
        $this->complete($offering, 'student3', ['XAMPP Installation Guide'], '-7 days');
    }

    private function seedDatabaseSystems(): void
    {
        $offering = ['IT201', 'A'];
        $grader = 'instructor2@mentora.test';


        $quiz = [...$offering, 'Quiz: Keys and Relationships'];
        $this->quiz($quiz, 'student2', 1, [true, false], 'Graded', '-4 days');
        $this->quiz($quiz, 'student4', 1, [true, true], 'Graded', '-4 days');
        $this->quiz($quiz, 'student5', 1, [true, true], 'Graded', '-3 days');

        $diagram = [...$offering, 'ER Diagram Draft'];
        $this->assignment($diagram, 'student5', 1, 'Graded', '-2 days', [
            'score' => 22,
            'feedback' => 'Good entities and keys. The Loan–Book relationship should be many-to-one.',
            'grader' => $grader,
            'link' => 'https://example.com/er-diagram-library.png',
        ]);
        $this->assignment($diagram, 'student2', 1, 'Submitted', '-12 hours', ['late' => true]);

        // Learning progress, in unlock order (after the work it depends on).
        $this->complete($offering, 'student2', ['Primary and Foreign Keys', 'Quiz: Keys and Relationships', 'ER Diagram Draft'], '-5 days');
        $this->complete($offering, 'student4', ['Primary and Foreign Keys', 'Quiz: Keys and Relationships'], '-5 days');
        $this->complete($offering, 'student5', ['Primary and Foreign Keys', 'Quiz: Keys and Relationships', 'ER Diagram Draft'], '-5 days');
    }

    /*
    |--------------------------------------------------------------------------
    | Writers
    |--------------------------------------------------------------------------
    */

    private function student(string $name): int
    {
        return $this->userId("{$name}@mentora.test");
    }

    /**
     * Marks items completed, half a day apart from $from onward. A quiz or
     * assignment item is completed when the student turned it in.
     */
    private function complete(array $offering, string $student, array $titles, string $from): void
    {
        $start = strtotime($from);

        foreach ($titles as $index => $title) {

            $itemId = $this->learningItemId($offering[0], $offering[1], $title);

            $turnedIn = $this->db->table('assessment_submissions s')
                ->selectMin('s.submitted_at', 'at')
                ->join('assessments a', 'a.id = s.assessment_id')
                ->where(['a.learning_item_id' => $itemId, 's.student_id' => $this->student($student)])
                ->where('s.submitted_at IS NOT NULL')
                ->get()->getRowArray()['at'] ?? null;

            $when = $turnedIn ?? date('Y-m-d H:i:s', $start + $index * 86400 / 2);

            $this->db->table('learning_completions')->insert([
                'learning_item_id' => $itemId,
                'student_user_id' => $this->student($student),
                'status' => 'completed',
                'progress_percent' => 100,
                'completed_at' => $when,
                'created_at' => $when,
                'updated_at' => $when,
            ]);

        }
    }

    /**
     * One quiz attempt. $answers has one entry per question, in order:
     * true = right, false = wrong, null = left blank, and for an essay
     * ['text' => ..., 'score' => points once graded].
     *
     * Auto-graded questions are scored now; essays only count once the
     * attempt is Graded — matching QuizAttemptService::submitAttempt().
     */
    private function quiz(array $target, string $student, int $attempt, array $answers, string $status, string $submittedAgo, array $options = []): void
    {
        [$code, $section, $title] = $target;

        $assessmentId = $this->assessmentId($code, $section, $title);
        $assessment = $this->db->table('assessments')->where('id', $assessmentId)->get()->getRowArray();
        $questions = $this->questions($assessmentId);

        $inProgress = $status === 'In Progress';
        $isGraded = $status === 'Graded';

        $submittedAt = $this->at($submittedAgo);
        $startedAt = date('Y-m-d H:i:s', strtotime($submittedAt) - 15 * 60);

        $this->db->table('assessment_submissions')->insert([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student($student),
            'attempt_number' => $attempt,
            'started_at' => $startedAt,
            'submitted_at' => $inProgress ? null : $submittedAt,
            'time_spent' => $inProgress ? null : 15 * 60,
            'status' => $status,
            'grading_mode' => $isGraded ? 'Default' : null,
        ]);

        $submissionId = (int) $this->db->insertID();

        $score = 0.0;

        foreach ($questions as $index => $question) {

            $answer = $answers[$index] ?? null;

            if ($answer === null) {
                continue;
            }

            $isEssay = is_array($answer);

            $points = match (true) {
                $inProgress => 0.0,
                $isEssay => $isGraded ? (float) ($answer['score'] ?? 0) : 0.0,
                default => $answer ? (float) $question['points'] : 0.0,
            };

            $score += $points;

            $this->db->table('assessment_question_responses')->insert(array_merge(
                $this->responseFor($question, $answer),
                [
                    'submission_id' => $submissionId,
                    'question_id' => $question['id'],
                    'score' => $points,
                    'is_auto_graded' => !$inProgress && !$isEssay ? 1 : 0,
                    'graded_at' => $inProgress || ($isEssay && !$isGraded) ? null : $submittedAt,
                    'created_at' => $startedAt,
                    'updated_at' => $submittedAt,
                ]
            ));

        }

        $total = (float) $assessment['total_points'];

        $this->db->table('assessment_submissions')->where('id', $submissionId)->update([
            'score' => $score,
            'percentage' => $total > 0 ? round($score / $total * 100, 2) : 0,
            'is_late_submission' => !empty($options['late']) ? 1 : 0,
            'is_graded' => $isGraded ? 1 : 0,
            'graded_by' => $isGraded && isset($options['grader']) ? $this->userId($options['grader']) : null,
            'graded_at' => $isGraded ? $submittedAt : null,
            'feedback' => $options['feedback'] ?? null,
        ]);
    }

    private function responseFor(array $question, bool|array $answer): array
    {
        $response = ['selected_choice_id' => null, 'selected_choice_ids' => null, 'response_text' => null];

        if (is_array($answer)) {
            return ['response_text' => $answer['text']] + $response;
        }

        if ($question['question_type'] === 'Short Answer') {

            $accepted = json_decode((string) $question['accepted_answers'], true) ?: [''];

            // A right answer typed the way a student would (lower case,
            // unless the question is case sensitive).
            $text = $answer
                ? (!empty($question['case_sensitive']) ? $accepted[0] : mb_strtolower($accepted[0]))
                : self::WRONG_TEXT;

            return ['response_text' => $text] + $response;
        }

        $correct = array_values(array_filter($question['choices'], static fn ($c) => (int) $c['is_correct'] === 1));
        $wrong = array_values(array_filter($question['choices'], static fn ($c) => (int) $c['is_correct'] === 0));

        if (!empty($question['allow_multiple_answers'])) {

            $ids = $answer
                ? array_column($correct, 'id')
                : [$correct[0]['id'], $wrong[0]['id']];

            return ['selected_choice_ids' => json_encode(array_map('intval', $ids))] + $response;
        }

        return ['selected_choice_id' => (int) ($answer ? $correct[0]['id'] : $wrong[0]['id'])] + $response;
    }

    private function questions(int $assessmentId): array
    {
        $questions = $this->db->table('assessment_questions')
            ->where('assessment_id', $assessmentId)
            ->orderBy('order_index')
            ->get()->getResultArray();

        foreach ($questions as &$question) {
            $question['choices'] = $this->db->table('assessment_question_choices')
                ->where('question_id', $question['id'])
                ->orderBy('order_index')
                ->get()->getResultArray();
        }

        return $questions;
    }

    /**
     * One assignment submission. Graded work takes either `rubric`
     * (points per criterion, in order) or a plain `score`.
     */
    private function assignment(array $target, string $student, int $attempt, string $status, string $submittedAgo, array $options = []): void
    {
        [$code, $section, $title] = $target;

        $assessmentId = $this->assessmentId($code, $section, $title);
        $total = (float) $this->db->table('assessments')->select('total_points')->where('id', $assessmentId)->get()->getRowArray()['total_points'];

        $isGraded = $status === 'Graded';

        $rubricScores = null;
        $score = (float) ($options['score'] ?? 0);

        if (isset($options['rubric'])) {

            $criteria = $this->db->table('assessment_rubric_criteria')
                ->select('assessment_rubric_criteria.id')
                ->join('assessment_rubrics', 'assessment_rubrics.id = assessment_rubric_criteria.rubric_id')
                ->where('assessment_rubrics.assessment_id', $assessmentId)
                ->orderBy('assessment_rubric_criteria.order_index')
                ->get()->getResultArray();

            $rubricScores = array_combine(array_map('intval', array_column($criteria, 'id')), $options['rubric']);
            $score = (float) array_sum($options['rubric']);
        }

        $submittedAt = $this->at($submittedAgo);

        $this->db->table('assessment_submissions')->insert([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student($student),
            'attempt_number' => $attempt,
            'started_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'submission_text' => $options['text'] ?? 'Submitted my work — see the attached description of my solution.',
            'submission_link' => $options['link'] ?? null,
            'score' => $isGraded ? $score : 0,
            'percentage' => $isGraded && $total > 0 ? round($score / $total * 100, 2) : 0,
            'is_late_submission' => !empty($options['late']) ? 1 : 0,
            'is_graded' => $isGraded ? 1 : 0,
            'graded_by' => $isGraded ? $this->userId($options['grader']) : null,
            'graded_at' => $isGraded ? date('Y-m-d H:i:s', strtotime($submittedAt) + 86400) : null,
            'feedback' => $options['feedback'] ?? null,
            'grading_mode' => $isGraded ? ($rubricScores ? 'Rubric' : 'Default') : null,
            'rubric_scores' => $rubricScores ? json_encode($rubricScores) : null,
            'status' => $status,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Gradebook — graded work only, same rule as the app
    |--------------------------------------------------------------------------
    |
    | Quizzes count their best graded attempt, assignments their latest graded
    | one (AssessmentResultService). The term is still running, so remarks
    | stay "Incomplete".
    |
    */

    private function seedGradebook(): void
    {
        $rows = $this->db->table('assessment_submissions s')
            ->select('s.student_id, s.assessment_id, s.score, s.submitted_at, a.course_id, a.total_points, t.name AS type')
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('assessment_types t', 't.id = a.assessment_type_id')
            ->where('s.is_graded', 1)
            ->orderBy('s.submitted_at')
            ->get()->getResultArray();

        $counted = [];

        foreach ($rows as $row) {

            $key = "{$row['course_id']}:{$row['student_id']}:{$row['assessment_id']}";

            $keep = !isset($counted[$key])
                || $row['type'] !== 'Quiz'
                || (float) $row['score'] >= (float) $counted[$key]['score'];

            if ($keep) {
                $counted[$key] = $row;
            }

        }

        $totals = [];

        foreach ($counted as $row) {
            $key = "{$row['course_id']}:{$row['student_id']}";
            $totals[$key]['course_id'] = (int) $row['course_id'];
            $totals[$key]['student_id'] = (int) $row['student_id'];
            $totals[$key]['earned'] = ($totals[$key]['earned'] ?? 0) + (float) $row['score'];
            $totals[$key]['possible'] = ($totals[$key]['possible'] ?? 0) + (float) $row['total_points'];
        }

        foreach ($totals as $total) {

            $percentage = $total['possible'] > 0 ? round($total['earned'] / $total['possible'] * 100, 2) : 0;

            $this->db->table('gradebook_records')->insert([
                'course_id' => $total['course_id'],
                'student_id' => $total['student_id'],
                'calculated_final_grade' => $percentage,
                'overridden_final_grade' => null,
                'is_override_final_grade' => 0,
                'overall_percentage' => $percentage,
                'remarks' => 'Incomplete',
                'instructor_remarks' => null,
                'override_reason' => null,
                'last_calculated_at' => $this->at(),
                'created_by' => null,
                'created_at' => $this->at(),
                'updated_by' => null,
                'updated_at' => null,
                'status' => 'Active',
            ]);

        }
    }
}

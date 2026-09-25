<?php

namespace App\Database\Seeds;

use App\Services\Courses\Assessments\ShortAnswerGrader;

/**
 * Demo course content for every offering, in one place so the chain
 * offering → module → unit → item → assessment always hangs together:
 * a quiz / assignment learning item and its `assessments` row are created
 * together and linked (learning_item_id + module_id).
 *
 * IT101-A (Introduction to Computing, section A) is the rich showcase; the
 * other sections get a small, on-topic slice so lists and dashboards vary.
 */
class DemoContentSeeder extends DemoSeeder
{
    private array $typeIds = [];

    public function run()
    {
        foreach ($this->db->table('assessment_types')->get()->getResultArray() as $type) {
            $this->typeIds[$type['name']] = (int) $type['id'];
        }

        foreach ($this->catalog() as $offering) {
            $this->seedOffering($offering);
        }

        echo "Demo course content seeded.\n";
    }

    /*
    |--------------------------------------------------------------------------
    | Writer
    |--------------------------------------------------------------------------
    */

    private function seedOffering(array $offering): void
    {
        [$code, $section] = [$offering['course'], $offering['section']];

        $offeringId = $this->offeringId($code, $section);
        $courseId = $this->courseId($code);
        $instructorId = $this->userId($offering['instructor']);

        $customPrereqs = [];

        foreach ($offering['modules'] as $moduleIndex => $module) {

            $this->db->table('course_modules')->insert([
                'offering_id' => $offeringId,
                'title' => $module['title'],
                'description' => $module['description'] ?? null,
                'order_index' => $moduleIndex + 1,
                'status' => $module['status'] ?? 'published',
                'created_at' => $this->at('-30 days'),
                'updated_at' => $this->at('-30 days'),
            ]);

            $moduleId = (int) $this->db->insertID();

            foreach ($module['units'] as $unitIndex => $unit) {

                $this->db->table('learning_units')->insert([
                    'module_id' => $moduleId,
                    'title' => $unit['title'],
                    'description' => $unit['description'] ?? null,
                    'order_index' => $unitIndex + 1,
                    'status' => $unit['status'] ?? 'published',
                    'created_at' => $this->at('-30 days'),
                    'updated_at' => $this->at('-30 days'),
                ]);

                $unitId = (int) $this->db->insertID();

                foreach ($unit['items'] as $itemIndex => $item) {

                    $prereq = $item['prereq'] ?? 'none';

                    $this->db->table('learning_items')->insert([
                        'learning_unit_id' => $unitId,
                        'title' => $item['title'],
                        'item_type' => $item['type'],
                        'content' => match ($item['type']) {
                            'reading' => $this->doc($item['doc']),
                            'video' => json_encode(['autoplay' => false, 'show_controls' => true]),
                            'link' => json_encode(['url' => $item['url'], 'description' => $item['description'] ?? '']),
                            default => null,
                        },
                        'order_index' => $itemIndex + 1,
                        'status' => $item['status'] ?? 'published',
                        'prerequisite_mode' => is_array($prereq) ? 'custom' : $prereq,
                        'created_at' => $this->at('-30 days'),
                        'updated_at' => $this->at('-30 days'),
                    ]);

                    $itemId = (int) $this->db->insertID();

                    if (is_array($prereq)) {
                        $customPrereqs[$itemId] = $prereq;
                    }

                    match ($item['type']) {
                        'video', 'pdf' => $this->seedResource($itemId, $item, $instructorId),
                        'quiz', 'assignment' => $this->seedAssessment($courseId, $moduleId, $itemId, $item, $instructorId),
                        default => null,
                    };

                }

            }

        }

        // Custom prerequisites name other items of the same offering.
        foreach ($customPrereqs as $itemId => $titles) {

            foreach ($titles as $title) {
                $this->db->table('learning_item_prerequisites')->insert([
                    'learning_item_id' => $itemId,
                    'prerequisite_item_id' => $this->learningItemId($code, $section, $title),
                    'created_at' => $this->at('-30 days'),
                ]);
            }

        }
    }

    private function seedResource(int $itemId, array $item, int $ownerId): void
    {
        $fileId = isset($item['pdf'])
            ? $this->makePdf($item['pdf']['key'], $item['pdf']['name'], $item['pdf']['title'], $item['pdf']['lines'], $ownerId)
            : $this->fileId($item['file']);

        $this->db->table('learning_resources')->insert([
            'learning_item_id' => $itemId,
            'file_id' => $fileId,
            'title' => $item['title'],
            'resource_type' => $item['type'],
            'resource_url' => null,
            'created_at' => $this->at('-30 days'),
            'updated_at' => $this->at('-30 days'),
        ]);
    }

    private function seedAssessment(int $courseId, int $moduleId, int $itemId, array $item, int $instructorId): void
    {
        $spec = $item['assessment'];

        $isQuiz = $item['type'] === 'quiz';

        $questions = $spec['questions'] ?? [];

        $totalPoints = $isQuiz
            ? array_sum(array_column($questions, 'points'))
            : $spec['points'];

        $attempts = $spec['attempts'] ?? 1;

        $this->db->table('assessments')->insert([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'learning_item_id' => $itemId,
            'assessment_type_id' => $this->typeIds[$isQuiz ? 'Quiz' : 'Assignment'],
            'title' => $item['title'],
            'description' => $spec['description'],
            'instructions' => $this->doc($spec['instructions']),
            'available_from' => $this->at($spec['available']),
            'due_date' => $this->at($spec['due']),
            'available_until' => isset($spec['until']) ? $this->at($spec['until']) : null,
            'duration' => $spec['duration'] ?? null,
            'total_points' => $totalPoints,
            'weight_percentage' => $spec['weight'] ?? 10,
            'attempt_limit' => $attempts,
            'passing_percentage' => 60,
            'passing_score' => round($totalPoints * 0.6, 2),
            'created_by' => $instructorId,
            'created_at' => $this->at('-30 days'),
            'updated_by' => null,
            'updated_at' => null,
            'status' => ($item['status'] ?? 'published') === 'draft' ? 'Draft' : 'Published',
        ]);

        $assessmentId = (int) $this->db->insertID();

        $this->db->table('assessment_settings')->insert(array_merge([
            'assessment_id' => $assessmentId,
            'allow_file_upload' => $isQuiz ? 0 : 1,
            'allow_text_entry' => $isQuiz ? 0 : 1,
            'allow_link_entry' => $isQuiz ? 0 : 1,
            'allow_late_submission' => 0,
            'show_results_immediately' => $isQuiz ? 1 : 0,
            'show_correct_answers' => 0,
            'allow_review_after_submission' => 1,
            'require_password' => 0,
            'password' => null,
            'randomize_questions' => 0,
            'randomize_choices' => 0,
            'browser_lockdown' => 0,
            'allow_multiple_attempts' => $attempts > 1 ? 1 : 0,
            'created_at' => $this->at('-30 days'),
        ], $spec['settings'] ?? []));

        foreach ($questions as $index => $question) {
            $this->seedQuestion($assessmentId, $index + 1, $question, $instructorId);
        }

        if (!empty($spec['rubric'])) {
            $this->seedRubric($assessmentId, $item['title'], $spec['rubric'], $instructorId);
        }
    }

    private function seedQuestion(int $assessmentId, int $order, array $question, int $instructorId): void
    {
        $type = $question['type'];

        $this->db->table('assessment_questions')->insert([
            'assessment_id' => $assessmentId,
            'question_text' => $question['text'],
            'question_type' => $type,
            'allow_multiple_answers' => isset($question['select']) ? 1 : 0,
            'answers_count' => $question['select'] ?? null,
            'accepted_answers' => $type === 'Short Answer' ? ShortAnswerGrader::encode($question['answers']) : null,
            'case_sensitive' => !empty($question['case']) ? 1 : 0,
            'points' => $question['points'],
            'is_required' => 1,
            'order_index' => $order,
            'created_by' => $instructorId,
            'created_at' => $this->at('-30 days'),
            'status' => 'Active',
        ]);

        $questionId = (int) $this->db->insertID();

        foreach ($question['choices'] ?? [] as $index => [$text, $isCorrect]) {
            $this->db->table('assessment_question_choices')->insert([
                'question_id' => $questionId,
                'choice_text' => $text,
                'is_correct' => $isCorrect ? 1 : 0,
                'order_index' => $index + 1,
                'created_at' => $this->at('-30 days'),
            ]);
        }
    }

    private function seedRubric(int $assessmentId, string $title, array $criteria, int $instructorId): void
    {
        $this->db->table('assessment_rubrics')->insert([
            'question_id' => null,
            'assessment_id' => $assessmentId,
            'title' => "{$title} Rubric",
            'description' => 'How this work is scored.',
            'total_points' => array_sum(array_column($criteria, 1)),
            'created_by' => $instructorId,
            'created_at' => $this->at('-30 days'),
            'status' => 'Active',
        ]);

        $rubricId = (int) $this->db->insertID();

        foreach ($criteria as $index => [$criterion, $maxPoints, $description]) {
            $this->db->table('assessment_rubric_criteria')->insert([
                'rubric_id' => $rubricId,
                'criteria_title' => $criterion,
                'description' => $description,
                'max_points' => $maxPoints,
                'order_index' => $index + 1,
                'performance_level' => null,
                'created_at' => $this->at('-30 days'),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */

    private function catalog(): array
    {
        return [
            $this->introToComputingA(),
            $this->introToComputingB(),
            $this->programming1(),
            $this->databaseSystems(),
        ];
    }

    /** The showcase offering. */
    private function introToComputingA(): array
    {
        return [
            'course' => 'IT101',
            'section' => 'A',
            'instructor' => 'instructor1@mentora.test',
            'modules' => [

                [
                    'title' => 'Getting Started',
                    'description' => 'Meet the course and the computer itself.',
                    'units' => [
                        [
                            'title' => 'Course Orientation',
                            'description' => 'How this course works and what to expect.',
                            'items' => [
                                ['type' => 'reading', 'title' => 'Welcome to Introduction to Computing', 'doc' => [
                                    ['h', 'Welcome to Introduction to Computing'],
                                    ['p', 'This course takes you from how a computer works to writing your first programs and storing data. No prior experience is needed.'],
                                    ['h', 'How the course is organized'],
                                    ['p', 'The course is split into modules. Each module has learning units, and each unit has readings, videos, files and assessments. Items unlock in order, so finish each one before moving on.'],
                                    ['ul', ['Read every lesson carefully', 'Watch the videos and open the attached files', 'Take the quizzes — some allow a second attempt, and your best one counts', 'Submit assignments before the due date']],
                                ]],
                                ['type' => 'video', 'title' => 'Course Orientation Video', 'file' => 'videos/course-orientation.mp4', 'prereq' => 'previous'],
                                ['type' => 'link', 'title' => 'Course Syllabus and Class Policies', 'prereq' => 'previous',
                                    'url' => 'https://en.wikipedia.org/wiki/Syllabus',
                                    'description' => 'Grading, attendance and late-work policies for this course.'],
                                ['type' => 'quiz', 'title' => 'Course Pre-Test', 'prereq' => 'previous', 'assessment' => [
                                    'description' => 'A quick check of what you already know before we start.',
                                    'instructions' => [['p', 'Answer every question. Two attempts are allowed and your best score counts. Results show right after you submit.']],
                                    'available' => '-21 days', 'due' => '-7 days', 'duration' => 20, 'attempts' => 2, 'weight' => 5,
                                    'questions' => [
                                        ['type' => 'Multiple Choice', 'text' => 'Which part of a computer carries out program instructions?', 'points' => 2,
                                            'choices' => [['Central Processing Unit (CPU)', true], ['Monitor', false], ['Keyboard', false], ['Hard drive', false]]],
                                        ['type' => 'Multiple Choice', 'text' => 'Which of these is an input device?', 'points' => 2,
                                            'choices' => [['Keyboard', true], ['Speaker', false], ['Monitor', false], ['Printer', false]]],
                                        ['type' => 'Short Answer', 'text' => 'What does RAM stand for?', 'points' => 2, 'answers' => ['Random Access Memory']],
                                        ['type' => 'Short Answer', 'text' => 'How many bits are in one byte?', 'points' => 2, 'answers' => ['8', 'eight']],
                                        ['type' => 'Multiple Choice', 'text' => 'Which two of these are software?', 'points' => 2, 'select' => 2,
                                            'choices' => [['Operating system', true], ['Web browser', true], ['Mouse', false], ['Motherboard', false]]],
                                    ],
                                ]],
                            ],
                        ],
                        [
                            'title' => 'Computer Basics',
                            'description' => 'Hardware, software and how data is stored.',
                            'items' => [
                                ['type' => 'reading', 'title' => 'Hardware and Software', 'doc' => [
                                    ['h', 'Hardware and Software'],
                                    ['p', 'Hardware is every physical part of a computer: the processor, memory, storage and the devices you plug in. Software is the set of instructions that tells the hardware what to do.'],
                                    ['ul', ['Input devices send data in: keyboard, mouse, microphone', 'Output devices show results: monitor, speakers, printer', 'The CPU runs instructions; RAM holds what is being worked on', 'Storage (SSD, hard drive) keeps files when the power is off']],
                                    ['p', 'System software, like the operating system, manages the hardware. Application software, like a web browser or word processor, helps you do a task.'],
                                ]],
                                ['type' => 'pdf', 'title' => 'Parts of a Computer', 'prereq' => 'previous', 'pdf' => [
                                    'key' => 'documents/demo/parts-of-a-computer.pdf',
                                    'name' => 'Parts of a Computer.pdf',
                                    'title' => 'Parts of a Computer',
                                    'lines' => [
                                        'The Central Processing Unit (CPU) fetches, decodes and executes instructions. Its speed is measured in gigahertz (GHz), and modern CPUs have several cores that work in parallel.',
                                        'Random Access Memory (RAM) is fast, temporary memory. It holds the programs and data currently in use and is cleared when the computer turns off.',
                                        'Storage keeps data permanently. Solid-state drives (SSD) have no moving parts and are faster than hard disk drives (HDD).',
                                        'The motherboard connects every component. The power supply unit (PSU) converts wall power into the voltages the parts need.',
                                        'Input devices: keyboard, mouse, scanner, microphone. Output devices: monitor, printer, speakers.',
                                    ],
                                ]],
                                ['type' => 'reading', 'title' => 'How Computers Represent Data', 'prereq' => 'previous', 'doc' => [
                                    ['h', 'How Computers Represent Data'],
                                    ['p', 'Computers store everything as bits — ones and zeros. Eight bits make one byte, which can hold 256 different values.'],
                                    ['ul', ['1 kilobyte (KB) is about a thousand bytes', '1 megabyte (MB) is about a million bytes', '1 gigabyte (GB) is about a billion bytes']],
                                    ['p', 'Text is stored with a code such as ASCII or Unicode, where each character maps to a number. Images are grids of pixels, each pixel stored as numbers for its color.'],
                                ]],
                            ],
                        ],
                    ],
                ],

                [
                    'title' => 'Programming Fundamentals',
                    'description' => 'Variables, data types and control flow.',
                    'units' => [
                        [
                            'title' => 'Variables and Data Types',
                            'description' => 'Storing values and choosing the right type.',
                            'items' => [
                                ['type' => 'reading', 'title' => 'What Is a Variable?', 'doc' => [
                                    ['h', 'What Is a Variable?'],
                                    ['p', 'A variable is a named place in memory that holds a value. You give it a name so your program can read and change that value later.'],
                                    ['p', 'Good names describe what the value means: total_score or firstName, not x. Most languages do not allow names that start with a digit or contain spaces or dashes.'],
                                    ['p', 'A constant is a named value that cannot change once it is set, such as the number of days in a week.'],
                                ]],
                                ['type' => 'pdf', 'title' => 'Data Types Cheat Sheet', 'prereq' => 'previous', 'pdf' => [
                                    'key' => 'documents/demo/data-types-cheat-sheet.pdf',
                                    'name' => 'Data Types Cheat Sheet.pdf',
                                    'title' => 'Data Types Cheat Sheet',
                                    'lines' => [
                                        'Integer (int): whole numbers such as -3, 0 and 42. Use it for counts and indexes.',
                                        'Floating point (float): numbers with a decimal part such as 3.14 or 0.5. Use it for measurements and money calculations with care.',
                                        'String: text inside quotes such as "hello" or "42". A number in quotes is text, not a number.',
                                        'Boolean (bool): only true or false. Use it for yes-or-no conditions.',
                                        'Choosing the right type prevents bugs: adding "2" + "3" as strings may join them into "23" instead of giving 5.',
                                    ],
                                ]],
                                ['type' => 'quiz', 'title' => 'Quiz 1: Variables and Data Types', 'prereq' => 'previous', 'assessment' => [
                                    'description' => 'Checks your understanding of variables, naming and data types.',
                                    'instructions' => [['p', 'The multiple choice and short answer questions are graded automatically. The essay is graded by your instructor, so your full score appears once it has been reviewed.']],
                                    'available' => '-10 days', 'due' => '+3 days', 'duration' => 30, 'attempts' => 2, 'weight' => 15,
                                    'questions' => [
                                        ['type' => 'Multiple Choice', 'text' => 'Which of these values is a string?', 'points' => 3,
                                            'choices' => [['"42"', true], ['42', false], ['4.2', false], ['true', false]]],
                                        ['type' => 'Multiple Choice', 'text' => 'Which two are valid variable names in most programming languages?', 'points' => 4, 'select' => 2,
                                            'choices' => [['total_score', true], ['firstName', true], ['2ndPlace', false], ['my-name', false]]],
                                        ['type' => 'Short Answer', 'text' => 'What data type holds only true or false?', 'points' => 3, 'answers' => ['boolean', 'bool']],
                                        ['type' => 'Short Answer', 'text' => 'A named value that cannot change once it is set is called a …', 'points' => 3, 'answers' => ['constant']],
                                        ['type' => 'Essay', 'text' => 'In your own words, explain why choosing the right data type matters. Give one example.', 'points' => 7],
                                    ],
                                ]],
                            ],
                        ],
                        [
                            'title' => 'Control Flow',
                            'description' => 'Making decisions and repeating work.',
                            'items' => [
                                ['type' => 'reading', 'title' => 'Making Decisions with If Statements', 'doc' => [
                                    ['h', 'Making Decisions with If Statements'],
                                    ['p', 'An if statement runs a block of code only when a condition is true. Add else for what happens otherwise, and else if to check more conditions in order.'],
                                    ['ol', ['Check the condition', 'Run the matching block', 'Skip the others']],
                                ]],
                                ['type' => 'reading', 'title' => 'Repeating with Loops', 'prereq' => 'previous', 'doc' => [
                                    ['h', 'Repeating with Loops'],
                                    ['p', 'A loop repeats code. A for loop repeats a set number of times; a while loop repeats as long as a condition stays true.'],
                                    ['p', 'Always make sure a loop can end — a condition that never becomes false creates an infinite loop.'],
                                ]],
                                ['type' => 'assignment', 'title' => 'Assignment 1: Grade Calculator', 'prereq' => ['Making Decisions with If Statements', 'Repeating with Loops'], 'assessment' => [
                                    'description' => 'Write a program that turns scores into letter grades.',
                                    'instructions' => [
                                        ['p', 'Write a program that asks for five quiz scores (0–100), prints the average, and prints the letter grade using the scale below.'],
                                        ['ul', ['90 and above: A', '80–89: B', '70–79: C', 'Below 70: F']],
                                        ['p', 'Paste your code as text or upload the file, and include a screenshot of one run.'],
                                    ],
                                    'available' => '-9 days', 'due' => '-2 days', 'points' => 30, 'attempts' => 2, 'weight' => 20,
                                    'settings' => ['allow_late_submission' => 1],
                                    'rubric' => [
                                        ['Correctness', 12, 'The average and letter grade are right for every input.'],
                                        ['Code Readability', 10, 'Clear names, sensible structure, helpful comments.'],
                                        ['Testing and Output', 8, 'Shows a run with sample input and clear output.'],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],

                [
                    'title' => 'Working with Data',
                    'description' => 'Where programs keep their data.',
                    'units' => [
                        [
                            'title' => 'Introduction to Databases',
                            'description' => 'Tables, rows and keys.',
                            'items' => [
                                ['type' => 'reading', 'title' => 'What Is a Database?', 'doc' => [
                                    ['h', 'What Is a Database?'],
                                    ['p', 'A database stores data so it can be searched and updated reliably. A relational database keeps data in tables made of rows (records) and columns (fields).'],
                                    ['p', 'A primary key is a column whose value is unique for every row, such as a student number. It lets other tables point to exactly one row.'],
                                ]],
                                ['type' => 'assignment', 'title' => 'Lab 1: Designing a Table', 'prereq' => 'previous', 'assessment' => [
                                    'description' => 'Design a table for a small class list.',
                                    'instructions' => [['p', 'Design a Students table: list each column, its data type, and which column is the primary key. Explain your choice of key in two or three sentences.']],
                                    'available' => '-3 days', 'due' => '+1 day', 'points' => 20, 'weight' => 10,
                                ]],
                                ['type' => 'quiz', 'title' => 'Quiz 2: Database Basics', 'status' => 'draft', 'prereq' => 'previous', 'assessment' => [
                                    'description' => 'Tables, keys and your first SQL keyword.',
                                    'instructions' => [['p', 'All questions are graded automatically. The last answer is case sensitive.']],
                                    'available' => '+5 days', 'due' => '+12 days', 'duration' => 20, 'weight' => 15,
                                    'questions' => [
                                        ['type' => 'Multiple Choice', 'text' => 'What is a single row of a table also called?', 'points' => 2,
                                            'choices' => [['A record', true], ['A column', false], ['A schema', false], ['A query', false]]],
                                        ['type' => 'Short Answer', 'text' => 'What does SQL stand for?', 'points' => 3, 'answers' => ['Structured Query Language']],
                                        ['type' => 'Multiple Choice', 'text' => 'Which key uniquely identifies each row in a table?', 'points' => 2,
                                            'choices' => [['Primary key', true], ['Foreign key', false], ['Index', false], ['Alias', false]]],
                                        ['type' => 'Short Answer', 'text' => 'Write the SQL keyword (in capital letters) that retrieves rows from a table.', 'points' => 3, 'answers' => ['SELECT'], 'case' => true],
                                    ],
                                ]],
                            ],
                        ],
                        [
                            'title' => 'SQL Basics',
                            'description' => 'Still being written.',
                            'status' => 'draft',
                            'items' => [
                                ['type' => 'reading', 'title' => 'Your First SELECT Query', 'status' => 'draft', 'doc' => [
                                    ['h', 'Your First SELECT Query'],
                                    ['p', 'SELECT name FROM students; returns the name column of every row in the students table.'],
                                ]],
                            ],
                        ],
                    ],
                ],

            ],
        ];
    }

    private function introToComputingB(): array
    {
        return [
            'course' => 'IT101',
            'section' => 'B',
            'instructor' => 'instructor1@mentora.test',
            'modules' => [[
                'title' => 'Getting Started',
                'description' => 'Meet the course and the computer itself.',
                'units' => [[
                    'title' => 'Course Orientation',
                    'items' => [
                        ['type' => 'reading', 'title' => 'Welcome to Section B', 'doc' => [
                            ['h', 'Welcome to Section B'],
                            ['p', 'This section follows the same outline as section A, one week behind. Start with the pre-test below.'],
                        ]],
                        ['type' => 'quiz', 'title' => 'Course Pre-Test', 'prereq' => 'previous', 'assessment' => [
                            'description' => 'A quick check of what you already know.',
                            'instructions' => [['p', 'Answer every question.']],
                            'available' => '-2 days', 'due' => '+5 days', 'duration' => 15, 'weight' => 5,
                            'questions' => [
                                ['type' => 'Multiple Choice', 'text' => 'Which part of a computer carries out program instructions?', 'points' => 5,
                                    'choices' => [['CPU', true], ['Monitor', false], ['Printer', false]]],
                                ['type' => 'Short Answer', 'text' => 'What does RAM stand for?', 'points' => 5, 'answers' => ['Random Access Memory']],
                            ],
                        ]],
                        ['type' => 'assignment', 'title' => 'Reflection: Why Computing?', 'prereq' => 'previous', 'assessment' => [
                            'description' => 'A short reflection on your goals for this course.',
                            'instructions' => [['p', 'In one or two paragraphs, describe what you hope to learn in this course and why.']],
                            'available' => '-2 days', 'due' => '+7 days', 'points' => 10, 'weight' => 5,
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function programming1(): array
    {
        return [
            'course' => 'IT102',
            'section' => 'A',
            'instructor' => 'instructor2@mentora.test',
            'modules' => [[
                'title' => 'Setting Up PHP',
                'description' => 'Install the tools and write your first script.',
                'units' => [
                    [
                        'title' => 'Your Development Environment',
                        'items' => [
                            ['type' => 'pdf', 'title' => 'XAMPP Installation Guide', 'file' => 'documents/xampp-installation-guide.pdf'],
                            ['type' => 'reading', 'title' => 'Your First PHP Script', 'prereq' => 'previous', 'doc' => [
                                ['h', 'Your First PHP Script'],
                                ['p', 'Create hello.php in the htdocs folder, add <?php echo "Hello, world!"; and open http://localhost/hello.php in your browser.'],
                            ]],
                            ['type' => 'quiz', 'title' => 'Quiz: PHP Setup', 'prereq' => 'previous', 'assessment' => [
                                'description' => 'Checks your setup knowledge.',
                                'instructions' => [['p', 'Graded automatically.']],
                                'available' => '-8 days', 'due' => '-1 day', 'duration' => 15, 'weight' => 10,
                                'questions' => [
                                    ['type' => 'Multiple Choice', 'text' => 'Which folder does XAMPP serve PHP files from?', 'points' => 5,
                                        'choices' => [['htdocs', true], ['bin', false], ['logs', false]]],
                                    ['type' => 'Short Answer', 'text' => 'Which PHP statement prints text to the page?', 'points' => 5, 'answers' => ['echo', 'print']],
                                ],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'PHP Variables',
                        'items' => [
                            ['type' => 'pdf', 'title' => 'PHP Variables Reference', 'file' => 'documents/php-variables-reference.pdf'],
                            ['type' => 'link', 'title' => 'PHP Variables Explained (video)', 'prereq' => 'previous',
                                'url' => 'https://www.youtube.com/watch?v=OK_JCtrrv-c',
                                'description' => 'A short walkthrough of PHP variables.'],
                            ['type' => 'assignment', 'title' => 'Exercise: Hello, PHP', 'prereq' => 'previous', 'assessment' => [
                                'description' => 'Print a greeting that uses variables.',
                                'instructions' => [['p', 'Store your name and course in variables and print "Hello, <name>! Welcome to <course>."']],
                                'available' => '-5 days', 'due' => '+4 days', 'points' => 20, 'weight' => 10,
                            ]],
                        ],
                    ],
                ],
            ]],
        ];
    }

    private function databaseSystems(): array
    {
        return [
            'course' => 'IT201',
            'section' => 'A',
            'instructor' => 'instructor2@mentora.test',
            'modules' => [[
                'title' => 'The Relational Model',
                'description' => 'Tables, keys and relationships.',
                'units' => [[
                    'title' => 'Tables and Keys',
                    'items' => [
                        ['type' => 'reading', 'title' => 'Primary and Foreign Keys', 'doc' => [
                            ['h', 'Primary and Foreign Keys'],
                            ['p', 'A primary key identifies each row. A foreign key is a column that stores another table\'s primary key, linking the two tables.'],
                        ]],
                        ['type' => 'quiz', 'title' => 'Quiz: Keys and Relationships', 'prereq' => 'previous', 'assessment' => [
                            'description' => 'Keys and how tables connect.',
                            'instructions' => [['p', 'Graded automatically.']],
                            'available' => '-6 days', 'due' => '+2 days', 'duration' => 15, 'weight' => 10,
                            'questions' => [
                                ['type' => 'Multiple Choice', 'text' => 'A column that stores another table\'s primary key is a…', 'points' => 5,
                                    'choices' => [['Foreign key', true], ['Candidate key', false], ['Composite key', false]]],
                                ['type' => 'Short Answer', 'text' => 'What does ERD stand for?', 'points' => 5, 'answers' => ['Entity Relationship Diagram', 'Entity-Relationship Diagram']],
                            ],
                        ]],
                        ['type' => 'assignment', 'title' => 'ER Diagram Draft', 'prereq' => 'previous', 'assessment' => [
                            'description' => 'Sketch an ER diagram for a library.',
                            'instructions' => [['p', 'Draw the entities Book, Member and Loan with their keys and relationships. Upload a photo or PDF.']],
                            'available' => '-6 days', 'due' => '-1 day', 'points' => 25, 'weight' => 15,
                            'settings' => ['allow_late_submission' => 1],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}

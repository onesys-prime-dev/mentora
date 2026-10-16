<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\ImportPreviewStore;
use App\Services\Courses\Assessments\PrintedExamParser;
use App\Services\Courses\Assessments\QuestionImportService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * One 50-question exam, exported from a test-bank tool in five file types
 * (text, two kinds of RTF, a PDF's text layer and an HTML export). Each is
 * read to the same questions and the same answer key.
 *
 * @internal
 */
final class QuestionImportPrintedExamTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const FILES = [
        'text' => ['onesys sample.txt', 'txt'],
        'rtf' => ['onesys sample.rtf', 'rtf'],
        'rtf without spaces' => ['onesys sample1.rtf', 'rtf'],
        'pdf' => ['Onesys Sample.pdf', 'pdf'],
        'html' => ['onesys sample.html', 'html'],
    ];

    private QuestionImportService $import;
    private string $storeDirectory;
    private int $quiz;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeDirectory = sys_get_temp_dir() . '/pe-test-' . bin2hex(random_bytes(4));
        $this->import = new QuestionImportService(new ImportPreviewStore($this->storeDirectory));

        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->storeDirectory);

        parent::tearDown();
    }

    private function path(string $name): string
    {
        return TESTPATH . '_support/fixtures/exam-samples/' . $name;
    }

    /** The answer letters, read independently from the text file's own key. */
    private function expectedKey(): string
    {
        $text = (string) file_get_contents($this->path('onesys sample.txt'));
        preg_match_all('/^(\d{1,2})\) ([A-F])\s*$/m', $text, $m, PREG_SET_ORDER);

        $key = [];

        foreach ($m as $row) {
            $key[(int) $row[1]] = $row[2];
        }

        ksort($key);

        return implode('', $key);
    }

    /** @return array<string, mixed> */
    private function preview(string $name, string $extension): array
    {
        return $this->import->previewFile($this->instructor, $this->quiz, $this->path($name), $extension);
    }

    /** @return list<string> */
    private function answerLetters(array $preview): string
    {
        $letters = '';

        foreach ($preview['diff']['added'] as $item) {
            foreach ($item['choices'] as $i => $choice) {
                if ($choice['is_correct']) {
                    $letters .= chr(65 + $i);
                }
            }
        }

        return $letters;
    }

    public function testTheKeyOfTheSampleIsFiftyAnswers(): void
    {
        $this->assertSame(50, strlen($this->expectedKey()));
    }

    /**
     * @dataProvider sampleFiles
     */
    public function testEachFileGivesTheFiftyQuestionsAndTheirKey(string $label): void
    {
        [$name, $extension] = self::FILES[$label];

        $preview = $this->preview($name, $extension);

        $this->assertTrue($preview['success'], $label . ': ' . ($preview['message'] ?? ''));
        $this->assertSame(50, $preview['counts']['added'], $label);

        // The HTML export carries no answer key: every question waits for its answer to be chosen.
        if ($label === 'html') {
            $this->assertSame(50, $preview['counts']['problems']);
            $this->assertSame('', $this->answerLetters($preview));

            return;
        }

        $this->assertSame(0, $preview['counts']['problems'], $label);
        $this->assertSame($this->expectedKey(), $this->answerLetters($preview), $label);
    }

    /** @return iterable<string, array{string}> */
    public static function sampleFiles(): iterable
    {
        foreach (array_keys(self::FILES) as $label) {
            yield $label => [$label];
        }
    }

    public function testTheSameQuestionsComeFromEveryFile(): void
    {
        $stems = [];

        foreach (self::FILES as $label => [$name, $extension]) {
            $stems[$label] = array_column($this->preview($name, $extension)['diff']['added'], 'question_text');
        }

        $first = $stems['text'];

        $this->assertSame('The term ________ can be used to describe anything that uses binary numbers.', $first[0]);

        foreach ($stems as $label => $list) {
            $this->assertSame($first, $list, "{$label} reads the same stems as the text file");
        }
    }

    public function testChoicesComeOutRightWhenTheyWrapOrNumberSix(): void
    {
        $preview = $this->preview('onesys sample.txt', 'txt');
        $items = $preview['diff']['added'];

        // Question 13 offers six choices, A to F.
        $this->assertCount(6, $items[12]['choices']);
        $this->assertSame('It compiles and translates.', $items[12]['choices'][0]['text']);
        $this->assertSame('It compiles.', $items[12]['choices'][5]['text']);

        // Choices that wrap onto a second line (question 3).
        $this->assertSame(['software', 'machine language', 'syntax', 'high-level language'], array_column($items[2]['choices'], 'text'));

        // A stem with a code line before the blank (question 42) keeps it.
        $this->assertStringContainsString('1011000 010100110', $items[41]['question_text']);
    }

    public function testApplyingTheExamAddsFiftyQuestionsToTheQuiz(): void
    {
        $preview = $this->preview('Onesys Sample.pdf', 'pdf');
        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertTrue($applied['success']);
        $this->assertSame(50, db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->countAllResults());
    }

    public function testAnExamWithoutAKeyAsksForTheAnswers(): void
    {
        $text = (string) file_get_contents($this->path('onesys sample.txt'));
        $withoutKey = (string) preg_replace('/\n1\) D\b.*$/s', '', $text);
        $path = sys_get_temp_dir() . '/nokey-' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($path, $withoutKey);

        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'txt');
        @unlink($path);

        $this->assertTrue($preview['success']);
        $this->assertSame(50, count($preview['diff']['added']));
        $this->assertSame('', $this->answerLetters($preview));
        $this->assertFalse($preview['diff']['added'][0]['importable'], 'each question needs its answer chosen');
        $this->assertContains(
            'No answer key was found: choose the correct answer.',
            array_column($preview['diff']['added'][0]['problems'], 'message')
        );
    }

    public function testASectionHeaderChangesTheTypeOfWhatFollows(): void
    {
        $text = "MULTIPLE CHOICE.  Choose one.\n1) Which is a fruit?      1) _______\nA) apple      B) chair\n\n"
            . "TRUE/FALSE.  Write T or F.\n2) The sky is blue.      2) _______\n\n"
            . "ESSAY.  Answer fully.\n3) Discuss routing.      3) _______\n\n"
            . "1) A\n2) T\n";

        $read = (new PrintedExamParser())->parse($text);
        $q = $read['questions'];

        $this->assertCount(3, $q);
        $this->assertSame(['apple', 'chair'], array_column($q[0]['choices'], 'text'));
        $this->assertTrue($q[0]['choices'][0]['is_correct']);
        $this->assertSame(['True', 'False'], array_column($q[1]['choices'], 'text'));
        $this->assertTrue($q[1]['choices'][0]['is_correct']);
        $this->assertSame('Essay', $q[2]['question_type']);
    }

    public function testAScannedPdfSaysItHasNoText(): void
    {
        $path = sys_get_temp_dir() . '/scan-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $preview = $this->import->previewFile($this->instructor, $this->quiz, $path, 'pdf');
        @unlink($path);

        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('no selectable text', $preview['message']);
    }
}

<?php

declare(strict_types=1);

use App\Services\AI\PdfTextExtractor;
use App\Services\Courses\Assessments\PrintableTestExport;
use App\Services\Courses\Assessments\QuestionExportService;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpWord\IOFactory;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The printable test: the questions to hand out, with no answers marked, then
 * the answer key on pages of its own at the end, as PDF, Word or RTF. It is
 * separate from the editable export that imports back in.
 *
 * @internal
 */
final class PrintableTestExportTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionExportService $export;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->export = new QuestionExportService();
        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
        db_connect()->table('assessments')->where('id', $this->quiz)->update([
            'title' => 'Midterm: Data types',
            'instructions' => 'Write clearly. No notes allowed.',
        ]);

        $mc = AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, true, true], 4);
        $short = AssessmentFactory::shortAnswer($this->quiz, $this->instructor, ['CPU', 'Central Processing Unit'], 2, true);
        $essay = AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 6);
        $db = db_connect();
        $db->table('assessment_questions')->where('id', $mc['question'])->update(['question_text' => 'Which are even numbers?']);
        $db->table('assessment_questions')->where('id', $short)->update(['question_text' => 'What does CPU stand for?']);
        $db->table('assessment_questions')->where('id', $essay)->update(['question_text' => 'Discuss floating point.']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function model(): array
    {
        $assessment = db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray();
        $questions = (new \App\Models\Assessments\AssessmentQuestionModel())->listForAssessment($this->quiz);

        return (new PrintableTestExport())->model($assessment, $questions);
    }

    private function printable(string $format): string
    {
        $result = $this->export->exportPrintable($this->quiz, $format);
        $this->assertTrue($result['success'], json_encode($result));
        $this->files[] = $result['file_path'];

        return $result['file_path'];
    }

    public function testTheTestListsEveryQuestionNumberedWithItsPointsAndNoAnswersMarked(): void
    {
        $model = $this->model();

        $this->assertSame('Midterm: Data types', $model['title']);
        $this->assertSame('12', $model['total_points']);
        $this->assertSame(3, $model['question_count']);
        $this->assertSame([1, 2, 3], array_column($model['questions'], 'number'));
        $this->assertSame(['4', '2', '6'], array_column($model['questions'], 'points'));

        $mc = $model['questions'][0];
        $this->assertSame(['A', 'B', 'C'], array_column($mc['choices'], 'letter'));
        $this->assertSame(['Choice 1', 'Choice 2', 'Choice 3'], array_column($mc['choices'], 'text'));
        $this->assertSame('Select all that apply.', $mc['note']);
        $this->assertArrayNotHasKey('is_correct', $mc['choices'][0], 'the questions carry no answer');
    }

    public function testTheKeyHasTheRightAnswerOfEveryKind(): void
    {
        $key = $this->model()['key'];

        $this->assertSame('B. Choice 2; C. Choice 3', $key[0]['answer']);
        $this->assertSame('CPU / Central Processing Unit (case-sensitive)', $key[1]['answer']);
        $this->assertStringContainsString('graded by the instructor', $key[2]['answer']);
    }

    public function testAQuestionWithNoKeyIsSaidSoRatherThanLeftBlank(): void
    {
        AssessmentFactory::shortAnswer($this->quiz, $this->instructor, null, 1);
        AssessmentFactory::multipleChoice($this->quiz, $this->instructor, [false, false], 1);

        $answers = array_column($this->model()['key'], 'answer');

        $this->assertContains('No answer key is set.', $answers);
        $this->assertContains('No correct answer is set.', $answers);
    }

    public function testWordPutsTheKeyAfterAPageBreakAndNeverBeforeTheQuestions(): void
    {
        $path = $this->printable('docx');
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $text = strip_tags(str_replace('</w:p>', "\n", $xml));

        $this->assertStringContainsString('Midterm: Data types', $text);
        $this->assertStringContainsString('Write clearly. No notes allowed.', $text);
        $this->assertStringContainsString('Name:', $text);

        $lastQuestion = strrpos($text, 'Discuss floating point.');
        $keyHeading = strpos($text, 'Answer Key');

        $this->assertNotFalse($lastQuestion);
        $this->assertNotFalse($keyHeading);
        $this->assertGreaterThan($lastQuestion, $keyHeading, 'the key comes after every question');
        $this->assertGreaterThan($keyHeading, (int) strpos($text, 'B. Choice 2; C. Choice 3'));
        $this->assertStringNotContainsString('B. Choice 2; C. Choice 3', substr($text, 0, $keyHeading), 'no answer before the key');
        $this->assertGreaterThanOrEqual(2, substr_count($xml, '<w:sectPr'), 'the key is a section of its own, so it starts on a new page');
    }

    public function testWordCanBeOpenedAgainAsADocument(): void
    {
        $document = IOFactory::createReader('Word2007')->load($this->printable('docx'));

        $this->assertGreaterThanOrEqual(1, count($document->getSections()));
    }

    public function testRtfHasTheKeyAfterTheQuestions(): void
    {
        $rtf = (string) file_get_contents($this->printable('rtf'));

        $this->assertStringStartsWith('{\rtf', $rtf);
        $this->assertGreaterThan((int) strrpos($rtf, 'Discuss floating point.'), (int) strpos($rtf, 'Answer Key'));
        $this->assertStringContainsString('\sect', $rtf, 'the key is a section of its own, so it starts on a new page');
    }

    public function testPdfHasTheKeyOnLaterPagesThanTheQuestions(): void
    {
        $path = $this->printable('pdf');

        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path, false, null, 0, 8));

        $text = (new PdfTextExtractor())->extract($path)['text'];

        $pageOf = static function (string $needle) use ($text): int {
            $pieces = preg_split('/^\[\[page (\d+)\]\]$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

            for ($i = 1; $i + 1 < count($pieces); $i += 2) {
                if (str_contains($pieces[$i + 1], $needle)) {
                    return (int) $pieces[$i];
                }
            }

            return 0;
        };

        $this->assertGreaterThan(0, $pageOf('Which are even numbers?'));
        $this->assertGreaterThan($pageOf('Discuss floating point.'), $pageOf('Answer Key'));
        $this->assertSame($pageOf('Answer Key'), $pageOf('Central Processing Unit'), 'the answers are on the key page');
        $this->assertNotSame($pageOf('Which are even numbers?'), $pageOf('(case-sensitive)'), 'no answer on the question pages');
    }

    public function testTheHtmlEscapesWhatAPersonTyped(): void
    {
        db_connect()->table('assessment_questions')->where('question_text', 'What does CPU stand for?')
            ->update(['question_text' => 'Explain <script>alert(1)</script> & "quotes"']);

        $html = (new PrintableTestExport())->html($this->model());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @return list<array<string, mixed>> */
    private function versions(array $options): array
    {
        $assessment = db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray();
        $questions = (new \App\Models\Assessments\AssessmentQuestionModel())->listForAssessment($this->quiz);

        return (new PrintableTestExport())->versions($assessment, $questions, $options);
    }

    public function testOneVersionKeepsTheQuizOrderUnlessShufflingIsAsked(): void
    {
        $plain = $this->versions([]);

        $this->assertCount(1, $plain);
        $this->assertSame('', $plain[0]['version']);
        $this->assertSame(['Which are even numbers?', 'What does CPU stand for?', 'Discuss floating point.'], array_column($plain[0]['questions'], 'text'));
    }

    public function testSeveralVersionsAreLettersAndAIsTheQuizAsWritten(): void
    {
        $models = $this->versions(['versions' => 3, 'shuffle_questions' => true, 'shuffle_choices' => true]);

        $this->assertSame(['A', 'B', 'C'], array_column($models, 'version'));
        $this->assertSame(['Which are even numbers?', 'What does CPU stand for?', 'Discuss floating point.'], array_column($models[0]['questions'], 'text'));

        foreach ($models as $model) {
            $texts = array_column($model['questions'], 'text');
            sort($texts);
            $this->assertSame(['Discuss floating point.', 'What does CPU stand for?', 'Which are even numbers?'], $texts, 'same questions in every version');
        }
    }

    public function testTheShuffleIsTheSameEveryTimeItIsPrinted(): void
    {
        $options = ['versions' => 4, 'shuffle_questions' => true, 'shuffle_choices' => true];

        $this->assertSame($this->versions($options), $this->versions($options));
    }

    public function testEachVersionsKeyFollowsItsOwnShuffledChoices(): void
    {
        foreach ($this->versions(['versions' => 4, 'shuffle_choices' => true]) as $model) {
            $question = $model['questions'][0];
            $this->assertSame('Which are even numbers?', $question['text']);

            $letters = array_column($question['choices'], 'letter');
            $this->assertSame(['A', 'B', 'C'], $letters);

            // The key names the same two choices as the original, by their text, at their new letters.
            $key = $model['key'][0]['answer'];
            $texts = array_column($question['choices'], 'text', 'letter');

            foreach (explode('; ', $key) as $part) {
                [$letter, $text] = explode('. ', $part, 2);
                $this->assertSame($text, $texts[$letter]);
            }

            $this->assertCount(2, explode('; ', $key));
        }
    }

    public function testChoicesThatPointAtEachOtherAreNotShuffled(): void
    {
        $assessment = ['id' => 7, 'title' => 'T'];
        $question = [
            'question_type' => 'Multiple Choice', 'question_text' => 'Pick', 'points' => 1,
            'choices' => [
                ['choice_text' => 'Red', 'is_correct' => 0],
                ['choice_text' => 'Blue', 'is_correct' => 1],
                ['choice_text' => 'All of the above', 'is_correct' => 0],
            ],
        ];

        foreach ((new PrintableTestExport())->versions($assessment, [$question], ['versions' => 4, 'shuffle_choices' => true]) as $model) {
            $this->assertSame(['Red', 'Blue', 'All of the above'], array_column($model['questions'][0]['choices'], 'text'));
        }
    }

    public function testTheCountIsCappedAtFour(): void
    {
        $this->assertCount(4, $this->versions(['versions' => 99]));
        $this->assertCount(1, $this->versions(['versions' => 0]));
    }

    public function testAnExportOfSeveralVersionsHasEachVersionAndEveryKey(): void
    {
        $result = $this->export->exportPrintable($this->quiz, 'docx', ['versions' => 2, 'shuffle_questions' => true]);
        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];

        $zip = new ZipArchive();
        $zip->open($result['file_path']);
        $text = strip_tags(str_replace('</w:p>', "\n", (string) $zip->getFromName('word/document.xml')));
        $zip->close();

        $this->assertStringContainsString('Version A', $text);
        $this->assertStringContainsString('Version B', $text);
        $this->assertStringContainsString('Answer Key — Version A', $text);
        $this->assertStringContainsString('Answer Key — Version B', $text);
    }

    public function testKeyOnlyLeavesOutTheQuestionPages(): void
    {
        $models = $this->versions(['versions' => 2]);
        $html = (new PrintableTestExport())->htmlMany($models, true);

        $this->assertStringNotContainsString('class="test', $html);
        $this->assertStringContainsString('Answer Key — Version B', $html);
    }

    public function testPaperSizeIsAppliedAndAnUnknownOneFallsBackToA4(): void
    {
        $printable = new PrintableTestExport();
        $models = $this->versions([]);

        $legal = $printable->wordMany($models, false, 'Legal');
        $this->assertSame('Legal', $legal->getSections()[0]->getStyle()->getPaperSize());

        $path = tempnam(sys_get_temp_dir(), 'pt') ?: '';
        $this->files[] = $path;
        $printable->saveMany($models, 'pdf', $path, ['paper' => 'Tabloid']);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
    }

    public function testAnUnknownFormatOrAssessmentIsRefused(): void
    {
        $this->assertFalse($this->export->exportPrintable($this->quiz, 'exe')['success']);
        $this->assertSame('Assessment not found.', $this->export->exportPrintable(999999, 'pdf')['message']);
    }

    public function testAnEmptyQuizStillGivesATestSayingSo(): void
    {
        $offering = CourseFactory::offering();
        $empty = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));

        $result = $this->export->exportPrintable($empty, 'rtf');
        $this->files[] = $result['file_path'];

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('This test has no questions yet.', (string) file_get_contents($result['file_path']));
    }
}

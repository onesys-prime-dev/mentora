<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AikenParser;
use App\Services\Courses\Assessments\GiftParser;
use App\Services\Courses\Assessments\ParsedQuestion;
use App\Services\Courses\Assessments\SpreadsheetQuestions;
use App\Services\Courses\Assessments\TextQuizFormats;
use PHPUnit\Framework\TestCase;

/**
 * The readers and writers for other systems' quiz files: Aiken, GIFT and a
 * spreadsheet. All pure.
 *
 * @internal
 */
final class QuizFormatParsersTest extends TestCase
{
    public function testAikenReadsQuestionsChoicesAndTheAnswerLetter(): void
    {
        $read = (new AikenParser())->parse("Which layer routes packets?\nA. Transport\nB. Network\nC. Session\nANSWER: B\n\nWhat is 2+2?\nA) 3\nB) 4\nANSWER: B\n");

        $this->assertCount(2, $read['questions']);
        $this->assertSame('Which layer routes packets?', $read['questions'][0]['question_text']);
        $this->assertSame([false, true, false], array_column($read['questions'][0]['choices'], 'is_correct'));
        $this->assertSame(1, $read['questions'][0]['line']);
        $this->assertSame(7, $read['questions'][1]['line']);
    }

    public function testAikenFlagsAMissingOrUnknownAnswerInsteadOfGuessing(): void
    {
        $read = (new AikenParser())->parse("Pick one\nA. x\nB. y\n\nPick two\nA. x\nB. y\nANSWER: D\n");

        $this->assertContains('No ANSWER line was found for this question.', $read['questions'][0]['notes']);
        $this->assertContains('The answer D is not one of the choices.', $read['questions'][1]['notes']);
    }

    public function testAikenListsABlockWithoutChoicesAsSkipped(): void
    {
        $read = (new AikenParser())->parse("Just a sentence\nwith no choices\n");

        $this->assertSame([], $read['questions']);
        $this->assertSame(1, $read['skipped'][0]['line']);
    }

    public function testAikenIsRecognisedByItsAnswerLine(): void
    {
        $this->assertTrue(AikenParser::looks("Q\nA. a\nANSWER: A"));
        $this->assertFalse(AikenParser::looks('Just some notes'));
    }

    public function testGiftReadsTheCommonTypes(): void
    {
        $text = <<<'GIFT'
        // a comment
        $CATEGORY: top

        ::Routing::Which layer routes packets? {
        =Network
        ~Transport
        ~Session
        }

        The sky is blue. {TRUE}

        What does CPU stand for? {=Central Processing Unit =CPU}

        Pick the primes. {
        ~%50%2
        ~%50%3
        ~%-100%4
        }

        Discuss routing. {}
        GIFT;

        $read = (new GiftParser())->parse($text);
        $q = $read['questions'];

        $this->assertCount(5, $q);
        $this->assertSame('Which layer routes packets?', $q[0]['question_text']);
        $this->assertSame([true, false, false], array_column($q[0]['choices'], 'is_correct'));
        $this->assertSame('Multiple Choice', $q[1]['question_type']);
        $this->assertSame(['True', 'False'], array_column($q[1]['choices'], 'text'));
        $this->assertTrue($q[1]['choices'][0]['is_correct']);
        $this->assertSame('Short Answer', $q[2]['question_type']);
        $this->assertSame(['Central Processing Unit', 'CPU'], $q[2]['accepted_answers']);
        $this->assertTrue($q[3]['allow_multiple_answers']);
        $this->assertSame(2, $q[3]['answers_count']);
        $this->assertSame('Essay', $q[4]['question_type']);
    }

    public function testGiftListsWhatItCannotHoldWithTheLine(): void
    {
        $text = "Match these. {\n=cat -> meow\n=dog -> woof\n}\n\nHow many legs? {#4}\n\nPlain text, no answer block\n";

        $read = (new GiftParser())->parse($text);

        $this->assertSame([], $read['questions']);
        $this->assertCount(3, $read['skipped']);
        $this->assertSame(1, $read['skipped'][0]['line']);
        $this->assertStringContainsString('Matching', $read['skipped'][0]['reason']);
        $this->assertStringContainsString('Numeric', $read['skipped'][1]['reason']);
    }

    public function testGiftUnescapesSpecialCharacters(): void
    {
        $read = (new GiftParser())->parse("What is 1 \\= 1? {=Yes\\: it is ~No}\n");

        $this->assertSame('What is 1 = 1?', $read['questions'][0]['question_text']);
        $this->assertSame('Yes: it is', $read['questions'][0]['choices'][0]['text']);
    }

    public function testASpreadsheetIsReadByItsHeaderNamesInAnyOrder(): void
    {
        $rows = [
            ['Points', 'Question', 'Type', 'A', 'B', 'Answer'],
            ['2', 'Which layer routes packets?', 'MC', 'Transport', 'Network', 'B'],
            ['1', 'What does CPU stand for?', 'Short Answer', '', '', 'CPU | Central Processing Unit'],
            ['', '', '', '', '', ''],
            ['1', 'The sky is blue', 'True/False', '', '', 'true'],
            ['1', 'Explain', 'Essay', '', '', ''],
        ];

        $read = (new SpreadsheetQuestions())->parse($rows);

        $this->assertNotNull($read);
        $this->assertCount(4, $read['questions']);
        $this->assertSame(2.0, $read['questions'][0]['points']);
        $this->assertSame([false, true], array_column($read['questions'][0]['choices'], 'is_correct'));
        $this->assertSame(['CPU', 'Central Processing Unit'], $read['questions'][1]['accepted_answers']);
        $this->assertTrue($read['questions'][2]['choices'][0]['is_correct']);
        $this->assertSame('Essay', $read['questions'][3]['question_type']);
        $this->assertSame(2, $read['questions'][0]['line']);
    }

    public function testASpreadsheetRowThatCannotBeUsedIsSkippedWithItsLine(): void
    {
        $rows = [
            ['Type', 'Question', 'A', 'B', 'Answer', 'Points'],
            ['Riddle', 'What walks on four legs?', '', '', '', ''],
            ['MC', 'Fine', 'x', 'y', 'A', 'many'],
            ['MC', '', 'x', 'y', 'A', ''],
        ];

        $read = (new SpreadsheetQuestions())->parse($rows);

        $this->assertSame([], $read['questions']);
        $this->assertSame([2, 3, 4], array_column($read['skipped'], 'line'));
    }

    public function testASpreadsheetWithoutAQuestionColumnIsNotRead(): void
    {
        $this->assertNull((new SpreadsheetQuestions())->parse([['Name', 'Score'], ['a', '1']]));
    }

    public function testTheTemplateReadsBackAsItsOwnExamples(): void
    {
        $read = (new SpreadsheetQuestions())->parse(SpreadsheetQuestions::templateRows());

        $this->assertCount(4, $read['questions']);
        $this->assertSame([], $read['skipped']);
        $this->assertSame([], array_merge(...array_column($read['questions'], 'notes')));
    }

    public function testQuestionsWriteToRowsAndReadBackTheSame(): void
    {
        $questions = [
            ['question_type' => 'Multiple Choice', 'question_text' => 'Pick evens', 'points' => 3, 'is_required' => 1, 'choices' => [
                ['choice_text' => '1', 'is_correct' => 0], ['choice_text' => '2', 'is_correct' => 1], ['choice_text' => '4', 'is_correct' => 1],
            ]],
            ['question_type' => 'Short Answer', 'question_text' => 'Capital of France?', 'points' => 1, 'is_required' => 1, 'accepted_answers' => ['Paris']],
            ['question_type' => 'Essay', 'question_text' => 'Discuss', 'points' => 5, 'is_required' => 0],
        ];

        $read = (new SpreadsheetQuestions())->parse(SpreadsheetQuestions::rowsFor($questions));

        $this->assertSame(['Pick evens', 'Capital of France?', 'Discuss'], array_column($read['questions'], 'question_text'));
        $this->assertTrue($read['questions'][0]['allow_multiple_answers']);
        $this->assertSame(3.0, $read['questions'][0]['points']);
        $this->assertSame(['Paris'], $read['questions'][1]['accepted_answers']);
        $this->assertFalse($read['questions'][2]['is_required']);
    }

    public function testCsvCellsThatStartLikeFormulasAreGuardedAndRestoredOnTheWayBack(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'q') . '.csv';
        $rows = SpreadsheetQuestions::rowsFor([
            ['question_type' => 'Short Answer', 'question_text' => '=HYPERLINK("http://x")', 'points' => 1, 'is_required' => 1, 'accepted_answers' => ['-5']],
        ]);

        SpreadsheetQuestions::write($rows, 'csv', $path);

        $this->assertStringContainsString("'=HYPERLINK", (string) file_get_contents($path));

        $back = (new SpreadsheetQuestions())->parse(SpreadsheetQuestions::rowsOf($path, 'csv'));
        @unlink($path);

        $this->assertSame('=HYPERLINK("http://x")', $back['questions'][0]['question_text']);
        $this->assertSame(['-5'], $back['questions'][0]['accepted_answers']);
    }

    public function testACsvWithSemicolonsAndABomIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'q') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBFType;Question;A;B;Answer\nMC;Pick;x;y;A\n");

        $read = (new SpreadsheetQuestions())->parse(SpreadsheetQuestions::rowsOf($path, 'csv'));
        @unlink($path);

        $this->assertSame('Pick', $read['questions'][0]['question_text']);
        $this->assertTrue($read['questions'][0]['choices'][0]['is_correct']);
    }

    public function testAikenAndGiftWritersKeepOnlyWhatTheyCanHold(): void
    {
        $questions = [
            ['question_type' => 'Multiple Choice', 'question_text' => 'One right', 'choices' => [
                ['choice_text' => 'a', 'is_correct' => 0], ['choice_text' => 'b', 'is_correct' => 1],
            ]],
            ['question_type' => 'Multiple Choice', 'question_text' => 'Two right', 'choices' => [
                ['choice_text' => 'a', 'is_correct' => 1], ['choice_text' => 'b', 'is_correct' => 1],
            ]],
            ['question_type' => 'Short Answer', 'question_text' => 'Type: x = y', 'accepted_answers' => ['a:b']],
            ['question_type' => 'Essay', 'question_text' => 'Write'],
            ['question_type' => 'File Upload', 'question_text' => 'Upload'],
        ];

        $aiken = TextQuizFormats::aiken($questions);

        $this->assertStringContainsString("One right\nA. a\nB. b\nANSWER: B", $aiken);
        $this->assertStringNotContainsString('Two right', $aiken);

        $gift = TextQuizFormats::gift($questions);

        $this->assertStringContainsString('Two right', $gift);
        $this->assertStringContainsString('Type\: x \= y {=a\:b}', $gift);
        $this->assertStringContainsString('Write {}', $gift);
        $this->assertStringNotContainsString('Upload', $gift);

        // What is written is read back the same.
        $back = (new GiftParser())->parse($gift)['questions'];

        $this->assertSame(['One right', 'Two right', 'Type: x = y', 'Write'], array_column($back, 'question_text'));
        $this->assertSame(['a:b'], $back[2]['accepted_answers']);
        $this->assertTrue($back[1]['allow_multiple_answers']);
    }

    public function testEveryReaderHandsOverTheSameQuestionShape(): void
    {
        $choice = ParsedQuestion::choice(3, 'Q', ParsedQuestion::trueFalse(false), 2.0);
        $short = ParsedQuestion::shortAnswer(4, 'Q', ['x']);
        $open = ParsedQuestion::open(5, 'Q', 'File Upload');

        $this->assertSame(array_keys($choice), array_keys($short));
        $this->assertSame(array_keys($choice), array_keys($open));
        $this->assertSame([false, true], array_column($choice['choices'], 'is_correct'));
        $this->assertFalse($choice['allow_multiple_answers']);
        $this->assertSame('File Upload', $open['question_type']);
    }
}

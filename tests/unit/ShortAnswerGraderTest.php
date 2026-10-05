<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\ShortAnswerGrader;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Short answer auto-grading: every accepted answer counts, spacing and (by
 * default) letter case are ignored, nothing is fuzzy, and a question with no
 * answer key is left for the instructor.
 *
 * @internal
 */
final class ShortAnswerGraderTest extends CIUnitTestCase
{
    private ShortAnswerGrader $grader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grader = new ShortAnswerGrader();
    }

    private function question(array $accepted, bool $caseSensitive = false): array
    {
        return ['question_type' => 'Short Answer', 'accepted_answers' => $accepted, 'case_sensitive' => $caseSensitive];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading and storing the answer key
    |--------------------------------------------------------------------------
    */

    public function testDecodeTrimsDropsBlanksAndDuplicatesAndKeepsOrder(): void
    {
        $this->assertSame(['CPU', 'central processing unit'], ShortAnswerGrader::decode(['  CPU ', '', '   ', 'central   processing unit', 'CPU']));
    }

    public function testDecodeReadsStoredJsonAndToleratesGarbage(): void
    {
        $this->assertSame(['a', 'b'], ShortAnswerGrader::decode('["a","b"]'));
        $this->assertSame([], ShortAnswerGrader::decode(''));
        $this->assertSame([], ShortAnswerGrader::decode(null));
        $this->assertSame([], ShortAnswerGrader::decode('not json'));
        $this->assertSame([], ShortAnswerGrader::decode(42));
    }

    public function testDecodeIgnoresListEntriesThatAreNotText(): void
    {
        $this->assertSame(['ok', '5'], ShortAnswerGrader::decode(['ok', ['nested'], null, 5, new stdClass()]));
    }

    public function testDecodeKeepsAtMostTenAnswers(): void
    {
        $many = array_map(static fn ($i) => "answer {$i}", range(1, 15));

        $this->assertCount(ShortAnswerGrader::MAX_ANSWERS, ShortAnswerGrader::decode($many));
        $this->assertSame('answer 1', ShortAnswerGrader::decode($many)[0]);
    }

    public function testEncodeStoresJsonWithReadableUnicodeOrNothing(): void
    {
        $this->assertSame('["café","B"]', ShortAnswerGrader::encode([' café ', 'B', 'B']));
        $this->assertNull(ShortAnswerGrader::encode([]));
        $this->assertNull(ShortAnswerGrader::encode(['  ', '']));
        $this->assertNull(ShortAnswerGrader::encode(null));
    }

    public function testAcceptedAnswersAreReadFromTheQuestion(): void
    {
        $this->assertSame(['x'], $this->grader->acceptedAnswers(['accepted_answers' => '["x"]']));
        $this->assertSame([], $this->grader->acceptedAnswers([]));
    }

    public function testOnlyAShortAnswerWithAnAnswerKeyGradesItself(): void
    {
        $this->assertTrue($this->grader->isAutoGradable($this->question(['yes'])));
        $this->assertFalse($this->grader->isAutoGradable($this->question([])));
        $this->assertFalse($this->grader->isAutoGradable($this->question(['  ', ''])));
        $this->assertFalse($this->grader->isAutoGradable(['question_type' => 'Essay', 'accepted_answers' => ['yes']]));
        $this->assertFalse($this->grader->isAutoGradable(['question_type' => 'Multiple Choice']));
        $this->assertFalse($this->grader->isAutoGradable([]));
    }

    /*
    |--------------------------------------------------------------------------
    | Marking a response
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{0: ?string, 1: bool}> */
    public static function responses(): iterable
    {
        yield 'the main answer' => ['CPU', true];
        yield 'an equivalent form' => ['central processing unit', true];
        yield 'different case' => ['cpu', true];
        yield 'shouting' => ['CENTRAL PROCESSING UNIT', true];
        yield 'extra spaces around' => ['   CPU  ', true];
        yield 'extra spaces inside' => ['central    processing   unit', true];
        yield 'tabs and newlines' => ["central\tprocessing\nunit", true];
        yield 'a wrong answer' => ['GPU', false];
        yield 'a typo' => ['CPUU', false];
        yield 'a part of the answer' => ['central processing', false];
        yield 'the answer inside a sentence' => ['It is the CPU', false];
        yield 'blank' => ['', false];
        yield 'only spaces' => ['    ', false];
        yield 'nothing answered' => [null, false];
    }

    #[DataProvider('responses')]
    public function testMarkingIgnoresCaseAndSpacingButNothingElse(?string $response, bool $correct): void
    {
        $this->assertSame($correct, $this->grader->isCorrect($response, $this->question(['CPU', 'central processing unit'])), (string) $response);
    }

    public function testCaseSensitiveQuestionsAreStrictAboutCaseButNotSpacing(): void
    {
        $question = $this->question(['NaCl'], true);

        $this->assertTrue($this->grader->isCorrect('NaCl', $question));
        $this->assertTrue($this->grader->isCorrect('  NaCl ', $question));
        $this->assertFalse($this->grader->isCorrect('nacl', $question));
        $this->assertFalse($this->grader->isCorrect('NACL', $question));
    }

    public function testNonAsciiLettersFoldCaseToo(): void
    {
        $this->assertTrue($this->grader->isCorrect('ÉCOLE', $this->question(['école'])));
    }

    public function testAQuestionWithNoAnswerKeyMarksNothingCorrect(): void
    {
        $this->assertFalse($this->grader->isCorrect('anything', $this->question([])));
        $this->assertFalse($this->grader->isCorrect('', $this->question([])));
    }

    public function testTheAnswerKeyMayBeStoredJson(): void
    {
        $this->assertTrue($this->grader->isCorrect('b', ['accepted_answers' => '["a","B"]']));
    }
}

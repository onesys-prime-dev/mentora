<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\PlainQuestionParser;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reading an ordinary exam document: the layouts teachers really type, and
 * what is understood from each.
 *
 * @internal
 */
final class PlainQuestionParserTest extends CIUnitTestCase
{
    /**
     * @param list<string|array{0: string, 1: bool}> $lines text, or [text, bold]
     *
     * @return list<array<string, mixed>>
     */
    private function parse(array $lines): array
    {
        $paragraphs = array_map(
            static fn ($line): array => is_array($line) ? ['text' => $line[0], 'bold' => $line[1]] : ['text' => $line, 'bold' => false],
            $lines
        );

        return (new PlainQuestionParser())->parse($paragraphs);
    }

    /** @return list<string> the text of the correct choices of the first question */
    private function correct(array $question): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['text'],
            array_filter($question['choices'], static fn (array $c): bool => $c['is_correct'])
        ));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function questionNumberings(): iterable
    {
        yield 'dot' => [['1. Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'bracket' => [['1) Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'Q prefix' => [['Q1. Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'Q colon' => [['Q1: Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'Question word' => [['Question 1. Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'parenthesised number' => [['(1) Which layer routes packets?', 'a) Transport', 'b) Network', 'Answer: b']];
        yield 'upper letters with dots' => [['1. Which layer routes packets?', 'A. Transport', 'B. Network', 'Answer: B']];
        yield 'parenthesised letters' => [['1. Which layer routes packets?', '(a) Transport', '(b) Network', 'Ans: b']];
        yield 'key word' => [['1. Which layer routes packets?', 'a) Transport', 'b) Network', 'Correct answer: b']];
    }

    #[DataProvider('questionNumberings')]
    public function testEveryCommonNumberingAndChoiceStyleReadsTheSameQuestion(array $lines): void
    {
        $questions = $this->parse($lines);

        $this->assertCount(1, $questions);
        $this->assertSame('Which layer routes packets?', $questions[0]['question_text']);
        $this->assertSame('Multiple Choice', $questions[0]['question_type']);
        $this->assertSame(['Transport', 'Network'], array_column($questions[0]['choices'], 'text'));
        $this->assertSame(['Network'], $this->correct($questions[0]));
        $this->assertSame([], $questions[0]['notes']);
    }

    public function testAQuestionCanRunOverSeveralParagraphsAndChoicesCanWrap(): void
    {
        $questions = $this->parse([
            '1. A packet arrives at a router.',
            'The router reads its header.',
            'What does it use to decide where to send it?',
            'a) The destination address',
            'held in the header',
            'b) The source port',
            'Answer: a',
        ]);

        $this->assertSame("A packet arrives at a router.\nThe router reads its header.\nWhat does it use to decide where to send it?", $questions[0]['question_text']);
        $this->assertSame('The destination address held in the header', $questions[0]['choices'][0]['text']);
        $this->assertSame(['The destination address held in the header'], $this->correct($questions[0]));
    }

    public function testSeveralQuestionsAreSeparatedByTheirNumbers(): void
    {
        $questions = $this->parse([
            'Midterm exam',
            'Read each question carefully.',
            '1. First?', 'a) x', 'b) y', 'Answer: a',
            '',
            '2. Second?', 'a) x', 'b) y', 'Answer: b',
            '3. Third, explain your reasoning.',
        ]);

        $this->assertSame(['First?', 'Second?', 'Third, explain your reasoning.'], array_column($questions, 'question_text'));
        $this->assertSame(['Multiple Choice', 'Multiple Choice', 'Essay'], array_column($questions, 'question_type'));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function correctAnswerStyles(): iterable
    {
        yield 'asterisk after' => [['1. Q?', 'a) One', 'b) Two *', 'c) Three'], ['Two']];
        yield 'asterisk before' => [['1. Q?', 'a) One', '*b) Two', 'c) Three'], ['Two']];
        yield 'word in brackets' => [['1. Q?', 'a) One', 'b) Two (correct)', 'c) Three'], ['Two']];
        yield 'tick' => [['1. Q?', 'a) One', 'b) Two ✓', 'c) Three'], ['Two']];
        yield 'several letters' => [['1. Q?', 'a) One', 'b) Two', 'c) Three', 'Answer: a, c'], ['One', 'Three']];
        yield 'letters with and' => [['1. Q?', 'a) One', 'b) Two', 'c) Three', 'Answer: A and C'], ['One', 'Three']];
        yield 'answer written out' => [['1. Q?', 'a) One', 'b) Two', 'c) Three', 'Answer: Two'], ['Two']];
    }

    #[DataProvider('correctAnswerStyles')]
    public function testTheCorrectChoiceIsFoundFromMarksAndAnswerLines(array $lines, array $expected): void
    {
        $question = $this->parse($lines)[0];

        $this->assertSame($expected, $this->correct($question));
        $this->assertSame(count($expected) > 1, $question['allow_multiple_answers']);
        $this->assertSame(count($expected) > 1 ? count($expected) : null, $question['answers_count']);
    }

    public function testTheMarkIsRemovedFromTheChoiceText(): void
    {
        $question = $this->parse(['1. Q?', 'a) One', 'b) Two (correct)', 'c) Three *'])[0];

        $this->assertSame(['One', 'Two', 'Three'], array_column($question['choices'], 'text'));
    }

    public function testAnAnswerKeyAtTheEndIsUsedAndNotTakenForQuestions(): void
    {
        $questions = $this->parse([
            '1. First?', 'a) x', 'b) y',
            '2. Second?', 'a) x', 'b) y', 'c) z',
            'Answer Key',
            '1. b',
            '2) C',
        ]);

        $this->assertCount(2, $questions);
        $this->assertSame(['y'], $this->correct($questions[0]));
        $this->assertSame(['z'], $this->correct($questions[1]));
    }

    public function testBoldMeansCorrectOnlyWhenSomeButNotAllChoicesAreBold(): void
    {
        $some = $this->parse(['1. Q?', ['a) One', false], ['b) Two', true], ['c) Three', false]])[0];

        $this->assertSame(['Two'], $this->correct($some));
        $this->assertNotEmpty($some['notes']);

        $all = $this->parse(['1. Q?', ['a) One', true], ['b) Two', true]])[0];

        $this->assertSame([], $this->correct($all));
        $this->assertStringContainsString('No correct answer', implode(' ', $all['notes']));
    }

    public function testBoldDoesNotOverrideAnExplicitAnswer(): void
    {
        $question = $this->parse(['1. Q?', ['a) One', true], 'b) Two', 'c) Three', 'Answer: c'])[0];

        $this->assertSame(['Three'], $this->correct($question));
    }

    /** @return iterable<string, array{list<string>, string, int, bool|null}> */
    public static function shapes(): iterable
    {
        yield 'true or false answered' => [['1. The sky is blue. True or false', 'Answer: True'], 'Multiple Choice', 2, true];
        yield 'true or false unanswered' => [['1. True or false: routers have IP addresses.'], 'Multiple Choice', 2, null];
        yield 'short answer' => [['1. What does CPU stand for?', 'Answer: Central Processing Unit'], 'Short Answer', 0, null];
        yield 'blank with answer' => [['1. A ______ forwards packets.', 'Answer: router'], 'Short Answer', 0, null];
        yield 'essay' => [['1. Discuss the role of the network layer.'], 'Essay', 0, null];
    }

    #[DataProvider('shapes')]
    public function testTheTypeFollowsTheShapeOfTheQuestion(array $lines, string $type, int $choiceCount, ?bool $trueIsCorrect): void
    {
        $question = $this->parse($lines)[0];

        $this->assertSame($type, $question['question_type']);
        $this->assertCount($choiceCount, $question['choices']);

        if ($choiceCount === 2) {
            $this->assertSame($trueIsCorrect, $trueIsCorrect === null ? null : $question['choices'][0]['is_correct']);

            if ($trueIsCorrect === null) {
                $this->assertSame([], $this->correct($question));
                $this->assertNotEmpty($question['notes']);
            }
        }
    }

    public function testAShortAnswerKeepsEveryAcceptedForm(): void
    {
        $question = $this->parse(['1. Expand CPU.', 'Answer: Central Processing Unit | processor'])[0];

        $this->assertSame(['Central Processing Unit', 'processor'], $question['accepted_answers']);
    }

    /** @return iterable<string, array{list<string>, float, string}> */
    public static function pointStyles(): iterable
    {
        yield 'in brackets' => [['1. Which? (2 pts)', 'a) x', 'b) y', 'Answer: a'], 2.0, 'Which?'];
        yield 'square brackets' => [['1. Which? [3 points]', 'a) x', 'b) y', 'Answer: a'], 3.0, 'Which?'];
        yield 'own line' => [['1. Which?', 'Points: 4', 'a) x', 'b) y', 'Answer: a'], 4.0, 'Which?'];
        yield 'decimal comma' => [['1. Which? (1,5 pts)', 'a) x', 'b) y', 'Answer: a'], 1.5, 'Which?'];
        yield 'default' => [['1. Which?', 'a) x', 'b) y', 'Answer: a'], 1.0, 'Which?'];
    }

    #[DataProvider('pointStyles')]
    public function testPointsAreReadAndRemovedFromTheQuestion(array $lines, float $points, string $text): void
    {
        $question = $this->parse($lines)[0];

        $this->assertSame($points, $question['points']);
        $this->assertSame($text, $question['question_text']);
    }

    public function testADecimalNumberAtTheStartOfALineIsNotAQuestionNumber(): void
    {
        $questions = $this->parse(['1. What is 2.5 times 2?', '2.5 is the base value.', 'Answer: 5']);

        $this->assertCount(1, $questions);
        $this->assertSame('Short Answer', $questions[0]['question_type']);
    }

    public function testEachQuestionRemembersWhereItStarted(): void
    {
        $questions = $this->parse(['Title', '', '1. First?', 'a) x', 'b) y', 'Answer: a', '2. Second?']);

        $this->assertSame([3, 7], array_column($questions, 'line'));
    }

    public function testNothingRecognisableGivesNothing(): void
    {
        $this->assertSame([], $this->parse(['Just a paragraph of notes.', 'Another one.']));
        $this->assertSame([], $this->parse([]));
    }
}

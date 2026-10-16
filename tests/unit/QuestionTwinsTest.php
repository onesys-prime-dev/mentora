<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionTwins;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class QuestionTwinsTest extends TestCase
{
    public function testAQuestionThatRestatesAnotherIsFound(): void
    {
        $twin = QuestionTwins::find('Explain how a packet crosses a router, please.', [
            'What does CPU stand for?',
            'Explain how a packet crosses a router.',
        ]);

        $this->assertSame('Explain how a packet crosses a router.', $twin);
    }

    public function testDifferentQuestionsAreNotTwins(): void
    {
        $this->assertNull(QuestionTwins::find('Which layer routes packets?', ['What does CPU stand for?', 'Define latency in networks.']));
    }

    public function testVeryShortQuestionsAreNeverCalledTwins(): void
    {
        $this->assertNull(QuestionTwins::find('Define latency.', ['Define latency.']));
    }

    public function testCaseAndPunctuationDoNotMatter(): void
    {
        $this->assertNotNull(QuestionTwins::find('WHICH layer ROUTES packets??', ['Which layer routes packets?']));
    }

    public function testNonLatinTextIsCompared(): void
    {
        $this->assertNotNull(QuestionTwins::find('Какой уровень маршрутизирует пакеты данных', ['Какой уровень маршрутизирует пакеты данных?']));
    }
}

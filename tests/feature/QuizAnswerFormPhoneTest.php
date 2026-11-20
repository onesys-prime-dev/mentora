<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The quiz answer form on phones (no database): 44px choice rows, 16px text answers, a sticky opaque submit bar
 * that keeps a focused field clear of it, and the "view as student" attempts table in a sideways scroller.
 *
 * @internal
 */
final class QuizAnswerFormPhoneTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon']);
    }

    private function render(array $data): DOMXPath
    {
        $html = view('pages/courses/features/assessments/quiz-answer-form', $data + [
            'currentAssessment' => ['id' => 5, 'title' => str_repeat('A very long quiz title ', 6), 'total_points' => 10, 'attempt_limit' => 2],
            'blocked'           => false,
            'endsAt'            => date('Y-m-d H:i:s', time() + 600),
            'responsesByQuestion' => [],
            'questions'         => [
                ['id' => 1, 'question_type' => 'Multiple Choice', 'question_text' => 'Pick one', 'points' => 5, 'is_required' => 1,
                    'choices' => [['id' => 10, 'choice_text' => 'Yes'], ['id' => 11, 'choice_text' => 'No']]],
                ['id' => 2, 'question_type' => 'Essay', 'question_text' => 'Explain', 'points' => 5, 'is_required' => 0],
            ],
        ], ['saveData' => false]);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function classes(?DOMNode $node): array
    {
        $this->assertNotNull($node);

        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    public function testAnAttemptInProgressHasTouchSizedRowsAndAStickySubmitBarClearOfTheFocusedField(): void
    {
        $xpath = $this->render(['submission' => ['id' => 7, 'status' => 'In Progress']]);

        $root = $xpath->query('/html/body/div')->item(0);
        foreach (['p-5', 'sm:p-8', 'lg:p-10'] as $class) {
            $this->assertContains($class, $this->classes($root));
        }

        // The title gives way (truncates); the back button is a 44px target and has a name.
        $this->assertGreaterThan(0, $xpath->query('//span[contains(concat(" ", @class, " "), " truncate ")][contains(., "A very long quiz title")]')->length);
        $back = $xpath->query('//button[@data-back-to-assessment-view]')->item(0);
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($back));
        $this->assertNotSame('', $back->getAttribute('aria-label'));

        $rows = $xpath->query('//label[@data-quiz-choice-row]');
        $this->assertSame(2, $rows->length);
        foreach ($rows as $row) {
            $this->assertContains('min-h-11', $this->classes($row));
            // The whole row is the label of its input, so a tap anywhere on it chooses.
            $this->assertSame(1, $xpath->query('.//input[@data-quiz-choice-input]', $row)->length);
        }

        $text = $xpath->query('//textarea[@data-quiz-text-input]')->item(0);
        $this->assertContains('text-base', $this->classes($text));
        $this->assertNotContains('text-sm', $this->classes($text), 'phones get 16px; text-sm is sm: only');
        // The form is not the scroller (the window is), so the clearance is each field's own scroll margin.
        $this->assertContains('scroll-mb-36', $this->classes($text));
        $this->assertContains('scroll-mb-36', $this->classes($xpath->query('//input[@data-quiz-choice-input]')->item(0)));

        $bar = $xpath->query('//button[@data-submit-quiz]/parent::div')->item(0);
        foreach (['sticky', 'bottom-0', 'z-10', 'bg-white', 'max-lg:pb-safe-2'] as $class) {
            $this->assertContains($class, $this->classes($bar));
        }
        $this->assertNotContains('bg-white/95', $this->classes($bar), 'opaque');
        $this->assertContains('max-sm:w-full', $this->classes($xpath->query('//button[@data-submit-quiz]')->item(0)));

        // Below lg the top bar scrolls away, so the pinned bar carries a copy of the time left, not announced twice.
        $copy = $xpath->query('.//*[@data-quiz-timer-copy]', $bar)->item(0);
        $this->assertContains('lg:hidden', $this->classes($copy));
        $this->assertSame('true', $copy->getAttribute('aria-hidden'));
        $this->assertSame(1, $xpath->query('.//*[@data-quiz-timer-value]', $copy)->length);
        $this->assertSame(2, $xpath->query('//*[@data-quiz-timer-value]')->length);
    }

    public function testTheViewAsStudentAttemptsTableSitsInASidewaysScroller(): void
    {
        $xpath = $this->render([
            'submission' => null,
            'viewAs'     => ['name' => 'Stu Dent', 'teaches' => false, 'used' => 1, 'limit' => 2, 'attempts' => [
                ['attempt_number' => 1, 'submitted_at' => '2026-11-01 10:00:00', 'score' => 8, 'status' => 'Graded'],
            ]],
        ]);

        $table = $xpath->query('//*[@data-view-as-landing]//table')->item(0);
        $this->assertNotNull($table);
        $this->assertSame(1, $xpath->query('ancestor::*[@data-scroll-x]', $table)->length);
    }
}

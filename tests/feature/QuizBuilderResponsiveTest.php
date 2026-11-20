<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The Quiz Builder at every width: the palette is the pane beside the questions from lg up, a side panel opened from
 * the top bar at md–lg, and below md a sticky bar whose "Add question" opens the question types upward. Each card has
 * move arrows for touch reordering (no database needed).
 *
 * @internal
 */
final class QuizBuilderResponsiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'feature']);
    }

    private function render(): DOMXPath
    {
        $question = fn (int $id, string $type) => [
            'id' => $id, 'question_type' => $type, 'question_text' => "Question {$id}", 'points' => 2,
            'is_required' => 1, 'allow_multiple_answers' => 0, 'answers_count' => 2, 'case_sensitive' => 0,
            'choices' => [['id' => $id * 10, 'choice_text' => 'Yes', 'is_correct' => 1]], 'accepted_answers' => ['x'],
        ];

        $html = view('pages/courses/features/assessments/quiz-builder', [
            'currentAssessment' => ['id' => 5, 'title' => 'Quiz 1', 'total_points' => 4],
            'currentQuestions'  => [$question(1, 'Multiple Choice'), $question(2, 'Short Answer')],
        ]); // Data saved (the default), as the service renders it: the page's own includes (the modals) read it.

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function classes(DOMNode $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    public function testThePhoneBarHoldsTheSaveStatusAndTheQuestionTypes(): void
    {
        $x   = $this->render();
        $bar = $x->query('//*[@data-action-bar][@data-quiz-builder-bar]');
        $this->assertCount(1, $bar);
        $bar = $bar->item(0);

        foreach (['sticky', 'bottom-0', 'md:hidden', 'bg-white'] as $class) {
            $this->assertContains($class, $this->classes($bar), $class);
        }

        // Save: the builder saves as it goes, so the bar carries the live save status (the same hooks).
        $this->assertCount(1, $x->query('.//*[@data-builder-save-status]//*[@data-builder-save-message]', $bar));

        $toggle = $x->query('.//button[@data-action-bar-toggle]', $bar)->item(0);
        $this->assertNotNull($toggle);
        $this->assertStringContainsString('Add question', $toggle->textContent);
        $panelId = $toggle->getAttribute('aria-controls');
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));

        $panel = $x->query(".//*[@data-action-bar-panel][@id='{$panelId}']", $bar);
        $this->assertCount(1, $panel);
        $types = $x->query('.//button[@data-palette-item]', $panel->item(0));
        $this->assertSame(
            ['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'],
            array_map(fn ($b) => $b->getAttribute('data-palette-type'), iterator_to_array($types)),
        );
        foreach ($types as $button) {
            $this->assertContains('min-h-11', $this->classes($button));
        }
    }

    public function testThePaletteIsASidePanelBelowLgAndTheSamePaneFromLgUp(): void
    {
        $x       = $this->render();
        $palette = $x->query('//aside[@id="quiz-builder-palette"][@data-side-panel][@data-builder-palette]')->item(0);
        $this->assertNotNull($palette);

        $classes = $this->classes($palette);
        foreach (['lg:sticky', 'lg:top-24', 'lg:w-[290px]', 'lg:shrink-0', 'lg:self-start'] as $class) {
            $this->assertContains($class, $classes, $class);
        }
        // Plain sticky or a fixed width would beat the panel's own position and width below lg.
        $this->assertNotContains('sticky', $classes);
        $this->assertNotContains('w-[290px]', $classes);
        $this->assertCount(1, $x->query('.//button[@data-side-panel-close]', $palette));

        $toggle = $x->query('//*[contains(@class,"sticky-bar-top")]//button[@data-side-panel-toggle][@aria-controls="quiz-builder-palette"]')->item(0);
        $this->assertNotNull($toggle);
        foreach (['hidden', 'md:inline-flex', 'lg:hidden'] as $class) {
            $this->assertContains($class, $this->classes($toggle), $class);
        }
    }

    public function testEachCardHasMoveArrowsAndADecimalPointsField(): void
    {
        $x     = $this->render();
        $cards = $x->query('//*[@data-question-list]/*[@data-question-card]');
        $this->assertCount(2, $cards);

        foreach ($cards as $card) {
            foreach (['up', 'down'] as $direction) {
                $button = $x->query(".//button[@data-question-move='{$direction}']", $card)->item(0);
                $this->assertNotNull($button, $direction);
                $this->assertNotSame('', $button->getAttribute('aria-label'));
                foreach (['pointer-coarse:min-h-11', 'lg:pointer-fine:hidden'] as $class) {
                    $this->assertContains($class, $this->classes($button), $class);
                }
            }

            $points = $x->query('.//input[@data-question-points-input]', $card)->item(0);
            $this->assertSame('decimal', $points->getAttribute('inputmode'));
            $this->assertContains('text-base', $this->classes($points));
            $this->assertContains('flex-wrap', $this->classes($points->parentNode));
        }

        // The moves are announced.
        $this->assertCount(1, $x->query('//*[@data-question-move-status][@aria-live="polite"]'));
    }

    public function testTheAiReviewTrayWrapsAndItsButtonsAreTouchSized(): void
    {
        $x    = $this->render();
        $tray = $x->query('//*[@data-ai-review-tray]')->item(0);
        $this->assertNotNull($tray);

        $header = $x->query('./div[1]', $tray)->item(0);
        foreach (['flex-wrap', 'gap-2'] as $class) {
            $this->assertContains($class, $this->classes($header), $class);
        }

        foreach (['accept-clear', 'accept-all', 'dismiss', 'cancel', 'top-up'] as $hook) {
            $button = $x->query(".//button[@data-ai-tray-{$hook}]", $tray)->item(0);
            $this->assertNotNull($button, $hook);
            $this->assertContains('pointer-coarse:min-h-11', $this->classes($button), $hook);
        }

        $dismiss = $x->query('.//button[@data-ai-tray-dismiss]', $tray)->item(0);
        $this->assertContains('pointer-coarse:min-w-11', $this->classes($dismiss));
        $this->assertStringNotContainsString('#767676', $dismiss->getAttribute('class'));
    }
}

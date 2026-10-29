<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The generate modal has one source, the quiz's learning unit: a checklist of
 * its readings and PDFs, all ticked to begin with, and nothing about topics,
 * pasted text or uploads.
 *
 * @internal
 */
final class AiGenerateModalViewTest extends CIUnitTestCase
{
    private const VIEW = 'pages/courses/features/assessments/partials/modals/ai-generate';

    /** @return array<string, mixed> */
    private function unit(array $items): array
    {
        return [
            'id' => 7,
            'title' => 'Unit 1: Data types',
            'module_title' => 'Module A',
            'readings' => count(array_filter($items, static fn (array $i): bool => $i['type'] === 'reading')),
            'pdfs' => count(array_filter($items, static fn (array $i): bool => $i['type'] === 'pdf')),
            'items' => $items,
        ];
    }

    private function render(?array $unit): string
    {
        helper(['icon']);

        return view(self::VIEW, ['sourceUnit' => $unit]);
    }

    public function testEveryReadingAndPdfIsListedAndTickedWithItsTypeAndDraftState(): void
    {
        $html = $this->render($this->unit([
            ['id' => 11, 'title' => 'Data type article', 'type' => 'reading', 'status' => 'published'],
            ['id' => 12, 'title' => 'Lecture slides', 'type' => 'pdf', 'status' => 'published'],
            ['id' => 13, 'title' => 'Unfinished notes', 'type' => 'reading', 'status' => 'draft'],
        ]));

        $this->assertSame(3, preg_match_all('/data-ai-item(?![-\w])/', $html), 'one checkbox per item');
        $this->assertSame(3, substr_count($html, ' checked'), 'all start ticked');
        $this->assertStringContainsString('value="12"', $html);
        $this->assertStringContainsString('Lecture slides', $html);
        $this->assertStringContainsString('3 of 3 selected', $html);
        $this->assertSame(1, substr_count($html, '>Draft<'));
        $this->assertStringContainsString('data-ai-source-usable="1"', $html);
    }

    public function testTheOldSourcesAreGone(): void
    {
        $html = $this->render($this->unit([['id' => 1, 'title' => 'A', 'type' => 'reading', 'status' => 'published']]));

        foreach (['data-ai-source-tab', 'name="topic"', 'name="source_text"', 'name="file"', 'Paste Text', 'Upload File'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function testAQuizOutsideAUnitShowsTheHintAndCannotGenerate(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString("This quiz isn't in a learning unit", $html);
        $this->assertStringContainsString('data-ai-source-usable="0"', $html);
        $this->assertStringNotContainsString('data-ai-item', $html);
    }

    public function testAUnitWithNothingToReadSaysSo(): void
    {
        $html = $this->render($this->unit([]));

        $this->assertStringContainsString('no readings or PDFs yet', $html);
        $this->assertStringContainsString('data-ai-source-usable="0"', $html);
    }

    public function testTheDisclosureNoLongerPromisesNothingLeavesTheServerWhenAHostedProviderIsUsed(): void
    {
        \Tests\Support\TestEnv::set('questionBuilder.useJobs', 'true');

        try {
            $html = $this->render($this->unit([['id' => 1, 'title' => 'A', 'type' => 'reading', 'status' => 'published']]));
        } finally {
            \Tests\Support\TestEnv::restore();
        }

        $this->assertStringContainsString('sent to an external AI service (Groq)', $html);
        $this->assertStringNotContainsString('stays here', $html);
    }

    public function testTheModalOffersEveryTypeTheBuilderHasWithTheSameNames(): void
    {
        $html = $this->render(null);
        $palette = (string) file_get_contents(APPPATH . 'Views/pages/courses/features/assessments/partials/quiz-builder-palette.php');

        preg_match_all("/'type' => '([^']+)'/", $palette, $types);
        preg_match_all("/'description' => '([^']+)'/", $palette, $descriptions);

        $this->assertSame(['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'], $types[1]);

        foreach ($types[1] as $i => $type) {
            $this->assertStringContainsString('>' . $type . '</span>', $html, "{$type} is offered");
            $this->assertStringContainsString($descriptions[1][$i], $html, "{$type} has the builder's description");
        }

        foreach (['multiple_choice', 'multiple_answer', 'true_false', 'short_answer', 'essay', 'file_upload'] as $key) {
            $this->assertStringContainsString('data-type-key="' . $key . '"', $html);
        }
    }
}

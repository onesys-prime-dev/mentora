<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The Export modal of the quiz builder: two choices (an editable file, or a printable test), each with a picker of
 * only the formats the server can really write, and the print options under the printable one's picker.
 *
 * The script that drives it (export-modal.js) finds its parts by the data attributes checked here.
 *
 * @internal
 */
final class ExportQuestionsModalTest extends CIUnitTestCase
{
    private DOMXPath $xpath;

    private string $html;

    protected function setUp(): void
    {
        parent::setUp();

        $this->html = view('pages/courses/features/assessments/partials/modals/export-questions', ['currentAssessment' => ['id' => 9]], ['saveData' => false]);

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $this->html);
        $this->xpath = new DOMXPath($dom);
    }

    /** @return list<string> */
    private function values(string $xpath): array
    {
        $out = [];

        foreach ($this->xpath->query($xpath) as $node) {
            $out[] = $node->getAttribute('value');
        }

        return $out;
    }

    private function text(string $xpath): string
    {
        $node = $this->xpath->query($xpath)->item(0);

        return $node === null ? '' : preg_replace('/\s+/', ' ', trim($node->textContent));
    }

    public function testItIsADialogWithAHeadingAndWaysToClose(): void
    {
        $modal = $this->xpath->query('//*[@id="export-questions-modal"]')->item(0);

        $this->assertNotNull($modal);
        $this->assertSame('dialog', $modal->getAttribute('role'));
        $this->assertSame('true', $modal->getAttribute('aria-modal'));
        $this->assertStringContainsString('hidden', $modal->getAttribute('class'), 'closed until the icon is pressed');
        $this->assertSame('Export', $this->text('//*[@id="export-questions-title"]'));
        $this->assertGreaterThanOrEqual(2, $this->xpath->query('//*[@data-close-export-questions-modal]')->length, 'the X and Cancel');
    }

    public function testItOffersTwoChoicesAnEditableFileAndAPrintableTest(): void
    {
        $this->assertSame(['editable', 'printable'], $this->values('//input[@data-export-kind]'));

        $labels = $this->text('//label[input[@data-export-kind][@value="editable"]]') . '|' . $this->text('//label[input[@data-export-kind][@value="printable"]]');

        $this->assertStringContainsString('Editable file', $labels);
        $this->assertStringContainsString('Printable test', $labels);
    }

    public function testTheEditablePickerHasOnlyFormatsThatCanBeWrittenAndImportedBack(): void
    {
        $this->assertSame(
            ['docx', 'rtf', 'xlsx', 'csv', 'gift', 'aiken'],
            $this->values('//*[@data-export-formats="editable"]//input[@data-export-format-option]'),
        );
    }

    public function testThePrintablePickerHasOnlyPdfWordAndRichText(): void
    {
        $this->assertSame(
            ['test-pdf', 'test-docx', 'test-rtf'],
            $this->values('//*[@data-export-formats="printable"]//input[@data-export-format-option]'),
        );
    }

    public function testNoFormatTheServerCannotWriteIsOffered(): void
    {
        $all = array_map('strtolower', $this->values('//input[@data-export-format-option]'));

        foreach ($all as $value) {
            $this->assertDoesNotMatchRegularExpression('/zip|qti|xml|tst|html|blk|d2l|sakai/', $value, $value);
        }

        $labels = strtolower($this->text('//*[@id="export-questions-modal"]'));

        foreach (['qti', 'blackboard', 'd2l', 'sakai', '.tst', '.html', 'moodle.xml'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $labels, $dropped . ' is import-only, so it is not offered');
        }
    }

    public function testEveryFormatOptionIsLabelledWithItsFileType(): void
    {
        $this->assertSame('Word document (.docx)', $this->text('//label[input[@value="docx"]]'));
        $this->assertSame('Rich Text (.rtf)', $this->text('//label[input[@value="rtf"]]'));
        $this->assertSame('PDF (.pdf)', $this->text('//label[input[@value="test-pdf"]]'));
        $this->assertSame('GIFT (Moodle, .txt)', $this->text('//label[input[@value="gift"]]'));
        $this->assertSame('Aiken (.txt)', $this->text('//label[input[@value="aiken"]]'));
    }

    public function testThePrintOptionsAreUnderThePrintablePickerNotTheEditableOne(): void
    {
        $this->assertSame(1, $this->xpath->query('//*[@data-print-options]')->length);
        $this->assertSame(1, $this->xpath->query('//*[@data-export-formats="printable"]//*[@data-print-options]')->length, 'inside the printable choice');
        $this->assertSame(0, $this->xpath->query('//*[@data-export-formats="editable"]//*[@data-print-options]')->length);

        // After the format list, in the page's order.
        $this->assertLessThan(strpos($this->html, 'data-print-options'), strpos($this->html, 'value="test-rtf"'));
    }

    public function testThePrintOptionsAreVersionsPaperAndTheThreeCheckboxes(): void
    {
        $this->assertSame(['1', '2', '3', '4'], $this->values('//*[@data-print-options]//select[@data-print-versions]/option'));
        $this->assertSame(['A4', 'Letter', 'Legal'], $this->values('//*[@data-print-options]//select[@data-print-paper]/option'));

        $this->assertSame('Versions', $this->text('//*[@data-print-options]//label[select[@data-print-versions]]/span'));
        $this->assertSame('Paper', $this->text('//*[@data-print-options]//label[select[@data-print-paper]]/span'));
        $this->assertSame('Shuffle the order of questions', $this->text('//*[@data-print-options]//label[input[@data-print-shuffle-questions]]'));
        $this->assertSame('Shuffle the choices', $this->text('//*[@data-print-options]//label[input[@data-print-shuffle-choices]]'));
        $this->assertSame('Answer key only', $this->text('//*[@data-print-options]//label[input[@data-print-key-only]]'));
        $this->assertSame(3, $this->xpath->query('//*[@data-print-options]//input[@type="checkbox"]')->length);
    }

    public function testItCarriesTheAssessmentAndHasAnExportButton(): void
    {
        $button = $this->xpath->query('//button[@data-export-questions]')->item(0);

        $this->assertNotNull($button);
        $this->assertSame('9', $button->getAttribute('data-assessment-id'));
        $this->assertSame('Export', $this->text('//button[@data-export-questions]'));
    }
}

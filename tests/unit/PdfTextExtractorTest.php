<?php

declare(strict_types=1);

use App\Services\AI\LocalQuestionBuilderClient;
use App\Services\AI\PdfTextExtractor;
use App\Services\AI\QuestionGeneratorUnavailableException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reading a PDF's text layer in PHP, with page markers, and what a scan or a
 * broken file says. The PDFs are built here so no fixture file is needed.
 *
 * @internal
 */
final class PdfTextExtractorTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * A minimal valid PDF with one line of text per page (an empty string is a
     * page with no text, like a scan).
     *
     * @param list<string> $pageTexts
     */
    private function pdf(array $pageTexts): string
    {
        $objects = [];
        $kids    = [];
        $count   = count($pageTexts);

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ($pageTexts as $i => $text) {
            $page    = 4 + $i * 2;
            $content = 5 + $i * 2;
            $kids[]  = "{$page} 0 R";
            $stream  = $text === '' ? '' : 'BT /F1 12 Tf 72 720 Td (' . $text . ') Tj ET';

            $objects[$page]    = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$content} 0 R /Resources << /Font << /F1 3 0 R >> >> >>";
            $objects[$content] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count {$count} >>";
        ksort($objects);

        $out     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($out);
        $size = max(array_keys($objects)) + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";

        for ($n = 1; $n < $size; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }

        return $out . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function file(string $contents, string $extension = 'pdf'): string
    {
        $path = sys_get_temp_dir() . '/pdf-test-' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($path, $contents);

        return $this->made[] = $path;
    }

    public function testTextIsReadPageByPageWithPageMarkers(): void
    {
        $path = $this->file($this->pdf([
            'Routers forward packets using the destination address.',
            'TCP gives reliable ordered delivery with acknowledgements.',
        ]));

        $result = (new PdfTextExtractor())->extract($path);

        $this->assertSame(2, $result['pages']);
        $this->assertFalse($result['used_ocr']);
        $this->assertStringContainsString("[[page 1]]\nRouters forward packets", $result['text']);
        $this->assertStringContainsString("[[page 2]]\nTCP gives reliable", $result['text']);
    }

    public function testAnEmptyPageKeepsTheNumberingOfTheOthers(): void
    {
        $path = $this->file($this->pdf(['First page has plenty of readable words in it.', '', 'Third page also has plenty of readable words.']));

        $text = (new PdfTextExtractor())->extract($path)['text'];

        $this->assertStringContainsString('[[page 1]]', $text);
        $this->assertStringNotContainsString('[[page 2]]', $text);
        $this->assertStringContainsString('[[page 3]]', $text);
    }

    public function testAFileWithNoTextLayerSaysItLooksScanned(): void
    {
        $path = $this->file($this->pdf(['', '']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no selectable text');

        (new PdfTextExtractor())->extract($path);
    }

    public function testSomethingThatIsNotAPdfSaysSo(): void
    {
        $path = $this->file('this is just some text, not a pdf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be opened as a PDF');

        (new PdfTextExtractor())->extract($path);
    }

    public function testAMissingFileSaysSo(): void
    {
        $this->expectException(\RuntimeException::class);

        (new PdfTextExtractor())->extract('/no/such/file.pdf');
    }

    public function testTheClientReadsAPdfInPhpWithoutAnyServiceRunning(): void
    {
        $path = $this->file($this->pdf(['Photosynthesis converts light energy into chemical energy in plants.']));

        // A port nothing listens on: the original service is not needed for a PDF with text.
        $client = new LocalQuestionBuilderClient('http://127.0.0.1:1');

        $result = $client->extractText($path, 'application/pdf');

        $this->assertStringContainsString('Photosynthesis converts light energy', $result['text']);
        $this->assertSame(1, $result['pages']);
    }

    public function testAScanWithNoOcrServiceGivesTheFilesOwnReasonNotAConnectionError(): void
    {
        $path = $this->file($this->pdf(['']));
        $client = new LocalQuestionBuilderClient('http://127.0.0.1:1');

        try {
            $client->extractText($path, 'application/pdf');
            $this->fail('A scan cannot be read without OCR.');
        } catch (QuestionGeneratorUnavailableException $e) {
            $this->assertStringContainsString('no selectable text', $e->getMessage());
            $this->assertStringNotContainsString('Unable to reach', $e->getMessage());
        }
    }
}

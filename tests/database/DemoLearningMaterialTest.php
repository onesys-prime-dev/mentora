<?php

declare(strict_types=1);

use App\Database\Seeds\DemoContentSeeder;
use App\Database\Seeds\DemoPdf;
use App\Database\Seeds\Material\Material;
use App\Services\AI\PdfTextExtractor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The seeded readings and handouts are real course material: every title the
 * seeders use has a body, readings are chapter-length and valid for the
 * editor, and handouts are multi-page PDFs whose text can be read back.
 *
 * @internal
 */
final class DemoLearningMaterialTest extends CIUnitTestCase
{
    private const READING_TITLES = [
        'Welcome to Introduction to Computing', 'Hardware and Software', 'How Computers Represent Data',
        'What Is a Variable?', 'Making Decisions with If Statements', 'Repeating with Loops', 'What Is a Database?',
        'Your First SELECT Query', 'Welcome to Section B', 'Your First PHP Script', 'Primary and Foreign Keys',
    ];

    private const PDF_TITLES = ['Parts of a Computer', 'Data Types Cheat Sheet', 'XAMPP Installation Guide', 'PHP Variables Reference'];

    private function words(array $blocks): int
    {
        $text = '';

        foreach ($blocks as [$type, $value]) {
            $text .= ' ' . (is_array($value) ? implode(' ', $value) : $value);
        }

        return count(preg_split('/\s+/u', trim($text)) ?: []);
    }

    private function doc(array $blocks): array
    {
        $seeder = new DemoContentSeeder(config('Database'));
        $method = new ReflectionMethod($seeder, 'doc');
        $method->setAccessible(true);

        return json_decode((string) $method->invoke($seeder, $blocks), true);
    }

    public function testEveryTitleTheSeedersUseHasAReadingOrAHandout(): void
    {
        foreach (self::READING_TITLES as $title) {
            $this->assertArrayHasKey($title, Material::readings(), $title);
        }

        foreach (self::PDF_TITLES as $title) {
            $this->assertArrayHasKey($title, Material::pdfs(), $title);
        }
    }

    public function testEveryReadingInTheSeederCatalogHasMaterial(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Database/Seeds/DemoContentSeeder.php');

        preg_match_all("/'type' => 'reading', 'title' => '((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

        $this->assertCount(count(self::READING_TITLES), $matches[1]);

        foreach ($matches[1] as $title) {
            $this->assertArrayHasKey(stripslashes($title), Material::readings(), $title);
        }
    }

    public function testReadingsAreChapterLength(): void
    {
        foreach (Material::readings() as $title => $blocks) {
            $this->assertGreaterThanOrEqual(700, $this->words($blocks), "{$title} is too short");
        }
    }

    public function testReadingsOnlyUseWhatTheEditorCanShow(): void
    {
        foreach (Material::readings() as $title => $blocks) {
            $this->assertSame('h', $blocks[0][0], "{$title} starts with a heading");

            foreach ($blocks as [$type, $value]) {
                $this->assertContains($type, ['h', 'p', 'ul', 'ol', 'code'], "{$title}: {$type}");

                if (in_array($type, ['ul', 'ol'], true)) {
                    $this->assertNotEmpty($value, $title);
                    $this->assertContainsOnly('string', $value, true, $title);
                } else {
                    $this->assertIsString($value, $title);
                    $this->assertNotSame('', trim($value), $title);
                }
            }
        }
    }

    public function testTheSeederTurnsAReadingIntoValidEditorJson(): void
    {
        foreach (Material::readings() as $title => $blocks) {
            $doc = $this->doc($blocks);

            $this->assertSame(1, $doc['version']);

            $ids = array_column($doc['blocks'], 'id');
            $this->assertSame($ids, array_values(array_unique($ids)), "{$title} has unique block ids");

            foreach ($doc['blocks'] as $block) {
                $this->assertContains($block['type'], ['heading', 'paragraph', 'unordered-list', 'ordered-list']);

                if ($block['type'] === 'heading') {
                    $this->assertContains($block['level'], [1, 2]);
                }

                $runs = isset($block['items']) ? array_merge(...$block['items']) : $block['content'];

                foreach ($runs as $run) {
                    $this->assertArrayHasKey('text', $run);
                    $this->assertStringNotContainsString('**', $run['text'], $title);
                    $this->assertStringNotContainsString('`', $run['text'], $title);

                    foreach ($run['marks'] ?? [] as $mark) {
                        $this->assertContains($mark, ['bold', 'italic', 'underline']);
                    }
                }
            }
        }
    }

    public function testBoldMarkupBecomesBoldRuns(): void
    {
        $doc = $this->doc([['h', 'T'], ['p', 'A **bold** and ~~slanted~~ word.']]);

        $this->assertSame(
            [['text' => 'A '], ['text' => 'bold', 'marks' => ['bold']], ['text' => ' and '], ['text' => 'slanted', 'marks' => ['italic']], ['text' => ' word.']],
            $doc['blocks'][1]['content']
        );
    }

    public function testACodeSampleBecomesOneParagraphPerLine(): void
    {
        $doc = $this->doc([['h', 'T'], ['code', "a = 1;\nb = 2;"]]);

        $this->assertCount(3, $doc['blocks']);
        $this->assertSame('paragraph', $doc['blocks'][1]['type']);
        $this->assertSame('b = 2;', $doc['blocks'][2]['content'][0]['text']);
    }

    public function testHandoutsRenderAsMultiPagePdfsWithReadableText(): void
    {
        $dir = sys_get_temp_dir() . '/handouts-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $extractor = new PdfTextExtractor();

        foreach (Material::pdfs() as $title => $pdf) {
            $path = $dir . '/' . md5($title) . '.pdf';
            file_put_contents($path, DemoPdf::render($title, $pdf['subtitle'], $pdf['sections']));

            $read = $extractor->extract($path);

            $this->assertGreaterThanOrEqual(4, $read['pages'], "{$title} should be several pages");
            $this->assertStringContainsString('[[page 2]]', $read['text'], $title);
            $this->assertStringContainsString($title, $read['text'], $title);
            $this->assertGreaterThan(2500, strlen($read['text']), "{$title} has too little text");

            @unlink($path);
        }

        @rmdir($dir);
    }

    public function testThePdfHtmlEscapesAndMarksUpInlineText(): void
    {
        $html = DemoPdf::html('A & B', 'sub', [['p', 'x < y with **bold** and ~~it~~'], ['code', '<?php echo 1;']]);

        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('x &lt; y with <strong>bold</strong> and <em>it</em>', $html);
        $this->assertStringContainsString('&lt;?php echo 1;', $html);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

use Smalot\PdfParser\Parser;

/**
 * Reads the text layer of a PDF in PHP, so a learning item's PDF becomes
 * source text for question generation without any other service running.
 *
 * Pages keep their numbers as `[[page N]]` lines, which the question builder
 * uses to say where a fact came from. A scanned PDF has no text layer and
 * yields nothing here; reading it needs OCR, which the original service does.
 */
final class PdfTextExtractor
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_PAGES = 150;

    /** Fewer characters than this over the whole file counts as "no text layer". */
    private const MIN_CHARACTERS = 40;

    /**
     * @return array{text: string, pages: int, used_ocr: bool}
     *
     * @throws \RuntimeException when the file cannot be read, with a message fit to show
     */
    public function extract(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('The file is missing.');
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException('The PDF is larger than ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        try {
            $document = (new Parser())->parseFile($path);
            $pages    = $document->getPages();
        } catch (\Throwable $e) {
            throw new \RuntimeException('The file could not be opened as a PDF.', 0, $e);
        }

        $parts = [];
        $total = 0;

        foreach (array_slice($pages, 0, self::MAX_PAGES) as $index => $page) {
            try {
                $text = trim((string) preg_replace('/[ \t]+/', ' ', $page->getText()));
            } catch (\Throwable) {
                $text = '';
            }

            if ($text === '') {
                continue;
            }

            $total  += mb_strlen($text);
            $parts[] = '[[page ' . ($index + 1) . "]]\n" . $text;
        }

        if ($total < self::MIN_CHARACTERS) {
            throw new \RuntimeException('This PDF has no selectable text (it looks like a scan), so it cannot be read here.');
        }

        return ['text' => implode("\n\n", $parts), 'pages' => count($pages), 'used_ocr' => false];
    }
}

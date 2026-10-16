<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a demo handout as a real, multi-page PDF with a text layer, so the
 * viewer shows something worth reading and the AI question builder has
 * something to digest (it cites `[[page N]]` from the text it extracts).
 *
 * Sections are plain arrays, the same few shapes the reading seeder uses:
 *
 *   ['h', 'Heading']                  a section heading
 *   ['h3', 'Sub-heading']             a smaller heading
 *   ['p', 'Paragraph with **bold** and ~~italic~~']
 *   ['ul', ['item', 'item']]          bullets
 *   ['ol', ['step', 'step']]          numbered steps
 *   ['note', 'A highlighted aside']
 *   ['code', "line one\nline two"]    monospaced block
 *   ['table', ['head' => [...], 'rows' => [[...], ...]]]
 *   ['pagebreak']
 */
final class DemoPdf
{
    /**
     * @param list<array<int, mixed>> $sections
     */
    public static function render(string $title, string $subtitle, array $sections): string
    {
        $cache = WRITEPATH . 'cache/dompdf';

        if (! is_dir($cache)) {
            mkdir($cache, 0777, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', WRITEPATH);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($title, $subtitle, $sections), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param list<array<int, mixed>> $sections
     */
    public static function html(string $title, string $subtitle, array $sections): string
    {
        $body = '<div class="cover"><h1>' . self::e($title) . '</h1><p class="sub">' . self::e($subtitle) . '</p></div>';

        foreach ($sections as $section) {
            $type = (string) $section[0];
            $value = $section[1] ?? null;

            $body .= match ($type) {
                'h'         => '<h2>' . self::inline((string) $value) . '</h2>',
                'h3'        => '<h3>' . self::inline((string) $value) . '</h3>',
                'p'         => '<p>' . self::inline((string) $value) . '</p>',
                'note'      => '<div class="note">' . self::inline((string) $value) . '</div>',
                'code'      => '<pre>' . self::e((string) $value) . '</pre>',
                'ul'        => '<ul>' . implode('', array_map(static fn (string $i): string => '<li>' . self::inline($i) . '</li>', (array) $value)) . '</ul>',
                'ol'        => '<ol>' . implode('', array_map(static fn (string $i): string => '<li>' . self::inline($i) . '</li>', (array) $value)) . '</ol>',
                'table'     => self::table((array) $value),
                'pagebreak' => '<div class="break"></div>',
                default     => '',
            };
        }

        $css = <<<'CSS'
            @page { margin: 24mm 20mm 22mm 20mm; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; line-height: 1.45; color: #1a1c1e; }
            .cover { margin: 40mm 0 18mm 0; padding-bottom: 8mm; border-bottom: 2px solid #0040a6; }
            h1 { font-size: 24pt; color: #0040a6; margin: 0 0 6pt; }
            .sub { color: #555; font-size: 12pt; margin: 0; }
            h2 { font-size: 15pt; color: #0040a6; margin: 18pt 0 6pt; page-break-after: avoid; }
            h3 { font-size: 12pt; color: #1a1c1e; margin: 12pt 0 4pt; page-break-after: avoid; }
            p { margin: 0 0 8pt; text-align: justify; }
            ul, ol { margin: 0 0 8pt 18pt; padding: 0; }
            li { margin-bottom: 3pt; }
            .note { border-left: 3px solid #0040a6; background: #f2f7ff; padding: 6pt 9pt; margin: 6pt 0 10pt; }
            pre { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; background: #f4f4f4; border: 1px solid #ddd; padding: 7pt; margin: 4pt 0 10pt; white-space: pre-wrap; }
            table { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font-size: 9.5pt; }
            th { background: #e0e5f2; text-align: left; padding: 4pt 6pt; border: 1px solid #b8c0d6; }
            td { padding: 4pt 6pt; border: 1px solid #ccd; vertical-align: top; }
            .break { page-break-after: always; }
            .footer { position: fixed; bottom: -14mm; left: 0; right: 0; text-align: center; color: #777; font-size: 8pt; }
            .footer:after { content: "Page " counter(page); }
            CSS;

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
            . '<div class="footer">' . self::e($title) . ' &middot; </div>' . $body . '</body></html>';
    }

    /** @param array<string, mixed> $table */
    private static function table(array $table): string
    {
        $html = '<table>';

        if (! empty($table['head'])) {
            $html .= '<tr>' . implode('', array_map(static fn (string $c): string => '<th>' . self::inline($c) . '</th>', $table['head'])) . '</tr>';
        }

        foreach ((array) ($table['rows'] ?? []) as $row) {
            $html .= '<tr>' . implode('', array_map(static fn (string $c): string => '<td>' . self::inline($c) . '</td>', (array) $row)) . '</tr>';
        }

        return $html . '</table>';
    }

    /** Escapes, then turns **bold** and ~~italic~~ into tags. */
    private static function inline(string $text): string
    {
        $text = self::e($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);

        return (string) preg_replace('/~~(.+?)~~/s', '<em>$1</em>', $text);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

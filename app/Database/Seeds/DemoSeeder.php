<?php

namespace App\Database\Seeds;

use App\Services\Storage\StorageService;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Shared helpers for the demo content seeders.
 *
 * DEMO DATA ONLY — these seeders fill a fresh database so every feature has
 * something to show. They are not client data and assume empty tables:
 *
 *   php spark migrate:refresh --all
 *   php spark db:seed DatabaseSeeder
 *
 * Nothing here hard-codes a row id: rows are found by natural keys (user
 * email, course code + section, titles within their parent), and every date
 * is relative to the moment of seeding, so the demo always has upcoming,
 * due-soon and overdue work.
 */
abstract class DemoSeeder extends Seeder
{
    private static array $cache = [];

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    protected function userId(string $email): int
    {
        return self::$cache["user:{$email}"] ??= (int) ($this->db->table('auth_identities')
            ->select('user_id')
            ->where(['type' => 'email_password', 'secret' => $email])
            ->get()->getRowArray()['user_id'] ?? $this->missing("user {$email}"));
    }

    protected function courseId(string $courseCode): int
    {
        return self::$cache["course:{$courseCode}"] ??= (int) ($this->db->table('courses')
            ->select('id')->where('course_code', $courseCode)
            ->get()->getRowArray()['id'] ?? $this->missing("course {$courseCode}"));
    }

    protected function offeringId(string $courseCode, string $section): int
    {
        return self::$cache["offering:{$courseCode}-{$section}"] ??= (int) ($this->db->table('course_offerings')
            ->select('id')
            ->where(['course_id' => $this->courseId($courseCode), 'section_code' => $section])
            ->get()->getRowArray()['id'] ?? $this->missing("offering {$courseCode}-{$section}"));
    }

    protected function termId(string $name, string $academicYear, int $institutionId = 1): int
    {
        return (int) ($this->db->table('academic_terms')
            ->select('id')
            ->where(['institution_id' => $institutionId, 'name' => $name, 'academic_year' => $academicYear])
            ->get()->getRowArray()['id'] ?? $this->missing("term {$name} {$academicYear}"));
    }

    protected function fileId(string $storageKey): int
    {
        return (int) ($this->db->table('files')
            ->select('id')->where('storage_key', $storageKey)
            ->get()->getRowArray()['id'] ?? $this->missing("file {$storageKey}"));
    }

    /**
     * Learning item by title within one offering.
     */
    protected function learningItemId(string $courseCode, string $section, string $title): int
    {
        return (int) ($this->db->table('learning_items')
            ->select('learning_items.id')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
            ->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('course_modules.offering_id', $this->offeringId($courseCode, $section))
            ->where('learning_items.title', $title)
            ->get()->getRowArray()['id'] ?? $this->missing("learning item {$title}"));
    }

    /**
     * Assessment by title within one offering (titles repeat across sections).
     */
    protected function assessmentId(string $courseCode, string $section, string $title): int
    {
        return (int) ($this->db->table('assessments')
            ->select('assessments.id')
            ->join('course_modules', 'course_modules.id = assessments.module_id')
            ->where('course_modules.offering_id', $this->offeringId($courseCode, $section))
            ->where('assessments.title', $title)
            ->get()->getRowArray()['id'] ?? $this->missing("assessment {$title}"));
    }

    private function missing(string $what): never
    {
        throw new RuntimeException("Demo seed data is missing {$what} — run the full DatabaseSeeder on a fresh database.");
    }

    /*
    |--------------------------------------------------------------------------
    | Dates
    |--------------------------------------------------------------------------
    */

    /**
     * A datetime relative to now: at('-3 days'), at('+2 hours').
     */
    protected function at(string $offset = 'now'): string
    {
        return date('Y-m-d H:i:s', strtotime($offset));
    }

    /*
    |--------------------------------------------------------------------------
    | Content helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A reading / instructions document in the block editor's format.
     *
     * @param list<array{0: string, 1: string|list<string>}> $blocks
     *   ['h', 'Heading'] | ['p', 'Paragraph'] | ['ul', ['a', 'b']] | ['ol', [...]]
     */
    protected function doc(array $blocks): string
    {
        $out = [];

        foreach ($blocks as $index => [$type, $value]) {

            $id = sprintf('block_%03d', $index + 1);

            $out[] = match ($type) {
                'h' => ['id' => $id, 'type' => 'heading', 'level' => $index === 0 ? 1 : 2, 'align' => 'left', 'content' => [['text' => $value]]],
                'ul', 'ol' => [
                    'id' => $id,
                    'type' => $type === 'ul' ? 'unordered-list' : 'ordered-list',
                    'align' => 'left',
                    'items' => array_map(static fn (string $item) => [['text' => $item]], $value),
                ],
                default => ['id' => $id, 'type' => 'paragraph', 'align' => 'justify', 'content' => [['text' => $value]]],
            };

        }

        return json_encode(['version' => 1, 'blocks' => $out], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Writes a small, real, text-based PDF into storage (so AI generation
     * and the PDF viewer have something to read) and records it in `files`.
     *
     * @param list<string> $lines
     */
    protected function makePdf(string $storageKey, string $originalName, string $title, array $lines, int $ownerId): int
    {
        $path = (new StorageService())->path($storageKey);

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $this->renderPdf($title, $lines));

        $this->db->table('files')->insert([
            'owner_user_id' => $ownerId,
            'original_filename' => $originalName,
            'stored_filename' => basename($storageKey),
            'mime_type' => 'application/pdf',
            'file_size' => filesize($path),
            'storage_key' => $storageKey,
            'status' => 'active',
            'uploaded_at' => $this->at(),
            'updated_at' => $this->at(),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * One-page PDF: a title and wrapped Helvetica text lines.
     */
    private function renderPdf(string $title, array $lines): string
    {
        $escape = static fn (string $text) => str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            preg_replace('/[^\x20-\x7E]/', '-', $text)
        );

        $stream = "BT\n/F1 18 Tf\n72 740 Td\n(" . $escape($title) . ") Tj\n/F1 11 Tf\n15 TL\n0 -30 Td\n";

        foreach ($lines as $line) {

            foreach (explode("\n", wordwrap($line, 88, "\n", true) ?: ' ') as $wrapped) {
                $stream .= '(' . $escape($wrapped) . ") Tj T*\n";
            }

            $stream .= "T*\n";

        }

        $stream .= "ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);

        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

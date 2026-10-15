<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use ZipArchive;

/**
 * Reads the question files out of a zip package (Blackboard, D2L, Sakai,
 * WebCT/Vista, Moodle backups of XML). Nothing is extracted to disk, only
 * the XML members are read into memory, and a package that is too big, has
 * too many entries or names a path outside itself is refused.
 */
final class QuizPackage
{
    public const MAX_ENTRIES = 600;
    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
    public const MAX_MEMBER_BYTES = 8 * 1024 * 1024;

    /** @var list<string> member extensions that can hold questions */
    private const READABLE = ['xml', 'dat'];

    /**
     * @return array{members: array<string, string>, manifest: ?string, flavour: string}|array{error: string}
     */
    public function open(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return ['error' => 'This zip file could not be opened. Check that it is not damaged.'];
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            return ['error' => 'This package has more than ' . self::MAX_ENTRIES . ' files, which is more than a quiz export has.'];
        }

        $total = 0;
        $members = [];
        $manifest = null;
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];

            // Paths that climb out, absolute paths and drive letters are never legitimate in a quiz export.
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
                $zip->close();

                return ['error' => 'This package contains unsafe file paths, so it was not opened.'];
            }

            $total += (int) $stat['size'];
            $names[] = $name;

            if ($total > self::MAX_TOTAL_BYTES) {
                $zip->close();

                return ['error' => 'This package is larger than a quiz export should be once unpacked.'];
            }

            if (substr($name, -1) === '/') {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (! in_array($extension, self::READABLE, true) || (int) $stat['size'] > self::MAX_MEMBER_BYTES) {
                continue;
            }

            $content = $zip->getFromIndex($i);

            if ($content === false) {
                continue;
            }

            if (strtolower(basename($name)) === 'imsmanifest.xml') {
                $manifest = $content;

                continue;
            }

            $members[$name] = $content;
        }

        $zip->close();

        return ['members' => $members, 'manifest' => $manifest, 'flavour' => $this->flavour($names, $manifest, $members)];
    }

    /**
     * Which system wrote the package, for the "this is a Blackboard pool"
     * line in the preview.
     *
     * @param list<string>          $names
     * @param array<string, string> $members
     */
    private function flavour(array $names, ?string $manifest, array $members): string
    {
        $head = (string) $manifest;
        $all = implode(' ', $names);

        if (str_contains($head, 'blackboard.com') || str_contains($head, 'bb:file') || str_contains($all, '.bb-package')) {
            return 'Blackboard';
        }

        if (str_contains($head, 'desire2learn') || str_contains($head, 'd2l_2p0')) {
            return 'D2L Brightspace';
        }

        if (str_contains($head, 'webct') || str_contains($all, 'x-webct-vista')) {
            return 'WebCT / Blackboard Vista';
        }

        foreach ($members as $content) {
            if (str_contains(substr($content, 0, 3000), 'TEXT_FORMAT') || str_contains($head, 'imsqti_xmlv1p1')) {
                return 'Sakai';
            }

            break;
        }

        return 'QTI';
    }
}

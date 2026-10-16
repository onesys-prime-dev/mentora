<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use ZipArchive;

/**
 * Recognises a Pearson TestGen test file (.tst): a zip holding a proprietary
 * binary and a small XML of counts. The binary keeps question text, answer
 * choices and the key as scattered records that cannot be put back together
 * reliably, so the file is recognised and explained rather than imported:
 * importing choices under the wrong question would be worse than not
 * importing. Every other TestGen export (Word, RTF, PDF, Moodle, QTI) reads.
 */
final class TestGenFile
{
    /**
     * @return array{title: string, questions: int, multipleChoice: int}|null null when this is not a TestGen file
     */
    public static function describe(string $path): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return null;
        }

        $xml = null;
        $binary = false;

        for ($i = 0; $i < min($zip->numFiles, 20); $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'tst') {
                $binary = true;
            }

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xml') {
                $stat = $zip->statIndex($i);

                if ($stat !== false && (int) $stat['size'] < 262144) {
                    $xml = $zip->getFromIndex($i) ?: null;
                }
            }
        }

        $zip->close();

        if (! $binary || $xml === null || stripos($xml, '<testgen') === false) {
            return null;
        }

        $doc = XmlText::load($xml);
        $questions = 0;
        $mc = 0;

        if ($doc !== null) {
            foreach ($doc->getElementsByTagName('questions') as $node) {
                $questions = (int) $node->getAttribute('number');
                $mc = (int) $node->getAttribute('typemc');

                break;
            }
        }

        return [
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'questions' => $questions,
            'multipleChoice' => $mc,
        ];
    }

    /** The plain-words refusal for a TestGen file. */
    public static function message(array $info): string
    {
        $count = $info['questions'] > 0 ? $info['questions'] . ' question' . ($info['questions'] === 1 ? '' : 's') : 'questions';

        return "This is a Pearson TestGen test file ({$count}). TestGen keeps the answer choices and the key in a format "
            . 'that cannot be read reliably, so nothing was imported rather than risk choices under the wrong question. '
            . 'In TestGen, export the same test as Word, RTF, PDF, Moodle XML or QTI for Blackboard, D2L, Sakai or WebCT: every one of those imports here.';
    }
}

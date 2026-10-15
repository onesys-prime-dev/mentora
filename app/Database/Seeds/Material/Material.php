<?php

declare(strict_types=1);

namespace App\Database\Seeds\Material;

use RuntimeException;

/**
 * The demo course material: the body of every seeded reading and PDF,
 * written once here and found by the item's title. The seeders keep the
 * structure (modules, units, order, prerequisites); this is only the words.
 *
 * Reading blocks use the seeder's shorthand:
 *
 *   ['h', 'Heading'] ['p', 'Text with **bold** and ~~italic~~']
 *   ['ul', [...]] ['ol', [...]]
 *
 * A PDF is ['file' => 'Name.pdf', 'subtitle' => '...', 'sections' => [...]]
 * (see DemoPdf for the section shapes).
 */
final class Material
{
    /** @var list<class-string> */
    private const READINGS = [
        IntroComputingReadings::class,
        ProgrammingReadings::class,
        DatabaseReadings::class,
    ];

    /** @var list<class-string> */
    private const PDFS = [
        HandoutPdfs::class,
    ];

    /**
     * @return array<string, list<array{0: string, 1: string|list<string>}>>
     */
    public static function readings(): array
    {
        $all = [];

        foreach (self::READINGS as $class) {
            $all += $class::all();
        }

        return $all;
    }

    /**
     * @return array<string, array{file: string, subtitle: string, sections: list<array<int, mixed>>}>
     */
    public static function pdfs(): array
    {
        $all = [];

        foreach (self::PDFS as $class) {
            $all += $class::all();
        }

        return $all;
    }

    /**
     * @return list<array{0: string, 1: string|list<string>}>
     */
    public static function reading(string $title): array
    {
        return self::readings()[$title]
            ?? throw new RuntimeException("No demo reading is written for \"{$title}\".");
    }

    /**
     * @return array{file: string, subtitle: string, sections: list<array<int, mixed>>}
     */
    public static function pdf(string $title): array
    {
        return self::pdfs()[$title]
            ?? throw new RuntimeException("No demo PDF is written for \"{$title}\".");
    }
}

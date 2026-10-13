<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * An institution's two brand colours and the rules that keep every screen
 * readable whatever they pick (WCAG 2.x AA, 4.5:1 for normal text).
 *
 * The primary colour carries white text (buttons) and is itself used as text
 * on white and on the pale tint (links, headings), so it must reach 4.5:1
 * against the tint, which is the harder of the two. The accent carries white
 * text and must reach 4.5:1 against white. A colour that fails is refused
 * with the ratio it would give, never silently changed.
 *
 * The hover colour and the tint are derived from the primary, so choosing one
 * colour restyles the whole interface consistently.
 *
 * Pure: no database, no clock.
 */
final class BrandColours
{
    public const MIN_RATIO = 4.5;

    public const DEFAULT_PRIMARY = '#0040A6';

    public const DEFAULT_ACCENT = '#2E7D4F';

    /** How much of the primary the pale tint keeps (the rest is white). */
    private const TINT_STRENGTH = 0.05;

    /** The factor the hover colour scales each channel by. */
    private const DARKEN = 0.62;

    /**
     * #RRGGBB in capitals from "#abc", "abc", "0040a6" or "#0040A6"; null when
     * it is not a colour written in hex.
     */
    public static function normalise(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        $value = ltrim($value, '#');

        if (preg_match('/^[0-9A-F]{3}$/', $value) === 1) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        return preg_match('/^[0-9A-F]{6}$/', $value) === 1 ? '#' . $value : null;
    }

    /**
     * WCAG relative luminance, 0 (black) to 1 (white).
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        $lin = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** WCAG contrast ratio, 1 to 21, of two colours. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** The hover colour: the primary, darker. */
    public static function dark(string $primary): string
    {
        [$r, $g, $b] = self::channels($primary);

        return self::hex((int) round($r * self::DARKEN), (int) round($g * self::DARKEN), (int) round($b * self::DARKEN));
    }

    /** The pale background: the primary, mostly white. */
    public static function tint(string $primary): string
    {
        [$r, $g, $b] = self::channels($primary);
        $mix = static fn (int $c): int => (int) round($c * self::TINT_STRENGTH + 255 * (1 - self::TINT_STRENGTH));

        return self::hex($mix($r), $mix($g), $mix($b));
    }

    /**
     * Why this cannot be the primary colour, or null when it can.
     */
    public static function primaryProblem(?string $value): ?string
    {
        $hex = self::normalise($value);

        if ($hex === null) {
            return 'Write the colour as six hex digits, such as #0040A6.';
        }

        $ratio = self::contrast($hex, self::tint($hex));

        if ($ratio < self::MIN_RATIO) {
            return sprintf(
                'That colour is too light: text in it on the pale background would have a contrast of %.1f:1, and at least %.1f:1 is needed so people can read it. Choose a darker colour.',
                $ratio,
                self::MIN_RATIO,
            );
        }

        return null;
    }

    /**
     * Why this cannot be the accent colour, or null when it can.
     */
    public static function accentProblem(?string $value): ?string
    {
        $hex = self::normalise($value);

        if ($hex === null) {
            return 'Write the colour as six hex digits, such as #2E7D4F.';
        }

        $ratio = self::contrast($hex, '#FFFFFF');

        if ($ratio < self::MIN_RATIO) {
            return sprintf(
                'That colour is too light: white text on it would have a contrast of %.1f:1, and at least %.1f:1 is needed so people can read it. Choose a darker colour.',
                $ratio,
                self::MIN_RATIO,
            );
        }

        return null;
    }

    /**
     * The custom properties that put an institution's colours in force, or an
     * empty string when it has set none (it then looks like Mentora).
     */
    public static function css(?string $primary, ?string $accent): string
    {
        $rules = [];
        $p     = self::normalise($primary);
        $a     = self::normalise($accent);

        if ($p !== null && self::primaryProblem($p) === null) {
            $rules[] = '--color-brand:' . $p;
            $rules[] = '--color-brand-dark:' . self::dark($p);
            $rules[] = '--color-brand-tint:' . self::tint($p);
        }

        if ($a !== null && self::accentProblem($a) === null) {
            $rules[] = '--color-accent:' . $a;
        }

        return $rules === [] ? '' : ':root{' . implode(';', $rules) . '}';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}

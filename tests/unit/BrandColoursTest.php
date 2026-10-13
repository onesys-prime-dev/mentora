<?php

declare(strict_types=1);

use App\Services\Institutions\BrandColours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The brand colour rules: hex handling, WCAG contrast, the derived hover
 * colour and tint, what is refused and why, and the custom properties that
 * put a pair of colours in force.
 *
 * @internal
 */
final class BrandColoursTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function provideHex(): iterable
    {
        yield 'upper case' => ['#0040A6', '#0040A6'];
        yield 'lower case' => ['#0040a6', '#0040A6'];
        yield 'without the hash' => ['0040a6', '#0040A6'];
        yield 'short form' => ['#abc', '#AABBCC'];
        yield 'spaces around' => ['  #0040A6 ', '#0040A6'];
        yield 'a name' => ['blue', null];
        yield 'too short' => ['#12345', null];
        yield 'too long' => ['#1234567', null];
        yield 'not hex digits' => ['#GGGGGG', null];
        yield 'nothing' => [null, null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('provideHex')]
    public function testHexIsNormalisedOrRefused(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, BrandColours::normalise($input));
    }

    public function testContrastMatchesTheWcagDefinition(): void
    {
        $this->assertEqualsWithDelta(21.0, BrandColours::contrast('#000000', '#FFFFFF'), 0.001);
        $this->assertEqualsWithDelta(1.0, BrandColours::contrast('#FFFFFF', '#FFFFFF'), 0.001);
        $this->assertEqualsWithDelta(BrandColours::contrast('#0040A6', '#FFFFFF'), BrandColours::contrast('#FFFFFF', '#0040A6'), 0.0001, 'order does not matter');
        $this->assertEqualsWithDelta(1.0, BrandColours::luminance('#FFFFFF'), 0.0001);
        $this->assertEqualsWithDelta(0.0, BrandColours::luminance('#000000'), 0.0001);
        // The published figure for #767676 on white is 4.54:1.
        $this->assertEqualsWithDelta(4.54, BrandColours::contrast('#767676', '#FFFFFF'), 0.01);
    }

    public function testTheHoverColourAndTintAreDerivedFromThePrimary(): void
    {
        $this->assertSame('#002867', BrandColours::dark('#0040A6'));
        $this->assertSame('#F2F5FB', BrandColours::tint('#0040A6'));
        $this->assertSame('#000000', BrandColours::dark('#000000'));
        $this->assertSame('#FFFFFF', BrandColours::tint('#FFFFFF'));
    }

    public function testTheMentoraColoursPassTheirOwnRules(): void
    {
        $this->assertNull(BrandColours::primaryProblem(BrandColours::DEFAULT_PRIMARY));
        $this->assertNull(BrandColours::accentProblem(BrandColours::DEFAULT_ACCENT));
    }

    /** @return iterable<string, array{string}> */
    public static function provideTooLight(): iterable
    {
        yield 'a pale blue' => ['#7FB2FF'];
        yield 'yellow' => ['#FFD700'];
        yield 'white' => ['#FFFFFF'];
        yield 'light grey' => ['#CCCCCC'];
    }

    #[DataProvider('provideTooLight')]
    public function testAColourThatIsTooLightIsRefusedWithTheRatio(string $colour): void
    {
        $primary = BrandColours::primaryProblem($colour);
        $accent  = BrandColours::accentProblem($colour);

        $this->assertNotNull($primary);
        $this->assertMatchesRegularExpression('/too light.*\d+\.\d:1.*4\.5:1/s', $primary);
        $this->assertNotNull($accent);
        $this->assertStringContainsString('white text', $accent);
    }

    public function testSomethingThatIsNotAColourIsRefused(): void
    {
        $this->assertStringContainsString('six hex digits', (string) BrandColours::primaryProblem('blue'));
        $this->assertStringContainsString('six hex digits', (string) BrandColours::accentProblem(''));
    }

    public function testThePrimaryIsJudgedOnThePaleBackgroundNotJustWhite(): void
    {
        // Between the two: readable on white, a shade short on the tint.
        $found = null;

        for ($g = 0x60; $g <= 0x90; $g++) {
            $hex = sprintf('#00%02XA6', $g);

            if (BrandColours::contrast($hex, '#FFFFFF') >= 4.5 && BrandColours::contrast($hex, BrandColours::tint($hex)) < 4.5) {
                $found = $hex;
                break;
            }
        }

        $this->assertNotNull($found, 'a colour in the gap exists');
        $this->assertNotNull(BrandColours::primaryProblem($found));
        $this->assertNull(BrandColours::accentProblem($found), 'the accent is only judged against white');
    }

    public function testTheCustomPropertiesCarryTheDerivedColours(): void
    {
        $css = BrandColours::css('#8B1E3F', '#1B5E20');

        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('--color-brand:#8B1E3F', $css);
        $this->assertStringContainsString('--color-brand-dark:' . BrandColours::dark('#8B1E3F'), $css);
        $this->assertStringContainsString('--color-brand-tint:' . BrandColours::tint('#8B1E3F'), $css);
        $this->assertStringContainsString('--color-accent:#1B5E20', $css);
    }

    public function testNothingIsEmittedForUnsetOrUnreadableColours(): void
    {
        $this->assertSame('', BrandColours::css(null, null));
        $this->assertSame('', BrandColours::css('#FFD700', 'not-a-colour'), 'never emit a colour that fails the rules');
        $this->assertSame(':root{--color-accent:#1B5E20}', BrandColours::css(null, '#1B5E20'));
    }

    public function testNothingThatIsNotAHexCanReachTheStylesheet(): void
    {
        $css = BrandColours::css('#8B1E3F;}body{display:none', 'red');

        $this->assertSame('', $css);
    }
}

<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\GenerationOptions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class GenerationOptionsTest extends TestCase
{
    public function testAnythingUnknownFallsBackToTheDefaults(): void
    {
        $this->assertSame(
            ['difficulty' => 'mixed', 'level' => 'any', 'language' => '', 'focus' => '', 'choices' => 4],
            GenerationOptions::normalise(['difficulty' => 'brutal', 'level' => 'nope', 'choices' => 'many'])
        );
        $this->assertSame(4, GenerationOptions::normalise(null)['choices']);
    }

    public function testChoicesAreKeptBetweenThreeAndFive(): void
    {
        $this->assertSame(5, GenerationOptions::normalise(['choices' => 12])['choices']);
        $this->assertSame(3, GenerationOptions::normalise(['choices' => 1])['choices']);
    }

    public function testTextIsTrimmedCollapsedAndCapped(): void
    {
        $options = GenerationOptions::normalise(['focus' => "  chapter\n 3   only  ", 'language' => str_repeat('x', 100)]);

        $this->assertSame('chapter 3 only', $options['focus']);
        $this->assertSame(40, mb_strlen($options['language']));
    }

    public function testTheInstructionNamesOnlyWhatWasSteered(): void
    {
        $this->assertSame('', GenerationOptions::instruction(GenerationOptions::normalise([])));

        $text = GenerationOptions::instruction(GenerationOptions::normalise([
            'difficulty' => 'harder', 'level' => 'apply', 'language' => 'Filipino', 'focus' => 'chapter 3',
        ]));

        $this->assertStringContainsString('demanding', $text);
        $this->assertStringContainsString('apply the ideas', $text);
        $this->assertStringContainsString('in Filipino', $text);
        $this->assertStringContainsString('Only ask about: chapter 3.', $text);
    }

    public function testTheTypeMixKeepsKnownTypesWithPositiveCounts(): void
    {
        $this->assertSame(
            ['true_false' => 3, 'Essay' => 2, 'file_upload' => 1],
            GenerationOptions::typeMix(['true_false' => '3', 'Essay' => 2, 'file_upload' => 1, 'robot' => 4, 'multiple_choice' => 0, 'short_answer' => -1])
        );
        $this->assertNull(GenerationOptions::typeMix(['robot' => 1]));
        $this->assertNull(GenerationOptions::typeMix('x'));
    }
}

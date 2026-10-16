<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\TestGenFile;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TestGenFileTest extends TestCase
{
    public function testTheSampleIsRecognisedWithItsCounts(): void
    {
        $info = TestGenFile::describe(TESTPATH . '_support/fixtures/exam-samples/Onesys Sample.tst');

        $this->assertSame(50, $info['questions']);
        $this->assertSame(50, $info['multipleChoice']);
    }

    public function testAnythingElseIsNotATestGenFile(): void
    {
        $this->assertNull(TestGenFile::describe(TESTPATH . '_support/fixtures/exam-samples/onesys sample.txt'));
        $this->assertNull(TestGenFile::describe(TESTPATH . '_support/fixtures/exam-samples/Onesys Sample Blk.zip'));
        $this->assertNull(TestGenFile::describe(TESTPATH . '_support/fixtures/exam-samples/missing.tst'));
    }

    public function testTheMessageNamesTheCountAndTheWayOut(): void
    {
        $text = TestGenFile::message(['title' => 'x', 'questions' => 1, 'multipleChoice' => 1]);

        $this->assertStringContainsString('(1 question)', $text);
        $this->assertStringContainsString('Word, RTF, PDF, Moodle XML or QTI', $text);
    }
}

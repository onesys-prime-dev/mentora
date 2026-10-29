<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * An attempt limit is a whole number of at least 1 (QA AMB-ASM-09): 0, a negative number or text
 * is refused instead of saved, since 0 would lock every student out. A blank limit is one attempt
 * (QuizAttemptServiceTest::testTheDefaultLimitIsOneAttempt pins that).
 *
 * @internal
 */
final class QuizAttemptLimitIsCheckedTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssessmentService $service;
    private int $quiz;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new AssessmentService();
        $offering         = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
        db_connect()->table('assessments')->where('id', $this->quiz)->update(['attempt_limit' => 2]);
    }

    /** @return array{success: bool, message: string} */
    private function setLimit(mixed $limit): array
    {
        return $this->service->updateAssessment($this->instructor, $this->quiz, ['allow_multiple_attempts' => 1, 'attempt_limit' => $limit]);
    }

    private function limit(): int
    {
        return (int) db_connect()->table('assessments')->where('id', $this->quiz)->get()->getRowArray()['attempt_limit'];
    }

    public function testZeroIsRefused(): void
    {
        $this->assertFalse($this->setLimit(0)['success']);
        $this->assertSame(2, $this->limit());
    }

    public function testANegativeLimitIsRefused(): void
    {
        $this->assertFalse($this->setLimit(-1)['success']);
        $this->assertSame(2, $this->limit());
    }

    public function testTextIsRefused(): void
    {
        $this->assertFalse($this->setLimit('abc')['success']);
        $this->assertFalse($this->setLimit('1.5')['success']);
    }

    public function testOneAndMoreAreSaved(): void
    {
        $this->assertTrue($this->setLimit(1)['success']);
        $this->assertTrue($this->setLimit(99)['success']);
        $this->assertSame(99, $this->limit());
    }
}

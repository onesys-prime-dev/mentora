<?php

declare(strict_types=1);

use App\Services\Courses\CourseContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The course a request is about, and the person's role in it (audit decision 9.1).
 *
 * @internal
 */
final class CourseContextTest extends CIUnitTestCase
{
    public function testItIsEmptyUntilACourseIsSet(): void
    {
        $context = new CourseContext();

        $this->assertNull($context->offeringId());
        $this->assertNull($context->role());
    }

    public function testItHoldsTheCourseAndRoleUntilCleared(): void
    {
        $context = new CourseContext();
        $context->set(12, 'student');

        $this->assertSame(12, $context->offeringId());
        $this->assertSame('student', $context->role());

        $context->clear();

        $this->assertNull($context->offeringId());
        $this->assertNull($context->role());
    }

    public function testTheServiceIsSharedForTheRequest(): void
    {
        service('courseContext')->set(7, 'instructor');

        $this->assertSame(7, service('courseContext')->offeringId());

        service('courseContext')->clear();
    }
}

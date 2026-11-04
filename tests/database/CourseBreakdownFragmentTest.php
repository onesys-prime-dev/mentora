<?php

declare(strict_types=1);

use App\Services\Grades\CourseBreakdownFragment;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The small breakdown a course row loads on a Grades page: nothing for a course without a published scheme (and
 * nothing created by asking), the course's numbers and a link under the reader's own prefix for a published one.
 *
 * @internal
 */
final class CourseBreakdownFragmentTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testACourseWithoutAPublishedSchemeHasNothingAndCreatesNothing(): void
    {
        $student  = CourseFactory::user('student');
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        $this->assertNull((new CourseBreakdownFragment())->html($student, $offering, 'student'));
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->countAllResults(), 'asking writes nothing');

        $scheme = GradeFactory::scheme($offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Draft']);

        $this->assertNull((new CourseBreakdownFragment())->html($student, $offering, 'student'));
    }

    public function testAPublishedSchemeGivesTheFragmentWithTheReadersOwnCourseLink(): void
    {
        $student  = CourseFactory::user('student');
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        $scheme = GradeFactory::scheme($offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);

        $html = (new CourseBreakdownFragment())->html($student, $offering, 'parent/children/' . $student->id);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Passing is 75', $html);
        $this->assertStringContainsString(esc('/parent/children/' . $student->id . '/courses/' . $offering . '/', 'attr'), $html);
        $this->assertStringContainsString(esc('/grades', 'attr'), $html);
    }
}

<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The rubric endpoints hand the editor each criterion's scope and accept it back.
 *
 * @internal
 */
final class RubricScopeRouteTest extends RouteTestCase
{
    public function testTheRubricEndpointsReturnAndSaveScope(): void
    {
        $offering = CourseFactory::offering();
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $instructor);
        $assignment = CourseFactory::assessment($offering, $instructor, CourseFactory::module($offering), null, 'Assignment');
        $base = "instructor/api/courses/{$offering}";

        $this->loggedInAs($instructor);
        $created = json_decode($this->withBody(json_encode(['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 5, 'scope' => 'group'], ['title' => 'B', 'max_points' => 5]]]))
            ->withHeaders(['Content-Type' => 'application/json'])->call('post', "{$base}/assessments/{$assignment}/rubrics")->getJSON(), true);
        $this->assertTrue($created['success']);

        $shown = json_decode($this->call('get', "{$base}/rubrics/{$created['rubric']['id']}")->getJSON(), true);

        $this->assertSame(['group', 'individual'], array_column($shown['rubric']['criteria'], 'scope'));
    }
}

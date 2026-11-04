<?php

declare(strict_types=1);

use App\Services\Grades\SectionStates;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * Where a section stands on the way to finalizing, shared by the instructor's cards and the chairperson's
 * program table (the rules in order: finalized, not published, waiting, blocked, ready).
 *
 * @internal
 */
final class SectionStatesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function offering(): int
    {
        return CourseFactory::offering();
    }

    private function publish(int $offering): void
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);
    }

    public function testNoOfferingsGiveNothing(): void
    {
        $this->assertSame([], SectionStates::for([]));
    }

    public function testASectionWithoutAPublishedSchemeIsNotPublishedAndCreatesNoScheme(): void
    {
        $id = $this->offering();

        $states = SectionStates::for([['offering_id' => $id]]);

        $this->assertSame('not_published', $states[$id]['state']);
        $this->assertFalse($states[$id]['has_scheme']);
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $id)->countAllResults());
    }

    public function testAPublishedSectionIsReadyAndFinalizedWinsOverEverything(): void
    {
        $ready = $this->offering();
        $this->publish($ready);
        $done = $this->offering();

        db_connect()->table('grade_finalizations')->insert([
            'course_offering_id' => $done, 'finalized_by' => PeopleFactory::person('instructor', 'Fin', 'Alizer')->id,
            'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1,
        ]);

        $states = SectionStates::for([['offering_id' => $ready], ['offering_id' => $done]]);

        $this->assertSame('ready', $states[$ready]['state']);
        $this->assertTrue($states[$ready]['has_scheme']);
        $this->assertSame('finalized', $states[$done]['state']);
    }
}

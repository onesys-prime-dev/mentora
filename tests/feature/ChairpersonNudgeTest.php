<?php

declare(strict_types=1);

use App\Services\Courses\FollowUpRules;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Nudging an instructor from the Program tab: the dialog is on the page (its reasons from the follow-up rules),
 * its Send is the existing follow-up request, and it still refuses someone who does not teach the section.
 *
 * @internal
 */
final class ChairpersonNudgeTest extends RouteTestCase
{
    private int $offering;

    private User $chair;

    private User $teacher;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $home = DeanFactory::department('NDG');
        $sub  = DeanFactory::subDepartment($home['department'], 'NDG');

        $this->chair    = DeanFactory::chair($sub, 'Cara');
        $this->teacher  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $home['institution']);
        $this->outsider = PeopleFactory::person('instructor', 'Olga', 'Outside', null, $home['institution']);

        $this->offering = DeanFactory::offering(DeanFactory::program($sub, 'NDG'), DeanFactory::term($home['institution']), 'IT101', 'B');
        CourseFactory::assignInstructor($this->offering, $this->teacher);
    }

    /** @param array<string, mixed> $over */
    private function nudge(array $over = []): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($this->chair);

        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->call('post', 'chairperson/follow-ups', $over + [
            'offering_id'  => $this->offering,
            'recipient_id' => $this->teacher->id,
            'title'        => 'IT101-B: 23 submissions waiting to be graded, oldest 9 days',
            'message'      => 'IT101-B: 23 submissions waiting to be graded, oldest 9 days.',
            'reason'       => 'overdue_grading',
            'due_date'     => '',
        ]);
    }

    public function testANudgeBecomesAFollowUpForTheInstructor(): void
    {
        $response = $this->nudge()->response();

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertNotSame('', (string) (json_decode($response->getBody(), true)['message'] ?? ''));

        $row = db_connect()->table('follow_ups')->where('offering_id', $this->offering)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame((int) $this->teacher->id, (int) $row['recipient_id']);
        $this->assertSame((int) $this->chair->id, (int) $row['sender_id']);
        $this->assertSame('overdue_grading', $row['reason']);
        $this->assertStringContainsString('23 submissions waiting', $row['title']);
    }

    public function testANudgeToSomeoneNotTeachingTheSectionIsRefused(): void
    {
        $response = $this->nudge(['recipient_id' => $this->outsider->id])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('errors', json_decode($response->getBody(), true));
        $this->assertSame(0, db_connect()->table('follow_ups')->where('offering_id', $this->offering)->countAllResults());
    }

    public function testTheDialogOffersTheFollowUpReasons(): void
    {
        $this->loggedInAs($this->chair);
        $body = $this->call('get', 'chairperson/grades?tab=program')->response()->getBody();

        $this->assertStringContainsString('data-nudge-dialog', $body);

        foreach (FollowUpRules::REASONS as $key => $label) {
            $this->assertStringContainsString('value="' . $key . '"', $body);
            $this->assertStringContainsString($label, $body);
        }
    }
}

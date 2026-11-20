<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Grade Work at every width: the students list is the split start pane from lg up and a side panel below it, and
 * the grading panel is the phone's sticky score bar (the same controls, a static block from md up).
 *
 * Rendered through the course tab's API (the page asks the database who may grade), not with view() alone.
 *
 * @internal
 */
final class GradeWorkResponsiveTest extends RouteTestCase
{
    private int $offering;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->offering = CourseFactory::offering();
        $institution    = (int) db_connect()->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE o.id = ?', [$this->offering])->getRow()->institution_id;
        $this->teacher  = $this->member('instructor', $institution);
        $this->student  = $this->member('student', $institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function member(string $role, int $institution): User
    {
        $user = CourseFactory::user($role);
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id, 'created_at' => date('Y-m-d H:i:s')]);

        if (db_connect()->table('user_profiles')->where('user_id', $user->id)->countAllResults() === 0) {
            db_connect()->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => ucfirst($role), 'last_name' => 'Person']);
        }

        return $user;
    }

    /** The Grade Work page for the student, as the instructor. */
    private function page(int $assessment): DOMXPath
    {
        $this->loggedInAs($this->teacher);

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('GET', "instructor/api/courses/{$this->offering}/assessments/{$assessment}/grade-work/{$this->student->id}");

        $html = (string) (json_decode((string) $response->getJSON(), true)['html'] ?? '');
        $this->assertStringContainsString('data-grade-work-student-list', $html);

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    private function one(DOMXPath $xpath, string $query, ?DOMNode $context = null): \DOMElement
    {
        $node = $xpath->query($query, $context)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node, $query);

        return $node;
    }

    private function classes(\DOMElement $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')));
    }

    private function assignment(): int
    {
        $assessment = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering), null, 'Assignment');
        db_connect()->table('assessments')->where('id', $assessment)->update(['total_points' => 30]);
        AssessmentFactory::rubric($assessment, $this->teacher, [12, 10, 8]);

        return $assessment;
    }

    public function testTheStudentsListIsTheSplitStartPaneAndASidePanelBelowLg(): void
    {
        $assessment = $this->assignment();
        AssessmentFactory::attempt($assessment, $this->student, 'Grading');
        $xpath = $this->page($assessment);

        $split = $this->one($xpath, '//*[@data-split]');
        $this->assertSame('grade-work-students', $split->getAttribute('data-split-key'));
        $this->assertSame('16rem', $split->getAttribute('data-split-min'));
        $this->assertSame('28rem', $split->getAttribute('data-split-max'));
        // Today's 290px column until the instructor drags it (first paint included).
        $this->assertSame('290px', $split->getAttribute('data-split-default'));
        $this->assertStringContainsString('--split-start: 290px', $split->getAttribute('style'));

        $students = $this->one($xpath, './*[@data-split-start]', $split);
        $this->assertTrue($students->hasAttribute('data-side-panel'));
        $this->assertSame(1, $xpath->query('.//*[@data-grade-work-student-list]', $students)->length);
        $this->assertContains('lg:sticky', $this->classes($students), 'only a pane sticks; below lg the panel is fixed');
        $this->assertNotContains('sticky', $this->classes($students));
        $this->assertSame(1, $xpath->query('.//button[@data-side-panel-close]', $students)->length);

        $handle = $this->one($xpath, './*[@data-split-handle]', $split);
        $this->assertSame('separator', $handle->getAttribute('role'));
        $this->assertSame('0', $handle->getAttribute('tabindex'));
        $this->assertNotSame('', $handle->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query('./*[@data-split-end]//*[@id="student-work-container"]', $split)->length);

        // The toggle sits in the top bar, next to the save indicator, and only below lg.
        $toggle = $this->one($xpath, '//*[contains(@class, "sticky-bar-top")]//button[@data-side-panel-toggle]');
        $this->assertSame($students->getAttribute('id'), $toggle->getAttribute('aria-controls'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertContains('lg:hidden', $this->classes($toggle));
        $this->assertSame(1, $xpath->query('//*[contains(@class, "sticky-bar-top")]//*[@data-grade-work-save-indicator]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[contains(@class, "sticky-bar-top")]//span[contains(concat(" ", @class, " "), " truncate ")]')->length);
    }

    public function testTheGradingPanelIsAStickyScoreBarOnPhonesWithTheSubmitInsideIt(): void
    {
        $assessment = $this->assignment();
        AssessmentFactory::attempt($assessment, $this->student, 'Grading');
        $xpath = $this->page($assessment);

        $bar = $this->one($xpath, '//*[@data-expandable-grade-work-actions-panel]');
        $this->assertTrue($bar->hasAttribute('data-action-bar'));
        // Pinned and opaque only below md; from md up a static block, as before.
        foreach (['max-md:sticky', 'max-md:bottom-0', 'bg-white', 'max-md:pb-safe-2'] as $class) {
            $this->assertContains($class, $this->classes($bar));
        }
        $this->assertNotContains('sticky', $this->classes($bar));

        $submit = $this->one($xpath, './/button[@data-grade-work-submit-grade]', $bar);
        $this->assertFalse($submit->hasAttribute('disabled'));
        $this->assertMatchesRegularExpression('/^\s*Save\s*$/', $xpath->query('./span[contains(@class, "md:hidden")]', $submit)->item(0)->textContent);
        $this->assertSame(1, $xpath->query('.//*[@data-grade-work-score-preview]', $bar)->length);

        // "Rubric" opens the panel (the criteria, the feedback) upward inside the bar.
        $panel = $this->one($xpath, './*[@data-action-bar-panel]', $bar);
        $this->assertSame(1, $panel->childNodes->length - $xpath->query('./text()', $panel)->length, 'one child inside the panel');
        $toggle = $this->one($xpath, './/button[@data-action-bar-toggle]', $bar);
        $this->assertSame($panel->getAttribute('id'), $toggle->getAttribute('aria-controls'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertContains('md:hidden', $this->classes($toggle));
        $this->assertStringContainsString('Rubric', $toggle->textContent);
        $this->assertSame(3, $xpath->query('.//input[@data-grade-work-rubric-criteria-points]', $panel)->length);

        foreach ($xpath->query('//input[@data-grade-work-rubric-criteria-points] | //input[@data-grade-work-default-grading-points]') as $input) {
            $this->assertSame('decimal', $input->getAttribute('inputmode'));
        }

        $feedback = $this->one($xpath, './/textarea[@data-grade-work-feedback]', $panel);
        $this->assertContains('text-base', $this->classes($feedback));
        $this->assertContains('sm:text-sm', $this->classes($feedback));
    }

    public function testAStudentWithoutASubmissionGetsTheEmptyStateAndADisabledSave(): void
    {
        $xpath = $this->page($this->assignment());

        $bar = $this->one($xpath, '//*[@data-expandable-grade-work-actions-panel]');
        $this->assertSame('disabled', $bar->getAttribute('data-panel-state'));
        $this->assertStringContainsString('No Submission', $this->one($xpath, './/*[@data-grade-work-status-label]', $bar)->textContent);
        $this->assertTrue($this->one($xpath, './/button[@data-grade-work-submit-grade]', $bar)->hasAttribute('disabled'));
        $this->assertSame(0, $xpath->query('.//button[@data-action-bar-toggle]', $bar)->length, 'nothing to open');
    }

    public function testAQuizQuestionsScoreFieldIsDecimalAndClearsTheBar(): void
    {
        $quiz = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering), null, 'Quiz');
        AssessmentFactory::question($quiz, $this->teacher, 'Essay', 5.0);
        AssessmentFactory::attempt($quiz, $this->student, 'Grading');
        $xpath = $this->page($quiz);

        $points = $this->one($xpath, '//input[@data-question-points-input]');
        $this->assertSame('decimal', $points->getAttribute('inputmode'));
        // The window scrolls, not the form, so the field's own scroll margin keeps it above the bar.
        $this->assertContains('scroll-mb-36', $this->classes($points));
        $this->assertContains('text-base', $this->classes($points));
    }
}

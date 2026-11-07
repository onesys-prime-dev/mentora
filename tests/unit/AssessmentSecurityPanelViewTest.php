<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The assessment settings panel and the password modal: the settings that are quiz only (randomize the questions,
 * a password, the additional security section) show on a quiz and never on an assignment, they show what is saved,
 * the password itself is never in the page, and the security section says plainly what a web page cannot do.
 *
 * @internal
 */
final class AssessmentSecurityPanelViewTest extends CIUnitTestCase
{
    private const PANEL = 'pages/courses/features/assessments/partials/editor-inspector-panel';

    private const MODAL = 'pages/courses/features/assessments/partials/modals/quiz-password';

    /** @param array<string, mixed> $settings */
    private function panel(string $type, array $settings = []): DOMXPath
    {
        helper(['icon']);

        $html = view(self::PANEL, [
            'currentAssessment' => ['id' => 9, 'assessment_type' => $type, 'due_date' => null, 'available_from' => null, 'available_until' => null, 'total_points' => 10, 'attempt_limit' => 1, 'passing_percentage' => null, 'passing_score' => null, 'duration' => null],
            'currentSettings'   => $settings,
        ], ['saveData' => false]);

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new DOMXPath($dom);
    }

    private function field(DOMXPath $x, string $name): ?DOMElement
    {
        $node = $x->query('//*[@data-inspector-field="' . $name . '"]')->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $node = $x->query($query)->item(0);

        return $node === null ? '' : preg_replace('/\s+/', ' ', trim($node->textContent));
    }

    public function testAQuizShowsTheQuizOnlySettings(): void
    {
        $x = $this->panel('Quiz');

        foreach (['randomize_questions', 'require_password', 'detect_tab_switch', 'browser_lockdown'] as $name) {
            $this->assertNotNull($this->field($x, $name), $name);
            $this->assertSame('checkbox', $this->field($x, $name)->getAttribute('type'));
        }

        $this->assertStringContainsString('Randomize questions', $x->document->textContent);
    }

    public function testAnAssignmentShowsNoneOfThem(): void
    {
        $x = $this->panel('Assignment');

        foreach (['randomize_questions', 'require_password', 'detect_tab_switch', 'browser_lockdown'] as $name) {
            $this->assertNull($this->field($x, $name), "{$name} is quiz only");
        }

        $this->assertSame(0, $x->query('//*[@data-quiz-password]')->length);
        $this->assertSame(0, $x->query('//*[@data-additional-security]')->length);
        $this->assertStringNotContainsString('Additional security', $x->document->textContent);
        $this->assertStringNotContainsString('password', strtolower($x->document->textContent));
    }

    public function testTheTogglesShowWhatIsSaved(): void
    {
        $off = $this->panel('Quiz', []);
        $on  = $this->panel('Quiz', ['randomize_questions' => 1, 'detect_tab_switch' => 1, 'browser_lockdown' => 1, 'require_password' => 1, 'has_password' => true]);

        foreach (['randomize_questions', 'detect_tab_switch', 'browser_lockdown', 'require_password'] as $name) {
            $this->assertFalse($this->field($off, $name)->hasAttribute('checked'), "{$name} off");
            $this->assertTrue($this->field($on, $name)->hasAttribute('checked'), "{$name} on");
        }
    }

    public function testThePasswordSectionCarriesItsStateAndShowsNothingSecret(): void
    {
        $none = $this->panel('Quiz', ['require_password' => 1, 'has_password' => false]);
        $has  = $this->panel('Quiz', ['require_password' => 1, 'has_password' => true]);

        $this->assertSame('0', $none->query('//*[@data-quiz-password]')->item(0)->getAttribute('data-has-password'));
        $this->assertSame('1', $has->query('//*[@data-quiz-password]')->item(0)->getAttribute('data-has-password'));

        $this->assertSame('Create password', $this->text($none, '//button[@data-password-create]'));
        $this->assertSame('******', $this->text($has, '//*[@data-password-mask]'));
        $this->assertSame(1, $has->query('//button[@data-password-edit][@aria-label="Edit password"]')->length);

        foreach ([$none, $has] as $x) {
            $this->assertStringNotContainsString('$2y$', $x->document->saveHTML());
        }
    }

    public function testAdditionalSecurityIsAnExpandableSectionThatIsHonestAboutWhatItCannotDo(): void
    {
        $x = $this->panel('Quiz');

        $details = $x->query('//details[@data-additional-security]')->item(0);

        $this->assertNotNull($details, 'an expandable section');
        $this->assertFalse($details->hasAttribute('open'), 'collapsed to begin with');
        $this->assertStringContainsString('Additional security', $this->text($x, '//details[@data-additional-security]/summary'));

        $inside = $x->query('.//input[@data-inspector-field]', $details);
        $this->assertSame(['detect_tab_switch', 'browser_lockdown'], array_map(static fn (DOMElement $n): string => $n->getAttribute('data-inspector-field'), iterator_to_array($inside)));

        $words = strtolower($this->text($x, '//details[@data-additional-security]'));
        $this->assertStringContainsString('leaves the quiz', $words, 'it records that they left, not where');
        $this->assertStringContainsString('cannot prevent', $words);
        $this->assertStringContainsString('fullscreen', $words);
        $this->assertStringNotContainsString('block tab switching', $words, 'no promise a web page cannot keep');
    }

    public function testThePasswordModalIsAPlainTextFieldWithSaveAndCancel(): void
    {
        helper(['icon']);

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . view(self::MODAL, [], ['saveData' => false]));
        $x = new DOMXPath($dom);

        $modal = $x->query('//*[@id="quiz-password-modal"]')->item(0);
        $this->assertNotNull($modal);
        $this->assertSame('dialog', $modal->getAttribute('role'));
        $this->assertSame('true', $modal->getAttribute('aria-modal'));
        $this->assertStringContainsString('hidden', $modal->getAttribute('class'));

        $input = $x->query('//input[@data-password-input]')->item(0);
        $this->assertSame('text', $input->getAttribute('type'), 'plain text, not hidden dots');
        $this->assertSame('off', $input->getAttribute('autocomplete'));

        $this->assertSame('Save', $this->text($x, '//button[@data-password-save]'));
        $this->assertSame('Cancel', $this->text($x, '//button[@data-close-password-modal][contains(., "Cancel")]'));
        $this->assertGreaterThanOrEqual(2, $x->query('//*[@data-close-password-modal]')->length, 'the X and Cancel');
        $this->assertSame(1, $x->query('//*[@data-password-error][@role="alert"]')->length);
        $this->assertSame(1, $x->query('//button[@data-password-generate]')->length);
    }

    public function testSpecificStudentsShowsOnEveryKindOfAssessment(): void
    {
        foreach (['Quiz', 'Assignment'] as $type) {
            $x = $this->panel($type);

            $this->assertNotNull($this->field($x, 'assign_to_specific_students'), $type);
            $this->assertSame(1, $x->query('//*[@data-specific-open]')->length, $type);
        }
    }

    public function testSpecificStudentsShowsTheCountAndHidesTheListButtonWhileOff(): void
    {
        $off = $this->panel('Assignment', ['assign_to_specific_students' => 0, 'assignee_count' => 3]);
        $on  = $this->panel('Assignment', ['assign_to_specific_students' => 1, 'assignee_count' => 3]);
        $one = $this->panel('Assignment', ['assign_to_specific_students' => 1, 'assignee_count' => 1]);

        $this->assertFalse($this->field($off, 'assign_to_specific_students')->hasAttribute('checked'));
        $this->assertStringContainsString('hidden', $off->query('//*[@data-specific-state]')->item(0)->getAttribute('class'));

        $this->assertTrue($this->field($on, 'assign_to_specific_students')->hasAttribute('checked'));
        $this->assertStringNotContainsString('hidden', $on->query('//*[@data-specific-state]')->item(0)->getAttribute('class'));
        $this->assertSame('3 students selected', $this->text($on, '//*[@data-specific-count]'));
        $this->assertSame('1 student selected', $this->text($one, '//*[@data-specific-count]'));
        $this->assertSame('3', $on->query('//*[@data-specific-students]')->item(0)->getAttribute('data-assignee-count'));
    }
}

<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The Modules tab and a module page carry their editing dialogs (editor, delete, transfer, dependencies, the ⋯
 * sheet) only for someone who may change the content: the course's instructor has every one, the dean of its
 * college (a read-only viewer) none, so no hidden Save is in the dean's page (responsive Phase 6, Task 7).
 *
 * @internal
 */
final class ModulesEditingDialogsTest extends RouteTestCase
{
    private const DIALOGS = [
        'modules' => ['content-actions-sheet', 'module-editor-modal', 'module-delete-modal', 'content-transfer-modal', 'module-dependencies-modal'],
        'module'  => ['content-actions-sheet', 'module-editor-modal', 'module-delete-modal', 'learning-unit-editor-modal', 'learning-unit-delete-modal', 'learning-item-create-modal', 'learning-item-dependencies-modal', 'content-transfer-modal', 'learning-unit-dependencies-modal', 'module-dependencies-modal', 'learning-item-delete-modal'],
    ];

    private int $offering;

    private int $module;

    private User $dean;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $home           = DeanFactory::college('M');
        $program        = DeanFactory::program(DeanFactory::department($home['college'], 'M'), 'M');
        $this->offering = DeanFactory::offering($program, DeanFactory::term($home['institution']), 'IT101', 'A', 'active', 'Intro to Computing');
        $this->dean     = DeanFactory::dean($home['institution'], $home['college']);
        $this->teacher  = PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@med.example', $home['institution']);
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $db = db_connect();
        $db->table('course_modules')->insert(['offering_id' => $this->offering, 'title' => 'Getting Started', 'order_index' => 1, 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->module = (int) $db->insertID();
    }

    /** The two pages' HTML (the JSON answers' strings, joined) for this person in this area. */
    private function pages(User $user, string $area): array
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        $html = [];

        foreach (['modules' => 'modules', 'module' => "modules/{$this->module}"] as $page => $path) {
            $this->withHeaders(['Accept' => 'application/json']);
            $response = $this->call('get', "{$area}/api/courses/{$this->offering}/{$path}")->response();
            $this->assertSame(200, $response->getStatusCode(), "{$area} {$page}");

            $strings = [];
            $decoded = (array) json_decode((string) $response->getBody(), true);
            array_walk_recursive($decoded, static function ($value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });
            $html[$page] = implode("\n", $strings);
        }

        return $html;
    }

    public function testTheInstructorHasEveryEditingDialog(): void
    {
        foreach ($this->pages($this->teacher, 'instructor') as $page => $html) {
            foreach (self::DIALOGS[$page] as $id) {
                $this->assertStringContainsString('id="' . $id . '"', $html, "{$page}: {$id}");
            }

            $this->assertStringContainsString('data-dependencies-save', $html, "{$page}: the dependencies Save");
        }
    }

    public function testTheDeanHasNoEditingDialogAndNoSave(): void
    {
        foreach ($this->pages($this->dean, 'dean') as $page => $html) {
            foreach (self::DIALOGS[$page] as $id) {
                $this->assertStringNotContainsString('id="' . $id . '"', $html, "{$page}: {$id}");
            }

            $this->assertStringNotContainsString('data-dependencies-save', $html, "{$page}: the dependencies Save");
            $this->assertDoesNotMatchRegularExpression('/<button[^>]*>(?:(?!<\/button>).)*\bSave\b/is', $html, "{$page}: a Save button");
        }
    }
}

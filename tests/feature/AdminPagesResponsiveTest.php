<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogFilters;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;

/**
 * The platform administrator's own pages on a phone (responsive Phase 7). No fixtures: each page is rendered with
 * made-up data inside a stand-in layout. One method per page group; later tasks add theirs.
 *
 * @internal
 */
final class AdminPagesResponsiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'icon', 'form']);
    }

    /** @param array<string, mixed> $data */
    private function page(string $name, array $data): DOMXPath
    {
        $view = new View(config('View'), TESTPATH . '_support/Views/', service('locator'), false);
        $html = $view->setData($data)->render($name, null, false);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    /** @return list<string> */
    private function classes(?DOMNode $node): array
    {
        return $node instanceof DOMElement ? preg_split('/\s+/', trim($node->getAttribute('class'))) : [];
    }

    private function assertTouch(DOMXPath $x, string $query, string $what): void
    {
        $nodes = $x->query($query);
        $this->assertGreaterThan(0, $nodes->length, "{$what}: found");

        foreach ($nodes as $node) {
            $classes = $this->classes($node);
            $this->assertContains('pointer-coarse:min-h-11', $classes, "{$what} is 44px on touch");
            $this->assertNotSame([], array_intersect(['flex', 'inline-flex'], $classes), "{$what} is a flex box");
        }
    }

    private function assertClass(DOMXPath $x, string $query, string $class, string $what): void
    {
        $nodes = $x->query($query);
        $this->assertGreaterThan(0, $nodes->length, "{$what}: found");

        foreach ($nodes as $node) {
            $this->assertContains($class, $this->classes($node), $what);
        }
    }

    /** @return array<string, mixed> */
    private function monitoringData(array $usage): array
    {
        $line = ['state' => 'ok', 'used' => 1, 'limit' => 10, 'used_mb' => 1, 'limit_mb' => 10];

        return [
            'monHealth'   => ['database' => ['status' => 'up', 'detail' => 'ok'], 'disk' => ['status' => 'degraded', 'detail' => '91%']],
            'monOverall'  => 'up',
            'monPlatform' => ['institutions' => 1, 'users' => 2, 'signedInToday' => 1, 'sessions' => 1, 'failedLogins24h' => 0, 'failedLogins7d' => 0],
            'monWatch'    => [],
            'monUsage'    => array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Northfield', 'plan' => 'Standard', 'activeUsers' => 3, 'seats' => $line, 'storage' => $line, 'ai' => $line], $usage),
            'monRuntime'  => ['checkedAt' => '2026-11-17 10:00', 'php' => '8.3', 'framework' => '4.6', 'environment' => 'testing'],
            'monLiveUrl'  => 'admin/monitoring/live',
        ];
    }

    /** @return array<string, mixed> */
    private function homeData(array $activity): array
    {
        return [
            'homeAttention' => [['severity' => 'urgent', 'text' => 'Backup failed', 'link' => 'admin/backups']],
            'homeHealth'    => [['key' => 'database', 'label' => 'Database', 'status' => 'up', 'detail' => 'ok']],
            'homeSnapshot'  => ['byStatus' => ['active' => 1, 'pending' => 0, 'read_only' => 0, 'past' => 0], 'institutions' => 1, 'users' => 2, 'signedInToday' => 1],
            'homeActivity'  => $activity,
        ];
    }

    public function testHomeAndMonitoringCellsAreFixedHeight(): void
    {
        $activity = [['id' => 1, 'action' => 'user.login', 'actor_label' => 'Ana', 'created_at' => '2026-11-17 09:00:00']];
        $x        = $this->page('pages/admin/home', $this->homeData($activity));

        $this->assertClass($x, '//*[@data-health-cell]', 'h-16', 'a home health cell');
        $this->assertClass($x, '//*[@data-section="snapshot"]//dl/div[@data-status-count="active" or @data-total]', 'h-20', 'a snapshot tile');
        $this->assertSame(1, $x->query('//*[@data-section="activity"]//*[@data-table]')->length, 'recent activity is on components/data-table');
        $this->assertSame(0, $x->query('//*[@data-activity-empty]')->length);
        $this->assertSame(1, $this->page('pages/admin/home', $this->homeData([]))->query('//*[@data-activity-empty]')->length, 'the activity list has an empty state');

        $this->assertTouch($x, '//*[@data-shortcuts]/a | //*[@data-section="attention"]//a | //*[@data-section="shortcuts"]//a | //*[@data-section="activity"]//h2/following-sibling::a', 'a home link');

        $x = $this->page('pages/admin/monitoring', $this->monitoringData([4]));

        $this->assertClass($x, '//*[@data-check]', 'h-24', 'a health cell');
        $this->assertClass($x, '//*[@data-tile]/..', 'h-16', 'a snapshot tile');
        $grid = $x->query('//*[@data-section="usage"]//*[@data-scroll-x]')->item(0);
        $this->assertNotNull($grid, 'the institution table scrolls sideways');
        $this->assertTrue($grid->hasAttribute('data-sticky-first'), 'the name column is pinned');
        $this->assertClass($x, '//*[@data-section="usage"]//td[1]', 'left-0', 'the pinned name cell');
        $this->assertTouch($x, '//label[.//input[@data-auto-refresh]]', 'the live-updates label');
        $this->assertContains('pointer-coarse:h-6', $this->classes($x->query('//input[@data-auto-refresh]')->item(0)));

        $x = $this->page('pages/admin/monitoring', $this->monitoringData([]));
        $this->assertStringContainsString('No institutions yet.', $x->document->textContent, 'the usage table has an empty state');
    }

    public function testAuditLogFiltersAndExportAreTouchSized(): void
    {
        $row = ['id' => 1, 'created_at' => '2026-11-17 09:00:00', 'actor_label' => 'Ana', 'impersonator_user_id' => null, 'acting_role' => 'system_admin', 'action' => 'user.login', 'target_type' => null, 'target_id' => null, 'institution_id' => null, 'institution_name' => null, 'ip_address' => '::1', 'old_values' => null, 'new_values' => null];
        $x   = $this->page('pages/admin/audit-log', ['filters' => AuditLogFilters::fromInput([]), 'rows' => [$row], 'total' => 1, 'pages' => 1, 'actionNames' => ['user.login'], 'showIp' => false]);

        $this->assertSame(1, $x->query('//*[@data-table]')->length, 'the audit log is on components/data-table');
        $this->assertTouch($x, '//a[@data-audit-export] | //button[@data-dialog-open="audit-settings"]', 'a header action');

        $fields = $x->query('//form[@data-audit-filters]//input[not(@type="hidden")] | //form[@data-audit-filters]//select');
        $this->assertGreaterThan(0, $fields->length, 'the audit filter fields are found');

        foreach ($fields as $field) {
            $classes = $this->classes($field);
            $this->assertContains('pointer-coarse:min-h-11', $classes);
            $this->assertContains('text-base', $classes, 'a phone filter field is 16px');
        }

        $this->assertTouch($x, '//form[@data-audit-filters]//button | //form[@data-audit-filters]//summary | //form[@data-audit-filters]//a', 'a filter control');
        $this->assertSame(1, $x->query('//*[@id="audit-settings"]//form//button[@type="submit"]')->length, 'display settings keep their form');
        $this->assertTouch($x, '//*[@id="audit-settings"]//form//button', 'a display settings button');
    }

    public function testRolesAndFlagsControlsAreTouchSized(): void
    {
        $x = $this->page('pages/admin/roles', [
            'roleTitles' => ['system_admin' => 'System admin', 'institutional_admin' => 'Institution admin'], 'lockedRole' => 'system_admin',
            'platformPerms' => ['institutions.manage' => 'Manage institutions'], 'academicPerms' => ['courses.manage' => 'Manage courses'],
            'currentMatrix' => ['system_admin' => ['institutions.manage'], 'institutional_admin' => []], 'defaultMatrix' => ['system_admin' => ['institutions.manage'], 'institutional_admin' => []],
        ]);

        $fields = $x->query('//*[@data-roles-filter]//input[@data-roles-search] | //*[@data-roles-filter]//select');
        $this->assertGreaterThan(0, $fields->length, 'the roles filter fields are found');

        foreach ($fields as $field) {
            $this->assertContains('pointer-coarse:min-h-11', $this->classes($field));
            $this->assertContains('text-base', $this->classes($field), 'a phone search field is 16px');
        }

        $this->assertContains('pointer-coarse:h-6', $this->classes($x->query('//input[@data-roles-edited]')->item(0)));
        $this->assertTouch($x, '//label[.//input[@data-roles-edited]]', 'the Edited only label');
        $this->assertSame(1, $x->query('//*[@data-roles-empty]')->length, 'the permission list has an empty state');
        $this->assertSame(1, $x->query('//*[@data-scroll-x][@data-sticky-first]')->length, 'the grid scrolls with its first column pinned');

        $defs = ['reports' => ['description' => 'Reports', 'default' => true]];
        $x    = $this->page('pages/admin/flags/index', [
            'flagDefs' => $defs, 'flagGlobal' => ['reports' => ['global' => null]], 'flagAdoption' => ['reports' => ['on' => 1, 'total' => 2, 'overrides' => 0]],
        ]);
        $this->assertTouch($x, '//*[@data-flag]//summary | //*[@data-flag]//form//button[@type="submit"]', 'a flag control');

        $x = $this->page('pages/admin/flags/index', ['flagDefs' => [], 'flagGlobal' => [], 'flagAdoption' => []]);
        $this->assertStringContainsString('No feature flags are defined.', $x->document->textContent, 'the flag list has an empty state');
    }

    public function testBackupsAndArchiveKeepTheirConfirmsAndAreTouchSized(): void
    {
        $backup = ['id' => 1, 'status' => 'succeeded', 'message' => null, 'created_at' => '2026-11-17 02:00:00', 'trigger' => 'schedule', 'file_name' => 'a.sql.gz', 'size_bytes' => 2048, 'finished_at' => '2026-11-17 02:01:00', 'offsite_status' => null, 'offsite_message' => null, 'requested_by_label' => null];
        $x      = $this->page('pages/admin/backups', ['backupRows' => [$backup], 'backupAlert' => null, 'backupFolder' => 'writable/backups']);

        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertSame(1, $x->query('//form[@data-dialog-form][contains(@action, "admin/backups")]//button[@type="submit"]')->length, 'Back up now stays in its form');
        $this->assertTouch($x, '//form[contains(@action, "admin/backups")]//button', 'Back up now');

        $deleted = ['id' => 7, 'name' => 'Old U', 'code' => 'OLD', 'deleted_at' => '2026-11-01 00:00:00', 'purge_on' => '2026-12-01', 'days_left' => 14, 'contents' => ['colleges' => 1, 'programs' => 1, 'courses' => 1, 'members' => 2, 'members_left_without_an_institution' => 0]];
        $x       = $this->page('pages/admin/archive', ['pastRows' => [['id' => 5, 'name' => 'Past U', 'code' => 'PST', 'updated_at' => '2026-10-01 00:00:00']], 'deletedRows' => [$deleted], 'retentionDays' => 30]);

        $this->assertSame(2, $x->query('//*[@data-table]')->length);
        $this->assertSame(1, $x->query('//form[contains(@action, "/purge")][.//input[@name="confirm_name"]]//button[@type="submit"]')->length, 'purge keeps its typed confirmation inside its form');
        $this->assertSame(1, $x->query('//form[contains(@action, "/restore")]//button[@type="submit"]')->length, 'restore stays in its form');
        $this->assertTouch($x, '//td[@data-role="actions"]//a | //td[@data-role="actions"]//summary | //td[@data-role="actions"]//button', 'an archive action');

        $field = $x->query('//input[@name="confirm_name"]')->item(0);
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($field));
        $this->assertContains('text-base', $this->classes($field));

        $x = $this->page('pages/admin/archive', ['pastRows' => [], 'deletedRows' => [], 'retentionDays' => 30]);
        $this->assertStringContainsString('No past institutions', $x->document->textContent, 'the archive has empty states');
        $this->assertStringContainsString('No deleted institutions', $x->document->textContent);
    }

    /** Every pill-shaped button, link and summary on the page is 44px on touch. */
    private function assertPillsAreTouchSized(DOMXPath $x, string $page): void
    {
        $pills = $x->query('//button[contains(@class,"rounded-full")][not(@data-row-actions-open)] | //a[contains(@class,"rounded-full")] | //summary[contains(@class,"rounded-full")]');
        $this->assertGreaterThan(0, $pills->length, "{$page}: pills found");

        foreach ($pills as $pill) {
            $classes = $this->classes($pill);
            $this->assertContains('pointer-coarse:min-h-11', $classes, "{$page}: {$pill->textContent} is 44px on touch");

            if ($pill->nodeName !== 'button') { // a button centres its own text; a link or summary needs the flex box
                $this->assertNotSame([], array_intersect(['flex', 'inline-flex'], $classes), "{$page}: {$pill->textContent} is a flex box");
            }
        }
    }

    /** @return array<string, mixed> */
    private function institution(string $status = 'active'): array
    {
        return ['id' => 4, 'name' => 'Northfield', 'code' => 'NF', 'status' => $status, 'deleted_at' => null, 'created_at' => '2026-01-01 00:00:00'];
    }

    private function lifecycle(): object
    {
        return (object) ['state' => 'active', 'daysToEnd' => 30, 'endDate' => '2026-12-31', 'graceEnds' => '2027-01-10', 'readOnlyEnds' => '2027-02-10'];
    }

    public function testInstitutionPagesShareOneScrollingTabStrip(): void
    {
        $x     = $this->page('pages/admin/partials/institution-tabs', ['tabInstitution' => ['id' => 4], 'tabActive' => 'users']);
        $strip = $x->query('//nav[@data-institution-tabs]//*[@data-scroll-x][@data-scroll-current]');
        $this->assertSame(1, $strip->length, 'the tabs are one scrolling strip');
        $this->assertSame(1, $x->query('//nav[@data-institution-tabs]//*[@aria-current="page"]')->length, 'one current tab');
        $this->assertSame('users', $x->query('//*[@aria-current="page"]')->item(0)->getAttribute('data-tab'));
        $this->assertSame(6, $x->query('//nav//a[@data-tab]')->length);

        foreach ($x->query('//nav//a[@data-tab]') as $tab) {
            $this->assertContains('pointer-coarse:min-h-11', $this->classes($tab));
            $this->assertContains('whitespace-nowrap', $this->classes($tab), 'a tab never wraps inside the strip');
        }

        $x = $this->page('pages/admin/onboarding/administrator', [
            'onbInstitution' => $this->institution('pending'), 'onbTerm' => ['period_start' => '2026-01-01', 'period_end' => '2026-12-31'], 'onbPlanName' => 'Standard',
            'onbValidityDays' => 7, 'onbValues' => ['first_name' => '', 'last_name' => '', 'email' => ''],
        ]);
        $this->assertSame(1, $x->query('//*[@data-stepper][@data-onboarding-steps]')->length, 'the onboarding steps are the stepper component');
        $this->assertStringContainsString('Step 3 of 3', $x->query('//*[@data-stepper-summary]')->item(0)->textContent, 'below sm the steps read "Step n of m"');
        $this->assertPillsAreTouchSized($x, 'onboarding administrator');

        $x = $this->page('pages/admin/users/show', [
            'person' => ['id' => 9, 'username' => 'ana', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'a@x.test', 'active' => 1, 'deactivated_at' => null, 'roles' => ['student'], 'institutions' => [['id' => 4, 'name' => 'Northfield']], 'last_active' => null, 'created_at' => '2026-01-01 00:00:00'],
            'personAssignableRoles' => ['student', 'instructor'], 'personIsSelf' => false,
            'personValues' => ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'a@x.test'], 'personSisManaged' => false,
        ]);
        $forms = $x->query('//form');
        $this->assertSame(6, $forms->length, 'details, deactivate, sign out, reset password, view as and roles (a deactivated person swaps deactivate for reactivate)');
        $this->assertSame(0, $x->query('//form//form')->length, 'no form is nested');

        foreach ($forms as $form) {
            $this->assertSame(1, (new DOMXPath($form->ownerDocument))->query('.//button[@type="submit"]', $form)->length, 'each form keeps its own submit');
        }

        $this->assertPillsAreTouchSized($x, 'user');
        $this->assertContains('pointer-coarse:h-6', $this->classes($x->query('//input[@name="roles[]"]')->item(0)), 'a role tick is 44px with its label');
        $this->assertTouch($x, '//label[.//input[@name="roles[]"]]', 'a role label');
    }

    public function testUserPageOtherStatesKeepTheirForms(): void
    {
        $person = ['id' => 9, 'username' => 'ana', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'a@x.test', 'active' => 0, 'deactivated_at' => '2026-11-01 00:00:00', 'roles' => ['student'], 'institutions' => [], 'last_active' => null, 'created_at' => '2026-01-01 00:00:00'];
        $data   = ['person' => $person, 'personAssignableRoles' => ['student'], 'personIsSelf' => false, 'personValues' => ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'a@x.test'], 'personSisManaged' => false];

        $x = $this->page('pages/admin/users/show', $data);
        $this->assertSame(1, $x->query('//form[contains(@action,"/reactivate")]//button[@type="submit"]')->length, 'a deactivated person can be reactivated');
        $this->assertSame(0, $x->query('//form[contains(@action,"/deactivate")]')->length);
        $this->assertSame(0, $x->query('//form[contains(@action,"/impersonate")]')->length, 'no view-as for a deactivated person');
        $this->assertSame(0, $x->query('//form//form')->length);

        foreach ($x->query('//form') as $form) {
            $this->assertSame(1, (new DOMXPath($form->ownerDocument))->query('.//button[@type="submit"]', $form)->length);
        }

        $this->assertPillsAreTouchSized($x, 'deactivated user');

        $x = $this->page('pages/admin/users/show', ['person' => ['active' => 1, 'deactivated_at' => null] + $person, 'personIsSelf' => true] + $data);
        $this->assertSame(1, $x->query('//form[contains(@action,"/deactivate")]//button[@disabled]')->length, 'you cannot deactivate yourself');
        $this->assertSame(0, $x->query('//form//form')->length);
        $this->assertPillsAreTouchSized($x, 'own user page');
    }

    public function testFormsStackAndTheirButtonsAreTouchSized(): void
    {
        $x = $this->page('pages/admin/institutions/form', ['formHeading' => 'Edit', 'institution' => $this->institution(), 'formValues' => ['name' => 'Northfield', 'code' => 'NF']]);
        $this->assertPillsAreTouchSized($x, 'institution form');
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($x->query('//input[@id="institution-code"]')->item(0)));
        $this->assertContains('text-base', $this->classes($x->query('//input[@id="institution-code"]')->item(0)), 'the code field is 16px on a phone');

        $x = $this->page('pages/admin/plans/form', ['formHeading' => 'Add', 'plan' => null, 'planFeatureKeys' => [], 'formValues' => ['name' => '', 'description' => '', 'features' => '', 'seat_limit' => '', 'storage_limit_mb' => '', 'ai_generation_limit' => '']]);
        $this->assertPillsAreTouchSized($x, 'plan form');

        $x = $this->page('pages/admin/users/new', ['newInstitutions' => [], 'newRoles' => ['student'], 'newValues' => ['institution_id' => '', 'role' => '', 'first_name' => '', 'last_name' => '', 'email' => '']]);
        $this->assertPillsAreTouchSized($x, 'invite a person');

        $x = $this->page('pages/admin/onboarding/subscription', ['onbInstitution' => $this->institution('pending'), 'onbPlans' => [['id' => 1, 'name' => 'Standard']], 'onbCurrency' => 'PHP', 'onbValues' => []]);
        $this->assertPillsAreTouchSized($x, 'onboarding subscription');
        $this->assertStringContainsString('Step 2 of 3', $x->document->textContent);
        $this->assertContains('grid-cols-1', $this->classes($x->query('//form[contains(@action,"onboarding/subscription")]')->item(0)), 'the fields stack on one column below md');

        $general = ['systemName' => 'Mentora', 'supportContact' => '', 'timezone' => 'UTC', 'maintenanceMode' => false, 'maintenanceMessage' => 'Back soon', 'announcement' => '', 'expiryWarningDays' => 30, 'expiryGraceDays' => 7, 'expiryReadOnlyDays' => 30, 'backupAlertHours' => 26];
        $x       = $this->page('pages/admin/settings/general', ['genValues' => $general, 'genZones' => ['UTC']]);
        $this->assertPillsAreTouchSized($x, 'general settings');
        $this->assertTouch($x, '//label[.//input[@name="maintenanceMode"]]', 'the maintenance label');

        $x = $this->page('pages/admin/settings/security', [
            'secValues' => ['passwordMinLength' => 12, 'sessionTimeoutMinutes' => 30, 'lockoutAttempts' => 5, 'lockoutMinutes' => 15, 'twoFactorRoles' => ['dean']],
            'secChoices' => ['dean', 'instructor'], 'secRoleLabels' => ['admin' => 'System administrator', 'dean' => 'Dean', 'instructor' => 'Instructor'],
        ]);
        $this->assertPillsAreTouchSized($x, 'security settings');
        $ticks = $x->query('//input[@name="twoFactorRoles[]"]');
        $this->assertSame(2, $ticks->length);

        foreach ($ticks as $tick) {
            $this->assertContains('pointer-coarse:h-6', $this->classes($tick), 'a role tick is 44px with its label');
        }

        $this->assertTouch($x, '//label[.//input[@name="twoFactorRoles[]"]]', 'a sign-in code role label');
    }

    public function testInstitutionAndPeopleListsAreDataTables(): void
    {
        $row = ['id' => 4, 'name' => 'Northfield', 'code' => 'NF', 'status' => 'past', 'effective_status' => 'past', 'deleted_at' => null, 'plan_name' => 'Standard', 'created_at' => '2026-01-01 00:00:00'];
        $x   = $this->page('pages/admin/institutions/index', ['institutionFilters' => App\Services\Institutions\InstitutionFilters::fromInput([]), 'institutionRows' => [$row], 'institutionPlans' => [], 'institutionTotal' => 1, 'institutionPages' => 1]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertSame(1, $x->query('//form[contains(@action,"/delete")][.//input[@name="confirm_name"]]//button[@type="submit"]')->length, 'delete keeps its typed confirmation inside its form');
        $this->assertPillsAreTouchSized($x, 'institutions');
        $this->assertContains('text-base', $this->classes($x->query('//input[@name="confirm_name"]')->item(0)), 'the typed name is 16px on a phone');
        $this->assertContains('pointer-coarse:min-h-11', $this->classes($x->query('//input[@name="confirm_name"]')->item(0)));

        $x = $this->page('pages/admin/plans/index', ['planRows' => [['id' => 1, 'name' => 'Std', 'description' => null, 'status' => 'active', 'seat_limit' => null, 'storage_limit_mb' => null, 'ai_generation_limit' => null, 'features' => []]]]);
        $this->assertPillsAreTouchSized($x, 'plans');

        $person = ['id' => 9, 'username' => 'ana', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'a@x.test', 'active' => 1, 'deactivated_at' => null, 'roles' => ['student'], 'institutions' => [['id' => 4, 'name' => 'Northfield']], 'last_active' => null];
        $x      = $this->page('pages/admin/users/index', ['userFilters' => App\Services\Users\UserFilters::fromInput([]), 'userRows' => [$person], 'userInstitutions' => [], 'userSelf' => 1, 'userTotal' => 1, 'userPages' => 1]);
        $this->assertSame(1, $x->query('//*[@data-table]')->length);
        $this->assertPillsAreTouchSized($x, 'users');
        $this->assertSame(1, $x->query('//th[contains(.,"Last active")]')->length, 'the Last active heading is there');
        $this->assertSame(0, $x->query('//th[contains(.,"Last active")][contains(@class,"md:max-")]')->length, 'Last active and its sort link stay on a tablet');
        $this->assertSame(3, $x->query('//form[@data-bulk-form]//button')->length, 'the bulk buttons stay in their one form');

        $invitation = ['id' => 3, 'status' => 'pending', 'first_name' => 'Bo', 'last_name' => 'Lee', 'email' => 'b@x.test', 'role' => 'institutional_admin', 'expires_at' => '2026-12-01 00:00:00', 'sent_at' => null, 'invited_by_label' => 'Ana'];
        $member     = ['first_name' => 'Cy', 'last_name' => 'Dee', 'username' => 'cy', 'email' => 'c@x.test', 'groups' => ['student'], 'active' => 1];
        $x          = $this->page('pages/admin/institutions/users', [
            'peopleInstitution' => $this->institution(), 'peopleInvitations' => [$invitation], 'peopleValues' => ['first_name' => '', 'last_name' => '', 'email' => ''],
            'peopleMembers' => ['total' => 1, 'perRole' => ['student' => 1], 'rows' => [$member]],
        ]);
        $this->assertSame(2, $x->query('//*[@data-table]')->length, 'invitations and people are both on components/data-table');
        $this->assertGreaterThan(0, $x->query('//th[normalize-space()="Email"]')->length, 'an Email heading is there');
        $this->assertSame(0, $x->query('//th[normalize-space()="Email"][contains(@class,"md:max-")]')->length, 'the Sent / Not sent cue shows on a tablet');
        $this->assertSame(1, $x->query('//th[normalize-space()="Invited by"][contains(@class,"md:max-lg:hidden")]')->length, 'only Invited by drops out on a tablet');
        $this->assertSame(1, $x->query('//form[contains(@action,"/resend")]//button[@type="submit"]')->length, 'resend stays in its form');
        $this->assertSame(1, $x->query('//form[contains(@action,"/revoke")]//button[@type="submit"]')->length, 'revoke stays in its form');
        $this->assertSame(1, $x->query('//*[@data-scroll-x][@data-scroll-current]')->length, 'only the tab strip is a bare scroller');
        $this->assertPillsAreTouchSized($x, 'institution users');
    }

    public function testInstitutionShowIntegrationsAndSubscriptionFitANarrowContainer(): void
    {
        $x = $this->page('pages/admin/institutions/show', [
            'profInstitution' => $this->institution(), 'profSubscription' => ['plan_name' => 'Std', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], 'profStatus' => $this->lifecycle(),
            'profInvitations' => [], 'profMembers' => ['total' => 0, 'perRole' => [], 'rows' => []],
        ]);
        $this->assertSame(1, $x->query('//*[contains(@class,"@container")]/div[contains(@class,"grid")]')->length, 'the cards sit in a container-query grid');
        $this->assertStringContainsString('max-lg:@xl:grid-cols-2', $x->query('//*[contains(@class,"@container")]/div[contains(@class,"grid")]')->item(0)->getAttribute('class'));
        $this->assertPillsAreTouchSized($x, 'institution profile');

        $run = ['status' => 'succeeded', 'started_at' => '2026-11-01 10:00:00', 'direction' => 'inbound', 'dry_run' => 0, 'trigger' => 'manual', 'created_count' => 1, 'updated_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'message' => 'ok'];
        $x   = $this->page('pages/admin/institutions/integrations', [
            'sisInstitution' => $this->institution(), 'sisType' => ['label' => 'Folder', 'fields' => []],
            'sisConnection' => ['flagOn' => true, 'enabled' => true, 'hasCredentials' => false, 'credentialsSetAt' => null, 'folders' => ['inbound' => 'in', 'outbound' => 'out']],
            'sisRuns' => [$run], 'sisLatest' => ['run' => $run + ['dry_run' => 1], 'issues' => [['dataset' => 'people', 'item' => 'x', 'reason' => 'bad']], 'truncated' => false],
        ]);
        $grids = $x->query('//*[@data-scroll-x][@data-sticky-first][@role="region"][@tabindex="0"]');
        $this->assertSame(2, $grids->length, 'the issues and the run history are pinned, keyboard-reachable grids');
        $this->assertClass($x, '//*[@data-section="runs"]//tbody/tr/td[1]', 'left-0', 'the pinned run cell');
        $this->assertPillsAreTouchSized($x, 'integrations');

        $x = $this->page('pages/admin/subscription', [
            'subInstitution' => $this->institution(), 'subscription' => ['plan_name' => 'Std', 'plan_status' => 'active', 'plan_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            'subStatus' => $this->lifecycle(), 'subPlans' => [['id' => 1, 'name' => 'Std']], 'subDefaultCurrency' => 'PHP',
            'subUsage' => ['seats' => ['state' => 'ok', 'used' => 1, 'limit' => 10, 'percent' => 10], 'storage' => ['state' => 'unlimited', 'used_mb' => 1, 'limit_mb' => null, 'percent' => null], 'ai' => ['state' => 'ok', 'used' => 1, 'limit' => 10, 'percent' => 10]],
            'subHistory' => [['id' => 1, 'recorded_at' => '2026-01-01 00:00:00', 'kind' => 'start', 'corrects_renewal_id' => null, 'plan_name' => 'Std', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount' => '10', 'currency' => 'PHP', 'reference' => null, 'notes' => null, 'recorded_by_label' => 'Ana']],
        ]);
        $this->assertSame(1, $x->query('//*[@data-scroll-x][@data-sticky-first][@role="region"][@tabindex="0"]')->length, 'the history is a pinned grid');
        $this->assertClass($x, '//*[@data-section="history"]//tbody/tr/td[1]', 'left-0', 'the pinned history cell');
        $this->assertSame(1, $x->query('//form[contains(@action,"/correct")]//button[@type="submit"]')->length, 'a correction stays in its form');
        $this->assertPillsAreTouchSized($x, 'subscription');

        $x = $this->page('pages/admin/institutions/audit', ['auditInstitution' => $this->institution(), 'auditTotal' => 1, 'auditRows' => [['created_at' => '2026-11-01 10:00:00', 'actor_label' => 'Ana', 'acting_role' => 'admin', 'action' => 'x.y', 'target_type' => null, 'target_id' => null]]]);
        $this->assertSame(2, $x->query('//*[@data-scroll-x]')->length, 'the audit list is a grid beside the tab strip');
        $this->assertClass($x, '//*[@data-section="audit"]//tbody/tr/td[1]', 'left-0', 'the pinned audit cell');
    }
}

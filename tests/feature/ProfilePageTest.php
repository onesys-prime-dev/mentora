<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The owner's /profile page, for every role: what people in the school see of them,
 * what the school manages, and the old student and parent profile URLs.
 *
 * @internal
 */
final class ProfilePageTest extends RouteTestCase
{
    private int $institution;

    private User $ann;

    private User $pam;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PRF');
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@school.example', $this->institution);
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@family.example', $this->institution);

        db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->update(['student_number' => '2026-001', 'middle_name' => 'Marie']);
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->pam->id, 'student_user_id' => $this->ann->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function body(User $user, string $uri = '/profile'): string
    {
        $this->loggedInAs($user);
        $response = $this->call('GET', $uri)->response();
        $this->assertSame(200, $response->getStatusCode(), $uri);

        return (string) $response->getBody();
    }

    public function testEveryRoleGetsTheirPage(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'institutional_admin', 'parent', 'admin'] as $role) {
            $user = PeopleFactory::person($role, 'Pat', ucfirst($role), null, $role === 'admin' ? null : $this->institution);
            $body = $this->body($user);

            $this->assertStringContainsString('My profile', $body, $role);
            $this->assertStringContainsString($role === 'admin' ? 'This is your account&#039;s profile.' : 'This is what people in your school see when they open your profile.', $body, $role);
        }
    }

    public function testTheSystemAdminSeesNameEmailAndRoleOnly(): void
    {
        $admin = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@mentora.example');
        $body  = $this->body($admin);

        $this->assertStringContainsString('sys@mentora.example', $body);
        foreach (['About', 'Office &amp; consultation', 'This term', 'Parents who can see', 'Your children'] as $section) {
            $this->assertStringNotContainsString($section, $body, $section);
        }
    }

    public function testAStudentSeesTheirParentsByNameAndTheirOwnNumber(): void
    {
        $body = $this->body($this->ann);

        $this->assertStringContainsString('Parents who can see your progress', $body);
        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertStringContainsString('2026-001', $body);
        $this->assertStringContainsString('Marie', $body);
        $this->assertStringContainsString('ann@school.example', $body);
        $this->assertStringNotContainsString('pam@family.example', $body, 'never another person email');
        $this->assertStringNotContainsString('Office &amp; consultation', $body);
    }

    public function testAStudentWhosePortalIsOffSeesNoParent(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $body = $this->body($this->ann);

        $this->assertStringContainsString('No parent can see your progress', $body);
        $this->assertStringNotContainsString('Pam Parent', $body);
    }

    public function testAParentSeesTheirChildren(): void
    {
        $body = $this->body($this->pam);

        $this->assertStringContainsString('Your children', $body);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringNotContainsString('ann@school.example', $body);
    }

    public function testAParentWithNoChildrenSeesTheEmptyState(): void
    {
        $body = $this->body(PeopleFactory::person('parent', 'Lone', 'Parent', 'lone@example.test', $this->institution));

        $this->assertStringContainsString('Your children', $body);
        $this->assertStringContainsString('You are not linked to a student yet', $body);
    }

    public function testAParentWhosePortalIsOffSeesNoChildren(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $body = $this->body($this->pam);

        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
    }

    public function testOtherRolesAreTurnedAwayFromTheOldStudentUrlLikeAnyStudentPage(): void
    {
        foreach (['instructor', 'chairperson', 'institutional_admin', 'admin', 'parent'] as $role) {
            $this->loggedInAs(PeopleFactory::person($role, 'Zoe', 'Other', null, $role === 'admin' ? null : $this->institution));

            $this->assertSame(302, $this->call('GET', '/student/profile')->response()->getStatusCode(), $role);
        }
    }

    public function testStaffSeeOfficeAndThisTerm(): void
    {
        $body = $this->body(PeopleFactory::person('instructor', 'Ivy', 'Teach', null, $this->institution));

        $this->assertStringContainsString('Office &amp; consultation', $body);
        $this->assertStringContainsString('This term', $body);
        $this->assertStringContainsString('Not added yet', $body);
        $this->assertStringNotContainsString('Parents who can see', $body);
    }

    public function testLockedOfficialFieldsSayManagedByTheSchool(): void
    {
        foreach ([$this->ann, PeopleFactory::person('instructor', 'Ivy', 'Teach', null, $this->institution)] as $user) {
            $body = $this->body($user);

            $this->assertStringContainsString('Official details', $body);
            $this->assertStringContainsString('Managed by your school', $body);
        }
    }

    public function testTheOldStudentProfileUrlRedirectsPermanentlyAndTheParentPageStaysUntilPhase5(): void
    {
        $this->loggedInAs($this->ann);
        $response = $this->call('GET', '/student/profile')->response();

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/profile', $response->getHeaderLine('Location'));

        $this->loggedInAs($this->pam);
        $this->assertSame(200, $this->call('GET', '/parent/profile')->response()->getStatusCode());
    }

    public function testTheParentFormsStillRespond(): void
    {
        $mailer = FakeEmail::install();
        $this->loggedInAs($this->pam);

        $this->assertSame(302, $this->call('POST', '/parent/profile/notifications', ['email' => [(string) $this->ann->id]])->response()->getStatusCode());
        $this->assertSame(302, $this->call('POST', '/parent/profile/password-link')->response()->getStatusCode());
        $this->assertSame(['pam@family.example'], array_column($mailer->sent, 'to'));

        Services::resetSingle('email');
    }

    public function testTheAccountMenuLinksToTheProfileForEveryRole(): void
    {
        foreach (['student', 'instructor', 'dean', 'parent'] as $role) {
            $user = PeopleFactory::person($role, 'Menu', ucfirst($role), null, $this->institution);
            $body = $this->body($user);

            $this->assertMatchesRegularExpression('#href="[^"]*/profile"[^>]*>(?:(?!</a>).)*My Profile#s', $body, $role);
            $this->assertMatchesRegularExpression('#aria-disabled="true"(?:(?!</nav>).)*Help &amp; Support(?:(?!</nav>).)*Coming soon#s', $body, $role);
        }
    }

    private function staffUser(string $role = 'instructor'): User
    {
        return PeopleFactory::person($role, 'Ivy', 'Teach', null, $this->institution);
    }

    /** @param array<string, mixed> $set */
    private function profileUpdate(User $u, array $set): void
    {
        db_connect()->table('user_profiles')->where('user_id', $u->id)->update($set);
    }

    /** @return list<string> */
    private function checklist(string $body): array
    {
        preg_match_all('/data-checklist-item="([a-z]+)"/', $body, $m);

        return $m[1];
    }

    public function testTheStaffChecklistListsWhatIsMissingAndDropsWhatIsFilled(): void
    {
        $ivy = $this->staffUser();

        $this->assertEqualsCanonicalizing(['consultation', 'office', 'pronouns', 'bio'], $this->checklist($this->body($ivy)));

        $this->profileUpdate($ivy, ['office_location' => 'CS 204', 'bio' => 'Hello']);
        $this->assertEqualsCanonicalizing(['consultation', 'pronouns'], $this->checklist($this->body($ivy)));
    }

    public function testAStudentAndAParentOnlyGetPronounsAndBio(): void
    {
        $this->assertEqualsCanonicalizing(['pronouns', 'bio'], $this->checklist($this->body($this->ann)));
        $this->assertEqualsCanonicalizing(['pronouns', 'bio'], $this->checklist($this->body($this->pam)));
    }

    public function testTheChecklistCardIsHiddenWhenEverythingIsFilled(): void
    {
        $this->profileUpdate($this->ann, ['pronouns' => 'she/her', 'bio' => 'Hi']);

        $this->assertStringNotContainsString('data-profile-checklist', $this->body($this->ann));
    }

    public function testTheSystemAdminHasNoChecklistAndNoToggle(): void
    {
        $body = $this->body(PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@mentora.example'));

        $this->assertStringNotContainsString('data-profile-checklist', $body);
        $this->assertStringNotContainsString('data-profile-preview-toggle', $body);
    }

    public function testDismissingTheChecklistKeepsItHiddenAfterReload(): void
    {
        $ivy = $this->staffUser();

        $this->assertStringContainsString('data-profile-checklist', $this->body($ivy));
        $this->step('POST', 'profile/checklist/dismiss')->assertRedirectTo(site_url('profile'));

        $this->assertNotNull(db_connect()->table('user_profiles')->where('user_id', $ivy->id)->get()->getRow()->checklist_dismissed_at);
        $this->assertStringNotContainsString('data-profile-checklist', $this->body($ivy));
    }

    public function testDismissOnlyEverTouchesTheSignedInPerson(): void
    {
        $this->loggedInAs($this->ann);
        $this->step('POST', 'profile/checklist/dismiss', ['user_id' => $this->pam->id]);

        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $this->pam->id)->get()->getRow()->checklist_dismissed_at);
        $this->assertNotNull(db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->get()->getRow()->checklist_dismissed_at);
    }

    public function testAReadOnlyInstitutionRefusesTheDismiss(): void
    {
        $ro = PeopleFactory::person('instructor', 'Ro', 'Teach', null, PeopleFactory::institution('PRRO', 'read_only'));
        $this->loggedInAs($ro);
        $this->step('POST', 'profile/checklist/dismiss');

        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $ro->id)->get()->getRow()->checklist_dismissed_at);
    }

    public function testAStudentPreviewingHidesEmailCoursesOfficialDetailsAndPencils(): void
    {
        $body = $this->body($this->ann, '/profile?preview=student');
        $body = substr($body, (int) strpos($body, 'data-profile-preview-toggle')); // the account menu above shows the signed-in person's own email

        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringNotContainsString('ann@school.example', $body);
        $this->assertStringNotContainsString('data-profile-official', $body);
        $this->assertStringNotContainsString('Managed by your school', $body);
        $this->assertStringNotContainsString('Student number', $body);
        $this->assertStringNotContainsString('data-profile-contact', $body);
        $this->assertStringNotContainsString('2026-001', $body);
        $this->assertStringNotContainsString('data-dialog-open="profile-edit', $body);
        $this->assertStringNotContainsString('data-profile-checklist', $body);
        $this->assertStringNotContainsString('data-profile-parents', $body);
    }

    public function testAStudentPreviewShowsProgramYearSectionAndCollege(): void
    {
        ['college' => $c] = DeanFactory::college('SD', $this->institution);
        $prog = DeanFactory::program(DeanFactory::department($c, 'SD'), 'SD');
        $term = DeanFactory::term($this->institution, 'Now', date('Y-m-d', strtotime('-10 days')));
        $this->profileUpdate($this->ann, ['program_id' => $prog]);
        $db = db_connect();
        $db->table('class_sections')->insert(['institution_id' => $this->institution, 'term_id' => $term, 'program_id' => $prog, 'year_level' => 3, 'code' => 'BSIT-3A']);
        $db->table('class_section_students')->insert(['class_section_id' => $db->insertID(), 'student_user_id' => $this->ann->id]);

        $body = $this->body($this->ann, '/profile?preview=student');
        $body = substr($body, (int) strpos($body, 'data-profile-preview-toggle'));

        $this->assertStringContainsString('data-profile-studies', $body);
        foreach (['Program SD', 'BSIT-3A'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        $this->assertMatchesRegularExpression('#Year</dt>\s*<dd[^>]*>\s*3\s*<#', $body);
        $this->assertStringContainsString('College</dt>', $body);
        $this->assertStringNotContainsString('data-profile-official', $body);
    }

    public function testOwnerPageKeepsOfficialDetailsWithTheStudentNumber(): void
    {
        $body = $this->body($this->ann);

        $this->assertStringContainsString('data-profile-official', $body);
        $this->assertStringContainsString('Managed by your school', $body);
        $this->assertStringContainsString('2026-001', $body);
        $this->assertStringNotContainsString('data-profile-contact', $body);
    }

    public function testThisTermShowsSectionCodesAndSplitsTeachingFromEnrolled(): void
    {
        ['college' => $c] = DeanFactory::college('TT', $this->institution);
        $prog = DeanFactory::program(DeanFactory::department($c, 'TT'), 'TT');
        $term = DeanFactory::term($this->institution, 'Now', date('Y-m-d', strtotime('-10 days')));
        $ivy  = $this->staffUser();
        db_connect()->table('auth_groups_users')->insert(['user_id' => $ivy->id, 'group' => 'student', 'created_at' => date('Y-m-d H:i:s')]);
        CourseFactory::assignInstructor(DeanFactory::offering($prog, $term, 'IT101', 'A', 'active', 'Intro'), $ivy);
        CourseFactory::assignInstructor(DeanFactory::offering($prog, $term, 'IT101', 'B', 'active', 'Intro'), $ivy);
        CourseFactory::enroll(DeanFactory::offering($prog, $term, 'IT200', 'C', 'active', 'Data'), $ivy);
        $body = $this->body($ivy);

        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('IT101 B', $body);
        $this->assertStringContainsString('IT200 C', $body);
        $this->assertStringContainsString('>Teaching<', $body);
        $this->assertStringContainsString('>Enrolled<', $body);
    }

    public function testAViewerNeverSeesNotAddedYet(): void
    {
        $ivy  = $this->staffUser();
        $this->profileUpdate($ivy, ['office_location' => 'CS 204']);
        $body = $this->body($ivy, '/profile?preview=student');
        $body = substr($body, (int) strpos($body, 'data-profile-preview-toggle'));

        $this->assertStringContainsString('CS 204', $body);
        $this->assertStringNotContainsString('Work phone', $body);
        $this->assertStringNotContainsString('Consultation', $body);
        $this->assertStringNotContainsString('Not added yet', $body);
        $this->assertStringNotContainsString('data-profile-about', $body);

        $this->profileUpdate($ivy, ['office_location' => '']);
        $body = $this->body($ivy, '/profile?preview=student');
        $this->assertStringNotContainsString('data-profile-office', $body);
        $this->assertStringContainsString('Not added yet', $this->body($ivy), 'the owner still sees it');
    }

    public function testTheSystemAdminPageHasNoLockLine(): void
    {
        $body = $this->body(PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@mentora.example'));

        $this->assertStringNotContainsString('Managed by your school', $body);
        $this->assertStringNotContainsString('people in your school', $body);
    }

    public function testAnInstructorPreviewShowsOfficeAndConsultation(): void
    {
        $ivy = $this->staffUser();
        $this->profileUpdate($ivy, ['office_location' => 'CS 204', 'consultation_hours' => 'Mon 1-3']);
        $body = $this->body($ivy, '/profile?preview=student');

        $this->assertStringContainsString('CS 204', $body);
        $this->assertStringContainsString('Mon 1-3', $body);
        $this->assertStringContainsString('data-profile-contact', $body);
        $this->assertStringNotContainsString('Managed by your school', $body);
        $this->assertStringNotContainsString('data-profile-official', $body);
        $this->assertStringNotContainsString('data-dialog-open="profile-edit', $body);
    }

    public function testAParentOnlyPreviewSaysStudentsCannotSeeThem(): void
    {
        $body = $this->body($this->pam, '/profile?preview=student');

        $this->assertStringContainsString("Students in your school can't see your profile.", html_entity_decode($body, ENT_QUOTES));
        $this->assertStringNotContainsString('data-profile-band', $body);
    }

    public function testTheToggleIsAPairOfLinks(): void
    {
        $body = $this->body($this->ann);

        $this->assertStringContainsString('data-profile-preview-toggle', $body);
        $this->assertMatchesRegularExpression('#href="[^"]*/profile\?preview=student"#', html_entity_decode($body));
        $this->assertStringContainsString('data-profile-band', $body);
    }

    public function testPreferNotToSayIsStoredButNeverShown(): void
    {
        $this->profileUpdate($this->ann, ['pronouns' => 'prefer not to say']);

        $own = $this->body($this->ann);
        $this->assertStringNotContainsString('prefer not to say', strtolower(preg_replace('#<select.*?</select>#s', '', $own)));
        $this->assertMatchesRegularExpression('#<option value="prefer not to say" selected#', html_entity_decode($own));
        $this->assertStringNotContainsString('prefer not to say', strtolower($this->body($this->ann, '/profile?preview=student')));
    }
}

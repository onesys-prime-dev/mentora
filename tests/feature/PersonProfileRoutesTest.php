<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Other people's profiles through HTTP: /people/{id} (page), /card (popover) and /panel (modal).
 * Each FR2 cell is checked by seeded values: what the viewer may see is in the body, the rest is not.
 *
 * @internal
 */
final class PersonProfileRoutesTest extends RouteTestCase
{
    use MessagingScenario;

    private const ENDPOINTS = ['', '/card', '/panel'];

    private User $staff;

    private User $stud;

    private User $par;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        $this->buildScenario(); // Messages on; $this->student and $this->instructor share a course

        $db = db_connect();
        ['college' => $college] = DeanFactory::college('PX', $this->institution);
        $program = DeanFactory::program(DeanFactory::department($college, 'PX'), 'PXZ');

        $this->staff = PeopleFactory::person('instructor', 'Sabrina', 'Staffer', 'staff-subj@x.test', $this->institution);
        $this->stud  = PeopleFactory::person('student', 'Tobias', 'Studwell', 'student-subj@x.test', $this->institution);
        $this->par   = PeopleFactory::person('parent', 'Penelope', 'Parentson', 'parent-subj@x.test', $this->institution);

        $db->table('user_profiles')->where('user_id', $this->staff->id)->update(['bio' => 'BIO-STAFF', 'office_location' => 'OFFICE-STAFF']);
        $db->table('user_profiles')->where('user_id', $this->stud->id)->update(['bio' => 'BIO-STUDENT', 'program_id' => $program]);
        $db->table('user_profiles')->where('user_id', $this->par->id)->update(['bio' => 'BIO-PARENT']);

        CourseFactory::assignInstructor(DeanFactory::offering($this->program, $this->term, 'ST1', 'A', 'active', 'COURSE-STAFF'), $this->staff);
        CourseFactory::enroll(DeanFactory::offering($this->program, $this->term, 'SU1', 'A', 'active', 'COURSE-STUDENT'), $this->stud);

        $this->link($this->par, $this->stud);
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function signIn(User $user): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($user);
    }

    /** @return array{int, string, string} status, body, Cache-Control */
    private function fetch(string $uri): array
    {
        try {
            $r = $this->call('GET', $uri)->response();

            return [$r->getStatusCode(), (string) $r->getBody(), $r->getHeaderLine('Cache-Control')];
        } catch (PageNotFoundException $e) {
            return [404, $e->getMessage(), ''];
        }
    }

    /**
     * The three bodies, each asserted 200. The page body is cut after the layout's account menu, which shows the viewer.
     *
     * @return array<string, string>
     */
    private function bodies(User $viewer, User $subject): array
    {
        $this->signIn($viewer);
        $out = [];
        foreach (self::ENDPOINTS as $suffix) {
            $uri          = "/people/{$subject->id}{$suffix}";
            [$status, $body] = $this->fetch($uri);
            $this->assertSame(200, $status, $uri);
            $out[$uri] = $suffix === '' ? substr($body, (int) strpos($body, 'data-profile-band')) : $body;
        }

        return $out;
    }

    /** @param list<string> $present @param list<string> $absent */
    private function sees(User $viewer, User $subject, array $present, array $absent): void
    {
        foreach ($this->bodies($viewer, $subject) as $uri => $body) {
            foreach ($present as $i => $text) {
                // The card is the short summary: of the visible values it carries only the name (listed first).
                if (str_ends_with($uri, '/card') && $i > 0) {
                    continue;
                }
                $this->assertStringContainsString($text, $body, "{$uri} should show {$text}");
            }
            foreach ($absent as $text) {
                $this->assertStringNotContainsString($text, $body, "{$uri} must hide {$text}");
            }
        }
    }

    private function assertHidden(User $viewer, int $subjectId, string $name): void
    {
        $this->signIn($viewer);
        foreach (self::ENDPOINTS as $suffix) {
            [$status, $body] = $this->fetch("/people/{$subjectId}{$suffix}");
            $this->assertSame(404, $status, "/people/{$subjectId}{$suffix}");
            $this->assertStringNotContainsString($name, $body);
        }
    }

    // --- AC2 matrix ---------------------------------------------------------------

    public function testStaffSeeStaffInFull(): void
    {
        $this->sees($this->instructor, $this->staff, ['Sabrina Staffer', 'BIO-STAFF', 'OFFICE-STAFF', 'staff-subj@x.test', 'COURSE-STAFF'], []);
    }

    public function testStaffSeeAStudentsStudiesContactAndCourses(): void
    {
        $this->sees($this->instructor, $this->stud, ['Tobias Studwell', 'BIO-STUDENT', 'Program PXZ', 'student-subj@x.test', 'COURSE-STUDENT', 'Enrolled'], []);
    }

    public function testStaffSeeAParentsNameOnly(): void
    {
        $this->sees($this->instructor, $this->par, ['Penelope Parentson'], ['BIO-PARENT', 'parent-subj@x.test']);
    }

    public function testStudentsSeeStaffInFull(): void
    {
        $this->sees($this->student, $this->staff, ['Sabrina Staffer', 'BIO-STAFF', 'OFFICE-STAFF', 'staff-subj@x.test', 'COURSE-STAFF'], []);
    }

    public function testStudentsSeeAClassmateWithoutContactOrCourses(): void
    {
        $this->sees($this->student, $this->stud, ['Tobias Studwell', 'BIO-STUDENT', 'Program PXZ'], ['student-subj@x.test', 'COURSE-STUDENT']);
    }

    public function testAStudentSeesTheirOwnParentsNameOnly(): void
    {
        $this->sees($this->stud, $this->par, ['Penelope Parentson'], ['BIO-PARENT', 'parent-subj@x.test']);
    }

    public function testParentsSeeStaffInFull(): void
    {
        $this->sees($this->par, $this->staff, ['Sabrina Staffer', 'BIO-STAFF', 'OFFICE-STAFF', 'staff-subj@x.test', 'COURSE-STAFF'], []);
    }

    public function testAParentSeesTheirOwnChildWithoutContactOrTheirOwnParentList(): void
    {
        $this->sees($this->par, $this->stud, ['Tobias Studwell', 'BIO-STUDENT', 'Program PXZ', 'COURSE-STUDENT'], ['student-subj@x.test', 'Parents who can see', 'data-profile-parents']);
    }

    public function testAnInstructorWhoIsAlsoAParentViewsAsStaff(): void
    {
        $both = PeopleFactory::person('instructor', 'Ira', 'Both', null, $this->institution);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $both->id, 'group' => 'parent', 'created_at' => date('Y-m-d H:i:s')]);

        $this->sees($both, $this->stud, ['Tobias Studwell', 'BIO-STUDENT', 'student-subj@x.test', 'COURSE-STUDENT'], []);
        $this->sees($both, $this->par, ['Penelope Parentson'], ['BIO-PARENT', 'parent-subj@x.test']);
    }

    public function testAStudentWhoTeachesShowsOfficeButNoStudentContactToStudents(): void
    {
        db_connect()->table('auth_groups_users')->insert(['user_id' => $this->stud->id, 'group' => 'instructor', 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('user_profiles')->where('user_id', $this->stud->id)->update(['office_location' => 'OFFICE-DUAL']);

        $this->sees($this->student, $this->stud, ['Tobias Studwell', 'OFFICE-DUAL'], ['student-subj@x.test', 'COURSE-STUDENT']);
    }

    // --- 404s ---------------------------------------------------------------------

    public function testInvisibleSubjectsAnswer404WithoutTheirName(): void
    {
        $other    = PeopleFactory::institution('PXO');
        $outsider = PeopleFactory::person('instructor', 'Olga', 'Outsider', null, $other);
        $lone     = PeopleFactory::person('parent', 'Lonny', 'Loneparent', null, $this->institution);
        $sys      = PeopleFactory::person('admin', 'Sigmund', 'Sysadmin', 'sys@mentora.example');

        $this->assertHidden($outsider, (int) $this->staff->id, 'Sabrina Staffer');
        $this->assertHidden($this->instructor, (int) $outsider->id, 'Olga Outsider');
        $this->assertHidden($lone, (int) $this->stud->id, 'Tobias Studwell');
        $this->assertHidden($this->student, (int) $this->par->id, 'Penelope Parentson');
        $this->assertHidden($this->instructor, 999999, 'Sabrina');
        $this->assertHidden($this->instructor, (int) $sys->id, 'Sigmund');
    }

    // --- Deactivated ----------------------------------------------------------------

    public function testADeactivatedPersonShowsTheBadgeAndNothingElseEvenToStaffAndTheirAdmin(): void
    {
        $gone = PeopleFactory::person('instructor', 'Rico', 'Gonely', 'rico@x.test', $this->institution, false);
        db_connect()->table('user_profiles')->where('user_id', $gone->id)->update(['bio' => 'BIO-GONE', 'office_location' => 'OFFICE-GONE']);
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ([$this->instructor, $admin, $this->student] as $viewer) {
            foreach ($this->bodies($viewer, $gone) as $uri => $body) {
                $this->assertStringContainsString('Rico Gonely', $body, $uri);
                $this->assertStringContainsString('Deactivated', $body, $uri);
                foreach (['BIO-GONE', 'OFFICE-GONE', 'rico@x.test', 'data-person-message', 'data-profile-contact'] as $hidden) {
                    $this->assertStringNotContainsString($hidden, $body, "{$uri} {$hidden}");
                }
            }
        }
    }

    public function testTheirAdminStillGetsOpenInPeopleOnADeactivatedPerson(): void
    {
        $gone  = PeopleFactory::person('instructor', 'Rico', 'Gonely', 'rico@x.test', $this->institution, false);
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ($this->bodies($admin, $gone) as $uri => $body) {
            if (str_ends_with($uri, '/card')) {
                continue;
            }
            $this->assertStringContainsString('data-profile-admin', $body, $uri);
            $this->assertStringContainsString('Open in People', $body, $uri);
            $this->assertStringNotContainsString('data-person-message', $body, $uri);
            $this->assertStringNotContainsString('rico@x.test', $body, $uri);
        }
        foreach ($this->bodies($this->instructor, $gone) as $uri => $body) {
            $this->assertStringNotContainsString('data-profile-admin', $body, $uri);
        }
    }

    public function testADeactivatedPersonStaysHiddenFromWhoCouldNotSeeThemActive(): void
    {
        $gone = PeopleFactory::person('student', 'Gina', 'Gonestud', null, $this->institution, false);
        $lone = PeopleFactory::person('parent', 'Lonny', 'Loneparent', null, $this->institution);

        $this->assertHidden($lone, (int) $gone->id, 'Gina Gonestud');
    }

    // --- Message ------------------------------------------------------------------

    public function testMessageShowsWhenMessagesAllowsThePair(): void
    {
        foreach ($this->bodies($this->student, $this->instructor) as $uri => $body) {
            $this->assertStringContainsString('data-person-message', $body, $uri);
        }
    }

    /** Sabrina has blocked the student and does not teach them, so the block binds and Messages refuses the pair. */
    private function staffBlocksStudent(): void
    {
        db_connect()->table('user_blocks')->insert(['institution_id' => $this->institution, 'blocker_id' => $this->staff->id, 'blocked_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testMessageIsAbsentWhenMessagesRefusesThePair(): void
    {
        $this->staffBlocksStudent();

        foreach ($this->bodies($this->student, $this->staff) as $uri => $body) {
            $this->assertStringNotContainsString('data-person-message', $body, $uri);
        }
    }

    public function testMessageIsAbsentInAReadOnlyInstitutionWithoutAThread(): void
    {
        foreach ($this->bodies($this->student, $this->staff) as $uri => $body) {
            $this->assertStringContainsString('data-person-message', $body, "{$uri}: reachable while writable");
        }

        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        foreach ($this->bodies($this->student, $this->staff) as $uri => $body) {
            $this->assertStringNotContainsString('data-person-message', $body, $uri);
        }
    }

    /** FR11: reading stays open, so an existing thread is still reachable from the profile in a read-only institution. */
    public function testMessageReopensAnExistingThreadInAReadOnlyInstitution(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        foreach ($this->bodies($this->student, $this->instructor) as $uri => $body) {
            $this->assertStringContainsString('data-person-message', $body, $uri);
        }

        $this->signIn($this->student);
        $this->step('POST', "people/{$this->instructor->id}/message")->assertRedirectTo(site_url('messages/' . $this->conversation));
    }

    public function testCopyEmailSitsBesideMessageNotInTheContactCard(): void
    {
        foreach ($this->bodies($this->instructor, $this->staff) as $uri => $body) {
            if (str_ends_with($uri, '/card')) {
                $this->assertStringNotContainsString('data-profile-copy-email', $body, $uri);

                continue;
            }
            $this->assertStringContainsString('staff-subj@x.test', $body, $uri);
            $band = substr($body, 0, (int) strpos($body, 'data-profile-contact'));
            $this->assertStringContainsString('data-profile-copy-email', $band, "{$uri}: in the action row, before the Contact card");
            $this->assertSame(1, substr_count($body, 'data-profile-copy-email'), $uri);
        }
    }

    public function testMessageIsAbsentOnYourOwnCard(): void
    {
        $this->signIn($this->student);
        foreach (['/card', '/panel'] as $suffix) {
            [$status, $body] = $this->fetch("/people/{$this->student->id}{$suffix}");
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString('data-person-message', $body);
            $this->assertStringNotContainsString('data-dialog-open="profile-edit', $body);
        }
    }

    public function testPostingMessageReusesTheConversationAndOpensIt(): void
    {
        $this->signIn($this->student);
        $this->withSession();

        $this->step('POST', "people/{$this->instructor->id}/message")->assertRedirectTo(site_url('messages/' . $this->conversation));
    }

    public function testPostingMessageOpensTheDraftAndCreatesNothing(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->signIn($admin);
        $this->withSession();

        $result = $this->step('POST', "people/{$this->stud->id}/message");

        $result->assertRedirectTo(site_url('messages?to=' . $this->stud->id));
        $this->assertSame(0, db_connect()->table('conversations')->where('created_by', $admin->id)->countAllResults());
    }

    public function testPostingMessageToARefusedPairIsRefused(): void
    {
        $this->staffBlocksStudent();
        $this->signIn($this->student);
        $this->withSession();

        try {
            $status = $this->step('POST', "people/{$this->staff->id}/message")->response()->getStatusCode();
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status);
        $this->assertSame(0, db_connect()->table('conversations')->where('created_by', $this->student->id)->where('id !=', $this->conversation)->countAllResults());
    }

    public function testPostingMessageToAnInvisiblePersonIs404(): void
    {
        $outsider = PeopleFactory::person('instructor', 'Olga', 'Outsider', null, PeopleFactory::institution('PXQ'));
        $this->signIn($this->student);
        $this->withSession();

        try {
            $response = $this->step('POST', "people/{$outsider->id}/message")->response();
            $status   = $response->getStatusCode();
            $this->assertStringNotContainsString('Olga', (string) $response->getBody());
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status);
    }

    /** A parent whose $n children are all in a course $this->instructor teaches. */
    private function parentOfPupils(int $n): User
    {
        $parent   = PeopleFactory::person('parent', 'Mona', 'Multi', null, $this->institution);
        $offering = DeanFactory::offering($this->program, $this->term, 'PK' . $n);
        CourseFactory::assignInstructor($offering, $this->instructor);
        for ($i = 0; $i < $n; $i++) {
            $child = PeopleFactory::person('student', 'Kid' . $i, 'Multi', null, $this->institution);
            CourseFactory::enroll($offering, $child);
            $this->link($parent, $child);
        }

        return $parent;
    }

    private function postMessageStatus(User $viewer, User $subject): int
    {
        $this->signIn($viewer);
        $this->withSession();

        try {
            return $this->step('POST', "people/{$subject->id}/message")->response()->getStatusCode();
        } catch (PageNotFoundException) {
            return 404;
        }
    }

    public function testAParentSharingOneChildWithTheInstructorCanMessageThem(): void
    {
        $parent = $this->parentOfPupils(1);

        foreach ($this->bodies($parent, $this->instructor) as $uri => $body) {
            $this->assertStringContainsString('data-person-message', $body, $uri);
        }
        $this->assertSame(302, $this->postMessageStatus($parent, $this->instructor));
        $this->assertSame(0, db_connect()->table('conversations')->where('created_by', $parent->id)->countAllResults());
    }

    public function testAParentSharingTwoChildrenWithTheInstructorGetsNoButtonThatWouldFail(): void
    {
        $parent = $this->parentOfPupils(2);

        foreach ($this->bodies($parent, $this->instructor) as $uri => $body) {
            $this->assertStringNotContainsString('data-person-message', $body, $uri);
        }
        $this->assertSame(404, $this->postMessageStatus($parent, $this->instructor));
        $this->assertSame(0, db_connect()->table('conversations')->where('created_by', $parent->id)->countAllResults());
    }

    // --- Admin row, self, caching ---------------------------------------------------

    public function testTheAdminRowIsOnlyForTheSubjectsOwnInstitutionalAdmin(): void
    {
        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        foreach ($this->bodies($admin, $this->staff) as $uri => $body) {
            if (str_ends_with($uri, '/card')) {
                continue;
            }
            $this->assertStringContainsString('data-profile-admin', $body, $uri);
            $this->assertStringContainsString('Open in People', $body, $uri);
            $this->assertStringContainsString('institution/people?q=', html_entity_decode($body), $uri);
        }
        foreach ($this->bodies($this->instructor, $this->staff) as $uri => $body) {
            $this->assertStringNotContainsString('data-profile-admin', $body, $uri);
        }

        $foreignAdmin = PeopleFactory::person('institutional_admin', 'Fay', 'Foreign', null, PeopleFactory::institution('PXF'));
        $this->assertHidden($foreignAdmin, (int) $this->staff->id, 'Sabrina Staffer');
    }

    public function testYourOwnPersonPageRedirectsToYourProfile(): void
    {
        $this->signIn($this->student);

        $this->call('GET', "/people/{$this->student->id}")->assertRedirectTo(site_url('profile'));
    }

    public function testFragmentsArePrivateAndNotStored(): void
    {
        $this->signIn($this->instructor);
        foreach (['/card', '/panel'] as $suffix) {
            [$status, $body, $cache] = $this->fetch("/people/{$this->staff->id}{$suffix}");
            $this->assertSame(200, $status);
            $this->assertStringContainsString('private', $cache);
            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringNotContainsString('<html', $body, 'a fragment has no layout');
        }
    }

    public function testThePageHasTheLayoutAndThePersonsName(): void
    {
        $this->signIn($this->instructor);
        [$status, $body] = $this->fetch("/people/{$this->staff->id}");

        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('#<h1[^>]*>(?:(?!</h1>).)*Sabrina Staffer#s', $body);
        $this->assertStringContainsString('data-person-panel', $this->fetch("/people/{$this->staff->id}/card")[1]);
    }
}

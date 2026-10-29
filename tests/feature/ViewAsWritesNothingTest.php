<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Viewing as someone saves nothing on their behalf, even by opening a page (audit decision 3.2).
 *
 * While an administrator views as a student, parent, instructor, chairperson or dean, every GET route the app
 * has is requested (its number placeholders filled with that person's offering, quiz and child), and every
 * table must be exactly as it was, except the audit log, whose entries name the administrator. A GET that
 * starts writing later fails here.
 *
 * @internal
 */
final class ViewAsWritesNothingTest extends RouteTestCase
{
    /**
     * Not the person's: a course's gradebook is set up the first time anyone opens it (its default scheme, its
     * items, the calculated records). The same rows whoever comes first, attributed to nobody (ruling, plan 5).
     */
    private const LAZY_SETUP = ['grading_schemes', 'grade_items', 'gradebook_records'];

    /** Never requested: they end the view itself, or leave the site. */
    private const SKIP = ['#(^|/)logout$#', '#^impersonation/stop$#', '#^institution/view-as#'];

    private int $institution;

    private User $admin;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, int> the id a number placeholder gets, by the path segment before it (see idFor) */
    private array $ids = [];

    /** @var list<string> what was not requested, with why */
    private array $skipped = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        FakeEmail::install();

        $home              = DeanFactory::department('VW');
        $this->institution = $home['institution'];
        $sub               = DeanFactory::subDepartment($home['department'], 'VW');
        $offering          = DeanFactory::offering(DeanFactory::program($sub, 'VW'), DeanFactory::term($this->institution), 'IT140');

        $this->admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@vw.test', $this->institution);

        $student    = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@vw.test', $this->institution);
        $instructor = PeopleFactory::person('instructor', 'Tess', 'Teacher', 'tess@vw.test', $this->institution);
        $parent     = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@vw.test', $this->institution);
        CourseFactory::assignInstructor($offering, $instructor);
        CourseFactory::enroll($offering, $student);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        // One of each thing a page opens: a module with a video and a reading (with a file), a quiz, a discussion.
        $module = CourseFactory::module($offering);
        $unit   = CourseFactory::unit($module);
        [$video, $reading] = CourseFactory::items($unit, [['type' => 'video'], ['type' => 'reading']]);
        CourseFactory::resource($video, 'video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $file = CourseFactory::file($instructor);
        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);
        CourseFactory::resource($reading, 'file', null, $file['id']);
        $quiz = CourseFactory::assessment($offering, $instructor, $module);
        AssessmentFactory::open($quiz, ['attempt_limit' => 2]);
        AssessmentFactory::question($quiz, $instructor, 'Essay', 5.0);
        $thread = CourseFactory::thread($offering, $instructor, $video);

        $this->people = [
            'student'     => $student,
            'parent'      => $parent,
            'instructor'  => $instructor,
            'chairperson' => DeanFactory::chair($sub, 'Maria'),
            'dean'        => DeanFactory::dean($this->institution, $home['department']),
        ];
        $this->ids = [
            'children' => (int) $student->id, 'courses' => $offering, 'offering' => $offering, 'modules' => $module, 'units' => $unit,
            'items' => $video, 'assessments' => $quiz, 'quiz' => $quiz, 'discussions' => $thread, 'threads' => $thread,
            'files' => $file['id'], 'student' => (int) $student->id, 'students' => (int) $student->id,
        ];
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function provideViewedRoles(): iterable
    {
        foreach (['student', 'parent', 'instructor', 'chairperson', 'dean'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideViewedRoles')]
    public function testOpeningEveryPageWhileViewingAsThemSavesNothing(string $role): void
    {
        $this->signIn($this->admin);
        $this->step('POST', "institution/view-as/{$this->people[$role]->id}", ['reason' => 'Checking what they see']);
        $this->assertSame((int) $this->people[$role]->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view started');

        $before = $this->snapshot();
        $status = [];

        foreach ($this->urls() as $url) {
            Services::resetSingle('auth');

            try {
                $status[$url] = $this->step('GET', $url)->response()->getStatusCode();
            } catch (Throwable) {
                // A page that fails for these ids still must not have written anything; the snapshot says.
                $status[$url] = 500;
            }
        }

        $changed = array_keys(array_diff_assoc($this->snapshot(), $before));

        // The ids reach real pages: the person's own area answers, not just 404s.
        $opened = array_keys(array_filter($status, static fn (int $code): bool => $code === 200));
        $this->assertGreaterThan(5, count($opened), 'pages that opened while viewing as a ' . $role);

        if ($role === 'parent') {
            $this->assertSame(200, $status['parent/children/' . $this->ids['children']] ?? null, "the child's page opens");
        }

        if ($role === 'student') {
            // A lesson link opens the course page on the lesson (a redirect to modules?item=, audit 5.1).
            $this->assertContains(302, [$status['student/courses/' . $this->ids['courses'] . '/course/modules/items/' . $this->ids['items']] ?? null, $status['courses/' . $this->ids['courses'] . '/course/modules/items/' . $this->ids['items']] ?? null], 'the video item opens');
        }

        $this->assertSame([], $changed, 'tables written while viewing as a ' . $role);
        $this->assertSame(0, db_connect()->table('assessment_submissions')->countAllResults(), 'opening the quiz started no attempt');
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                         = ['user' => $user->id, 'at' => time()];
        $this->withSession();
    }

    /**
     * Every GET route, its placeholders filled: a number gets the seeded id of what the segment before it names
     * (children → the child, items → the video item, assessments → the quiz, files → the file, ...), else the
     * offering; anything else gets a slug. What can't be filled or must not be opened is listed in $skipped.
     *
     * @return list<string>
     */
    private function urls(): array
    {
        $urls = [];

        foreach (array_keys(service('routes')->getRoutes('GET')) as $route) {
            $url = preg_replace_callback('#([^/]*)/\(([^()]*)\)#', function (array $m): string {
                $isNumber = str_contains($m[2], '0-9') && ! str_contains($m[2], 'a-z');

                return $m[1] . '/' . ($isNumber ? (string) ($this->ids[$m[1]] ?? $this->ids['courses']) : 'course');
            }, '/' . $route);
            $url = $url === null ? null : ltrim($url, '/');

            if ($url === null || preg_match('#[()\[\]\\\\^$*+?]#', $url) === 1) {
                $this->skipped[] = "{$route}: a placeholder this test can't fill";

                continue;
            }

            if (array_filter(self::SKIP, static fn (string $skip): bool => preg_match($skip, $url) === 1) !== []) {
                $this->skipped[] = "{$url}: ends the view or leaves the site";

                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    /** A fingerprint of every table but the audit log. @return array<string, string> */
    private function snapshot(): array
    {
        $db    = db_connect();
        $state = [];

        foreach ($db->listTables() as $table) {
            if (in_array($table, ['audit_logs', 'migrations', ...self::LAZY_SETUP], true)) {
                continue;
            }

            $state[$table] = md5(serialize($db->table($table)->get()->getResultArray()));
        }

        return $state;
    }
}

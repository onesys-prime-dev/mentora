<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Profiles\ProfilePhotoService;
use App\Services\Storage\StorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\FakeEmail;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Task 8.1: every route the User Profile feature added, against every kind of caller, answers the exact status
 * expected. GETs are sent as a browser; POSTs as the page scripts send them (JSON), so a refusal by a filter is an
 * exact 401/403/404 rather than a redirect. A route a caller may use answers what its controller answers for an
 * empty form; the cell only proves the caller got past the gates (or did not).
 *
 * Placeholders: {s} the subject (a student of the institution), {r} a correction request routed to the
 * institution, {p} one routed to the platform.
 *
 * @internal
 */
final class ProfileRouteSecurityTest extends RouteTestCase
{
    use MessagingScenario;

    /** Every route the feature added (Routes.php), as [verb, path]. */
    private const ROUTES = [
        ['GET', 'profile'],
        // Before the photo removals below, so the photo cells see the subject's photo.
        ['GET', 'people/{s}'],
        ['GET', 'people/{s}/card'],
        ['GET', 'people/{s}/panel'],
        ['GET', 'people/{s}/photo/64'],
        ['GET', 'people/{s}/photo/256'],
        ['POST', 'profile/checklist/dismiss'],
        ['POST', 'profile/identity'],
        ['POST', 'profile/about'],
        ['POST', 'profile/staff'],
        ['POST', 'profile/corrections'],
        ['POST', 'profile/identity-official'],
        ['POST', 'profile/photo'],
        ['POST', 'profile/photo/remove'],
        ['POST', 'people/{s}/message'],
        ['GET', 'settings'],
        ['POST', 'settings/password'],
        ['POST', 'settings/password-link'],
        ['POST', 'settings/two-factor'],
        ['POST', 'settings/notifications'],
        ['GET', 'email-change/not-a-token'],
        ['POST', 'email-change/not-a-token'],
        ['GET', 'student/profile'],
        ['GET', 'parent/profile'],
        ['POST', 'parent/profile/notifications'],
        ['POST', 'parent/profile/password-link'],
        ['GET', 'messages/api/preferences'],
        ['POST', 'institution/people/{s}/photo'],
        ['POST', 'institution/people/{s}/photo/remove'],
        ['POST', 'institution/people/{s}/clear'],
        ['GET', 'institution/people/requests'],
        ['POST', 'institution/people/requests/{r}/approve'],
        ['POST', 'institution/people/requests/{r}/reject'],
        ['POST', 'admin/users/requests/{p}/approve'],
        ['POST', 'admin/users/requests/{p}/reject'],
        // Last: it ends the sessions the other cells of the same caller reuse.
        ['POST', 'settings/sign-out-others'],
    ];

    /** @var array<string, User> */
    private array $people = [];

    private int $request;

    private int $platformRequest;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        Services::resetSingle('throttler');
        FakeEmail::install();
        $this->buildScenario(); // Messages on; $this->student is taught by $this->instructor

        ['college' => $college] = DeanFactory::college('RS', $this->institution);
        $department             = DeanFactory::department($college, 'RS');

        $parent = PeopleFactory::person('parent', 'Pia', 'Parent', null, $this->institution);
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->people = [
            'admin'               => CourseFactory::user('admin'),
            'institutional_admin' => PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution),
            'dean'                => DeanFactory::dean($this->institution, $college),
            'chairperson'         => DeanFactory::chair($department),
            'instructor'          => $this->instructor,
            'student'             => $this->student,
            'parent'              => $parent,
            'other_parent'        => PeopleFactory::person('parent', 'Una', 'Linked', null, $this->institution),
            'other_admin'         => PeopleFactory::person('institutional_admin', 'Olga', 'Elsewhere', null, PeopleFactory::institution('RSX')),
        ];

        $this->request         = $this->correction('institution', $this->institution);
        $this->platformRequest = $this->correction('platform', null);

        // The subject has a photo, so the photo cells show who may see it.
        $path = tempnam(sys_get_temp_dir(), 'prs');
        $img  = imagecreatetruecolor(300, 200);
        imagepng($img, $path);
        (new ProfilePhotoService())->upload((int) $this->student->id, new class ($path, 'me.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        }, $this->student);
        @unlink($path);
    }

    protected function tearDown(): void
    {
        // Only the photos this test wrote under writable/uploads.
        foreach (db_connect()->table('files')->where('owner_user_id', $this->student->id)->get()->getResultArray() as $f) {
            (new StorageService())->delete($f['storage_key']);
        }
        $this->releaseScenario();
        Services::resetSingle('email');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    private function correction(string $routedTo, ?int $institution): int
    {
        db_connect()->table('profile_correction_requests')->insert([
            'user_id' => $this->student->id, 'institution_id' => $institution, 'field' => 'last_name', 'old_value' => 'Dent',
            'new_value' => 'Dentt', 'reason' => 'Typo', 'status' => 'pending', 'routed_to' => $routedTo, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function path(string $template): string
    {
        return strtr($template, ['{s}' => (string) $this->student->id, '{r}' => (string) $this->request, '{p}' => (string) $this->platformRequest]);
    }

    /** The session a browser signed in as $user carries (signed in a minute ago, password confirmed). */
    private function sessionOf(?User $user): array
    {
        $_SESSION = [];
        Services::resetSingle('auth');

        if ($user === null) {
            return [];
        }

        $this->loggedInAs($user);
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                          = ['user' => $user->id, 'at' => time()];

        return $_SESSION;
    }

    /** An administrator's session viewing as the subject (view-only). */
    private function viewingAsSession(): array
    {
        $admin = $this->people['admin'];
        $this->withSession($this->sessionOf($admin));
        Services::resetSingle('auth');
        $this->withHeaders([]);
        $this->call('POST', "admin/users/{$this->student->id}/impersonate", ['reason' => 'Support ticket 1234']);
        $this->assertSame((int) $this->student->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view of the subject must be on');

        return $_SESSION;
    }

    /** The status one route answers for one session. */
    private function answer(array $session, string $verb, string $template): int
    {
        $_SESSION = $session;
        $this->withSession($session);
        Services::resetSingle('auth');
        Services::resetSingle('renderer');
        $this->withHeaders($verb === 'GET' ? [] : ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        try {
            return $this->call($verb, $this->path($template))->response()->getStatusCode();
        } catch (PageNotFoundException) {
            return 404;
        }
    }

    /** @return array<string, int> "VERB path" => status, for every route (only the POSTs when $postsOnly) */
    private function sweep(array $session, bool $postsOnly = false): array
    {
        $out = [];

        foreach (self::ROUTES as [$verb, $template]) {
            if (! $postsOnly || $verb === 'POST') {
                $out["{$verb} {$template}"] = $this->answer($session, $verb, $template);
            }
        }

        return $out;
    }

    /** @return array<string, array{string}> */
    public static function callers(): array
    {
        $names = ['anonymous', 'admin', 'institutional_admin', 'dean', 'chairperson', 'instructor', 'student', 'parent', 'other_parent', 'other_admin',
            'viewing_as', 'read_only_student', 'read_only_admin', 'no_csrf_student', 'no_csrf_admin', 'no_csrf_sysadmin'];

        return array_combine($names, array_map(static fn (string $n): array => [$n], $names));
    }

    /** @dataProvider callers */
    public function testEveryRouteAnswersAsExpectedForTheCaller(string $caller): void
    {
        $readOnly = str_starts_with($caller, 'read_only_');
        $noCsrf   = str_starts_with($caller, 'no_csrf_');
        $who      = match (true) {
            $caller === 'anonymous', $caller === 'viewing_as' => null,
            $caller === 'read_only_student', $caller === 'no_csrf_student' => $this->people['student'],
            $caller === 'read_only_admin', $caller === 'no_csrf_admin'     => $this->people['institutional_admin'],
            $caller === 'no_csrf_sysadmin'                                 => $this->people['admin'],
            default                                                        => $this->people[$caller],
        };

        if ($readOnly) {
            db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);
        }

        $session = $caller === 'viewing_as' ? $this->viewingAsSession() : $this->sessionOf($who);

        if ($noCsrf) {
            $this->withoutCsrfToken();
        }

        $actual = $this->sweep($session, $noCsrf);

        $this->assertSame(self::expected($caller), $actual);
    }

    /**
     * The expected status of each route (rows) for each caller (columns, in callers() order); "-" is not tried.
     * 302 to /login for anonymous; 302 to their own area for a role a route group does not admit; 404 where the
     * subject or request is out of the caller's reach (another institution, an unlinked parent, a system
     * administrator, who has no profile); 403 for every write while viewing as someone, without a CSRF token, and
     * for profile and moderation writes in a read-only institution, where /settings stays open (D29-C3).
     */
    private const MATRIX = <<<'TXT'
        GET profile                                  302 200 200 200 200 200 200 200 200 200 200 200 200   -   -   -
        GET people/{s}                               302 404 200 200 200 200 302 200 404 404 302 302 200   -   -   -
        GET people/{s}/card                          302 404 200 200 200 200 200 200 404 404 200 200 200   -   -   -
        GET people/{s}/panel                         302 404 200 200 200 200 200 200 404 404 200 200 200   -   -   -
        GET people/{s}/photo/64                      302 404 200 200 200 200 200 200 404 404 200 200 200   -   -   -
        GET people/{s}/photo/256                     302 404 200 200 200 200 200 200 404 404 200 200 200   -   -   -
        POST profile/checklist/dismiss               302 404 302 302 302 302 302 302 302 302 403 403 403 403 403 403
        POST profile/identity                        302 404 200 200 200 200 200 200 200 200 403 403 403 403 403 403
        POST profile/about                           302 404 200 200 200 200 200 200 200 200 403 403 403 403 403 403
        POST profile/staff                           302 404 200 200 200 200 422 422 422 200 403 403 403 403 403 403
        POST profile/corrections                     302 404 422 422 422 422 422 422 422 422 403 403 403 403 403 403
        POST profile/identity-official               302 422 404 404 404 404 404 404 404 404 403 403 403 403 403 403
        POST profile/photo                           302 404 422 422 422 422 422 422 422 422 403 403 403 403 403 403
        POST profile/photo/remove                    302 404 200 200 200 200 200 200 200 200 403 403 403 403 403 403
        POST people/{s}/message                      302 404 302 404 404 302 404 404 404 404 403 403 403 403 403 403
        GET settings                                 302 200 200 200 200 200 200 200 200 200 200 200 200   -   -   -
        POST settings/password                       302 302 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        POST settings/password-link                  302 302 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        POST settings/two-factor                     302 302 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        POST settings/notifications                  302 302 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        GET email-change/not-a-token                 410 410 410 410 410 410 410 410 410 410 410 410 410   -   -   -
        POST email-change/not-a-token                410 410 410 410 410 410 410 410 410 410 403 410 410 403 403 403
        GET student/profile                          302 302 302 302 302 302 301 302 302 302 301 301 302   -   -   -
        GET parent/profile                           302 302 302 302 302 302 302 301 301 302 302 302 302   -   -   -
        POST parent/profile/notifications            302 302 302 302 302 302 302 303 303 302 403 302 302 403 403 403
        POST parent/profile/password-link            302 302 302 302 302 302 302 303 303 302 403 302 302 403 403 403
        GET messages/api/preferences                 302 404 301 301 301 301 301 301 301 301 404 301 301   -   -   -
        POST institution/people/{s}/photo            302 302 422 302 302 302 302 302 302 404 403 302 403 403 403 403
        POST institution/people/{s}/photo/remove     302 302 200 302 302 302 302 302 302 404 403 302 403 403 403 403
        POST institution/people/{s}/clear            302 302 404 302 302 302 302 302 302 404 403 302 403 403 403 403
        GET institution/people/requests              302 302 200 302 302 302 302 302 302 200 302 302 200   -   -   -
        POST institution/people/requests/{r}/approve 302 302 200 302 302 302 302 302 302 404 403 302 403 403 403 403
        POST institution/people/requests/{r}/reject  302 302 200 302 302 302 302 302 302 404 403 302 403 403 403 403
        POST admin/users/requests/{p}/approve        302 200 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        POST admin/users/requests/{p}/reject         302 200 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        POST settings/sign-out-others                302 302 302 302 302 302 302 302 302 302 403 302 302 403 403 403
        TXT;

    /** @return array<string, int> */
    private static function expected(string $caller): array
    {
        $column = array_search($caller, array_keys(self::callers()), true);
        $out    = [];

        foreach (explode("\n", self::MATRIX) as $line) {
            $cells = preg_split('/\s+/', trim($line));
            $cell  = $cells[2 + $column];

            if ($cell !== '-') {
                $out["{$cells[0]} {$cells[1]}"] = (int) $cell;
            }
        }

        return $out;
    }
}

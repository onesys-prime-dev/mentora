<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * A dean's or an institution administrator's link to a lesson opens the course on that lesson, read-only,
 * drafts included; a lesson that is gone or another course's, or a course out of reach, is not found
 * (audit decisions 5.1–5.3).
 *
 * @internal
 */
final class ReadOnlyLessonLinkTest extends RouteTestCase
{
    private int $offering;

    private int $foreign;

    private int $lesson;

    private int $draft;

    private int $foreignLesson;

    /** @var array<string, User> */
    private array $viewers = [];

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $home           = DeanFactory::department('RL');
        $term           = DeanFactory::term($home['institution']);
        $this->offering = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($home['department'], 'RL'), 'RL'), $term, 'IT101', 'A', 'active', 'Intro to Computing');

        $elsewhere     = DeanFactory::department('RM');
        $this->foreign = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'RM'), 'RM'), DeanFactory::term($elsewhere['institution']), 'ZZ900', 'A', 'active', 'Foreign Course');

        [$this->lesson, $this->draft] = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->offering)), [['title' => 'Variables'], ['title' => 'Loops', 'status' => 'draft']]);
        [$this->foreignLesson]        = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->foreign)), [['title' => 'Elsewhere']]);

        $this->viewers = [
            'dean'        => DeanFactory::dean($home['institution'], $home['department']),
            'institution' => PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@rl.example', $home['institution']),
        ];
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@rl.example', $home['institution']);
        CourseFactory::enroll($this->offering, $this->student);
    }

    /** @return iterable<string, array{string}> */
    public static function provideAreas(): iterable
    {
        yield 'dean' => ['dean'];
        yield 'institution administrator' => ['institution'];
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function link(string $area, int $offering, int $lesson): string
    {
        return "{$area}/courses/{$offering}/intro-to-computing/modules/items/{$lesson}";
    }

    private function assertNotFound(string $uri): void
    {
        try {
            $status = $this->call('get', $uri)->response()->getStatusCode();
            $this->assertSame(404, $status, "{$uri} must be not found");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    #[DataProvider('provideAreas')]
    public function testTheLinkOpensTheCourseOnTheLesson(string $area): void
    {
        $this->signIn($this->viewers[$area]);

        $response = $this->call('get', $this->link($area, $this->offering, $this->lesson))->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith("{$area}/courses/{$this->offering}/intro-to-computing/modules?item={$this->lesson}", $response->getHeaderLine('Location'));
    }

    #[DataProvider('provideAreas')]
    public function testADraftLessonOpensAndIsMarkedDraft(string $area): void
    {
        $this->signIn($this->viewers[$area]);

        $this->assertStringEndsWith("modules?item={$this->draft}", $this->call('get', $this->link($area, $this->offering, $this->draft))->response()->getHeaderLine('Location'));

        $this->withHeaders(['Accept' => 'application/json']);
        $body = json_decode((string) $this->call('get', "{$area}/api/courses/{$this->offering}/modules/items/{$this->draft}")->response()->getBody(), true);
        $html = implode(' ', array_filter((array) $body, 'is_string'));

        // The lesson's header badge, shown "Draft" (capitalised by its class).
        $this->assertMatchesRegularExpression('#class="capitalize rounded-full[^"]*bg-gray-100[^"]*">\s*draft\s*</span>#', $html, 'shown, marked as a draft');
    }

    #[DataProvider('provideAreas')]
    public function testADeletedLessonIsNotFound(string $area): void
    {
        $this->signIn($this->viewers[$area]);

        $this->assertNotFound($this->link($area, $this->offering, 999999));
    }

    #[DataProvider('provideAreas')]
    public function testAnotherCoursesLessonIsNotFound(string $area): void
    {
        $this->signIn($this->viewers[$area]);

        $this->assertNotFound($this->link($area, $this->offering, $this->foreignLesson));
    }

    #[DataProvider('provideAreas')]
    public function testACourseOutOfReachIsNotFound(string $area): void
    {
        $this->signIn($this->viewers[$area]);

        $this->assertNotFound($this->link($area, $this->foreign, $this->foreignLesson));
    }

    public function testAStudentsLinkToADraftLessonIsNotFound(): void
    {
        $this->signIn($this->student);

        $this->assertNotFound("student/courses/{$this->offering}/intro-to-computing/modules/items/{$this->draft}");
    }
}

<?php

declare(strict_types=1);

use App\Services\Messaging\CourseChatSync;
use App\Services\Messaging\MessageService;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\ParentScenario;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent's "From the instructor" tab: read-only, the instructor's words only,
 * and not there at all while Messages is off.
 *
 * @internal
 */
final class ParentInstructorFeedTest extends RouteTestCase
{
    use ParentScenario;

    private const SECRET = 'student-secret-5521';

    private int $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        $this->buildParentScenario('PI');
        $this->enableMessages();
        $this->chat = (new CourseChatSync())->syncOffering($this->offering)['conversation_id'];
    }

    protected function tearDown(): void
    {
        $this->releaseMessages();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function say(User $user, string $text): void
    {
        (new MessageService($this->parentCipher()))->send($user, $this->chat, $text, 'u-' . bin2hex(random_bytes(6)));
    }

    private function api(?int $offering = null, ?int $child = null): string
    {
        return '/parent/children/' . ($child ?? $this->child->id) . '/api/courses/' . ($offering ?? $this->offering) . '/instructor';
    }

    /** @return array{int, string} */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testALinkedParentSeesTheInstructorsWordsAndNoStudents(): void
    {
        $this->say($this->instructor, 'Quiz on Friday');
        $this->say($this->child, self::SECRET);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->api());
        $html            = json_decode($body, true)['html'];

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Quiz on Friday', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function testItIsAReadOnlyListWithOneMessageButtonPerInstructor(): void
    {
        $second = PeopleFactory::person('instructor', 'Sec', 'Ond', null, $this->institution);
        CourseFactory::assignInstructor($this->offering, $second);
        (new CourseChatSync())->syncOffering($this->offering);
        $this->loggedInAs($this->parent);

        $html = json_decode($this->open($this->api())[1], true)['html'];

        $this->assertSame(2, preg_match_all('/data-parent-message(?=\s)/', $html));
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function testAPostIsEscapedNotTreatedAsHtml(): void
    {
        $this->say($this->instructor, '<img src=x onerror=alert(1)>');
        $this->loggedInAs($this->parent);

        $html = json_decode($this->open($this->api())[1], true)['html'];

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testAnUnlinkedParentAnotherFamilyAndANonexistentCourseAreAllNotFound(): void
    {
        $stranger   = PeopleFactory::person('parent', 'Stran', 'Ger', null, $this->institution);
        $otherChild = PeopleFactory::person('student', 'Other', 'Kid', null, $this->institution);
        CourseFactory::enroll($this->offering, $otherChild);

        $this->loggedInAs($stranger);
        $this->assertSame(404, $this->open($this->api())[0], 'a parent not linked to the child');

        $this->loggedInAs($this->parent);
        $this->assertSame(404, $this->open($this->api(null, (int) $otherChild->id))[0], 'another family\'s child');
        $this->assertSame(404, $this->open($this->api(99999999))[0], 'a course that does not exist');
    }

    public function testNothingExistsWhileMessagesIsOff(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();
        \App\Services\Admin\PermissionMatrix::flush();
        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->api())[0]);
        $this->assertSame(404, $this->open('/parent/children/' . $this->child->id . '/courses/' . $this->offering . '/intro/instructor')[0], 'the page for the tab');

        $page = $this->open('/parent/children/' . $this->child->id . '/courses/' . $this->offering . '/intro/overview')[1];
        $this->assertStringNotContainsString('data-course-feature="instructor"', $page);
    }

    public function testTheTabIsOnTheCoursePageWhileMessagesIsOn(): void
    {
        $this->loggedInAs($this->parent);

        $page = $this->open('/parent/children/' . $this->child->id . '/courses/' . $this->offering . '/intro/overview')[1];

        $this->assertStringContainsString('data-course-feature="instructor"', $page);
        $this->assertStringContainsString('From the instructor', $page);
    }

    public function testASwitchedOffPortalGivesNothing(): void
    {
        $this->say($this->instructor, 'Quiz on Friday');
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->api());

        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('Quiz on Friday', $body);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $this->assertSame(302, $this->open($this->api())[0], $role);
        }
    }
}

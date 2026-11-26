<?php

declare(strict_types=1);

use App\Services\Institutions\MinorFlag;
use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GroupService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\StudentMessaging;
use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * FR2: existing threads that no longer qualify stay readable and become read-only (D3 scope, D9 minor),
 * a deactivated other person replaces the composer with a note (D11), and groups are never made
 * read-only by the minor flag (D26).
 *
 * @internal
 */
final class ThreadReadOnlyTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

    private User $ann;

    private User $ben;

    private User $carl;

    private User $teacher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::college('TRO1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'TROS'), 'TROP');
        $this->offering    = DeanFactory::offering($program, DeanFactory::term($this->institution), 'TRO101');

        $this->ann     = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben     = PeopleFactory::person('student', 'Ben', 'Ng', null, $this->institution);
        $this->carl    = PeopleFactory::person('student', 'Carl', 'Ito', null, $this->institution);
        $this->teacher = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);
        $this->admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);

        // Ann, Ben and the teacher share the course; Carl studies elsewhere.
        CourseFactory::enroll($this->offering, $this->ann);
        CourseFactory::enroll($this->offering, $this->ben);
        CourseFactory::assignInstructor($this->offering, $this->teacher);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function cipher(): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageCipher($config);
    }

    private function messages(): MessageService
    {
        return new MessageService($this->cipher());
    }

    private function thread(User $from, User $to): int
    {
        $thread = (int) (new ConversationService())->startDirect($from, (int) $to->id)['id'];
        $this->assertTrue($this->messages()->send($from, $thread, 'Hello', bin2hex(random_bytes(8)))['ok']);

        return $thread;
    }

    private function sends(User $user, int $thread): bool
    {
        return $this->messages()->send($user, $thread, 'Again', bin2hex(random_bytes(8)))['ok'] ?? false;
    }

    /** @return array<string, mixed> the viewer's presented row for $thread */
    private function row(User $viewer, int $thread): array
    {
        foreach ((new ConversationPresenter($this->cipher()))->present($viewer, (new ConversationService())->listFor($viewer)) as $row) {
            if ($row['id'] === $thread) {
                return $row;
            }
        }

        $this->fail('thread not in the list');
    }

    public function testThreadReadOnlyAfterDrop(): void
    {
        (new StudentMessaging())->setScope($this->institution, 'course');
        $thread = $this->thread($this->ann, $this->ben);

        db_connect()->table('enrollments')->where('student_user_id', (int) $this->ben->id)->update(['status' => 'dropped']);

        $this->assertFalse($this->sends($this->ann, $thread));
        $this->assertFalse($this->sends($this->ben, $thread));
        $this->assertCount(1, $this->messages()->page($this->ben, $thread)['messages'], 'still readable');
        $this->assertFalse($this->row($this->ann, $thread)['can_post']);
        $this->assertNull($this->row($this->ann, $thread)['composer_note'], 'the reason is not named');

        // The whole institution again: the thread qualifies and opens up.
        (new StudentMessaging())->setScope($this->institution, 'institution');

        $this->assertTrue($this->sends($this->ben, $thread));
    }

    public function testThreadReadOnlyWhenRecipientBecomesMinor(): void
    {
        $stranger = $this->thread($this->ann, $this->carl);
        $teaching = $this->thread($this->teacher, $this->ben);
        $started  = $this->thread($this->carl, $this->teacher);

        $this->assertTrue((new MinorFlag())->set($this->admin, $this->institution, (int) $this->carl->id, true));
        $this->assertTrue((new MinorFlag())->set($this->admin, $this->institution, (int) $this->ben->id, true));

        // Ann shares no course with Carl: read-only for both, readable, and nothing names the flag.
        $this->assertFalse($this->sends($this->ann, $stranger));
        $this->assertFalse($this->sends($this->carl, $stranger));
        $this->assertCount(1, $this->messages()->page($this->carl, $stranger)['messages']);
        $this->assertNull($this->row($this->ann, $stranger)['composer_note']);

        // Ben's own instructor still reaches him, and a thread the minor started is theirs to keep.
        $this->assertTrue($this->sends($this->teacher, $teaching));
        $this->assertTrue($this->sends($this->ben, $teaching));
        $this->assertTrue($this->sends($this->teacher, $started));

        $this->assertTrue((new MinorFlag())->set($this->admin, $this->institution, (int) $this->carl->id, false));

        $this->assertTrue($this->sends($this->ann, $stranger));
    }

    public function testDeactivatedOtherShowsNote(): void
    {
        $thread = $this->thread($this->ann, $this->teacher);

        (new AccountControl())->deactivate((int) $this->teacher->id);

        $row = $this->row($this->ann, $thread);

        $this->assertFalse($row['can_post']);
        $this->assertSame('This account is deactivated', $row['composer_note']);
        $this->assertFalse($this->sends($this->ann, $thread));

        // An active pair has no note.
        $this->assertNull($this->row($this->ann, $this->thread($this->ann, $this->ben))['composer_note']);
    }

    public function testGroupsNotMadeReadOnlyByMinorFlag(): void
    {
        $group = (new GroupService())->create($this->ann, 'Study group', [(int) $this->carl->id, (int) $this->ben->id]);
        $this->assertNotNull($group);
        $id = (int) $group['id'];

        $this->assertTrue((new MinorFlag())->set($this->admin, $this->institution, (int) $this->carl->id, true));
        (new StudentMessaging())->setScope($this->institution, 'off');

        $this->assertTrue($this->sends($this->ann, $id));
        $this->assertTrue($this->sends($this->carl, $id));
        $this->assertTrue($this->row($this->carl, $id)['can_post']);
    }
}

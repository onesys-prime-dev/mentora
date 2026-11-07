<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageCipher;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\StudentMessaging;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Config\Messages;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * The institution's student-to-student switch: on by default; off stops new
 * student threads and leaves the old ones readable but read-only; nothing else
 * is affected.
 *
 * @internal
 */
final class StudentDmSwitchTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    private int $institution;

    private User $ann;

    private User $ben;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $d                 = DeanFactory::college('DM1');
        $this->institution = $d['institution'];
        $program           = DeanFactory::program(DeanFactory::department($d['college'], 'DMS'), 'DMP');
        $offering          = DeanFactory::offering($program, DeanFactory::term($this->institution), 'DM101');

        $this->ann     = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben     = PeopleFactory::person('student', 'Ben', 'Ng', null, $this->institution);
        $this->teacher = PeopleFactory::person('instructor', 'Tea', 'Cher', null, $this->institution);

        foreach ([$this->ann, $this->ben] as $student) {
            CourseFactory::enroll($offering, $student);
        }

        CourseFactory::assignInstructor($offering, $this->teacher);
        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function messages(): MessageService
    {
        $config                = new Messages();
        $config->encryptionKey = self::KEY;
        $config->previousKeys  = '';

        return new MessageService(new MessageCipher($config));
    }

    public function testItIsOnByDefaultAndPerInstitution(): void
    {
        $other = DeanFactory::college('DM2')['institution'];

        $this->assertTrue((new StudentMessaging())->enabled($this->institution));

        (new StudentMessaging())->set($this->institution, false);

        $this->assertFalse((new StudentMessaging())->enabled($this->institution));
        $this->assertTrue((new StudentMessaging())->enabled($other));
    }

    public function testEachChangeIsAudited(): void
    {
        (new StudentMessaging())->set($this->institution, false);
        (new StudentMessaging())->set($this->institution, true);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->orderBy('id')->get()->getResultArray(), 'action');

        $this->assertSame(['settings.changed', 'settings.reset'], $actions);
    }

    public function testNewStudentThreadsAreRefusedWhileOffButInstructorThreadsStillWork(): void
    {
        (new StudentMessaging())->set($this->institution, false);
        $service = new ConversationService();

        $this->assertNull($service->startDirect($this->ann, (int) $this->ben->id));
        $this->assertNotNull($service->startDirect($this->ann, (int) $this->teacher->id));
        $this->assertNotNull($service->startDirect($this->teacher, (int) $this->ben->id));
    }

    public function testAnExistingStudentThreadStaysReadableButNobodyPosts(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ann, (int) $this->ben->id)['id'];
        $this->messages()->send($this->ann, $thread, 'Before', 'uuid-ann-1');

        (new StudentMessaging())->set($this->institution, false);

        $this->assertSame('not_allowed', $this->messages()->send($this->ann, $thread, 'After', 'uuid-ann-2')['reason']);
        $this->assertSame('not_allowed', $this->messages()->send($this->ben, $thread, 'After', 'uuid-ben-1')['reason']);

        foreach ([$this->ann, $this->ben] as $reader) {
            $this->assertCount(1, $this->messages()->page($reader, $thread)['messages']);
        }

        (new StudentMessaging())->set($this->institution, true);

        $this->assertTrue($this->messages()->send($this->ben, $thread, 'Back', 'uuid-ben-2')['ok']);
    }

    public function testAThreadWithAnInstructorIsNeverBlockedByIt(): void
    {
        $thread = (int) (new ConversationService())->startDirect($this->ann, (int) $this->teacher->id)['id'];

        (new StudentMessaging())->set($this->institution, false);

        $this->assertTrue($this->messages()->send($this->ann, $thread, 'Question', 'uuid-ann-3')['ok']);
        $this->assertTrue($this->messages()->send($this->teacher, $thread, 'Answer', 'uuid-teacher-1')['ok']);
    }

    public function testAnotherInstitutionsSwitchDoesNotReachThisOne(): void
    {
        $far = DeanFactory::college('DM3')['institution'];
        (new StudentMessaging())->set($far, false);

        $this->assertNotNull((new ConversationService())->startDirect($this->ann, (int) $this->ben->id));
    }
}

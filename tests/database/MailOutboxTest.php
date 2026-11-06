<?php

declare(strict_types=1);

use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Notifications\MailOutbox;
use App\Services\Users\PasswordResetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The emails people are owed: what is noted, that quick changes make one email per person, the welcome email's
 * link to choose a password, the news for people who already have an account, retries, and who is never emailed.
 * Also that the services whose changes call for an email note it.
 *
 * @internal
 */
final class MailOutboxTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $admin;

    private FakeEmail $mail;

    private MailOutbox $outbox;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        MailOutbox::reset();

        $this->mail        = FakeEmail::install();
        $this->institution = SisFixture::institution('MOA');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->outbox      = new MailOutbox();
        $this->now         = new DateTimeImmutable('2026-11-05 09:00:00');
    }

    protected function tearDown(): void
    {
        MailOutbox::reset();
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function person(string $role, string $first, ?string $email = null, bool $active = true): User
    {
        return PeopleFactory::person($role, $first, 'Tester', $email ?? strtolower($first) . '@outbox.test', $this->institution, $active);
    }

    private function later(int $seconds = MailOutbox::MERGE_SECONDS): DateTimeImmutable
    {
        return $this->now->modify("+{$seconds} seconds");
    }

    private function rows(): int
    {
        return db_connect()->table('mail_outbox')->countAllResults();
    }

    /** @return array{course: int, offering: int} */
    private function offering(string $code = 'IT101', string $section = 'A'): array
    {
        $db      = db_connect();
        $program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => $code, 'course_title' => 'Intro to Computing', 'units' => 3]);
        $course   = (int) $db->insertID();
        $offering = (new OfferingService())->create($this->institution, ['course_id' => $course, 'term_id' => $term, 'section_code' => $section, 'capacity' => '5'], $this->admin);

        return ['course' => $course, 'offering' => $offering];
    }

    public function testAChangeIsNotedAndWaitsSoQuickOnesCanMerge(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->assertTrue($this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101, section A', $this->now));

        $row = db_connect()->table('mail_outbox')->get()->getRowArray();
        $this->assertSame(['enrolled', 'IT101, section A', '2026-11-05 09:02:00', null], [$row['kind'], $row['detail'], $row['send_after'], $row['sent_at']]);

        $this->assertSame(0, $this->outbox->sendDue($this->now), 'not before its wait is over');
        $this->assertSame([], $this->mail->sent);
    }

    public function testTheSameNewsWaitingIsNotNotedTwiceAndAnUnknownKindIsIgnored(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101, section A', $this->now);
        $this->assertFalse($this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101, section A', $this->now));
        $this->assertFalse($this->outbox->tell($this->institution, (int) $ann->id, 'newsletter', 'x', $this->now));
        $this->assertTrue($this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT102, section A', $this->now));

        $this->assertSame(2, $this->rows());
    }

    public function testAMutedRunNotesNothing(): void
    {
        $ann = $this->person('student', 'Ann');
        MailOutbox::context('run-1', true);

        $this->assertFalse($this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::WELCOME, 'student', $this->now));
        $this->assertSame(0, $this->rows());
        $this->assertTrue(MailOutbox::isMuted());

        MailOutbox::reset();
        $this->assertFalse(MailOutbox::isMuted());
    }

    public function testSixChangesToOnePersonAreOneEmail(): void
    {
        $ann = $this->person('student', 'Ann');

        foreach (['IT101', 'IT102', 'IT103'] as $code) {
            $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, "{$code} Intro, section A", $this->now);
        }

        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::TEACHING, 'IT201 Data, section B', $this->now);

        $this->assertSame(1, $this->outbox->sendDue($this->later()));
        $this->assertCount(1, $this->mail->sent);

        $mail = $this->mail->sent[0];
        $this->assertSame('ann@outbox.test', $mail['to']);
        $this->assertSame('Updates to your Mentora account at MOA College', $mail['subject']);
        $this->assertStringContainsString("You are enrolled in:\n - IT101 Intro, section A\n - IT102 Intro, section A\n - IT103 Intro, section A", $mail['message']);
        $this->assertStringContainsString("You are assigned to teach:\n - IT201 Data, section B", $mail['message']);
        $this->assertStringContainsString('Hello Ann,', $mail['message']);
        $this->assertStringNotContainsString('reset-password', $mail['message'], 'someone with an account gets no password link');

        $this->assertSame(0, db_connect()->table('mail_outbox')->where('sent_at IS NULL', null, false)->countAllResults(), 'all four are marked sent');
        $this->assertSame(0, $this->outbox->sendDue($this->later(600)), 'and not sent again');
    }

    public function testSomeoneWithAnAccountAlsoFindsTheNewsInTheApp(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        $this->outbox->sendDue($this->later());

        $note = db_connect()->table('notifications')->where('user_id', $ann->id)->get()->getRowArray();

        $this->assertSame('Updates to your account at MOA College', $note['title']);
        $this->assertStringContainsString('Enrolled in IT101 Intro, section A.', (string) $note['body']);
        $this->assertSame('0', (string) $note['urgent'], 'the email was sent by the outbox, not twice');
    }

    public function testANewAccountGetsALinkToChooseAPasswordThatWorks(): void
    {
        $ben = $this->person('student', 'Ben');
        $this->outbox->tell($this->institution, (int) $ben->id, MailOutbox::WELCOME, 'student', $this->now);
        $this->outbox->tell($this->institution, (int) $ben->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);

        $this->assertSame(1, $this->outbox->sendDue($this->later()));

        $mail = $this->mail->sent[0];
        $this->assertSame('Your Mentora account at MOA College', $mail['subject']);
        $this->assertStringContainsString('You have been added to MOA College on Mentora as a student.', $mail['message']);
        $this->assertStringContainsString('Your sign-in email is ben@outbox.test', $mail['message']);
        $this->assertStringContainsString(' - IT101 Intro, section A', $mail['message'], 'and what they were given');

        $this->assertSame(1, preg_match('#reset-password/([A-Za-z0-9_-]+)#', $mail['message'], $m), 'the link is in the email');

        $reset = (new PasswordResetService())->open($m[1], $this->later());
        $this->assertSame('ben@outbox.test', $reset['email']);

        $days = (int) (new \App\Services\Admin\PlatformSettings())->get('invitationValidityDays');
        $this->assertStringContainsString('expires on ' . $this->later()->modify("+{$days} days")->format('Y-m-d H:i'), $mail['message'], 'it lasts as long as an invitation');

        $this->assertNotNull(db_connect()->table('password_resets')->where('user_id', $ben->id)->get()->getRowArray()['sent_at']);
        $this->assertSame(0, db_connect()->table('notifications')->where('user_id', $ben->id)->countAllResults(), 'no app notice before they have signed in');
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'user.welcome_link_sent')->countAllResults());
    }

    public function testAnInstructorIsWelcomedAsAnInstructor(): void
    {
        $ivy = $this->person('instructor', 'Ivy');
        $this->outbox->tell($this->institution, (int) $ivy->id, MailOutbox::WELCOME, 'instructor', $this->now);
        $this->outbox->sendDue($this->later());

        $this->assertStringContainsString('as an instructor.', $this->mail->sent[0]['message']);
    }

    public function testAFailedSendIsRetriedLaterAndLosesNothing(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        $this->mail->failToSend = true;

        $this->assertSame(0, $this->outbox->sendDue($this->later()));

        $row = db_connect()->table('mail_outbox')->get()->getRowArray();
        $this->assertSame(['1', null], [(string) $row['attempts'], $row['sent_at']]);
        $this->assertNotNull($row['last_error']);

        $this->assertSame(0, $this->outbox->sendDue($this->later(MailOutbox::MERGE_SECONDS + 60)), 'not again at once');

        $this->mail->failToSend = false;
        $this->assertSame(1, $this->outbox->sendDue($this->later(MailOutbox::MERGE_SECONDS + MailOutbox::RETRY_SECONDS + 1)));
        $this->assertNotNull(db_connect()->table('mail_outbox')->get()->getRowArray()['sent_at']);
    }

    public function testAMailerThatThrowsIsCaughtLikeAFailure(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        $this->mail->throwOnSend = new RuntimeException('connection refused');

        $this->assertSame(0, $this->outbox->sendDue($this->later()));
        $this->assertSame('connection refused', db_connect()->table('mail_outbox')->get()->getRowArray()['last_error']);
    }

    public function testItGivesUpAfterTheLastAttempt(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        db_connect()->table('mail_outbox')->update(['attempts' => MailOutbox::MAX_ATTEMPTS]);

        $this->assertSame(0, $this->outbox->sendDue($this->later(100000)));
        $this->assertSame([], $this->mail->sent);
        $this->assertSame(0, $this->outbox->waiting());
    }

    public function testADeactivatedAccountIsNeverEmailedAndNotAskedAboutAgain(): void
    {
        $gone = $this->person('student', 'Gone', null, false);
        $this->outbox->tell($this->institution, (int) $gone->id, MailOutbox::WELCOME, 'student', $this->now);

        $this->assertSame(0, $this->outbox->sendDue($this->later()));

        $row = db_connect()->table('mail_outbox')->get()->getRowArray();
        $this->assertNotNull($row['sent_at']);
        $this->assertStringContainsString('deactivated', (string) $row['last_error']);
        $this->assertSame([], $this->mail->sent);
        $this->assertSame(0, db_connect()->table('password_resets')->countAllResults(), 'no link was made');
    }

    public function testAnAccountSwitchedOffByAnAdministratorIsNeverEmailedEither(): void
    {
        $off = $this->person('student', 'Off');
        (new \App\Services\Users\AccountControl())->deactivate((int) $off->id, $this->now);
        $this->outbox->tell($this->institution, (int) $off->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);

        $this->assertSame(0, $this->outbox->sendDue($this->later()));
        $this->assertSame([], $this->mail->sent);
        $this->assertStringContainsString('deactivated', (string) db_connect()->table('mail_outbox')->get()->getRowArray()['last_error']);
    }

    public function testAtMostFiftyPeopleAreEmailedPerRunAndTheRestWait(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->outbox->tell($this->institution, (int) $this->person('student', 'P' . $i)->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        }

        $this->assertSame(2, $this->outbox->sendDue($this->later(), 2));
        $this->assertSame(1, $this->outbox->waiting());
        $this->assertSame(1, $this->outbox->sendDue($this->later(), 2));
    }

    public function testThePlanCountsPeopleNotRows(): void
    {
        MailOutbox::context('run-9', false);
        $ann = $this->person('student', 'Ann');
        $ben = $this->person('student', 'Ben');

        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::WELCOME, 'student', $this->now);
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);
        $this->outbox->tell($this->institution, (int) $ann->id, MailOutbox::ENROLLED, 'IT102 Intro, section A', $this->now);
        $this->outbox->tell($this->institution, (int) $ben->id, MailOutbox::ENROLLED, 'IT101 Intro, section A', $this->now);

        $this->assertSame(['people' => 2, 'welcome' => 1, 'notices' => 1], $this->outbox->plan('run-9'));
        $this->assertSame(['people' => 0, 'welcome' => 0, 'notices' => 0], $this->outbox->plan('run-other'));
    }

    // The services whose changes call for an email note it.

    public function testEnrollingAStudentByHandNotesIt(): void
    {
        $o   = $this->offering();
        $ann = $this->person('student', 'Ann');
        db_connect()->table('user_profiles')->where('user_id', $ann->id)->update(['student_number' => '2026-001']);

        (new EnrolmentService())->add($this->institution, $o['offering'], '2026-001', false, $this->admin);

        $row = db_connect()->table('mail_outbox')->get()->getRowArray();
        $this->assertSame(['enrolled', 'IT101 Intro to Computing, section A', (string) $ann->id], [$row['kind'], $row['detail'], (string) $row['user_id']]);
    }

    public function testBringingAStudentBackNotesItButDroppingDoesNot(): void
    {
        $o   = $this->offering();
        $ann = $this->person('student', 'Ann');
        db_connect()->table('user_profiles')->where('user_id', $ann->id)->update(['student_number' => '2026-001']);
        $svc = new EnrolmentService();

        $svc->add($this->institution, $o['offering'], '2026-001', false, $this->admin);
        $id = (int) db_connect()->table('enrollments')->where('student_user_id', $ann->id)->get()->getRowArray()['id'];
        db_connect()->table('mail_outbox')->emptyTable();

        $svc->setStatus($this->institution, $id, 'dropped', false, $this->admin);
        $this->assertSame(0, $this->rows(), 'a dropped place is not emailed');

        $svc->setStatus($this->institution, $id, 'enrolled', false, $this->admin);
        $this->assertSame(1, $this->rows());
    }

    public function testAssigningAnInstructorNotesIt(): void
    {
        $o   = $this->offering();
        $ivy = $this->person('instructor', 'Ivy');

        (new OfferingService())->assignInstructor($this->institution, $o['offering'], (int) $ivy->id, $this->admin);

        $row = db_connect()->table('mail_outbox')->get()->getRowArray();
        $this->assertSame(['teaching', 'IT101 Intro to Computing, section A'], [$row['kind'], $row['detail']]);
    }

    public function testMakingSomeoneDeanOrChairpersonNotesIt(): void
    {
        $db   = db_connect();
        $college = $db->table('colleges')->where('institution_id', $this->institution)->get()->getRowArray();
        $department  = $db->query('SELECT sd.* FROM departments sd JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRowArray();
        $dean = $this->person('dean', 'Dee');
        $chair = $this->person('chairperson', 'Cho');

        (new DeanAssignmentService())->assign($this->institution, (int) $college['id'], (int) $dean->id, $this->admin);
        (new ChairAssignmentService())->assign($this->institution, (int) $department['id'], (int) $chair->id, $this->admin);

        $rows = $db->table('mail_outbox')->orderBy('id')->get()->getResultArray();

        $this->assertSame(['dean', $college['name']], [$rows[0]['kind'], $rows[0]['detail']]);
        $this->assertSame(['chairperson', $department['name']], [$rows[1]['kind'], $rows[1]['detail']]);

        // The assignment services note it by the real clock, so the wait is over by the real clock too (not the fixed one).
        $this->outbox->sendDue((new DateTimeImmutable())->modify('+' . MailOutbox::MERGE_SECONDS . ' seconds'), 10);
        $this->assertStringContainsString("You are now the dean of {$college['name']}.", $this->mail->sent[0]['message']);
    }

    public function testWhenMutedNoServiceNotesAnything(): void
    {
        $o   = $this->offering();
        $ann = $this->person('student', 'Ann');
        db_connect()->table('user_profiles')->where('user_id', $ann->id)->update(['student_number' => '2026-001']);
        MailOutbox::context(null, true);

        (new EnrolmentService())->add($this->institution, $o['offering'], '2026-001', false, $this->admin);

        $this->assertSame(0, $this->rows());
    }
}

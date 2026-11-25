<?php

declare(strict_types=1);

use App\Services\Profiles\ProfileModeration;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * An institution's administrator clearing a misused profile field (D5, FR9).
 *
 * @internal
 */
final class ProfileModerationTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $inst;

    private User $admin;

    private User $student;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->mailer  = FakeEmail::install();
        $this->inst    = PeopleFactory::institution('PMA');
        $this->admin   = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->inst);
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@pm.test', $this->inst);
        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['preferred_first_name' => 'Rude', 'pronouns' => 'x/y', 'bio' => 'Spam', 'office_location' => 'Rm 1', 'consultation_hours' => 'Mon', 'work_phone' => '123', 'middle_name' => 'Keep']);
        db_connect()->table('audit_logs')->emptyTable();
    }

    private function value(string $field): mixed
    {
        return db_connect()->table('user_profiles')->where('user_id', $this->student->id)->get()->getRow($field);
    }

    public function testEachAllowedFieldIsClearedAuditedWithoutItsOldValueAndTheOwnerIsToldInTheAppAndByEmail(): void
    {
        foreach (array_keys(ProfileModeration::CLEARABLE) as $field) {
            (new ProfileModeration())->clearField((int) $this->student->id, $field, $this->admin);
            $this->assertNull($this->value($field), $field);
        }

        $this->assertSame('Keep', $this->value('middle_name'));

        $entries = db_connect()->table('audit_logs')->where('action', 'profile.field_cleared_by_admin')->get()->getResultArray();
        $this->assertCount(6, $entries);
        $this->assertSame((int) $this->admin->id, (int) $entries[0]['actor_user_id']);
        $this->assertSame((int) $this->student->id, (int) $entries[0]['target_id']);
        $this->assertStringContainsString('preferred_first_name', (string) $entries[0]['new_values']);
        $this->assertStringNotContainsString('Rude', (string) json_encode($entries));

        $bells = db_connect()->table('notifications')->where('user_id', $this->student->id)->where('type', 'profile.field')->get()->getResultArray();
        $this->assertCount(6, $bells);
        $this->assertContains('Your school cleared your preferred name.', array_column($bells, 'title'));
        $this->assertSame(6, db_connect()->table('mail_outbox')->where('kind', 'field_cleared')->where('user_id', $this->student->id)->countAllResults());
        (new \App\Services\Notifications\MailOutbox())->sendDue(new DateTimeImmutable('+1 hour'));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('stu@pm.test', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('Your school cleared your preferred name.', $this->mailer->sent[0]['message']);
    }

    public function testTheOwnerEmailGoesThroughTheOutboxAndAnEmptyStringCountsAsEmpty(): void
    {
        (new ProfileModeration())->clearField((int) $this->student->id, 'bio', $this->admin);

        $this->assertSame([], $this->mailer->sent);
        $rows = db_connect()->table('mail_outbox')->where('kind', 'field_cleared')->where('user_id', $this->student->id)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('bio', $rows[0]['detail']);

        db_connect()->table('user_profiles')->where('user_id', $this->student->id)->update(['pronouns' => '']);
        (new ProfileModeration())->clearField((int) $this->student->id, 'pronouns', $this->admin);
        $this->assertSame('', $this->value('pronouns'));
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'profile.field_cleared_by_admin')->countAllResults());
    }

    public function testOtherFieldsAreRefusedAndNothingChanges(): void
    {
        foreach (['first_name', 'student_number', 'photo_file_id', 'nonsense'] as $field) {
            try {
                (new ProfileModeration())->clearField((int) $this->student->id, $field, $this->admin);
                $this->fail("{$field} should be refused.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Stu', db_connect()->table('user_profiles')->where('user_id', $this->student->id)->get()->getRow('first_name'));
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'profile.field_cleared_by_admin')->countAllResults());
    }

    public function testAnAlreadyEmptyFieldTellsNobodyAndLogsNothing(): void
    {
        (new ProfileModeration())->clearField((int) $this->student->id, 'bio', $this->admin);
        $queued = db_connect()->table('mail_outbox')->countAllResults();

        (new ProfileModeration())->clearField((int) $this->student->id, 'bio', $this->admin);

        $this->assertSame($queued, db_connect()->table('mail_outbox')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'profile.field_cleared_by_admin')->countAllResults());
    }

    public function testAnAdminOfAnotherInstitutionGetsA404AndNothingChanges(): void
    {
        $other = PeopleFactory::person('institutional_admin', 'Olga', 'Elsewhere', null, PeopleFactory::institution('PMB'));

        try {
            (new ProfileModeration())->clearField((int) $this->student->id, 'bio', $other);
            $this->fail('Expected a 404.');
        } catch (PageNotFoundException) {
            $this->assertSame('Spam', $this->value('bio'));
            $this->assertSame([], $this->mailer->sent);
        }
    }
}

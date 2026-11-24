<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * POST /profile/{identity|about|staff}: the owner edits their own profile through the whole filter stack.
 *
 * @internal
 */
final class ProfileEditRoutesTest extends RouteTestCase
{
    private int $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PER');
        $this->teacher     = PeopleFactory::person('instructor', 'Tess', 'Teacher', 'tess@school.example', $this->institution);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return db_connect()->table('user_profiles')->where('user_id', $u->id)->get()->getRowArray();
    }

    /** @return array<string, string> */
    private function json(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    public function testEachSectionSavesAndRedirectsWithTheMessage(): void
    {
        $this->loggedInAs($this->teacher);

        $result = $this->step('POST', 'profile/identity', ['preferred_first_name' => 'Tessa', 'pronouns_choice' => 'they/them']);
        $result->assertRedirectTo(site_url('profile'));
        $this->assertSame('Profile updated', session('message'));

        $this->step('POST', 'profile/about', ['bio' => 'I teach databases.']);
        $this->step('POST', 'profile/staff', ['office_location' => 'CS 204', 'consultation_hours' => 'Mon 1-3', 'work_phone' => '8123']);

        $r = $this->row($this->teacher);
        $this->assertSame(['Tessa', 'they/them', 'I teach databases.', 'CS 204', 'Mon 1-3', '8123'], [$r['preferred_first_name'], $r['pronouns'], $r['bio'], $r['office_location'], $r['consultation_hours'], $r['work_phone']]);
    }

    public function testACustomPronounComesFromTheTextBox(): void
    {
        $this->loggedInAs($this->teacher);
        $this->step('POST', 'profile/identity', ['preferred_first_name' => '', 'pronouns_choice' => 'custom', 'pronouns_custom' => 'xe/xem']);

        $this->assertSame('xe/xem', $this->row($this->teacher)['pronouns']);
    }

    public function testAScriptGetsJsonAndARefusalIs422WithTheFieldMessage(): void
    {
        $this->loggedInAs($this->teacher);

        $ok = $this->withHeaders($this->json())->step('POST', 'profile/about', ['bio' => 'Hi']);
        $ok->assertStatus(200);
        $this->assertSame('Profile updated', json_decode($ok->getJSON(), true)['message']);

        $bad = $this->withHeaders($this->json())->step('POST', 'profile/identity', ['preferred_first_name' => 'Mary$', 'pronouns_choice' => '']);
        $bad->assertStatus(422);
        $this->assertArrayHasKey('preferred_first_name', json_decode($bad->getJSON(), true)['errors']);
        $this->assertNull($this->row($this->teacher)['preferred_first_name']);
    }

    public function testABrowserRefusalGoesBackWithTheError(): void
    {
        $this->loggedInAs($this->teacher);

        $this->step('POST', 'profile/about', ['bio' => str_repeat('a', 301)])->assertRedirectTo(site_url('profile'));
        $this->assertStringContainsString('300', (string) session('error'));
    }

    public function testAStudentCannotPostStaffDetails(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->loggedInAs($student);

        $this->step('POST', 'profile/staff', ['office_location' => 'Room 1']);

        $this->assertNull($this->row($student)['office_location']);
        $this->assertNotEmpty(session('error'));
    }

    public function testAReadOnlyInstitutionRefusesTheEdit(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ro', 'Teacher', null, PeopleFactory::institution('PERO', 'read_only'));
        $this->loggedInAs($teacher);

        $this->step('POST', 'profile/about', ['bio' => 'Frozen'])->assertRedirect();

        $this->assertStringContainsString('read-only', (string) session('error'));
        $this->assertNull($this->row($teacher)['bio']);
    }

    public function testAMissingTokenIsRefused(): void
    {
        $this->loggedInAs($this->teacher);

        $this->withoutCsrfToken()->post('profile/about', ['bio' => 'No token'])->assertRedirect();

        $this->assertNull($this->row($this->teacher)['bio']);
    }

    public function testThePostOnlyEditsTheSignedInPerson(): void
    {
        $other = PeopleFactory::person('instructor', 'Olly', 'Other', null, $this->institution);
        $this->loggedInAs($this->teacher);

        $this->step('POST', 'profile/about', ['bio' => 'Mine', 'user_id' => (string) $other->id]);

        $this->assertSame('Mine', $this->row($this->teacher)['bio']);
        $this->assertNull($this->row($other)['bio']);
    }

    public function testASignedOutPostIsSentToLogin(): void
    {
        $this->step('POST', 'profile/about', ['bio' => 'x'])->assertRedirect();
        $this->assertNull($this->row($this->teacher)['bio']);
    }

    public function testThePageShowsPencilsAndDialogsToTheOwner(): void
    {
        $this->loggedInAs($this->teacher);
        $body = (string) $this->call('GET', 'profile')->response()->getBody();

        foreach (['profile-edit-identity', 'profile-edit-about', 'profile-edit-staff'] as $id) {
            $this->assertStringContainsString('data-dialog-open="' . $id . '"', $body, $id);
            $this->assertStringContainsString('id="' . $id . '"', $body, $id);
        }
        $this->assertStringContainsString('name="pronouns_choice"', $body);

        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $this->loggedInAs($student);
        $body = (string) $this->call('GET', 'profile')->response()->getBody();
        $this->assertStringNotContainsString('profile-edit-staff', $body);
        $this->assertStringContainsString('profile-edit-identity', $body);
    }
}

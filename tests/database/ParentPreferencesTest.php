<?php

declare(strict_types=1);

use App\Services\Parents\ParentPreferences;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * A parent's email choice for each of their children: on unless turned off,
 * kept per parent and child, and only ever for children they are linked to.
 *
 * @internal
 */
final class ParentPreferencesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private User $pam;

    private User $ann;

    private User $ben;

    private ParentPreferences $prefs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = PeopleFactory::institution('PPR');
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben         = PeopleFactory::person('student', 'Ben', 'Lee', null, $this->institution);
        $this->prefs       = new ParentPreferences();

        $this->link($this->pam, $this->ann);
        $this->link($this->pam, $this->ben);
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testEmailIsOnUntilTurnedOff(): void
    {
        $this->assertTrue($this->prefs->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
    }

    public function testTheChoiceIsKeptPerChild(): void
    {
        $this->prefs->save((int) $this->pam->id, [(int) $this->ann->id => false, (int) $this->ben->id => true]);

        $this->assertFalse($this->prefs->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertTrue($this->prefs->emailEnabled((int) $this->pam->id, (int) $this->ben->id));
    }

    public function testItCanBeTurnedBackOnAndTheRowIsReused(): void
    {
        $this->prefs->save((int) $this->pam->id, [(int) $this->ann->id => false]);
        $this->prefs->save((int) $this->pam->id, [(int) $this->ann->id => true]);

        $this->assertTrue($this->prefs->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertSame(1, db_connect()->table('parent_child_preferences')->where('parent_user_id', $this->pam->id)->countAllResults());
    }

    public function testAStudentTheParentIsNotLinkedToIsIgnored(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);

        $this->prefs->save((int) $this->pam->id, [(int) $stranger->id => false]);

        $this->assertSame(0, db_connect()->table('parent_child_preferences')->where('student_user_id', $stranger->id)->countAllResults());
        $this->assertTrue($this->prefs->emailEnabled((int) $this->pam->id, (int) $stranger->id), 'nothing was stored, so the default stands');
    }

    public function testOneParentsChoiceNeverChangesAnothers(): void
    {
        $olga = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $this->link($olga, $this->ann);

        $this->prefs->save((int) $this->pam->id, [(int) $this->ann->id => false]);

        $this->assertFalse($this->prefs->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertTrue($this->prefs->emailEnabled((int) $olga->id, (int) $this->ann->id));
    }
}

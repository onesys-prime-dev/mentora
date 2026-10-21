<?php

declare(strict_types=1);

use App\Models\Audit\AuditLogModel;
use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Flags\FeatureFlags;
use App\Services\Parents\ParentPortal;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Is the parent portal on for an institution: Mentora's `parent.portal` flag
 * AND the institution's own off-only switch.
 *
 * @internal
 */
final class ParentPortalTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = PeopleFactory::institution('PPT');
    }

    private function platformFlag(bool $on): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'parent.portal', 'institution_id' => null, 'enabled' => (int) $on]);
    }

    public function testThePortalIsOnByDefault(): void
    {
        $this->assertTrue((new ParentPortal())->enabled($this->institution));
        $this->assertFalse((new ParentPortal())->institutionSwitchedOff($this->institution));
    }

    public function testThereIsNoPortalOutsideAnInstitution(): void
    {
        $this->assertFalse((new ParentPortal())->enabled(null));
    }

    public function testAnInstitutionCanSwitchItOffAndOnAgain(): void
    {
        $portal = new ParentPortal();

        $portal->setInstitutionOn($this->institution, false);

        $this->assertTrue($portal->institutionSwitchedOff($this->institution));
        $this->assertFalse($portal->enabled($this->institution));

        $portal->setInstitutionOn($this->institution, true);

        $this->assertTrue($portal->enabled($this->institution));
    }

    public function testOneInstitutionSwitchingOffDoesNotAffectAnother(): void
    {
        $other = PeopleFactory::institution('PPO');

        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $this->assertTrue((new ParentPortal())->enabled($other));
    }

    public function testMentoraSwitchingTheFlagOffTurnsItOffForEveryone(): void
    {
        $this->platformFlag(false);

        $this->assertFalse((new ParentPortal())->enabled($this->institution));
    }

    public function testAnInstitutionCannotTurnItOnPastMentora(): void
    {
        $this->platformFlag(false);

        (new ParentPortal())->setInstitutionOn($this->institution, true);

        $this->assertFalse((new ParentPortal())->enabled($this->institution));
    }

    public function testASwitchThatCannotBeReadMeansOff(): void
    {
        $broken = new class () extends FeatureFlags {
            public function isEnabled(string $key, ?int $institutionId, ?DateTimeImmutable $now = null): bool
            {
                throw new RuntimeException('the table is gone');
            }
        };

        $this->assertFalse((new ParentPortal($broken))->enabled($this->institution));
    }

    public function testMentorasOwnSwitchCanBeAskedAboutOnItsOwn(): void
    {
        $this->assertTrue((new ParentPortal())->platformOn($this->institution));

        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->assertTrue((new ParentPortal())->platformOn($this->institution), 'the institution\'s own switch is not Mentora\'s');

        $this->platformFlag(false);
        $this->assertFalse((new ParentPortal())->platformOn($this->institution));
    }

    public function testMentorasSwitchThatCannotBeReadMeansOffToo(): void
    {
        $broken = new class () extends FeatureFlags {
            public function isEnabled(string $key, ?int $institutionId, ?DateTimeImmutable $now = null): bool
            {
                throw new RuntimeException('the table is gone');
            }
        };

        $this->assertFalse((new ParentPortal($broken))->platformOn($this->institution));
    }

    public function testSwitchingItIsAudited(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $entry = (new AuditLogModel())->where('action', 'settings.changed')->where('institution_id', $this->institution)->first();

        $this->assertNotNull($entry, 'the settings store audits the change');
    }
}

<?php

declare(strict_types=1);

use App\Services\Flags\FeatureFlagResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Whether a feature flag is on, from everything that can say something about
 * it. In order: the kill switch, the institution's own override, the plan,
 * then the default. A row that has ended is ignored. Pure logic.
 *
 * @internal
 */
final class FeatureFlagResolverTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-12';

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY . ' 09:30:00');
    }

    /** @return array{enabled: int, ends_on: ?string} */
    private static function row(bool $enabled, ?string $endsOn = null): array
    {
        return ['enabled' => (int) $enabled, 'ends_on' => $endsOn];
    }

    /** @return array{enabled: bool, reason: string} */
    private static function resolve(?array $global = null, ?array $override = null, bool $default = false, bool $plan = false): array
    {
        return FeatureFlagResolver::resolve($global, $override, $default, $plan, self::now());
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing says anything: the default
    |--------------------------------------------------------------------------
    */

    public function testWithNothingElseTheCodeDefaultDecides(): void
    {
        $this->assertSame(['enabled' => false, 'reason' => 'default'], self::resolve(default: false));
        $this->assertSame(['enabled' => true, 'reason' => 'default'], self::resolve(default: true));
    }

    /*
    |--------------------------------------------------------------------------
    | The plan
    |--------------------------------------------------------------------------
    */

    public function testAFlagTheInstitutionsPlanGrantsIsOnEvenIfTheDefaultIsOff(): void
    {
        $this->assertSame(['enabled' => true, 'reason' => 'plan'], self::resolve(default: false, plan: true));
    }

    public function testAFlagIsOnForEveryoneWhenTheDefaultIsOnWhetherOrNotThePlanGrantsIt(): void
    {
        $this->assertTrue(self::resolve(default: true, plan: false)['enabled']);
        $this->assertTrue(self::resolve(default: true, plan: true)['enabled']);
    }

    /*
    |--------------------------------------------------------------------------
    | The global row
    |--------------------------------------------------------------------------
    */

    public function testAGlobalOnRowMakesItOnByDefaultForEveryone(): void
    {
        $this->assertSame(['enabled' => true, 'reason' => 'global_on'], self::resolve(global: self::row(true), default: false));
    }

    public function testAGlobalOffRowIsTheKillSwitch(): void
    {
        $this->assertSame(['enabled' => false, 'reason' => 'kill_switch'], self::resolve(global: self::row(false), default: true));
    }

    /*
    |--------------------------------------------------------------------------
    | The institution's own override
    |--------------------------------------------------------------------------
    */

    public function testAnOverrideOnTurnsItOnWhateverThePlanAndDefaultSay(): void
    {
        $this->assertSame(['enabled' => true, 'reason' => 'override_on'], self::resolve(override: self::row(true), default: false, plan: false));
    }

    public function testAnOverrideOffTurnsItOffEvenWhenThePlanGrantsItAndTheDefaultIsOn(): void
    {
        $this->assertSame(['enabled' => false, 'reason' => 'override_off'], self::resolve(override: self::row(false), default: true, plan: true));
    }

    public function testAnOverrideBeatsAGlobalOnRow(): void
    {
        $this->assertSame(['enabled' => false, 'reason' => 'override_off'], self::resolve(global: self::row(true), override: self::row(false)));
    }

    /*
    |--------------------------------------------------------------------------
    | The kill switch beats everything
    |--------------------------------------------------------------------------
    */

    public function testTheKillSwitchBeatsAnOverrideThePlanAndTheDefault(): void
    {
        $answer = self::resolve(global: self::row(false), override: self::row(true), default: true, plan: true);

        $this->assertSame(['enabled' => false, 'reason' => 'kill_switch'], $answer);
    }

    /*
    |--------------------------------------------------------------------------
    | Rows that have ended
    |--------------------------------------------------------------------------
    */

    public function testAnOverrideAppliesThroughItsLastDay(): void
    {
        $this->assertSame('override_on', self::resolve(override: self::row(true, self::TODAY))['reason']);
        $this->assertSame('override_on', self::resolve(override: self::row(true, '2026-10-13'))['reason']);
    }

    public function testAnOverrideThatEndedYesterdayIsIgnored(): void
    {
        $this->assertSame(['enabled' => false, 'reason' => 'default'], self::resolve(override: self::row(true, '2026-10-11'), default: false));
        $this->assertSame(['enabled' => true, 'reason' => 'plan'], self::resolve(override: self::row(false, '2026-10-11'), default: false, plan: true), 'a lapsed "off" no longer turns it off');
    }

    public function testAKillSwitchThatHasEndedNoLongerKills(): void
    {
        $this->assertSame(['enabled' => true, 'reason' => 'default'], self::resolve(global: self::row(false, '2026-10-11'), default: true));
    }

    public function testAKillSwitchThatHasNotEndedStillKills(): void
    {
        $this->assertSame('kill_switch', self::resolve(global: self::row(false, self::TODAY), default: true)['reason']);
    }

    public function testAnOverrideWithNoEndNeverEnds(): void
    {
        $this->assertSame('override_on', FeatureFlagResolver::resolve(null, self::row(true, null), false, false, new DateTimeImmutable('2099-01-01'))['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | What a database hands back
    |--------------------------------------------------------------------------
    */

    public function testEnabledMayBeAnyOfTheFormsADatabaseReturns(): void
    {
        foreach ([1, '1', true] as $on) {
            $this->assertTrue(self::resolve(override: ['enabled' => $on, 'ends_on' => null])['enabled']);
        }

        foreach ([0, '0', false] as $off) {
            $this->assertFalse(self::resolve(override: ['enabled' => $off, 'ends_on' => null], default: true)['enabled']);
        }
    }

    public function testTheTimeOfDayDoesNotMatter(): void
    {
        $late = new DateTimeImmutable(self::TODAY . ' 23:59:59');

        $this->assertSame('override_on', FeatureFlagResolver::resolve(null, self::row(true, self::TODAY), false, false, $late)['reason']);
    }
}

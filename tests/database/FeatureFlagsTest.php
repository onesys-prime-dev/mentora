<?php

declare(strict_types=1);

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Flags\FeatureFlags;
use CodeIgniter\Config\Factories;
use Config\FeatureFlags as FlagConfig;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Feature flags against the real tables: the registry, what has been
 * changed (globally and per institution), what a plan grants, and the answer
 * for an institution, a person and the page.
 *
 * @internal
 */
final class FeatureFlagsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FeatureFlags $flags;

    protected function setUp(): void
    {
        parent::setUp();

        // A registry of our own, so the tests do not depend on which real flags exist.
        config(FlagConfig::class)->flags = [
            'test.off'  => ['description' => 'Off by default', 'default' => false],
            'test.on'   => ['description' => 'On by default', 'default' => true],
            'test.plan' => ['description' => 'Granted by plans', 'default' => false],
        ];

        $this->flags = new FeatureFlags();
    }

    protected function tearDown(): void
    {
        Factories::reset('config');

        parent::tearDown();
    }

    private function institution(string $code = 'ACME'): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    /** Puts the institution on a plan that grants the given feature keys. */
    private function onPlan(int $institution, array $features): void
    {
        db_connect()->table('plans')->insert(['name' => 'Plan' . random_int(1000, 9999), 'features' => json_encode($features), 'status' => 'active']);

        db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) db_connect()->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);
    }

    private function change(string $key, ?int $institution, bool $enabled, ?string $endsOn = null): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => $key, 'institution_id' => $institution, 'enabled' => (int) $enabled, 'ends_on' => $endsOn]);
    }

    /*
    |--------------------------------------------------------------------------
    | The table
    |--------------------------------------------------------------------------
    */

    public function testTheTableExists(): void
    {
        $this->assertTrue(db_connect()->tableExists('feature_flag_overrides'));
    }

    public function testThereIsOneRowPerFlagAndInstitution(): void
    {
        $institution = $this->institution();
        $this->change('test.off', $institution, true);

        $this->assertFalse($this->tryToStore('test.off', $institution, false));
        $this->assertSame(1, db_connect()->table('feature_flag_overrides')->where('institution_id', $institution)->countAllResults());
    }

    public function testThereIsOneGlobalRowPerFlagToo(): void
    {
        $this->change('test.off', null, true);

        $this->assertFalse($this->tryToStore('test.off', null, false), 'a plain unique key alone would let NULL repeat');
        $this->assertSame(1, db_connect()->table('feature_flag_overrides')->where('flag_key', 'test.off')->where('institution_id IS NULL', null, false)->countAllResults());
    }

    public function testAGlobalRowIsMarkedGlobalAndAnInstitutionsRowIsNot(): void
    {
        $institution = $this->institution();
        $this->change('test.off', null, true);
        $this->change('test.on', $institution, false);

        $global = db_connect()->table('feature_flag_overrides')->where('flag_key', 'test.off')->get()->getRowArray();
        $mine   = db_connect()->table('feature_flag_overrides')->where('flag_key', 'test.on')->get()->getRowArray();

        $this->assertSame(1, (int) $global['is_global']);
        $this->assertNull($mine['is_global']);
    }

    public function testTheMarkerCannotBeSetWrongThroughTheModel(): void
    {
        $institution = $this->institution();

        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.off', 'institution_id' => $institution, 'is_global' => 1, 'enabled' => 1]);
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.on', 'institution_id' => null, 'is_global' => null, 'enabled' => 1]);

        $this->assertNull(db_connect()->table('feature_flag_overrides')->where('flag_key', 'test.off')->get()->getRowArray()['is_global']);
        $this->assertSame(1, (int) db_connect()->table('feature_flag_overrides')->where('flag_key', 'test.on')->get()->getRowArray()['is_global']);
    }

    public function testTheMarkerFollowsARowWhoseScopeChanges(): void
    {
        $institution = $this->institution();
        $this->change('test.off', $institution, true);
        $id = (int) db_connect()->table('feature_flag_overrides')->get()->getRowArray()['id'];

        (new FeatureFlagOverrideModel())->update($id, ['institution_id' => null]);

        $row = db_connect()->table('feature_flag_overrides')->where('id', $id)->get()->getRowArray();

        $this->assertNull($row['institution_id']);
        $this->assertSame(1, (int) $row['is_global']);
    }

    /** Tries to store a row; false when the database refuses it. */
    private function tryToStore(string $key, ?int $institution, bool $enabled): bool
    {
        try {
            return (new FeatureFlagOverrideModel())->insert(['flag_key' => $key, 'institution_id' => $institution, 'enabled' => (int) $enabled]) !== false;
        } catch (CodeIgniter\Database\Exceptions\DatabaseException) {
            return false;
        }
    }

    public function testDifferentFlagsAndInstitutionsCanEachHaveTheirOwnRow(): void
    {
        $a = $this->institution('A');
        $b = $this->institution('B');

        $this->change('test.off', $a, true);
        $this->change('test.off', $b, true);
        $this->change('test.on', $a, false);
        $this->change('test.off', null, true);

        $this->assertSame(4, db_connect()->table('feature_flag_overrides')->countAllResults());
    }

    public function testRemovingAnInstitutionRemovesItsOverrides(): void
    {
        $gone = $this->institution('GONE');
        $kept = $this->institution('KEPT');
        $this->change('test.off', $gone, true);
        $this->change('test.off', $kept, true);
        $this->change('test.off', null, true);

        db_connect()->table('institutions')->where('id', $gone)->delete();

        $this->assertSame(0, db_connect()->table('feature_flag_overrides')->where('institution_id', $gone)->countAllResults());
        $this->assertSame(1, db_connect()->table('feature_flag_overrides')->where('institution_id', $kept)->countAllResults());
        $this->assertSame(1, db_connect()->table('feature_flag_overrides')->where('institution_id IS NULL', null, false)->countAllResults(), 'the global row stays');
    }

    /*
    |--------------------------------------------------------------------------
    | The registry
    |--------------------------------------------------------------------------
    */

    public function testTheListedFlagsAreKnownAndOthersAreNot(): void
    {
        $this->assertTrue($this->flags->exists('test.off'));
        $this->assertFalse($this->flags->exists('test.nope'));
        $this->assertSame(['test.off', 'test.on', 'test.plan'], array_keys($this->flags->definitions()));
    }

    public function testAnUnknownFlagIsAMistakeAndThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown feature flag');

        $this->flags->isEnabled('test.nope', null);
    }

    public function testTheRealFlagsAreWellFormed(): void
    {
        Factories::reset('config');

        foreach ((new FeatureFlags())->definitions() as $key => $definition) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9._-]{1,63}$/', $key);
            $this->assertNotSame('', trim($definition['description']), "{$key} needs a description");
            $this->assertIsBool($definition['default']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Answers
    |--------------------------------------------------------------------------
    */

    public function testWithNothingChangedTheCodeDefaultsApply(): void
    {
        $institution = $this->institution();

        $this->assertFalse($this->flags->isEnabled('test.off', $institution));
        $this->assertTrue($this->flags->isEnabled('test.on', $institution));
        $this->assertSame('default', $this->flags->explain('test.off', $institution)['reason']);
    }

    public function testAnInstitutionOverrideChangesTheAnswerForThatInstitutionOnly(): void
    {
        $mine  = $this->institution('MINE');
        $other = $this->institution('OTHER');
        $this->change('test.off', $mine, true);
        $this->change('test.on', $mine, false);

        $this->assertTrue($this->flags->isEnabled('test.off', $mine));
        $this->assertFalse($this->flags->isEnabled('test.on', $mine));
        $this->assertFalse($this->flags->isEnabled('test.off', $other));
        $this->assertTrue($this->flags->isEnabled('test.on', $other));
        $this->assertSame('override_on', $this->flags->explain('test.off', $mine)['reason']);
        $this->assertSame('override_off', $this->flags->explain('test.on', $mine)['reason']);
    }

    public function testAGlobalOnRowTurnsAnOffByDefaultFlagOnForEveryone(): void
    {
        $this->change('test.off', null, true);

        $this->assertTrue($this->flags->isEnabled('test.off', $this->institution()));
        $this->assertTrue($this->flags->isEnabled('test.off', null), 'and for someone with no institution');
    }

    public function testTheKillSwitchTurnsItOffForEveryoneWhateverElseSays(): void
    {
        $institution = $this->institution();
        $this->onPlan($institution, ['test.plan']);
        $this->change('test.plan', $institution, true);
        $this->change('test.on', $institution, true);
        $this->change('test.plan', null, false);
        $this->change('test.on', null, false);

        $this->assertFalse($this->flags->isEnabled('test.plan', $institution));
        $this->assertFalse($this->flags->isEnabled('test.on', $institution));
        $this->assertSame('kill_switch', $this->flags->explain('test.plan', $institution)['reason']);
        $this->assertFalse($this->flags->isEnabled('test.plan', null));
    }

    public function testTheKillSwitchOnOneFlagLeavesTheOthersAlone(): void
    {
        $institution = $this->institution();
        $this->change('test.on', null, false);

        $this->assertFalse($this->flags->isEnabled('test.on', $institution));
        $this->assertFalse($this->flags->isEnabled('test.off', $institution));
        $this->assertSame(['test.off' => false, 'test.on' => false, 'test.plan' => false], $this->flags->enabledFor($institution));

        $this->change('test.off', null, true);

        $this->assertTrue($this->flags->isEnabled('test.off', $institution), 'a different flag is untouched by the kill switch');
    }

    public function testAPlanGrantTurnsAFlagOnForInstitutionsOnThatPlan(): void
    {
        $granted   = $this->institution('GRANTED');
        $ungranted = $this->institution('UNGRANTED');
        $this->onPlan($granted, ['test.plan']);
        $this->onPlan($ungranted, ['something.else']);

        $this->assertTrue($this->flags->isEnabled('test.plan', $granted));
        $this->assertSame('plan', $this->flags->explain('test.plan', $granted)['reason']);
        $this->assertFalse($this->flags->isEnabled('test.plan', $ungranted));
        $this->assertFalse($this->flags->isEnabled('test.plan', $this->institution('NOSUB')), 'no subscription, no grant');
        $this->assertFalse($this->flags->isEnabled('test.plan', null));
    }

    public function testAnOverrideOffBeatsAPlanGrant(): void
    {
        $institution = $this->institution();
        $this->onPlan($institution, ['test.plan']);
        $this->change('test.plan', $institution, false);

        $this->assertFalse($this->flags->isEnabled('test.plan', $institution));
    }

    public function testAnEmptyOrBrokenPlanFeatureListGrantsNothing(): void
    {
        $institution = $this->institution();
        db_connect()->table('plans')->insert(['name' => 'Odd', 'features' => 'not json', 'status' => 'active']);
        db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) db_connect()->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);

        $this->assertFalse($this->flags->isEnabled('test.plan', $institution));
    }

    public function testAnOverrideThatHasEndedIsIgnored(): void
    {
        $institution = $this->institution();
        $now         = new DateTimeImmutable('2026-10-12');

        $this->change('test.off', $institution, true, '2026-10-11');

        $this->assertFalse($this->flags->isEnabled('test.off', $institution, $now), 'ended yesterday');
        $this->assertTrue($this->flags->isEnabled('test.off', $institution, new DateTimeImmutable('2026-10-11')), 'still on its last day');
    }

    public function testAKillSwitchThatHasEndedNoLongerKills(): void
    {
        $institution = $this->institution();
        $this->change('test.on', null, false, '2026-10-11');

        $this->assertTrue($this->flags->isEnabled('test.on', $institution, new DateTimeImmutable('2026-10-12')));
        $this->assertFalse($this->flags->isEnabled('test.on', $institution, new DateTimeImmutable('2026-10-10')));
    }

    public function testEnabledForGivesEveryFlagsAnswerAtOnce(): void
    {
        $institution = $this->institution();
        $this->onPlan($institution, ['test.plan']);
        $this->change('test.on', $institution, false);

        $this->assertSame(['test.off' => false, 'test.on' => false, 'test.plan' => true], $this->flags->enabledFor($institution));
        $this->assertSame(['test.off' => false, 'test.on' => true, 'test.plan' => false], $this->flags->enabledFor(null));
    }

    public function testAnotherInstitutionsOverridesNeverLeak(): void
    {
        $this->change('test.off', $this->institution('THEIRS'), true);

        $this->assertSame(['test.off' => false, 'test.on' => true, 'test.plan' => false], $this->flags->enabledFor($this->institution('MINE')));
    }

    /*
    |--------------------------------------------------------------------------
    | The model and the helpers
    |--------------------------------------------------------------------------
    */

    public function testTheModelStoresARowWithItsNoteAndEndDate(): void
    {
        $institution = $this->institution();

        $id = (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.off', 'institution_id' => $institution, 'enabled' => 1, 'note' => 'Pilot with their IT team', 'ends_on' => '2026-12-31']);

        $row = (new FeatureFlagOverrideModel())->find($id);

        $this->assertSame('Pilot with their IT team', $row['note']);
        $this->assertSame('2026-12-31', $row['ends_on']);
    }

    public function testTheHelperAnswersForTheSignedInPersonsInstitution(): void
    {
        $institution = $this->institution();
        $this->change('test.off', $institution, true);
        $user = CourseFactory::user('instructor');
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id]);

        auth()->login($user);

        $this->assertTrue(feature_enabled('test.off'));
        $this->assertTrue(feature_enabled('test.on'));
        $this->assertSame(['test.off' => true, 'test.on' => true, 'test.plan' => false], current_features());

        auth()->logout();
    }

    public function testTheHelperForSomeoneWithNoInstitutionUsesTheGlobalAnswers(): void
    {
        $this->change('test.off', null, true);
        auth()->login(CourseFactory::user('admin'));

        $this->assertTrue(feature_enabled('test.off'));

        auth()->logout();

        $this->assertSame(['test.off' => true, 'test.on' => true, 'test.plan' => false], current_features(), 'and for nobody signed in');
    }

    public function testTheHelperThrowsForAnUnknownFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);

        feature_enabled('test.nope');
    }
}

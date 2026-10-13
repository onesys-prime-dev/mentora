<?php

declare(strict_types=1);

use App\Models\Flags\FeatureFlagOverrideModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Entities\User;
use Config\FeatureFlags as FlagConfig;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The page tells the browser which feature flags are on for the signed-in
 * person's institution, in the body's data-features attribute, which
 * resources/js/shared/features.js reads.
 *
 * @internal
 */
final class FeatureFlagsPageTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(FlagConfig::class)->flags = [
            'test.off' => ['description' => 'Off by default', 'default' => false],
            'test.on'  => ['description' => 'On by default', 'default' => true],
        ];
    }

    protected function tearDown(): void
    {
        Factories::reset('config');

        parent::tearDown();
    }

    private function institution(): int
    {
        db_connect()->table('institutions')->insert(['name' => 'Flag University', 'code' => 'FLAGU', 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    private function userIn(string $role, ?int $institution): User
    {
        $user = CourseFactory::user($role);

        if ($institution !== null) {
            db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id]);
        }

        return $user;
    }

    /** @return array<string, mixed> the flags the page printed */
    private function printedFlags(string $uri): array
    {
        $body = $this->step('GET', $uri)->response()->getBody();

        $this->assertSame(1, preg_match('/<body[^>]*data-features="([^"]*)"/s', $body, $match), 'the page prints data-features on the body');

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testThePageCarriesTheFlagsAsTheyAreForTheInstitution(): void
    {
        $institution = $this->institution();
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.off', 'institution_id' => $institution, 'enabled' => 1]);

        $this->loggedInAs($this->userIn('student', $institution));
        $this->withSession();

        $this->assertSame(['test.off' => true, 'test.on' => true], $this->printedFlags('student/dashboard'));
    }

    public function testADifferentInstitutionSeesItsOwnAnswers(): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.on', 'institution_id' => $this->institution(), 'enabled' => 0]);

        $this->loggedInAs($this->userIn('instructor', $this->institution2()));
        $this->withSession();

        $this->assertSame(['test.off' => false, 'test.on' => true], $this->printedFlags('instructor/dashboard'));
    }

    public function testTheKillSwitchShowsOnThePage(): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'test.on', 'institution_id' => null, 'enabled' => 0]);

        $this->loggedInAs($this->userIn('student', $this->institution()));
        $this->withSession();

        $this->assertFalse($this->printedFlags('student/dashboard')['test.on']);
    }

    public function testSomeoneWithNoInstitutionGetsTheGlobalAnswers(): void
    {
        $this->loggedInAs($this->userIn('student', null));
        $this->withSession();

        $this->assertSame(['test.off' => false, 'test.on' => true], $this->printedFlags('student/dashboard'));
    }

    public function testAnEmptyRegistryPrintsAnEmptyObjectNotAnEmptyList(): void
    {
        config(FlagConfig::class)->flags = [];

        $this->loggedInAs($this->userIn('student', null));
        $this->withSession();

        $body = $this->step('GET', 'student/dashboard')->response()->getBody();

        $this->assertSame(1, preg_match('/<body[^>]*data-features="([^"]*)"/s', $body, $match));
        $this->assertSame('{}', html_entity_decode($match[1], ENT_QUOTES), 'an object, so the browser module can read it');
    }

    private function institution2(): int
    {
        db_connect()->table('institutions')->insert(['name' => 'Other University', 'code' => 'OTHERU', 'status' => 'active']);

        return (int) db_connect()->insertID();
    }
}

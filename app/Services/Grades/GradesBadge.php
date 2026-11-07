<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Throwable;

/**
 * The count on the Grades tile in the Apps menu and the dashboard's Quick Access: what needs this person on
 * their Grades page, in the page's own scope (its default term), from the services the page reads.
 *
 *  - instructor: work waiting to grade in the sections they teach (InstructorGradesOverview's summary);
 *  - chairperson: the same over My sections (ChairpersonProgramOverview::mySections);
 *  - student: published courses whose grade changed since their last look (GradesSeen, viewer = student);
 *  - parent: the same summed over their linked children (viewer = parent), only while the portal is on;
 *  - dean: sections finalized and not signed off (DeanCollegeOverview, every program).
 *
 * The tile renders on every page, so the count is cached for TTL seconds per role and user (a badge may lag
 * by up to a minute, spec §10) and kept for the rest of the request, so the menu and Quick Access on one page
 * work it out once. Reading it never writes: in particular GradesSeen::remember is the page's alone. A count
 * that fails to load is 0 and is not cached, so the page around it still renders.
 */
final class GradesBadge
{
    public const TTL = 60;

    private const ROLES = ['instructor', 'chairperson', 'student', 'parent', 'dean'];

    /** @var array<string, int> this request's counts, by cache key */
    private static array $memo = [];

    /** The request the memo belongs to: a long-running worker serves many, and none may see another's counts. */
    private static ?object $memoRequest = null;

    public static function for(User $user, string $role): int
    {
        if (! in_array($role, self::ROLES, true)) {
            return 0;
        }

        $key = self::key($role, (int) $user->id);

        self::memoFor(service('request'));

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        try {
            $cached = cache()->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_int($cached)) {
            return self::$memo[$key] = $cached;
        }

        try {
            $count = (new self())->count($user, $role);
        } catch (Throwable $e) {
            log_message('error', 'The Grades badge failed to load: ' . $e->getMessage());

            return self::$memo[$key] = 0;
        }

        try {
            cache()->save($key, $count, self::TTL);
        } catch (Throwable) {
            // Without a cache the count is still right; the next page works it out again.
        }

        return self::$memo[$key] = $count;
    }

    /** What the pill reads: the count, or "99+" from 100 on. */
    public static function text(int $count): string
    {
        return $count >= 100 ? '99+' : (string) $count;
    }

    /** What the pill says to a screen reader, in the role's words: "3 waiting to grade", "2 courses changed". */
    public static function label(string $role, int $count): string
    {
        return match ($role) {
            'student', 'parent' => $count . ($count === 1 ? ' course changed' : ' courses changed'),
            'dean'              => $count . ($count === 1 ? ' section ready to sign off' : ' sections ready to sign off'),
            default             => $count . ' waiting to grade',
        };
    }

    /** Drops the person's count for a role, so the next page counts again (after their Grades page remembered). */
    public static function forget(int $userId, string $role): void
    {
        $key = self::key($role, $userId);

        unset(self::$memo[$key]);

        try {
            cache()->delete($key);
        } catch (Throwable) {
            // Nothing cached: nothing to drop.
        }
    }

    /** Forgets this request's counts (the cache stays): a new request starts with none. */
    public static function flush(): void
    {
        self::$memo        = [];
        self::$memoRequest = null;
    }

    /** Starts the memo over when the request is not the one it was kept for. */
    private static function memoFor(object $request): void
    {
        if (self::$memoRequest !== $request) {
            self::$memo        = [];
            self::$memoRequest = $request;
        }
    }

    private static function key(string $role, int $userId): string
    {
        return 'grades-badge-' . $role . '-' . $userId;
    }

    private function count(User $user, string $role): int
    {
        return match ($role) {
            'instructor'  => $this->waitingAsInstructor($user),
            'chairperson' => $this->waitingAsChairperson($user),
            'student'     => $this->changed($user, $user),
            'parent'      => $this->changedForChildren($user),
            'dean'        => $this->readyToSignOff($user),
            default       => 0, // for(): ROLES holds the five above
        };
    }

    private function waitingAsInstructor(User $user): int
    {
        $overview = new InstructorGradesOverview();
        $termId   = $overview->defaultTermId($overview->terms($user), null);

        return $termId === null ? 0 : $overview->summary($overview->sections($user, $termId))['waiting'];
    }

    private function waitingAsChairperson(User $user): int
    {
        $overview = new ChairpersonProgramOverview();
        $termId   = $overview->defaultTermId($overview->terms($user), null);

        return $termId === null ? 0 : (new InstructorGradesOverview())->summary($overview->mySections($user, $termId))['waiting'];
    }

    /** Published courses of the student's default term that the viewer would see badged "Changed". */
    private function changed(User $viewer, User $student): int
    {
        $overview = new StudentGradesOverview();
        $terms    = $overview->terms($student);
        $termId   = $overview->defaultTermId($terms, null);

        if ($termId === null) {
            return 0;
        }

        $rows       = $overview->courses($student, array_column($terms, null, 'id')[$termId]);
        $signatures = [];

        foreach ($rows as $row) {
            $signatures[$row['offering_id']] = GradesSeen::shownSignature($row);
        }

        $changed = (new GradesSeen())->changed((int) $viewer->id, (int) $student->id, $signatures);

        return count(array_filter($rows, static fn (array $row): bool => $row['published'] && ($changed[$row['offering_id']] ?? false)));
    }

    /** changed() for each linked child the parent page shows (a child who is also staff shows nothing). */
    private function changedForChildren(User $parent): int
    {
        if (! (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $parent->id))) {
            return 0;
        }

        $users = model(UserModel::class);
        $total = 0;

        foreach ((new ParentAccessService())->children($parent) as $child) {
            $user = $users->findById($child['student_id']);

            if ($user !== null && ParentAccessService::readsAsStudent($user)) {
                $total += $this->changed($parent, $user);
            }
        }

        return $total;
    }

    /** Sections of the dean's page (default term, every program) finalized and not yet signed off. */
    private function readyToSignOff(User $dean): int
    {
        $overview = new DeanCollegeOverview();
        $termId   = $overview->defaultTermId($overview->terms($dean), null);

        if ($termId === null) {
            return 0;
        }

        return count(array_filter(
            $overview->all($dean, $termId, null),
            static fn (array $row): bool => $row['state'] === 'finalized' && ! $row['signed_off'],
        ));
    }
}

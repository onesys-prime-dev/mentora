<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Services\Auth\RolePermissions;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Grades\PendingGrading;
use App\Services\Grades\GradesAtRisk;
use App\Services\Grades\GradesSeen;
use App\Services\Grades\InstructorGradesOverview;
use App\Services\Grades\StudentGradesOverview;
use App\Services\Institutions\AcademicDefaults;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;

/**
 * The Grades app: grades across every section a person teaches or course they take, on one page, so term end
 * is not a course-by-course walk. One controller under each role's own area, the way Announcements is; the
 * instructor, the student and the parent have it so far (spec §2, §5, §6, §7).
 *
 * The student's page is one term at a time: a row per course with its grade, the summary with the GWA, and a
 * "Changed" badge on a course whose grade moved since they last looked (spec §5).
 *
 * The parent's is the same page for one linked child, read through the parent boundary (the `parentportal` and
 * `parentchild` filters, and ParentAccessService): its links go to the parent's views, a finalized course offers
 * its report, and the "Changed" memory is the parent's own (spec §6).
 *
 * The instructor's has three tabs, all in the query string so a view can be bookmarked: the Gradebook (a card per section and that
 * section's grid, which the page script fetches), To grade (work waiting across every section, oldest first)
 * and At risk. Every number is read from stored rows; nothing is created or recalculated by looking, so a
 * section that never had its Grades tab opened reads as "not published yet" (spec §13).
 */
class GradesController extends BaseController
{
    private const TABS = ['gradebook', 'to-grade', 'at-risk'];

    public function index(): string
    {
        $user = auth()->user();
        $area = $this->area();
        $base = site_url($area . '/grades');

        if ($area === 'student') {
            return $this->student($user, $user, $base, [
                'area'          => 'student',
                'backTo'        => site_url('student/dashboard'),
                'courseBase'    => site_url('student/courses'),
                'breakdownBase' => site_url('student/api/grades/courses'),
                'reportBase'    => null,
            ]);
        }

        $tab = (string) $this->request->getGet('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'gradebook';

        // ?section= names a section by its number; anything else is no page at all, not a silent fallback.
        $requestedSection = $this->number('section');

        if ($requestedSection === false) {
            throw PageNotFoundException::forPageNotFound();
        }

        $reason = (string) $this->request->getGet('reason');
        $reason = in_array($reason, GradesAtRisk::KINDS, true) ? $reason : null;

        $overview  = new InstructorGradesOverview();
        $loadError = false;
        $terms     = [];
        $termId    = null;
        $sections  = [];
        $risk      = [];
        $queue     = [];
        $types     = [];
        $filters   = ['qsection' => null, 'type' => null, 'late' => false];

        try {
            $terms    = $overview->terms($user);
            $termId   = $overview->defaultTermId($terms, $this->number('term') ?: null);
            $sections = $termId === null ? [] : $overview->sections($user, $termId);
            $risk     = (new GradesAtRisk())->forSections($sections, null);

            if ($tab === 'to-grade') {
                [$queue, $types, $filters] = $this->toGrade($sections, $termId, $area);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Grades failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $byId = array_column($sections, null, 'offering_id');

        // A section they do not teach (or not in this term) is a 404, like the course routes.
        if (! $loadError && $requestedSection !== null && ! isset($byId[$requestedSection])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $summary  = $overview->summary($sections);
        $selected = $byId[$overview->defaultSectionId($sections, $requestedSection)] ?? null;

        // The At risk count is everyone, whatever reason is picked; the list keeps only that reason.
        $atRisk = $reason === null ? $risk : array_values(array_filter(
            $risk,
            static fn (array $row): bool => in_array($reason, array_column($row['reasons'], 'kind'), true),
        ));

        return view('pages/grades/index', [
            'title'        => 'Grades',
            'page'         => 'grades',
            'user'         => $user,
            'area'         => $area,
            'base'         => $base,
            'tab'          => $tab,
            'terms'        => $terms,
            'termId'       => $termId,
            'sections'     => $sections,
            'selected'     => $selected,
            'summary'      => $summary,
            'counts'       => ['to-grade' => $summary['waiting'], 'at-risk' => count($risk)],
            'atRisk'       => $atRisk,
            'reason'       => $reason,
            'queue'        => $queue,
            'types'        => $types,
            'filters'      => $filters,
            'canManage'    => (new RolePermissions())->allows($user, 'grading.manage', $area),
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);
    }

    /**
     * The parent's menu entry: the first child's grades, or the existing explanation while the portal is off or
     * no child is linked (as the parent's calendar does).
     */
    public function parentIndex(): string|RedirectResponse
    {
        $user  = auth()->user();
        $shell = ['title' => 'Grades', 'page' => 'grades', 'user' => $user];

        if (! (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $user->id))) {
            return view('pages/parent/portal-off', $shell);
        }

        $children = (new ParentAccessService())->children($user);

        if ($children === []) {
            return view('pages/parent/not-linked', $shell);
        }

        return redirect()->to(site_url('parent/children/' . $children[0]['student_id'] . '/grades'));
    }

    /**
     * One linked child's Grades page, for the parent. The filters have answered for the portal and the link; a
     * child who is also staff is read by the course services as staff, so no grade of theirs is read or shown.
     */
    public function parentChild(int $studentId): string
    {
        $parent = auth()->user();
        $access = new ParentAccessService();
        $child  = $access->childUser($parent, $studentId) ?? throw PageNotFoundException::forPageNotFound();
        $prefix = 'parent/children/' . $studentId;

        return $this->student($parent, $child, site_url($prefix . '/grades'), [
            'area'          => 'parent',
            'backTo'        => site_url($prefix),
            'courseBase'    => site_url($prefix . '/courses'),
            'breakdownBase' => site_url($prefix . '/api/grades/courses'),
            'reportBase'    => site_url($prefix . '/api/courses'),
            'children'      => $access->children($parent),
            'childId'       => $studentId,
        ], ParentAccessService::readsAsStudent($child));
    }

    /** The area this request is in: its first address segment, one of those that have the page. */
    private function area(): string
    {
        $area = $this->request->getUri()->getSegment(1);

        return in_array($area, ['instructor', 'student'], true) ? $area : 'instructor';
    }

    /**
     * The student's page for one term: its courses, the summary and the bar's line, all from stored rows. The
     * viewer is the student themselves or their parent; $links carries what differs between the two (the area,
     * the way back, and where the course links, the breakdowns and the reports go).
     *
     * The page's one write is the viewer's "seen" snapshot of every row it showed (published or not), made after
     * the page is built so each badge compares with the previous look; GradesSeen skips it while someone views as
     * the viewer. With $readable false (a child who is also staff) nothing is read and nothing is written.
     *
     * @param array<string, mixed> $links
     */
    private function student(User $viewer, User $user, string $base, array $links, bool $readable = true): string
    {
        $overview   = new StudentGradesOverview();
        $seen       = new GradesSeen();
        $loadError  = false;
        $terms      = [];
        $term       = null;
        $rows       = [];
        $summary    = null;
        $bar        = null;
        $hasScale   = false;
        $changed    = [];
        $signatures = [];

        try {
            $terms  = $readable ? $overview->terms($user) : [];
            $termId = $overview->defaultTermId($terms, $this->number('term') ?: null);
            $term   = $termId === null ? null : array_column($terms, null, 'id')[$termId];

            if ($term !== null) {
                $rows = $overview->courses($user, $term);

                // The GWA is on the institution's default scale; with none, there is no GWA.
                $institution = (int) db_connect()->table('academic_terms')->select('institution_id')->where('id', $term['id'])->get()->getRow()->institution_id;
                $scaleId     = (new AcademicDefaults())->forInstitution($institution)['scaleId'];
                $hasScale    = $scaleId !== null;
                $summary     = $overview->summary($rows, $scaleId);
                $bar         = $overview->barLine($term, $rows, date('Y-m-d'));

                // Every row is remembered as shown, so a grade published since the last look is a change too;
                // an unpublished row shows only its chip (a withdrawn one keeps its Withdrawn tag).
                foreach ($rows as $row) {
                    $shown = $row['published'] || $row['withdrawn'] ? $row : ['tag' => 'Not published yet'] + $row;

                    $signatures[$row['offering_id']] = GradesSeen::signature($shown);
                }

                $changed = $seen->changed((int) $viewer->id, (int) $user->id, $signatures);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Grades failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $relative = new AssessmentOverviewService();

        foreach ($rows as $i => $row) {
            $rows[$i]['changed'] = $changed[$row['offering_id']] ?? false;
            $rows[$i]['updated'] = $relative->relativeTime($row['updated_at']);
        }

        $html = view('pages/grades/index', $links + [
            'title'        => 'Grades',
            'page'         => 'grades',
            'user'         => $viewer,
            'base'         => $base,
            'terms'        => $terms,
            'termId'       => $term['id'] ?? null,
            'rows'         => $rows,
            'summary'      => $summary,
            'bar'          => $bar,
            'hasScale'     => $hasScale,
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);

        if (! $loadError) {
            $seen->remember((int) $viewer->id, (int) $user->id, $signatures);
        }

        return $html;
    }

    /**
     * A whole number from the query string: null when it is absent or empty, false when it is something else.
     */
    private function number(string $key): int|false|null
    {
        $value = $this->request->getGet($key);

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : false;
    }

    /**
     * The To grade queue: one row per assessment with work waiting, oldest first, narrowed by section, type and
     * late work. Each row's Grade → carries the way back to this queue, filters and all.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: array{qsection: ?int, type: ?string, late: bool}}
     */
    private function toGrade(array $sections, ?int $termId, string $area): array
    {
        $byId = array_column($sections, null, 'offering_id');
        $rows = (new PendingGrading())->forOfferings(array_keys($byId))['assessments'];

        $types = array_values(array_unique(array_column($rows, 'type')));
        sort($types);

        // A filter that names nothing of theirs is ignored.
        $qsection = $this->number('qsection') ?: null;
        $qsection = isset($byId[$qsection]) ? $qsection : null;
        $type     = (string) $this->request->getGet('type');
        $type     = in_array($type, $types, true) ? $type : null;
        $late     = $this->request->getGet('late') === '1';

        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($qsection === null || $row['offering_id'] === $qsection)
            && ($type === null || $row['type'] === $type)
            && (! $late || $row['late'] > 0)));

        // Oldest first; work without a hand-in time goes last.
        usort($rows, static fn (array $a, array $b): int => [$a['oldest_at'] === null, $a['oldest_at'], $a['offering_id'], $a['assessment_id']]
            <=> [$b['oldest_at'] === null, $b['oldest_at'], $b['offering_id'], $b['assessment_id']]);

        $back = (string) parse_url(site_url($area . '/grades'), PHP_URL_PATH) . '?' . http_build_query(array_filter([
            'tab'      => 'to-grade',
            'term'     => $termId,
            'qsection' => $qsection,
            'type'     => $type,
            'late'     => $late ? 1 : null,
        ], static fn ($value): bool => $value !== null));

        $now   = time();
        $queue = [];

        foreach ($rows as $row) {
            $section = $byId[$row['offering_id']];
            $days    = $row['oldest_at'] === null ? null : max(0, intdiv($now - $row['oldest_at'], 86400));
            $oldest  = match (true) {
                $days === null => null,
                $days === 0    => 'oldest today',
                $days === 1    => 'oldest 1 day',
                default        => "oldest {$days} days",
            };

            $queue[] = [
                'offering_id'   => $row['offering_id'],
                'assessment_id' => $row['assessment_id'],
                'label'         => $section['course_code'] . '-' . $section['section_code'] . ' · ' . $row['title'],
                'detail'        => implode(' · ', array_filter([$row['waiting'] . ' waiting', $oldest, ucfirst($row['type'])])),
                'late'          => $row['late'],
                // Grade work opens on the student who has waited longest, when there is one to name.
                'href'          => site_url("{$area}/courses/{$row['offering_id']}/{$section['slug']}/assessments")
                    . '?assessment=' . $row['assessment_id'] . '&grade=1'
                    . ($row['first_student_id'] === null ? '' : '&student=' . $row['first_student_id'])
                    . '&return=' . rawurlencode($back),
            ];
        }

        return [$queue, $types, ['qsection' => $qsection, 'type' => $type, 'late' => $late]];
    }
}

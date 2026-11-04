<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Services\Admin\Impersonation;
use App\Services\Auth\RolePermissions;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Grades\PendingGrading;
use App\Services\Grades\ChairpersonProgramOverview;
use App\Services\Grades\DeanDepartmentOverview;
use App\Services\Grades\GradesAtRisk;
use App\Services\Grades\GradesBadge;
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
 *
 * The chairperson's has two tabs (spec §8): My sections, the instructor's workbench over the sections they teach
 * in the sub-departments they chair, without its finalize controls (finalizing is the instructor's); and Program,
 * the program's sections as a table with the finalization tracker, the chosen section's grid below it (read-only
 * until Edit as chairperson) and the program's at-risk list. A tab the chairperson cannot use is not shown.
 *
 * The dean's is one Department view (spec §9): the chairperson's Program table over every section of their
 * department, narrowed by a program picker, with a Signed off column and, in place of the tracker, a strip whose
 * counts link to Sign-off and Approvals; the chosen section's grid is always read-only.
 */
class GradesController extends BaseController
{
    private const TABS = ['gradebook', 'to-grade', 'at-risk'];

    /** The chairperson's tabs, and the lists inside each (the first is the default). */
    private const CHAIR_SUBS = ['mine' => ['gradebook', 'to-grade', 'at-risk'], 'program' => ['sections', 'at-risk']];

    /** The dean's lists (the first is the default). */
    private const DEAN_SUBS = ['sections', 'at-risk'];

    public function index(): string
    {
        $user = auth()->user();
        $area = $this->area();
        $base = site_url($area . '/grades');

        if ($area === 'chairperson') {
            return $this->chairperson($user, $base);
        }

        if ($area === 'dean') {
            return $this->dean($user, $base);
        }

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
                [$queue, $types, $filters] = $this->toGrade($sections, $termId, $area, ['tab' => 'to-grade']);
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

    /**
     * The chairperson's page. My sections shows only when they teach a section of what they chair (or when
     * Program is not theirs, so the page always has a tab); Program needs program.monitor. Without a tab in the
     * address the page opens on My sections when there is one, else on Program.
     *
     * Program's table is one page of the term's sections, searched, filtered (not finalized only) and sorted from
     * the address; the tracker counts the whole term. The grid below it is the chosen section's (?section=, else
     * the page's first row), drawn by the page script; rendering never opens the roster or creates a scheme. A
     * section outside the open tab's sections is a 404.
     */
    private function chairperson(User $user, string $base): string
    {
        $permissions = new RolePermissions();
        $canProgram  = $permissions->allows($user, 'program.monitor', 'chairperson');

        $requestedSection = $this->number('section');

        if ($requestedSection === false) {
            throw PageNotFoundException::forPageNotFound();
        }

        $reason = (string) $this->request->getGet('reason');
        $reason = in_array($reason, GradesAtRisk::KINDS, true) ? $reason : null;

        $overview  = new ChairpersonProgramOverview();
        $loadError = false;
        $terms     = [];
        $termId    = null;
        $tabs      = $canProgram ? ['program'] : ['mine'];
        $tab       = $tabs[0];
        $sub       = self::CHAIR_SUBS[$tab][0];
        $sections  = []; // My sections: the workbench rows; Program: every section of the term
        $risk      = [];
        $queue     = [];
        $types     = [];
        $filters   = ['qsection' => null, 'type' => null, 'late' => false];
        $program   = ['rows' => [], 'total' => 0, 'tracker' => ['finalized' => 0, 'ready' => 0, 'blocked' => 0, 'total' => 0]];
        $termEnd   = null;

        $state = $this->tableState(ChairpersonProgramOverview::SORTS);

        try {
            $terms  = $overview->terms($user);
            $termId = $overview->defaultTermId($terms, $this->number('term') ?: null);
            $mine   = $termId === null ? [] : $overview->mySections($user, $termId);

            $tabs = array_keys(array_filter(['mine' => $mine !== [] || ! $canProgram, 'program' => $canProgram]));
            $tab  = (string) $this->request->getGet('tab');
            $tab  = in_array($tab, $tabs, true) ? $tab : $tabs[0];
            $sub  = (string) $this->request->getGet('sub');
            $sub  = in_array($sub, self::CHAIR_SUBS[$tab], true) ? $sub : self::CHAIR_SUBS[$tab][0];

            if ($tab === 'mine') {
                $sections = $mine;
                $risk     = (new GradesAtRisk())->forSections($sections, null);

                if ($sub === 'to-grade') {
                    [$queue, $types, $filters] = $this->toGrade($sections, $termId, 'chairperson', ['tab' => 'mine', 'sub' => 'to-grade']);
                }
            } elseif ($termId !== null) {
                $sections = $overview->all($user, $termId);
                $risk     = (new GradesAtRisk())->forSections($sections, null);
                $program  = $overview->pageOf($sections, [
                    'q'            => $state['q'],
                    'notFinalized' => $state['nf'],
                    'sort'         => $state['sort'],
                    'dir'          => $state['dir'],
                    'page'         => $state['page'],
                ]);

                // A page past the end shows the last one (the service's rule); the links follow what is shown.
                $state['page'] = min($state['page'], max(1, (int) ceil($program['total'] / ChairpersonProgramOverview::PER_PAGE)));

                $program['rows'] = $this->forNudge($program['rows'], (int) $user->id);
                $termEnd         = $overview->termEnd($termId);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Grades failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $byId = array_column($sections, null, 'offering_id');

        // A section outside the open tab's sections (another program's, or one they do not teach) is a 404.
        if (! $loadError && $requestedSection !== null && ! isset($byId[$requestedSection])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $instructor = new InstructorGradesOverview();

        if ($tab === 'mine') {
            $summary  = $instructor->summary($sections);
            $selected = $byId[$instructor->defaultSectionId($sections, $requestedSection)] ?? null;
            $counts   = ['to-grade' => $summary['waiting'], 'at-risk' => count($risk)];
        } else {
            $summary  = null;
            $selected = $requestedSection !== null ? ($byId[$requestedSection] ?? null) : ($program['rows'][0] ?? null);

            // The action bar carries what Nudge needs for the shown section, also when its row is on another page
            // or filtered out of this one.
            if ($selected !== null) {
                $onPage   = array_column($program['rows'], null, 'offering_id');
                $selected = $onPage[$selected['offering_id']] ?? $this->forNudge([$selected], (int) $user->id)[0];
            }
            $counts   = ['sections' => $program['tracker']['total'], 'at-risk' => count($risk)];
        }

        // The At risk count is everyone, whatever reason is picked; the list keeps only that reason.
        $atRisk = $reason === null ? $risk : array_values(array_filter(
            $risk,
            static fn (array $row): bool => in_array($reason, array_column($row['reasons'], 'kind'), true),
        ));

        return view('pages/grades/index', [
            'title'        => 'Grades',
            'page'         => 'grades',
            'user'         => $user,
            'area'         => 'chairperson',
            'base'         => $base,
            'chairTabs'    => $tabs,
            'tab'          => $tab,
            'sub'          => $sub,
            'terms'        => $terms,
            'termId'       => $termId,
            'sections'     => $tab === 'mine' ? $sections : [],
            'program'      => $program,
            'state'        => $state,
            'termEnd'      => $termEnd,
            'selected'     => $selected,
            'summary'      => $summary,
            'counts'       => $counts,
            'atRisk'       => $atRisk,
            'reason'       => $reason,
            'queue'        => $queue,
            'types'        => $types,
            'filters'      => $filters,
            'canManage'    => $permissions->allows($user, 'grading.manage', 'chairperson'),
            'canNudge'     => $permissions->allows($user, 'followups.manage', 'chairperson'),
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);
    }

    /**
     * The dean's page: the sections of their department(s) in a term, of every program or the one picked (?program=,
     * one of the department's, else a 404). The table is one page, searched, filtered and sorted from the address;
     * the strip counts every section of the choice. The grid below is the chosen section's (?section=, else the
     * page's first row), through the dean's read-only course API and only with courses.view, which that API asks;
     * it is never editable here. A section outside the choice is a 404. department.monitor is the route's filter.
     */
    private function dean(User $user, string $base): string
    {
        $permissions = new RolePermissions();

        $requestedSection = $this->number('section');
        $requestedProgram = $this->number('program');

        if ($requestedSection === false || $requestedProgram === false) {
            throw PageNotFoundException::forPageNotFound();
        }

        $reason = (string) $this->request->getGet('reason');
        $reason = in_array($reason, GradesAtRisk::KINDS, true) ? $reason : null;

        $sub = (string) $this->request->getGet('sub');
        $sub = in_array($sub, self::DEAN_SUBS, true) ? $sub : self::DEAN_SUBS[0];

        $overview   = new DeanDepartmentOverview();
        $loadError  = false;
        $programs   = [];
        $terms      = [];
        $termId     = null;
        $sections   = []; // every section of the term and the program choice
        $risk       = [];
        $department = ['rows' => [], 'total' => 0, 'strip' => ['finalized' => 0, 'signed_off' => 0, 'total' => 0, 'requests_waiting' => 0]];
        $termEnd    = null;
        $state      = $this->tableState(DeanDepartmentOverview::SORTS);

        try {
            $programs = $overview->programs($user);

            // A program that is not the department's is no page (thrown below, outside this catch).
            if ($requestedProgram === null || in_array($requestedProgram, array_column($programs, 'id'), true)) {
                $terms  = $overview->terms($user);
                $termId = $overview->defaultTermId($terms, $this->number('term') ?: null);
            }

            if ($termId !== null) {
                $sections   = $overview->all($user, $termId, $requestedProgram);
                $risk       = (new GradesAtRisk())->forSections($sections, null);
                $department = $overview->pageOf($sections, [
                    'q'            => $state['q'],
                    'notFinalized' => $state['nf'],
                    'sort'         => $state['sort'],
                    'dir'          => $state['dir'],
                    'page'         => $state['page'],
                ]);

                // A page past the end shows the last one (the service's rule); the links follow what is shown.
                $state['page'] = min($state['page'], max(1, (int) ceil($department['total'] / ChairpersonProgramOverview::PER_PAGE)));

                $department['rows'] = $this->forNudge($department['rows'], (int) $user->id);
                $termEnd            = $overview->termEnd($termId);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Grades failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $byId = array_column($sections, null, 'offering_id');

        // Another department's program, or a section outside the choice, is a 404.
        if (! $loadError && (
            ($requestedProgram !== null && ! in_array($requestedProgram, array_column($programs, 'id'), true))
            || ($requestedSection !== null && ! isset($byId[$requestedSection]))
        )) {
            throw PageNotFoundException::forPageNotFound();
        }

        $selected = $requestedSection !== null ? ($byId[$requestedSection] ?? null) : ($department['rows'][0] ?? null);

        // As on the chairperson's Program tab: the action bar carries what Nudge needs, wherever the row is.
        if ($selected !== null) {
            $onPage   = array_column($department['rows'], null, 'offering_id');
            $selected = $onPage[$selected['offering_id']] ?? $this->forNudge([$selected], (int) $user->id)[0];
        }

        // The At risk count is everyone, whatever reason is picked; the list keeps only that reason.
        $atRisk = $reason === null ? $risk : array_values(array_filter(
            $risk,
            static fn (array $row): bool => in_array($reason, array_column($row['reasons'], 'kind'), true),
        ));

        // The strip's counts link to the pages where those decisions are made, only for a dean who may open them.
        $stripLinks = [
            'signed_off' => $permissions->allows($user, 'grades.signoff', 'dean') ? site_url('dean/signoff') . ($termId === null ? '' : '?term=' . $termId) : null,
            'requests'   => $permissions->allows($user, 'grades.approve', 'dean') ? site_url('dean/approvals') . ($requestedProgram === null ? '' : '?program=' . $requestedProgram) : null,
        ];

        return view('pages/grades/index', [
            'title'        => 'Grades',
            'page'         => 'grades',
            'user'         => $user,
            'area'         => 'dean',
            'base'         => $base,
            'chairTabs'    => ['department'],
            'tab'          => 'department',
            'sub'          => $sub,
            'terms'        => $terms,
            'termId'       => $termId,
            'programs'     => $programs,
            'programId'    => $requestedProgram,
            'sections'     => [],
            'program'      => $department,
            'state'        => $state,
            'termEnd'      => $termEnd,
            'selected'     => $selected,
            'summary'      => null,
            'counts'       => ['sections' => $department['strip']['total'], 'at-risk' => count($risk)],
            'atRisk'       => $atRisk,
            'reason'       => $reason,
            'queue'        => [],
            'types'        => [],
            'filters'      => ['qsection' => null, 'type' => null, 'late' => false],
            'canManage'    => false, // the dean never edits here, whatever else they may do
            'canNudge'     => $permissions->allows($user, 'followups.manage', 'dean'),
            'canOpen'      => $permissions->allows($user, 'courses.view', 'dean'),
            'stripLinks'   => $stripLinks,
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);
    }

    /**
     * The section table's choices, as the address gives them; anything else is the default.
     *
     * @param list<string> $sorts the columns the table sorts by
     *
     * @return array{q: string, nf: bool, sort: ?string, dir: ?string, page: int}
     */
    private function tableState(array $sorts): array
    {
        $sort = (string) $this->request->getGet('sort');
        $dir  = (string) $this->request->getGet('dir');
        $page = $this->number('page');

        return [
            'q'    => mb_substr(trim((string) $this->request->getGet('q')), 0, 100),
            'nf'   => $this->request->getGet('nf') === '1',
            'sort' => in_array($sort, $sorts, true) ? $sort : null,
            'dir'  => in_array($dir, ['asc', 'desc'], true) ? $dir : null,
            'page' => is_int($page) && $page > 0 ? $page : 1,
        ];
    }

    /**
     * Each row with what Nudge words its message from and sends to: oldest_days (withOldestWaiting) and recipients,
     * the section's instructors less the chairperson themselves (the Instructor column still names everyone).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function forNudge(array $rows, int $userId): array
    {
        $rows = $this->withOldestWaiting($rows);

        foreach ($rows as $i => $row) {
            $rows[$i]['recipients'] = array_values(array_filter($row['instructors'], static fn (array $person): bool => (int) $person['id'] !== $userId));
        }

        return $rows;
    }

    /**
     * Each row with oldest_days: whole days since its oldest submission still waiting to be graded (null when none
     * is waiting), for the nudge's wording. One PendingGrading call for every row together.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withOldestWaiting(array $rows): array
    {
        $waiting = array_column(array_filter($rows, static fn (array $row): bool => $row['waiting'] > 0), 'offering_id');
        $oldest  = [];

        foreach ((new PendingGrading())->forOfferings($waiting)['assessments'] as $assessment) {
            if ($assessment['oldest_at'] !== null) {
                $oldest[$assessment['offering_id']] = min($oldest[$assessment['offering_id']] ?? PHP_INT_MAX, $assessment['oldest_at']);
            }
        }

        $now = time();

        foreach ($rows as $i => $row) {
            $rows[$i]['oldest_days'] = isset($oldest[$row['offering_id']]) ? max(0, intdiv($now - $oldest[$row['offering_id']], 86400)) : null;
        }

        return $rows;
    }

    /** The area this request is in: its first address segment, one of those that have the page. */
    private function area(): string
    {
        $area = $this->request->getUri()->getSegment(1);

        return in_array($area, ['instructor', 'student', 'chairperson', 'dean'], true) ? $area : 'instructor';
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

                // Every row is remembered as shown, so a grade published since the last look is a change too.
                foreach ($rows as $row) {
                    $signatures[$row['offering_id']] = GradesSeen::shownSignature($row);
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

            // What they have now seen no longer counts on their Grades tile: the next page counts again rather
            // than show the cached number for up to a minute. Nothing was remembered while viewing as them.
            if (! Impersonation::active()) {
                GradesBadge::forget((int) $viewer->id, $links['area']);
            }
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
     * @param array<string, string>      $list     the address parameters that name the queue on this page
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: array{qsection: ?int, type: ?string, late: bool}}
     */
    private function toGrade(array $sections, ?int $termId, string $area, array $list): array
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

        $back = (string) parse_url(site_url($area . '/grades'), PHP_URL_PATH) . '?' . http_build_query(array_filter($list + [
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

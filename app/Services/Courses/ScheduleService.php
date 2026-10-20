<?php

namespace App\Services\Courses;

use App\Models\Courses\CourseScheduleModel;

use CodeIgniter\Shield\Entities\User;

/**
 * A person's own weekly timetable — every class they're enrolled in
 * (student) or teach (instructor), across every course. Own page, not a
 * course tab, the same reasoning as the Calendar: a schedule is personal,
 * not scoped to one offering.
 *
 * Rendered as a static weekly grid (day columns x time rows) — nothing on
 * this page fetches or switches views the way the Calendar feature does;
 * it's one fixed picture of a person's week, built once per request.
 *
 * The grid math lives here rather than in the view: each class gets a
 * `row_start`/`row_span` in half-hour units relative to the week's own
 * busiest span (earliest start to latest end, rounded to the hour), so the
 * view only has to place a div at `grid-row: {row_start} / span {row_span}`
 * — no time arithmetic in PHP-in-HTML.
 */
class ScheduleService
{
    protected const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected const DAY_LABELS = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    /** Grid row unit, in minutes. Every seeded class lands on the hour or half-hour. */
    protected const ROW_MINUTES = 30;

    protected CourseService $courseService;

    protected CourseScheduleModel $courseScheduleModel;

    public function __construct()
    {
        $this->courseService = new CourseService();
        $this->courseScheduleModel = new CourseScheduleModel();
    }

    /** Everything the view needs — the controller renders it, same split as CalendarController. */
    public function forUser(User $user): array
    {
        $active = (new \App\Services\Auth\ActiveRoleService())->resolve($user);

        $role = match (true) {
            in_array($active, ['instructor', 'chairperson'], true) => $active,
            $user->inGroup('instructor')  => 'instructor',
            $user->inGroup('chairperson') => 'chairperson',
            default                       => 'student',
        };

        $courses = $this->courseService->getCourseOfferings($user);

        $today = strtolower(date('D')); // 'Mon' -> 'mon', matches day_of_week exactly

        $classes = $this->collectClasses($role, $courses);

        $grid = $this->buildGrid($classes);

        return [
            'user'      => $user,
            'role'      => $role,
            'today'     => $today,
            'todayLabel' => self::DAY_LABELS[$today] . ', ' . date('F j'),
            'days'      => self::DAYS,
            'dayLabels' => self::DAY_LABELS,
            'grid'      => $grid,
            'hasAnyCourses' => $courses !== [],
            'hasAnyClasses' => $classes !== [],
        ];
    }

    /**
     * Every meeting block this person has, flat — day, time and which
     * course/section it belongs to. Grid placement is computed afterwards,
     * once the full list's time range is known.
     *
     * @return list<array>
     */
    protected function collectClasses(string $role, array $courses): array
    {
        $offeringIds = array_column($courses, 'offering_id');

        if ($offeringIds === []) {
            return [];
        }

        $labels = [];

        foreach ($courses as $course) {

            $labels[(int) $course['offering_id']] = [
                'course_code'  => $course['course_code'],
                'course_title' => $course['course_title'],
                'section_code' => $course['section_code'],
                'slug'         => url_title((string) $course['course_title'], '-', true),
            ];

        }

        $classes = [];

        foreach ($this->courseScheduleModel->retrieveForOfferings($offeringIds) as $row) {

            $day = $row['day_of_week'];

            if (! in_array($day, self::DAYS, true)) {
                continue; // an unknown day_of_week value shouldn't crash the page
            }

            $offeringId = (int) $row['offering_id'];

            $label = $labels[$offeringId] ?? null;

            if ($label === null) {
                continue; // a block for an offering not in $courses isn't this person's to see
            }

            $classes[] = [
                'day'          => $day,
                'offering_id'  => $offeringId,
                'course_code'  => $label['course_code'],
                'course_title' => $label['course_title'],
                'section_code' => $label['section_code'],
                'url'          => site_url($role . '/courses/' . $offeringId . '/' . $label['slug']),
                'start_minutes' => $this->toMinutes($row['start_time']),
                'end_minutes'   => $this->toMinutes($row['end_time']),
                'time'         => date('g:i A', strtotime($row['start_time']))
                    . ' – ' . date('g:i A', strtotime($row['end_time'])),
                'room'         => (string) ($row['room'] ?? ''),
                'mode'         => match ($row['delivery_mode']) {
                    'online' => 'Online',
                    'hybrid' => 'Hybrid',
                    default  => 'On-site',
                },
            ];

        }

        return $classes;
    }

    /**
     * The grid itself: a shared time axis (earliest start to latest end,
     * rounded out to the hour) in ROW_MINUTES-sized rows, the hour labels
     * to run down the side of it, and every class positioned on it with a
     * day column (1-7, Monday first) and a row_start/row_span pair.
     *
     * @return array{total_rows: int, hour_marks: list<array{row: int, label: string}>, by_day: array<string, list<array>>}
     */
    protected function buildGrid(array $classes): array
    {
        if ($classes === []) {
            return ['total_rows' => 0, 'hour_marks' => [], 'by_day' => array_fill_keys(self::DAYS, [])];
        }

        $startMinutes = (int) floor(min(array_column($classes, 'start_minutes')) / 60) * 60;

        $endMinutes = (int) ceil(max(array_column($classes, 'end_minutes')) / 60) * 60;

        $totalRows = (int) (($endMinutes - $startMinutes) / self::ROW_MINUTES);

        $hourMarks = [];

        for ($minute = $startMinutes; $minute < $endMinutes; $minute += 60) {

            $hourMarks[] = [
                'row'   => (int) (($minute - $startMinutes) / self::ROW_MINUTES) + 1,
                'label' => date('g A', strtotime('00:00:00') + $minute * 60),
            ];

        }

        $byDay = array_fill_keys(self::DAYS, []);

        foreach ($classes as $class) {

            $class['column'] = array_search($class['day'], self::DAYS, true) + 1;

            $class['row_start'] = (int) (($class['start_minutes'] - $startMinutes) / self::ROW_MINUTES) + 1;

            $class['row_span'] = max(1, (int) (($class['end_minutes'] - $class['start_minutes']) / self::ROW_MINUTES));

            $byDay[$class['day']][] = $class;

        }

        return ['total_rows' => $totalRows, 'hour_marks' => $hourMarks, 'by_day' => $byDay];
    }

    /** "09:30:00" -> 570 (minutes since midnight). */
    protected function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}

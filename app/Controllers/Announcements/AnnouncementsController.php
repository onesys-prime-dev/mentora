<?php

declare(strict_types=1);

namespace App\Controllers\Announcements;

use App\Controllers\BaseController;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Courses\AnnouncementModel;
use App\Models\Courses\EnrollmentModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Courses\ChairpersonAccessService;
use App\Services\Courses\DepartmentAnnouncementService;
use App\Services\Institutions\InstitutionAnnouncement;

/**
 * The Announcements app: every announcement that reaches this person, at four levels and in this order:
 * the platform's, the institution's, their department's and their courses'. A student, an instructor and a
 * chairperson each have it under their own area. The first two are one line each (the same text the banner
 * above every page carries); the department's are the dean's posts that reach the person through their
 * program, sections or sub-departments; the course's are the latest posts in the courses they are in. A
 * level with nothing to say is shown as such rather than left out, so the order is always the same.
 */
class AnnouncementsController extends BaseController
{
    private const COURSE_LIMIT = 30;

    public function index(): string
    {
        $user = auth()->user();
        $area = $this->area();

        $loadError  = false;
        $platform   = '';
        $institution = null;
        $department = [];
        $courses    = [];

        try {
            $platform    = trim((string) (new PlatformSettings())->get('announcement'));
            $institution = (new InstitutionAnnouncement())->forUser($user);
            $department  = (new DepartmentAnnouncementService())->forUser($user);
            $courses     = $this->courseAnnouncements($user, $area);
        } catch (\Throwable $e) {
            log_message('error', 'Announcements failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        // ?q= keeps only what mentions every word typed, in any of the levels.
        $query = mb_substr(trim((string) $this->request->getGet('q')), 0, 100);

        if ($query !== '' && ! $loadError) {
            $has         = static function (array $parts) use ($query): bool {
                $text = mb_strtolower(implode(' ', array_map('strval', $parts)));

                foreach (preg_split('/\s+/', mb_strtolower($query)) as $word) {
                    if (! str_contains($text, $word)) {
                        return false;
                    }
                }

                return true;
            };
            $platform    = $has([$platform]) ? $platform : '';
            $institution = $institution !== null && $has([$institution]) ? $institution : null;
            $department  = array_values(array_filter($department, static fn (array $a): bool => $has([$a['title'], $a['message'], $a['author'], $a['scope_label']])));
            $courses     = array_values(array_filter($courses, static fn (array $c): bool => $has([$c['subject'], $c['code'], $c['author']])));
        }

        // One list, narrowed to a level by ?level=; anything else means all of them.
        $level = (string) $this->request->getGet('level');
        $level = in_array($level, ['platform', 'institution', 'department', 'course'], true) ? $level : 'all';

        return view('pages/announcements/index', [
            'level'        => $level,
            'query'        => $query,
            'title'        => 'Announcements',
            'page'         => 'announcements',
            'user'         => $user,
            'area'         => $area,
            'platform'     => $platform,
            'institution'  => $institution,
            'department'   => $department,
            'courses'      => $courses,
            'loadError'    => $loadError,
            'previousPage' => 'dashboard',
        ]);
    }

    /** The area this request is in: its first address segment, one of the three that have the page. */
    private function area(): string
    {
        $area = $this->request->getUri()->getSegment(1);

        return in_array($area, ['student', 'chairperson', 'instructor'], true) ? $area : 'student';
    }

    /**
     * The latest posts in the courses this person is in, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function courseAnnouncements(\CodeIgniter\Shield\Entities\User $user, string $area): array
    {
        $offerings = match ($area) {
            'student'     => (new EnrollmentModel())->retrieveStudentCourseOfferings((int) $user->id),
            'instructor'  => (new InstructorAssignmentModel())->retrieveInstructorSections((int) $user->id),
            default       => (new ChairpersonAccessService())->offeringsFor($user),
        };

        $by = [];

        foreach ($offerings as $offering) {
            $by[(int) $offering['offering_id']] = $offering;
        }

        if ($by === []) {
            return [];
        }

        $out = [];

        foreach ((new AnnouncementModel())->retrieveForOfferings(array_keys($by), self::COURSE_LIMIT) as $row) {
            $offering = $by[(int) $row['offering_id']] ?? null;

            if ($offering === null) {
                continue;
            }

            $out[] = [
                'id'         => (int) $row['id'],
                'subject'    => (string) $row['subject'],
                'author'     => trim((string) $row['author_name']) !== '' ? trim((string) $row['author_name']) : 'Instructor',
                'created_at' => (string) $row['created_at'],
                'code'       => trim($offering['course_code'] . ' ' . $offering['section_code']),
                'url'        => site_url("{$area}/courses/" . (int) $row['offering_id'] . '/' . url_title((string) $offering['course_title'], '-', true) . '/announcements'),
            ];
        }

        return $out;
    }
}

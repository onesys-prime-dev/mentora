<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\ClassSectionService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's block sections (BSIT 1-A), their courses
 * and their students. The routes decide who may (offerings.manage for the
 * section and its courses, enrolments.manage for its students, and not while
 * the institution is read-only); the rules and the audit entries live in
 * ClassSectionService.
 */
class SectionsController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/sections';

    private ClassSectionService $sections;

    public function __construct()
    {
        $this->sections = new ClassSectionService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $filters = [
            'q'       => (string) $this->request->getGet('q'),
            'term'    => (int) $this->request->getGet('term'),
            'program' => (int) $this->request->getGet('program'),
            'status'  => (string) $this->request->getGet('status'),
            'page'    => (int) $this->request->getGet('page'),
        ];

        $result = $this->sections->list($institutionId, $filters);

        return view('pages/institution/sections', [
            'title'          => 'Sections',
            'page'           => 'sections',
            'pageTitle'      => 'Sections',
            'user'           => auth()->user(),
            'sectionRows'    => $result['rows'],
            'sectionTotal'   => $result['total'],
            'sectionPages'   => $result['pages'],
            'sectionPage'    => $result['page'],
            'sectionFilters' => $filters,
            'sectionOptions' => $this->sections->options($institutionId),
            'sectionCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function show(int $id): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $detail        = $this->sections->detail($institutionId, $id);

        return view('pages/institution/section', [
            'title'          => $detail['section']['code'],
            'page'           => 'sections',
            'pageTitle'      => $detail['section']['code'],
            'user'           => auth()->user(),
            'section'        => $detail['section'],
            'sectionCourses' => $detail['courses'],
            'sectionStudents' => $detail['students'],
            'sectionAvailable' => $detail['available'],
            'sectionCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function create(): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst): int => $this->sections->create($inst, $this->input(), auth()->user()),
            'The section was added. Now give it its courses and students.',
            self::BACK,
            // Straight to the new section, where its courses and students are added.
            static fn (mixed $id): string => 'institution/sections/' . (int) $id,
        );
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->sections->update($inst, $id, $this->input(), auth()->user()), 'The section was saved.', $this->sectionUrl($id));
    }

    public function archive(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->sections->setArchived($inst, $id, true, auth()->user()), 'The section was archived.', self::BACK);
    }

    public function reactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->sections->setArchived($inst, $id, false, auth()->user()), 'The section is active again.', $this->sectionUrl($id));
    }

    public function addCourse(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => $this->sections->addCourse($inst, $id, (int) ($this->input()['course_id'] ?? 0), auth()->user()),
            static function (mixed $result): string {
                $enrolled = (int) ($result['enrolled'] ?? 0);

                return 'The course was added to the section' . ($enrolled > 0 ? ($enrolled === 1 ? ' and 1 student was enrolled in it.' : " and {$enrolled} students were enrolled in it.") : '.');
            },
            $this->sectionUrl($id),
        );
    }

    public function removeCourse(int $id, int $offeringId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->sections->removeCourse($inst, $id, $offeringId, auth()->user()), 'The course was taken out of the section.', $this->sectionUrl($id));
    }

    public function addStudents(int $id): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(
            fn (int $inst) => $this->sections->addStudents($inst, $id, (string) ($input['students'] ?? ''), (string) ($input['over_capacity'] ?? '') === '1', auth()->user()),
            static function (mixed $result): string {
                $added = (int) ($result['added'] ?? 0);

                return ($added === 1 ? '1 student was added to the section and enrolled in its courses.' : "{$added} students were added to the section and enrolled in its courses.");
            },
            $this->sectionUrl($id),
        );
    }

    public function removeStudent(int $id, int $studentId): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => $this->sections->removeStudent($inst, $id, $studentId, auth()->user()),
            static function (mixed $result): string {
                $dropped = (int) ($result['dropped'] ?? 0);

                return 'The student was taken out of the section' . ($dropped > 0 ? ($dropped === 1 ? ' and 1 place was dropped.' : " and {$dropped} places were dropped.") : '.');
            },
            $this->sectionUrl($id),
        );
    }

    private function sectionUrl(int $id): string
    {
        return 'institution/sections/' . $id;
    }
}

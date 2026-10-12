<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's roster of an offering. The routes decide
 * who may (enrolments.manage, and not while the institution is read-only);
 * the rules and the audit entries live in EnrolmentService.
 */
class EnrolmentsController extends BaseController
{
    use AnswersDialogForms;

    private EnrolmentService $enrolments;

    public function __construct()
    {
        $this->enrolments = new EnrolmentService();
    }

    public function roster(int $offeringId): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $filters = [
            'q'      => (string) $this->request->getGet('q'),
            'status' => (string) $this->request->getGet('status'),
            'sort'   => (string) $this->request->getGet('sort'),
            'dir'    => (string) $this->request->getGet('dir'),
            'page'   => (int) $this->request->getGet('page'),
        ];

        $result = $this->enrolments->roster($institutionId, $offeringId, $filters);

        return view('pages/institution/roster', [
            'title'         => 'Roster',
            'page'          => 'offerings',
            'pageTitle'     => 'Roster',
            'user'          => auth()->user(),
            'rosterOffering' => $result['offering'],
            'rosterRows'    => $result['rows'],
            'rosterTotal'   => $result['total'],
            'rosterPages'   => $result['pages'],
            'rosterPage'    => $result['page'],
            'rosterCounts'  => $result['counts'],
            'rosterLocked'  => $result['courseLocked'],
            'rosterFilters' => $filters,
            'rosterCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function add(int $offeringId): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(
            fn (int $inst) => $this->enrolments->add($inst, $offeringId, (string) ($input['students'] ?? ''), $this->overCapacity(), auth()->user()),
            static function (mixed $result): string {
                $added      = (int) ($result['added'] ?? 0);
                $reinstated = (int) ($result['reinstated'] ?? 0);
                $parts      = [];

                if ($added > 0) {
                    $parts[] = $added === 1 ? '1 student was enrolled' : "{$added} students were enrolled";
                }

                if ($reinstated > 0) {
                    $parts[] = $reinstated === 1 ? '1 earlier place was reinstated' : "{$reinstated} earlier places were reinstated";
                }

                return implode(' and ', $parts) . '.';
            },
            $this->rosterUrl($offeringId),
        );
    }

    public function setStatus(int $enrollmentId): ResponseInterface|RedirectResponse
    {
        $offeringId = (int) db_connect()->table('enrollments')->select('offering_id')->where('id', $enrollmentId)->get()->getRow('offering_id');
        $status     = (string) ($this->input()['status'] ?? '');

        return $this->run(
            fn (int $inst) => $this->enrolments->setStatus($inst, $enrollmentId, $status, $this->overCapacity(), auth()->user()),
            'The enrolment was changed to ' . $status . '.',
            $offeringId > 0 ? $this->rosterUrl($offeringId) : 'institution/offerings',
        );
    }

    private function overCapacity(): bool
    {
        return (string) ($this->input()['over_capacity'] ?? '') === '1';
    }

    private function rosterUrl(int $offeringId): string
    {
        return 'institution/enrolments/offering/' . $offeringId;
    }
}

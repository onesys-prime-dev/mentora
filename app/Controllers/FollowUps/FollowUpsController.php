<?php

declare(strict_types=1);

namespace App\Controllers\FollowUps;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\FollowUpRules;
use App\Services\Courses\FollowUpService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Follow-up requests for the three roles that have them: the dean (the requests they sent), the
 * chairperson (the ones they received from the dean and the ones they sent to instructors) and the
 * instructor (the ones they received). One controller, because the page is one page: each area's
 * routes sit in that role's own route group, and the area is its first address segment. A new request
 * starts from a course (the "New follow-up" button asks which), or from an attention item that links
 * here with the course, student and reason filled in. The actions answer JSON for the dialog that
 * sends them, and redirect with a message without JavaScript. Who may do what is the service's check.
 */
class FollowUpsController extends BaseController
{
    /** The boxes each area has, the first being the one it opens on. */
    private const BOXES = [
        'dean'        => ['sent'],
        'chairperson' => ['received', 'sent'],
        'instructor'  => ['received'],
    ];

    public function index(): string
    {
        $user    = auth()->user();
        $area    = $this->area();
        $service = new FollowUpService();

        if ($area === 'dean' && (new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Follow-ups', 'page' => 'follow-ups', 'user' => $user]);
        }

        $boxes = self::BOXES[$area];
        $box   = in_array($this->request->getGet('box'), $boxes, true) ? (string) $this->request->getGet('box') : $boxes[0];
        $tab   = in_array($this->request->getGet('tab'), FollowUpService::TABS, true) ? (string) $this->request->getGet('tab') : 'open';

        $loadError  = false;
        $list       = ['rows' => [], 'counts' => ['open' => 0, 'overdue' => 0, 'done' => 0]];
        $offerings  = [];
        $recipients = [];
        $prefill    = null;
        $passTo     = [];
        $received   = 0;

        try {
            $list     = $service->listFor($user, $box, $tab);
            $received = count($boxes) > 1 ? $service->listFor($user, 'received')['counts']['open'] : 0;

            if ($area !== 'instructor') {
                $offerings = $service->offeringsFor($user);
                $prefill   = $this->prefill($service, $offerings);
                $recipients = $prefill === null ? [] : $service->recipientsFor($user, $prefill['offering']);
            }

            // A chairperson passes a dean's request down to one of the course's instructors.
            if ($area === 'chairperson' && $box === 'received') {
                foreach ($list['rows'] as $row) {
                    if ($row['status'] === 'open' && $row['sender_role'] === FollowUpRules::DEAN && $row['passed_to'] === null) {
                        $passTo[(int) $row['id']] = array_values(array_filter(
                            $service->recipientsFor($user, (int) $row['offering_id']),
                            static fn (array $person): bool => $person['role'] === FollowUpRules::INSTRUCTOR
                        ));
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Follow-ups failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/follow-ups/index', [
            'title'        => $area === 'instructor' ? 'Requests' : 'Follow-ups',
            'page'         => 'follow-ups',
            'user'         => $user,
            'area'         => $area,
            'boxes'        => $boxes,
            'box'          => $box,
            'tab'          => $tab,
            'list'         => $list,
            'received'     => $received,
            'offerings'    => $offerings,
            'recipients'   => $recipients,
            'prefill'      => $prefill,
            'passTo'       => $passTo,
            'canSend'      => $area !== 'instructor',
            'loadError'    => $loadError,
            'flashToasts'  => true,
            'previousPage' => 'dashboard',
        ]);
    }

    public function store(): ResponseInterface|RedirectResponse
    {
        $result = (new FollowUpService())->send(auth()->user(), (array) $this->request->getPost());

        return $this->answer($result, 'message', $this->area() === 'chairperson' ? 'sent' : null);
    }

    public function reply(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer((new FollowUpService())->reply(auth()->user(), $id, (string) $this->request->getPost('body')), 'body');
    }

    public function done(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer((new FollowUpService())->markDone(auth()->user(), $id), 'done');
    }

    public function passDown(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer((new FollowUpService())->passDown(auth()->user(), $id, (int) $this->request->getPost('instructor_id'), (string) $this->request->getPost('note')), 'instructor_id');
    }

    /** The area this request is in: its first address segment, one of the three that have follow-ups. */
    private function area(): string
    {
        $area = $this->request->getUri()->getSegment(1);

        return isset(self::BOXES[$area]) ? $area : 'instructor';
    }

    /**
     * What the "new follow-up" form starts with, from the address: the course, and for an attention item the
     * student and the reason. Nothing when no course is chosen or it is not one the sender can ask about.
     *
     * @param list<array{id: int, label: string}> $offerings
     *
     * @return array{offering: int, label: string, student: int, student_name: string, reason: string, title: string}|null
     */
    private function prefill(FollowUpService $service, array $offerings): ?array
    {
        $offering = (int) $this->request->getGet('offering');
        $label    = null;

        foreach ($offerings as $candidate) {
            if ($candidate['id'] === $offering) {
                $label = $candidate['label'];
            }
        }

        if ($label === null) {
            return null;
        }

        $reason  = (string) $this->request->getGet('reason');
        $reason  = array_key_exists($reason, FollowUpRules::REASONS) ? $reason : 'other';
        $student = (int) $this->request->getGet('student');
        $name    = '';

        if ($student > 0) {
            $row = db_connect()->table('enrollments e')
                ->select("TRIM(CONCAT_WS(' ', up.first_name, up.last_name)) AS name", false)
                ->join('user_profiles up', 'up.user_id = e.student_user_id', 'left')
                ->where(['e.offering_id' => $offering, 'e.student_user_id' => $student])
                ->get()->getRowArray();

            // A student who is not in the course is simply not part of the request.
            $student = $row === null ? 0 : $student;
            $name    = (string) ($row['name'] ?? '');
        }

        $short = trim(explode('·', $label)[0]);

        return [
            'offering'     => $offering,
            'label'        => $label,
            'student'      => $student,
            'student_name' => $name,
            'reason'       => $reason,
            'title'        => $reason === 'other' ? '' : FollowUpRules::REASONS[$reason] . ': ' . ($name !== '' ? $name . ', ' : '') . $short,
        ];
    }

    /**
     * @param array{success: bool, message: string} $result
     */
    private function answer(array $result, string $field, ?string $box = null): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url($this->area() . '/follow-ups') . ($box !== null ? '?box=' . $box : '');

        if (! $result['success']) {
            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => [$field => $result['message']], 'message' => $result['message']])
                : redirect()->to($back)->with('error', $result['message']);
        }

        return $wantsJson
            ? $this->response->setJSON(['message' => $result['message'], 'redirect' => $back])
            : redirect()->to($back)->with('message', $result['message']);
    }
}

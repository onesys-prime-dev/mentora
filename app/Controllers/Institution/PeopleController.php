<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\PeopleService;
use App\Services\Institutions\StructureException;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\UserExport;
use App\Services\Users\UserFilters;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's people. The routes decide who may
 * (people.view / people.manage, the password for the dangerous ones, and not
 * while the institution is read-only); the rules and the audit entries live
 * in PeopleService and the services behind it.
 */
class PeopleController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/people';

    /** The most rows one export holds. */
    private const EXPORT_MAX = 10000;

    private PeopleService $people;

    public function __construct()
    {
        $this->people = new PeopleService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $view          = (string) $this->request->getGet('view') === 'invitations' ? 'invitations' : 'people';

        $result = $this->people->list($institutionId, $this->request->getGet() ?: [], (int) auth()->id());

        return view('pages/institution/people', [
            'title'              => 'People',
            'page'               => 'people',
            'pageTitle'          => 'People',
            'user'               => auth()->user(),
            'reauthDialog'       => true,
            'peopleView'         => $view,
            'peopleRows'         => $result['rows'],
            'peopleTotal'        => $result['total'],
            'peoplePages'        => $result['pages'],
            'peopleFilters'      => $result['filters'],
            'peopleInvitations'  => $this->people->invitations($institutionId),
            'peopleOpen'         => $this->people->openInvitations($institutionId),
            'peopleRoles'        => PeopleService::ROLES,
            'peopleRoleLabels'   => UserFilters::ROLE_LABELS,
            'peopleSeats'        => (new PlanLimits())->report($institutionId)['seats'],
            'peopleCanEdit'      => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'peopleCanViewAs'    => (bool) auth()->user()->can('institution.impersonate'),
        ]);
    }

    public function invite(): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(
            fn (int $inst) => $this->invitationResult($this->people->invite($inst, (string) ($input['role'] ?? ''), $input, auth()->user())),
            static fn (mixed $result): string => 'An invitation was emailed to ' . ($result['email'] ?? 'them') . '. They choose their own password, and the account is made then.',
            self::BACK,
        );
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->people->update($inst, $id, $this->input(), auth()->user()), 'The details were saved.', self::BACK);
    }

    public function deactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->people->deactivate($inst, $id, auth()->user()), 'The account was deactivated. Their sessions ended.', self::BACK);
    }

    public function reactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->people->reactivate($inst, $id, auth()->user()), 'The account is active again.', self::BACK);
    }

    public function signOut(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->people->signOut($inst, $id, auth()->user()), 'They were signed out everywhere.', self::BACK);
    }

    public function resetPassword(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(
            function (int $inst) use ($id): array {
                $result = $this->people->resetPassword($inst, $id, auth()->user());

                return ['email' => $result['email'], 'warnings' => $result['email_error'] === null ? [] : [$result['email_error']]];
            },
            static fn (mixed $result): string => 'A password link was emailed to ' . ($result['email'] ?? 'them') . '.',
            self::BACK,
        );
    }

    public function roles(int $id): ResponseInterface|RedirectResponse
    {
        $input = $this->input();
        $roles = array_values(array_filter(PeopleService::ROLES, static fn (string $role): bool => (string) ($input['role_' . $role] ?? '') === '1'));

        return $this->run(fn (int $inst) => $this->people->setRoles($inst, $id, $roles, auth()->user()), 'The roles were saved.', self::BACK);
    }

    public function bulkDeactivate(): ResponseInterface|RedirectResponse
    {
        return $this->bulk('deactivate');
    }

    public function bulkReactivate(): ResponseInterface|RedirectResponse
    {
        return $this->bulk('reactivate');
    }

    public function resend(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => $this->invitationResult($this->people->resend($inst, $id, auth()->user())),
            static fn (mixed $result): string => 'A new invitation was emailed to ' . ($result['email'] ?? 'them') . '. The earlier link no longer works.',
            self::BACK . '?view=invitations',
        );
    }

    public function revoke(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->people->revoke($inst, $id, auth()->user()), 'The invitation was cancelled. Its link no longer works.', self::BACK . '?view=invitations');
    }

    /** Everyone matching the current filters, as a CSV file. */
    public function export(): ResponseInterface
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $input         = $this->request->getGet() ?: [];

        return $this->csv($institutionId, $this->people->each($institutionId, $input, self::EXPORT_MAX), ['filters' => $this->people->filters($institutionId, $input)->toQuery()]);
    }

    /** Only the people ticked on the list, as a CSV file. */
    public function exportSelected(): ResponseInterface|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $ids           = $this->ids();

        if ($ids === []) {
            return redirect()->to(self::BACK)->with('error', 'Choose at least one person first.');
        }

        return $this->csv($institutionId, $this->people->byIds($institutionId, array_slice($ids, 0, self::EXPORT_MAX)), ['selected' => count($ids)]);
    }

    /**
     * @param 'deactivate'|'reactivate' $action
     */
    private function bulk(string $action): ResponseInterface|RedirectResponse
    {
        $ids  = $this->ids();
        $back = redirect()->to(self::BACK);

        try {
            $result = $this->people->bulk((new InstitutionScope())->requireInstitutionId(), $action, $ids, auth()->user());
        } catch (StructureException $e) {
            return $back->with('error', $e->getMessage());
        }

        $verb    = $action === 'deactivate' ? 'deactivated' : 'reactivated';
        $message = $result['done'] === 1 ? "1 person was {$verb}." : "{$result['done']} people were {$verb}.";

        if ($result['skipped'] === []) {
            return $back->with('message', $message);
        }

        $why = implode(' ', array_map(static fn (array $s): string => $s['name'] . ': ' . $s['reason'], array_slice($result['skipped'], 0, 5)));

        return $back->with($result['done'] > 0 ? 'message' : 'error', trim($message . ' ' . count($result['skipped']) . ' skipped. ' . $why));
    }

    /**
     * @param array{email: string, expires_at: string, email_error: ?string} $result
     *
     * @return array{email: string, warnings: list<string>}
     */
    private function invitationResult(array $result): array
    {
        return ['email' => $result['email'], 'warnings' => $result['email_error'] === null ? [] : [$result['email_error']]];
    }

    /**
     * @param iterable<array<string, mixed>> $people
     * @param array<string, mixed>           $context what the export was of, for the audit entry
     */
    private function csv(int $institutionId, iterable $people, array $context): ResponseInterface
    {
        $export = UserExport::csv($people);

        service('auditLogger')->log('users.exported', auth()->user(), [
            'target_type'    => 'user',
            'institution_id' => $institutionId,
            'new'            => ['rows' => $export['rows']] + $context,
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="people-' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $export['csv']);
    }

    /** @return list<int> */
    private function ids(): array
    {
        $ids = $this->request->getPost('ids');

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))) : [];
    }
}

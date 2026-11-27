<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Controllers\BaseController;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\RestrictionService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;

/**
 * The institutional administrator's reports page: the list, one report with its
 * snapshot, and the decision. Anyone else gets a 404, as if it did not exist.
 * Server-rendered and escaped; the decision is a plain form post.
 */
class ReportsPageController extends BaseController
{
    /** What each button on a report means: status and whether the reported message is also blanked. */
    private const DECISIONS = [
        'dismiss' => ['dismissed', false],
        'action'  => ['actioned', false],
        'remove'  => ['actioned', true],
    ];

    public function index(): string
    {
        $this->adminOnly();

        $status = (string) $this->request->getGet('status');
        $status = in_array($status, ReportReviewService::STATUSES, true) ? $status : 'open';

        return view('pages/messages/reports', [
            'title'   => 'Message reports',
            'page'    => 'messages',
            'user'    => auth()->user(),
            'status'  => $status,
            'cases'   => (new ReportReviewService())->cases(auth()->user(), $status),
            'report'  => null,
        ]);
    }

    public function show(int $reportId): string
    {
        $this->adminOnly();

        $report = (new ReportReviewService())->view(auth()->user(), $reportId) ?? throw PageNotFoundException::forPageNotFound();

        return view('pages/messages/reports', [
            'title'   => 'Message report',
            'page'    => 'messages',
            'user'    => auth()->user(),
            'status'  => $report['status'],
            'cases'   => [],
            'report'  => $report,
        ]);
    }

    public function review(int $reportId): RedirectResponse
    {
        $this->adminOnly();

        $decision = self::DECISIONS[(string) $this->request->getPost('decision')] ?? null;

        $service = new ReportReviewService();

        if ($decision === null || ! $service->review(auth()->user(), $reportId, $decision[0], $decision[1])) {
            // A report of theirs that another administrator already decided is not a missing page.
            if ($decision === null || $service->view(auth()->user(), $reportId) === null) {
                throw PageNotFoundException::forPageNotFound();
            }

            return redirect()->to(site_url('messages/reports'))->with('message', 'That report was already reviewed.');
        }

        return redirect()->to(site_url('messages/reports'))->with('message', 'The report was reviewed.');
    }

    /** Dismisses, or removes the reported messages of, every open report about one person. */
    public function reviewPerson(int $userId): RedirectResponse
    {
        $this->adminOnly();

        $decision = (string) $this->request->getPost('decision');

        if (! in_array($decision, ['dismiss', 'remove'], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Nothing left open (another administrator decided them first) is told, not a missing page.
        if ((new ReportReviewService())->reviewAll(auth()->user(), $userId, $decision) < 1) {
            return redirect()->to(site_url('messages/reports'))->with('message', 'Those reports were already reviewed.');
        }

        return redirect()->to(site_url('messages/reports'))->with('message', 'The reports were reviewed.');
    }

    /** Restricts the reported person (this also ends an automatic pause). */
    public function restrict(int $userId): RedirectResponse
    {
        $this->adminOnly();

        $days = ['1d' => 1, '7d' => 7, '30d' => 30, 'lifted' => null];
        $key  = (string) $this->request->getPost('duration');

        if (! array_key_exists($key, $days) || ! (new RestrictionService())->restrict(auth()->user(), $userId, (string) $this->request->getPost('kind'), $days[$key] === null ? null : new DateTimeImmutable('+' . $days[$key] . ' days'))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return redirect()->to(site_url('messages/reports'))->with('message', 'The restriction was placed.');
    }

    /** Ends the person's administrator restriction early (D7). */
    public function lift(int $userId): RedirectResponse
    {
        $this->adminOnly();

        $service = new RestrictionService();

        if (! $service->administers(auth()->user(), $userId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $lifted = $service->lift(auth()->user(), $userId);

        return redirect()->to(site_url('messages/reports'))->with('message', $lifted ? 'The restriction was lifted.' : 'That restriction was already lifted.');
    }

    private function adminOnly(): void
    {
        if (! in_array('institutional_admin', (new MessagingContextResolver())->roles(auth()->user()), true)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}

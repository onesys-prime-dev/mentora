<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Controllers\BaseController;
use App\Services\Messaging\MessagingContextResolver;
use App\Services\Messaging\ReportReviewService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

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
            'reports' => (new ReportReviewService())->list(auth()->user(), $status),
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
            'reports' => [],
            'report'  => $report,
        ]);
    }

    public function review(int $reportId): RedirectResponse
    {
        $this->adminOnly();

        $decision = self::DECISIONS[(string) $this->request->getPost('decision')] ?? null;

        if ($decision === null || ! (new ReportReviewService())->review(auth()->user(), $reportId, $decision[0], $decision[1])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return redirect()->to(site_url('messages/reports'))->with('message', 'The report was reviewed.');
    }

    private function adminOnly(): void
    {
        if ((new MessagingContextResolver())->activeRole(auth()->user()) !== 'institutional_admin') {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}

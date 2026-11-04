<?php

declare(strict_types=1);

namespace App\Controllers\Messaging;

use App\Services\Messaging\RateLimitedException;
use App\Services\Messaging\ReportReviewService;
use App\Services\Messaging\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reporting a message, and the institutional administrator's JSON view of
 * reports. Anyone who is not allowed (not a participant, not this institution's
 * administrator) gets the shared 404; the services decide.
 */
class ReportController extends MessagingController
{
    public function create(int $conversationId, int $messageId): ResponseInterface
    {
        $input  = $this->input();
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        $note   = is_string($input['note'] ?? null) ? $input['note'] : null;

        try {
            $ok = (new ReportService())->report(auth()->user(), $conversationId, $messageId, $reason, $note);
        } catch (RateLimitedException $e) {
            return $this->rateLimited($e);
        }

        return $ok ? $this->response->setJSON(['ok' => true]) : $this->notFound();
    }

    public function index(): ResponseInterface
    {
        // Not an administrator: the same answer as a route that does not exist.
        if (! $this->isAdmin()) {
            return $this->notFound();
        }

        return $this->response->setJSON(['reports' => (new ReportReviewService())->list(auth()->user(), (string) ($this->request->getGet('status') ?: 'open'))]);
    }

    public function show(int $reportId): ResponseInterface
    {
        $report = (new ReportReviewService())->view(auth()->user(), $reportId);

        return $report === null ? $this->notFound() : $this->response->setJSON(['report' => $report]);
    }

    public function review(int $reportId): ResponseInterface
    {
        $input  = $this->input();
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        $remove = ($input['remove_message'] ?? false) === true;

        return (new ReportReviewService())->review(auth()->user(), $reportId, $status, $remove)
            ? $this->response->setJSON(['ok' => true])
            : $this->notFound();
    }

    private function isAdmin(): bool
    {
        return (new \App\Services\Messaging\MessagingContextResolver())->activeRole(auth()->user()) === 'institutional_admin';
    }
}

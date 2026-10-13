<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Audit\AuditLogFilters;
use App\Services\Institutions\InstitutionAuditLog;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institution's own audit log: filter, sort, search, page and export.
 * Read only, so it stays open while the institution is read-only. The
 * institution is the signed-in administrator's; nothing in the address or
 * the filters can widen it, and source addresses are never part of it.
 */
class AuditController extends BaseController
{
    public function index(): string
    {
        $log     = new InstitutionAuditLog((new InstitutionScope())->requireInstitutionId());
        $filters = AuditLogFilters::fromInput($this->request->getGet());
        $result  = $log->page($filters);

        return view('pages/institution/audit', [
            'title'        => 'Audit log',
            'page'         => 'audit',
            'pageTitle'    => 'Audit log',
            'user'         => auth()->user(),
            'auditFilters' => $filters,
            'auditRows'    => $result['rows'],
            'auditTotal'   => $result['total'],
            'auditPages'   => max(1, (int) ceil($result['total'] / $filters->perPage)),
            'auditActions' => $log->actionOptions(),
            'auditTargets' => $log->targetTypeOptions(),
        ]);
    }

    public function export(): ResponseInterface
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $log           = new InstitutionAuditLog($institutionId);
        $filters       = AuditLogFilters::fromInput($this->request->getGet());

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['time', 'who', 'acting_role', 'via', 'what', 'action', 'target_type', 'target_id', 'old_values', 'new_values']);

        $count = 0;

        foreach ($log->each($filters) as $row) {
            fputcsv($stream, array_map(self::safeCell(...), [
                $row['created_at'], $row['actor'], $row['acting_role'], $row['via'], $row['action_label'],
                $row['action'], $row['target_type'], $row['target_id'], $row['old_values'], $row['new_values'],
            ]));
            $count++;
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        // The export is on the record too: who took how many rows, narrowed how.
        service('auditLogger')->log('institution.audit_exported', auth()->user(), [
            'target_type'    => 'audit_log',
            'institution_id' => $institutionId,
            'new'            => ['rows' => $count, 'filters' => $filters->toQuery()],
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="audit-log-' . date('Ymd-His') . '.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($csv);
    }

    /** A cell starting with = + - @ (or a tab or return) would run as a formula; an apostrophe makes it text. */
    private static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }
}

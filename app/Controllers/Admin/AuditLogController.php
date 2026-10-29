<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogFilters;
use App\Services\Audit\AuditLogSearch;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * The audit log for system administrators: a filterable, sortable, paged
 * list, a CSV export (itself audited), and the setting that decides whether
 * source addresses are shown.
 */
class AuditLogController extends BaseController
{
    /** The most rows one export will contain. */
    private const EXPORT_MAX = 50000;

    public function index(): string
    {
        $filters = AuditLogFilters::fromInput($this->request->getGet());
        $search  = $this->search();
        $result  = $search->page($filters);

        return view('pages/admin/audit-log', [
            'title'   => 'Audit log',
            'page'    => 'admin',
            'user'    => auth()->user(),
            'filters' => $filters,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'pages'   => max(1, (int) ceil($result['total'] / $filters->perPage)),
            // Not called $actions: view data persists between renders and the shared
            // page-header component reads an $actions of its own.
            'actionNames' => $search->actions(),
            'showIp'  => $this->showIp(),
        ]);
    }

    public function export(): ResponseInterface
    {
        $filters = AuditLogFilters::fromInput($this->request->getGet());
        $showIp  = $this->showIp();

        $header = ['id', 'time', 'actor', 'acting_role', 'impersonator_user_id', 'action', 'target_type', 'target_id', 'institution_id', 'institution'];

        if ($showIp) {
            $header[] = 'ip_address';
        }

        array_push($header, 'old_values', 'new_values');

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $header, ',', '"', '');

        $count = 0;

        foreach ($this->search()->each($filters, self::EXPORT_MAX) as $row) {
            $cells = [
                $row['id'], $row['created_at'], $row['actor_label'], $row['acting_role'], $row['impersonator_user_id'],
                $row['action'], $row['target_type'], $row['target_id'], $row['institution_id'], $row['institution_name'],
            ];

            if ($showIp) {
                $cells[] = $row['ip_address'];
            }

            array_push($cells, $row['old_values'], $row['new_values']);

            fputcsv($stream, array_map(self::safeCell(...), $cells), ',', '"', '');
            $count++;
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        // The export itself is on the record: who took how many rows, narrowed how.
        service('auditLogger')->log('audit.exported', auth()->user(), [
            'target_type' => 'audit_log',
            'new'         => ['rows' => $count, 'filters' => $filters->toQuery(), 'includes_ip' => $showIp],
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="audit-log-' . date('Ymd-His') . '.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($csv);
    }

    /** Switches source-address display on or off. Behind the password confirmation. */
    public function updateSettings(): RedirectResponse
    {
        try {
            (new PlatformSettings())->set('auditShowSourceIp', (string) $this->request->getPost('show_ip'));
        } catch (InvalidArgumentException $e) {
            return redirect()->to('admin/audit-log')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/audit-log')->with('message', 'Audit log settings saved.');
    }

    private function showIp(): bool
    {
        return (bool) (new PlatformSettings())->get('auditShowSourceIp');
    }

    private function search(): AuditLogSearch
    {
        return new AuditLogSearch($this->showIp());
    }

    /**
     * A cell that starts with = + - @ (or a tab/return) would be run as a
     * formula by a spreadsheet; a leading apostrophe makes it plain text.
     */
    private static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }
}

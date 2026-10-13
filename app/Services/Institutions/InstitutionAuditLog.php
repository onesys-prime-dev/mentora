<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Audit\AuditLogFilters;
use App\Services\Audit\AuditLogSearch;
use Generator;

/**
 * The audit log as the institution's own administrator may read it.
 *
 * Only entries that belong to the institution are reachable: the search is
 * bound to it, so a filter, a search or an export can never name another.
 * Source addresses are never selected, never searched and never shown here;
 * that setting is the vendor's. What the vendor's staff did about the
 * institution is listed, under the name "Mentora support" rather than a
 * staff account, and the details of changes to the connection's settings
 * (folders, credentials) are withheld, as they are on the integrations page.
 */
class InstitutionAuditLog
{
    /** The most rows one export will contain. */
    public const EXPORT_MAX = 50000;

    /** Actions whose before and after values are the vendor's business. */
    private const WITHHELD_PREFIXES = ['sis.connection', 'backup.'];

    private const LABELS = [
        'auth.login'                    => 'Signed in',
        'auth.login_failed'             => 'Failed sign-in',
        'auth.logout'                   => 'Signed out',
        'impersonation.started'         => 'Started viewing as someone',
        'impersonation.ended'           => 'Stopped viewing as someone',
        'audit.exported'                => 'Exported the audit log',
        'institution.audit_exported'    => 'Exported the audit log',
        'users.exported'                => 'Exported people',
        'institution.report_exported'   => 'Exported a report',
        'institution.exported'          => 'Prepared a data export',
        'institution.export_downloaded' => 'Downloaded the data export',
        'sis.import_run'                => 'Imported from the SIS',
        'sis.import_previewed'          => 'Previewed an SIS import',
        'sis.import_failed'             => 'An SIS import failed',
        'sis.grades_sent'               => 'Sent final grades to the SIS',
        'sis.grades_previewed'          => 'Previewed final grades for the SIS',
        'sis.grades_failed'             => 'Sending grades to the SIS failed',
    ];

    /** @var array<int, bool> */
    private array $members = [];

    /** @var array<int, string> */
    private array $emails = [];

    private readonly AuditLogSearch $search;

    public function __construct(private readonly int $institutionId)
    {
        $this->search = new AuditLogSearch(false, $institutionId);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(AuditLogFilters $filters): array
    {
        $result = $this->search->page($filters);

        return ['rows' => array_map($this->present(...), $result['rows']), 'total' => $result['total']];
    }

    /** @return Generator<int, array<string, mixed>> */
    public function each(AuditLogFilters $filters, int $max = self::EXPORT_MAX): Generator
    {
        foreach ($this->search->each($filters, $max) as $row) {
            yield $this->present($row);
        }
    }

    /** @return array<string, string> action => plain label, for a choice list */
    public function actionOptions(): array
    {
        $options = [];

        foreach ($this->search->actions() as $action) {
            $options[$action] = self::label($action);
        }

        asort($options);

        return $options;
    }

    /** @return array<string, string> */
    public function targetTypeOptions(): array
    {
        $options = [];

        foreach ($this->search->targetTypes() as $type) {
            $options[$type] = ucfirst(str_replace(['_', '.'], ' ', $type));
        }

        return $options;
    }

    /** A plain-words name for an action key. */
    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $withheld = false;

        foreach (self::WITHHELD_PREFIXES as $prefix) {
            $withheld = $withheld || str_starts_with((string) $row['action'], $prefix);
        }

        return [
            'id'           => (int) $row['id'],
            'created_at'   => (string) $row['created_at'],
            'actor'        => $this->name($row['actor_user_id'] === null ? null : (int) $row['actor_user_id'], $row['actor_label']),
            'via'          => $row['impersonator_user_id'] === null ? null : $this->name((int) $row['impersonator_user_id'], null),
            'acting_role'  => $row['acting_role'],
            'action'       => (string) $row['action'],
            'action_label' => self::label((string) $row['action']),
            'target_type'  => $row['target_type'],
            'target_id'    => $row['target_id'],
            'old_values'   => $withheld ? null : $row['old_values'],
            'new_values'   => $withheld ? null : $row['new_values'],
            'withheld'     => $withheld && ($row['old_values'] !== null || $row['new_values'] !== null),
        ];
    }

    /** The person's own label when they belong to the institution; the vendor's staff are "Mentora support". */
    private function name(?int $userId, ?string $label): string
    {
        if ($userId === null) {
            return $label ?? 'The system';
        }

        if (! $this->isMember($userId)) {
            return 'Mentora support';
        }

        return $label ?? $this->emailOf($userId);
    }

    private function isMember(int $userId): bool
    {
        if (! isset($this->members[$userId])) {
            $this->members[$userId] = db_connect()->table('institution_users')
                ->where('user_id', $userId)->where('institution_id', $this->institutionId)->countAllResults() > 0;
        }

        return $this->members[$userId];
    }

    private function emailOf(int $userId): string
    {
        if (! isset($this->emails[$userId])) {
            $row = db_connect()->table('auth_identities')->select('secret')->where('user_id', $userId)->where('type', 'email_password')->get()->getRowArray();

            $this->emails[$userId] = $row === null ? 'A person of the institution' : (string) $row['secret'];
        }

        return $this->emails[$userId];
    }
}

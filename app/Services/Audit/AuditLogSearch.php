<?php

declare(strict_types=1);

namespace App\Services\Audit;

use CodeIgniter\Database\BaseBuilder;
use Generator;

/**
 * Reads the audit log for the admin list and the CSV export.
 *
 * Whether source addresses may be seen is decided once, when this is built.
 * When they may not, the address column is never selected and never searched,
 * so it cannot leak through a column, a search match or an export.
 *
 * It can also be bound to one institution. Then only that institution's
 * entries are read, whatever the filters say, and the lists of action names
 * and target types are drawn from them alone.
 */
class AuditLogSearch
{
    private const EXPORT_BATCH = 1000;

    private const COLUMNS = [
        'audit_logs.id',
        'audit_logs.created_at',
        'audit_logs.actor_user_id',
        'audit_logs.actor_label',
        'audit_logs.acting_role',
        'audit_logs.impersonator_user_id',
        'audit_logs.action',
        'audit_logs.target_type',
        'audit_logs.target_id',
        'audit_logs.institution_id',
        'institutions.name AS institution_name',
        'audit_logs.old_values',
        'audit_logs.new_values',
    ];

    public function __construct(private readonly bool $showIp, private readonly ?int $institutionId = null)
    {
    }

    /**
     * One page of entries and the number matching in total.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(AuditLogFilters $filters): array
    {
        $total = $this->filtered($filters)->countAllResults();

        $rows = $this->select($this->filtered($filters))
            ->orderBy('audit_logs.' . $filters->sort, $filters->dir)
            ->orderBy('audit_logs.id', $filters->dir)
            ->limit($filters->perPage, ($filters->page - 1) * $filters->perPage)
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Every matching entry, oldest first, read in batches so a large export
     * does not load the whole table at once. Stops after $max rows.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function each(AuditLogFilters $filters, int $max): Generator
    {
        $sent   = 0;
        $lastId = 0;

        while ($sent < $max) {
            $batch = $this->select($this->filtered($filters))
                ->where('audit_logs.id >', $lastId)
                ->orderBy('audit_logs.id', 'asc')
                ->limit(min(self::EXPORT_BATCH, $max - $sent))
                ->get()
                ->getResultArray();

            if ($batch === []) {
                return;
            }

            foreach ($batch as $row) {
                yield $row;
                $lastId = (int) $row['id'];
                $sent++;
            }
        }
    }

    /**
     * The distinct action names, for the filter's suggestions.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->distinct('action');
    }

    /**
     * The distinct target types, for the filter's choices.
     *
     * @return list<string>
     */
    public function targetTypes(): array
    {
        return $this->distinct('target_type');
    }

    /** @return list<string> */
    private function distinct(string $column): array
    {
        $builder = db_connect()->table('audit_logs')->select($column)->distinct()->where($column . ' IS NOT NULL', null, false);

        if ($this->institutionId !== null) {
            $builder->where('institution_id', $this->institutionId);
        }

        return array_column($builder->orderBy($column)->get()->getResultArray(), $column);
    }

    /**
     * Text for a LIKE, with % and _ made literal. The query builder adds the
     * ESCAPE clause but does not escape the value itself, so without this a
     * search for "%" would match every entry.
     */
    private function literal(string $text): string
    {
        return db_connect()->escapeLikeString($text);
    }

    private function select(BaseBuilder $builder): BaseBuilder
    {
        $columns = self::COLUMNS;

        if ($this->showIp) {
            $columns[] = 'audit_logs.ip_address';
        }

        return $builder->select($columns);
    }

    private function filtered(AuditLogFilters $filters): BaseBuilder
    {
        $builder = db_connect()->table('audit_logs')->join('institutions', 'institutions.id = audit_logs.institution_id', 'left');

        if (($from = $filters->fromTimestamp()) !== null) {
            $builder->where('audit_logs.created_at >=', $from);
        }

        if (($until = $filters->untilTimestamp()) !== null) {
            $builder->where('audit_logs.created_at <', $until);
        }

        if ($filters->actor !== null) {
            if (ctype_digit($filters->actor)) {
                $builder->where('audit_logs.actor_user_id', (int) $filters->actor);
            } else {
                $builder->like('audit_logs.actor_label', $this->literal($filters->actor), 'both', true);
            }
        }

        // A bound search never takes the institution from the filters.
        if ($this->institutionId !== null) {
            $builder->where('audit_logs.institution_id', $this->institutionId);
        } elseif ($filters->institutionId !== null) {
            $builder->where('audit_logs.institution_id', $filters->institutionId);
        }

        if ($filters->action !== null) {
            $builder->where('audit_logs.action', $filters->action);
        }

        if ($filters->targetType !== null) {
            $builder->where('audit_logs.target_type', $filters->targetType);
        }

        if ($filters->q !== null) {
            $builder->groupStart()
                ->like('audit_logs.actor_label', $this->literal($filters->q), 'both', true)
                ->orLike('audit_logs.action', $this->literal($filters->q), 'both', true)
                ->orLike('audit_logs.target_type', $this->literal($filters->q), 'both', true)
                ->orLike('audit_logs.target_id', $this->literal($filters->q), 'both', true)
                ->orLike('institutions.name', $this->literal($filters->q), 'both', true);

            if ($this->showIp) {
                $builder->orLike('audit_logs.ip_address', $this->literal($filters->q), 'both', true);
            }

            $builder->groupEnd();
        }

        return $builder;
    }
}

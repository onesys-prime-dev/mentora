<?php

declare(strict_types=1);

namespace App\Services\Users;

use CodeIgniter\Database\BaseBuilder;
use Generator;

/**
 * Reads people across every institution: the users list and its export (SA5).
 * Read-only. Deleted accounts are left out.
 *
 * A row is: id, username, email, first_name, last_name, active, last_active,
 * created_at, deactivated_at, plus `roles` (list of group names) and
 * `institutions` (list of [id, name]) filled in for the rows returned.
 */
class UserDirectory
{
    /**
     * One page of users matching the filters.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(UserFilters $filters): array
    {
        $total = (int) $this->base($filters)->select('COUNT(DISTINCT users.id) AS total', false)->get()->getRowArray()['total'];

        $rows = $this->ordered($this->base($filters), $filters)
            ->limit($filters->perPage, ($filters->page - 1) * $filters->perPage)
            ->get()->getResultArray();

        return ['rows' => $this->withMemberships($rows), 'total' => $total];
    }

    /**
     * Every matching user, in the list's order, up to $max.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function each(UserFilters $filters, int $max): Generator
    {
        $offset = 0;
        $chunk  = 500;

        while ($offset < $max) {
            $rows = $this->ordered($this->base($filters), $filters)
                ->limit(min($chunk, $max - $offset), $offset)
                ->get()->getResultArray();

            if ($rows === []) {
                return;
            }

            foreach ($this->withMemberships($rows) as $row) {
                yield $row;
            }

            $offset += $chunk;
        }
    }

    /**
     * The given users (the ones that exist and are not deleted), in the list's order.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->columns($this->base(new UserFilters()))->whereIn('users.id', $ids)->orderBy('users.id')->get()->getResultArray();

        return $this->withMemberships($rows);
    }

    /**
     * One user, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->byIds([$id])[0] ?? null;
    }

    /** How many active system administrators there are. */
    public function activeAdministrators(): int
    {
        return (int) db_connect()->table('users')
            ->select('COUNT(DISTINCT users.id) AS total', false)
            ->join('auth_groups_users', "auth_groups_users.user_id = users.id AND auth_groups_users.group = 'admin'")
            ->where('users.deleted_at IS NULL', null, false)
            ->where('users.active', 1)
            ->get()->getRowArray()['total'];
    }

    /** Filtered and joined, but not yet selected or ordered (so it can also be counted). */
    private function base(UserFilters $filters): BaseBuilder
    {
        $builder = db_connect()->table('users')
            ->join('user_profiles', 'user_profiles.user_id = users.id', 'left')
            ->join('user_account_controls', 'user_account_controls.user_id = users.id', 'left')
            ->join('auth_identities', "auth_identities.user_id = users.id AND auth_identities.type = 'email_password'", 'left')
            ->where('users.deleted_at IS NULL', null, false);

        if ($filters->institutionId !== null) {
            $builder->where('EXISTS (SELECT 1 FROM institution_users WHERE institution_users.user_id = users.id AND institution_users.institution_id = ' . (int) $filters->institutionId . ')', null, false);
        }

        if ($filters->role !== null) {
            $builder->where('EXISTS (SELECT 1 FROM auth_groups_users WHERE auth_groups_users.user_id = users.id AND auth_groups_users.group = ' . db_connect()->escape($filters->role) . ')', null, false);
        }

        if ($filters->status !== null) {
            $builder->where('users.active', $filters->status === 'active' ? 1 : 0);
        }

        if ($filters->q !== null) {
            $like = db_connect()->escapeLikeString($filters->q);

            $builder->groupStart()
                ->like('users.username', $like, 'both', true)
                ->orLike('auth_identities.secret', $like, 'both', true)
                ->orLike('user_profiles.first_name', $like, 'both', true)
                ->orLike('user_profiles.last_name', $like, 'both', true)
                ->orLike("CONCAT(COALESCE(user_profiles.first_name, ''), ' ', COALESCE(user_profiles.last_name, ''))", $like, 'both', true)
                ->groupEnd();
        }

        return $builder;
    }

    /** What a row is made of. */
    private function columns(BaseBuilder $builder): BaseBuilder
    {
        return $builder
            ->select('users.id, users.username, users.active, users.last_active, users.created_at')
            ->select('user_profiles.first_name, user_profiles.last_name, user_account_controls.deactivated_at')
            ->select('auth_identities.secret AS email');
    }

    private function ordered(BaseBuilder $builder, UserFilters $filters): BaseBuilder
    {
        $builder = $this->columns($builder);

        $column = match ($filters->sort) {
            'email'       => 'auth_identities.secret',
            'last_active' => 'users.last_active',
            'created_at'  => 'users.created_at',
            default       => 'user_profiles.last_name',
        };

        $builder->orderBy($column, $filters->dir);

        if ($filters->sort === 'name') {
            $builder->orderBy('user_profiles.first_name', $filters->dir);
        }

        return $builder->orderBy('users.id', $filters->dir);
    }

    /**
     * Adds each row's roles and institutions.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withMemberships(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $db  = db_connect();

        $roles = [];

        foreach ($db->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', $ids)->orderBy('group')->get()->getResultArray() as $link) {
            $roles[(int) $link['user_id']][] = $link['group'];
        }

        $institutions = [];

        foreach ($db->table('institution_users')
            ->select('institution_users.user_id, institutions.id, institutions.name')
            ->join('institutions', 'institutions.id = institution_users.institution_id')
            ->whereIn('institution_users.user_id', $ids)
            ->orderBy('institutions.name')
            ->get()->getResultArray() as $link) {
            $institutions[(int) $link['user_id']][] = ['id' => (int) $link['id'], 'name' => $link['name']];
        }

        foreach ($rows as &$row) {
            $row['roles']        = $roles[(int) $row['id']] ?? [];
            $row['institutions'] = $institutions[(int) $row['id']] ?? [];
        }

        return $rows;
    }
}

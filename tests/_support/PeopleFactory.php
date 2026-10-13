<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * People and institutions for the user-administration tests: an account with a
 * name, a sign-in email, a role and (optionally) an institution, written
 * straight to the tables so the test controls every field.
 *
 * @internal
 */
final class PeopleFactory
{
    private static int $counter = 0;

    public static function institution(string $code, string $status = 'active', ?string $name = null): int
    {
        db_connect()->table('institutions')->insert([
            'name'       => $name ?? "Institution {$code}",
            'code'       => $code,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    public static function plan(string $name = 'Standard', ?int $seats = null): int
    {
        db_connect()->table('plans')->insert(['name' => $name, 'status' => 'active', 'seat_limit' => $seats, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) db_connect()->insertID();
    }

    /** Gives an institution a running subscription on the plan (so its plan limits apply). */
    public static function subscribe(int $institutionId, int $planId): void
    {
        db_connect()->table('subscriptions')->insert([
            'institution_id' => $institutionId,
            'plan_id'        => $planId,
            'start_date'     => date('Y-m-d', strtotime('-1 month')),
            'end_date'       => date('Y-m-d', strtotime('+11 months')),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public static function person(
        string $role,
        string $first = 'Test',
        string $last = 'Person',
        ?string $email = null,
        ?int $institutionId = null,
        bool $active = true,
        ?string $lastActive = null,
    ): User {
        $db = db_connect();
        $n  = ++self::$counter;

        $db->table('users')->insert([
            'username'    => substr($role, 0, 6) . $n . substr(md5(uniqid('', true)), 0, 6),
            'active'      => $active ? 1 : 0,
            'last_active' => $lastActive,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $db->insertID();

        $db->table('auth_groups_users')->insert(['user_id' => $id, 'group' => $role, 'created_at' => date('Y-m-d H:i:s')]);

        $email ??= strtolower("{$first}.{$last}.{$n}") . '@example.com';

        $db->table('auth_identities')->insert([
            'user_id'    => $id,
            'type'       => 'email_password',
            'secret'     => $email,
            'secret2'    => password_hash('placeholder-' . $n, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($role !== 'admin') {
            $db->table('user_profiles')->insert(['user_id' => $id, 'first_name' => $first, 'last_name' => $last, 'created_at' => date('Y-m-d H:i:s')]);
        }

        if ($institutionId !== null) {
            $db->table('institution_users')->insert(['institution_id' => $institutionId, 'user_id' => $id, 'created_at' => date('Y-m-d H:i:s')]);
        }

        return model(\CodeIgniter\Shield\Models\UserModel::class)->findById($id);
    }
}

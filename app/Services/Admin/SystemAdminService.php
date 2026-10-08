<?php

declare(strict_types=1);

namespace App\Services\Admin;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

/**
 * Creates system administrator accounts.
 *
 * There is deliberately no sign-up path for this role: accounts are made only
 * here, from the `admin:create` spark command or a seeder. A system
 * administrator belongs to no institution, so no institution link is written.
 */
class SystemAdminService
{
    public const GROUP = 'admin';

    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * @throws InvalidArgumentException when the details are not acceptable
     */
    public function create(string $email, string $username, string $password): User
    {
        $email    = strtolower(trim($email));
        $username = trim($username);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That is not a valid email address.');
        }

        if ($username === '') {
            throw new InvalidArgumentException('A username is required.');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }

        /** @var UserModel $users */
        $users = model(UserModel::class);

        if ($users->findByCredentials(['email' => $email]) !== null) {
            throw new InvalidArgumentException("An account with {$email} already exists.");
        }

        if ($users->where('username', $username)->first() !== null) {
            throw new InvalidArgumentException("The username {$username} is already taken.");
        }

        $users->save(new User([
            'username' => $username,
            'email'    => $email,
            'password' => $password,
            'active'   => true,
        ]));

        /** @var User $user */
        $user = $users->findById($users->getInsertID());
        $user->addGroup(self::GROUP);

        return $user;
    }
}

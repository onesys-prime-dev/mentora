<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Admin\SystemAdminService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * The only way (besides the seeder) to create a system administrator.
 */
class CreateSystemAdmin extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'admin:create';
    protected $description = 'Creates a system administrator account.';
    protected $usage       = 'admin:create <email> <username> [password]';
    protected $arguments   = [
        'email'    => 'Sign-in email address',
        'username' => 'Unique username',
        'password' => 'Optional. Prompted for (not echoed to history) when left out.',
    ];

    public function run(array $params)
    {
        $email    = $params[0] ?? CLI::prompt('Email');
        $username = $params[1] ?? CLI::prompt('Username');
        $password = $params[2] ?? CLI::prompt('Password (at least ' . SystemAdminService::MIN_PASSWORD_LENGTH . ' characters)');

        try {
            $user = (new SystemAdminService())->create($email, $username, $password);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_USER_INPUT;
        }

        CLI::write("Created system administrator {$user->username} ({$email}).", 'green');

        return EXIT_SUCCESS;
    }
}

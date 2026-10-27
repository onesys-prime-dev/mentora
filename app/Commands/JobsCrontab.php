<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Monitoring\CrontabBlock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The deploy's crontab step (audit decisions D5, 6.3):
 *
 *   crontab -l | php spark jobs:crontab --site=<folder> --php=<binary>   prints the crontab with the jobs' block
 *   crontab -l | php spark jobs:crontab --verify                         names any scheduled job missing; exit 1
 */
class JobsCrontab extends BaseCommand
{
    protected $group       = 'Mentora';
    protected $name        = 'jobs:crontab';
    protected $description = "Prints the crontab with the scheduled jobs' block in place, or checks one has every job.";
    protected $usage       = 'jobs:crontab [--site=<folder> --php=<binary> | --verify]';
    protected $options     = [
        '--site'   => 'The site folder the jobs run in.',
        '--php'    => 'The PHP binary, for example php8.5.',
        '--verify' => 'Check the crontab on standard input has every scheduled job.',
    ];

    public function run(array $params)
    {
        $input = (string) stream_get_contents(STDIN);

        if (array_key_exists('verify', $params) || CLI::getOption('verify') !== null) {
            $missing = CrontabBlock::missing($input);

            if ($missing !== []) {
                CLI::error('Not in the crontab: ' . implode(', ', $missing) . '.');

                return EXIT_ERROR;
            }

            CLI::write('All scheduled jobs are installed.', 'green');

            return EXIT_SUCCESS;
        }

        $site = (string) ($params['site'] ?? CLI::getOption('site') ?? '');
        $php  = (string) ($params['php'] ?? CLI::getOption('php') ?? '');

        if ($site === '' || $php === '') {
            CLI::error('Give both --site and --php.');

            return EXIT_ERROR;
        }

        try {
            echo CrontabBlock::merge($input, CrontabBlock::render(CrontabBlock::template(), $site, $php));
        } catch (\InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}

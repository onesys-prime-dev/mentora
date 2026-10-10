<?php

declare(strict_types=1);

namespace App\Services\Users;

use RuntimeException;

/**
 * Makes a free username from a person's name ("ann.lee", then "ann.lee1"...).
 * People sign in with their email; the username is only an identifier.
 */
class UsernameGenerator
{
    public function from(string $first, string $last): string
    {
        $part = static function (string $name): string {
            $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

            return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $plain === false ? $name : $plain) ?? '');
        };

        $base = substr(trim(($part($first) ?: 'admin') . '.' . ($part($last) ?: 'user'), '.'), 0, 26);

        for ($n = 0; $n < 1000; $n++) {
            $candidate = $n === 0 ? $base : $base . $n;

            if (db_connect()->table('users')->where('username', $candidate)->countAllResults() === 0) {
                return $candidate;
            }
        }

        throw new RuntimeException('No free username could be found.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Sis\CredentialVault;
use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisConnectors;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;

/**
 * Sets up a SIS-ready institution for tests: an active institution with a
 * program and an active term, the feature on, a saved and enabled connection,
 * and a temporary folder for the files the SIS "drops".
 */
final class SisFixture
{
    public const PROGRAM = 'BSIT';

    public const STUDENTS = "external_id,email,first_name,last_name,student_number\nS1,ann@example.com,Ann,Lee,2026-001\nS2,ben@example.com,Ben,Ng,2026-002\nS3,not-an-email,Cy,Ho,\n";

    public const INSTRUCTORS = "external_id,email,first_name,last_name\nI1,ivy@example.com,Ivy,Tan\n";

    public const COURSES = "external_id,course_code,course_title,units,program_code\nC1,IT101,Intro to Computing,3,BSIT\nC2,IT102,No Program,3,\n";

    public const ENROLLMENTS = "course_external_id,student_external_id,section,status\nC1,S1,,enrolled\nC1,S2,B,dropped\nC9,S1,,enrolled\n";

    public static function useFolder(): string
    {
        $folder           = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-sis-' . bin2hex(random_bytes(4));
        $_ENV['sis.path'] = $folder;

        Services::injectMock('credentialVault', new CredentialVault('sis-fixture-encryption-key-0123456789'));

        return $folder;
    }

    public static function cleanup(string $folder): void
    {
        self::removeTree($folder);
        unset($_ENV['sis.path']);

        Services::resetSingle('credentialVault');
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * An active institution with a program (code BSIT) and an active term.
     *
     * @return int the institution id
     */
    public static function institution(string $code = 'SISI', bool $withTerm = true): int
    {
        $db = db_connect();
        $id = PeopleFactory::institution($code, 'active', "{$code} College");

        $db->table('departments')->insert(['institution_id' => $id, 'name' => 'Computing', 'code' => "D{$code}"]);
        $department = (int) $db->insertID();
        $db->table('sub_departments')->insert(['department_id' => $department, 'name' => 'IT', 'code' => "S{$code}"]);
        $sub = (int) $db->insertID();
        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'BS Information Technology', 'code' => self::PROGRAM]);

        if ($withTerm) {
            $db->table('academic_terms')->insert(['institution_id' => $id, 'name' => 'First Term', 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-20', 'status' => 'active']);
        }

        return $id;
    }

    /** Turns the feature on for the institution, saves the connection and enables it. */
    public static function connect(int $institutionId, User $actor, bool $enabled = true): void
    {
        (new FeatureFlagAdmin())->setForInstitution($institutionId, 'sis.sync', 'on', 'Pilot', null, $actor);
        (new SisConnectionService())->save($institutionId, $enabled, [], false, $actor);
    }

    public static function drop(int $institutionId, string $dataset, string $csv): void
    {
        $inbound = SisConnectors::folders($institutionId)['inbound'];

        if (! is_dir($inbound)) {
            mkdir($inbound, 0770, true);
        }

        file_put_contents($inbound . DIRECTORY_SEPARATOR . $dataset . '.csv', $csv);
    }

    /** Drops the four standard files. */
    public static function dropAll(int $institutionId): void
    {
        self::drop($institutionId, 'students', self::STUDENTS);
        self::drop($institutionId, 'instructors', self::INSTRUCTORS);
        self::drop($institutionId, 'courses', self::COURSES);
        self::drop($institutionId, 'enrollments', self::ENROLLMENTS);
    }

    /** A successful earlier run, so a real import is allowed (a preview would otherwise be needed). */
    public static function priorSuccess(int $institutionId): void
    {
        db_connect()->table('sis_runs')->insert([
            'institution_id' => $institutionId, 'direction' => 'inbound', 'trigger' => 'manual', 'status' => 'succeeded',
            'dry_run' => 1, 'started_at' => '2026-10-01 08:00:00', 'finished_at' => '2026-10-01 08:00:01',
        ]);
    }
}

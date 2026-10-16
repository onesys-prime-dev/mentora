<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * A department with sub-departments, programs, terms and offerings, and the
 * dean in charge of it: what the dean's screens read.
 */
final class DeanFactory
{
    /** @return array{institution: int, department: int} */
    public static function department(string $tag, ?int $institution = null): array
    {
        $db = db_connect();

        $institution ??= PeopleFactory::institution("I{$tag}");

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);

        return ['institution' => $institution, 'department' => (int) $db->insertID()];
    }

    public static function subDepartment(int $department, string $tag): int
    {
        db_connect()->table('sub_departments')->insert(['department_id' => $department, 'code' => "S{$tag}", 'name' => "Sub {$tag}"]);

        return (int) db_connect()->insertID();
    }

    public static function program(int $subDepartment, string $tag): int
    {
        db_connect()->table('programs')->insert(['sub_department_id' => $subDepartment, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);

        return (int) db_connect()->insertID();
    }

    public static function term(int $institution, string $name = 'First', string $start = '2026-08-01'): int
    {
        db_connect()->table('academic_terms')->insert([
            'institution_id' => $institution, 'name' => $name, 'academic_year' => '2026-2027', 'start_date' => $start, 'end_date' => '2026-12-20',
        ]);

        return (int) db_connect()->insertID();
    }

    public static function offering(int $program, int $term, string $code, string $section = 'A', string $status = 'active', ?string $title = null): int
    {
        $db = db_connect();

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => $code, 'course_title' => $title ?? "Course {$code}", 'units' => 3]);
        $db->table('course_offerings')->insert(['course_id' => (int) $db->insertID(), 'term_id' => $term, 'section_code' => $section, 'status' => $status]);

        return (int) $db->insertID();
    }

    public static function dean(int $institution, int $department, string $first = 'Dana'): User
    {
        $dean = PeopleFactory::person('dean', $first, 'Dean', null, $institution);

        db_connect()->table('dean_assignments')->insert([
            'department_id' => $department, 'dean_user_id' => $dean->id, 'status' => 'active',
            'active_marker' => 1, 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $dean;
    }

    public static function chair(int $subDepartment, string $first = 'Maria', string $status = 'active'): User
    {
        $chair = PeopleFactory::person('chairperson', $first, 'Chair');

        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $subDepartment, 'chairperson_user_id' => $chair->id, 'status' => $status, 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $chair;
    }
}

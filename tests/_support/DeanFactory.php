<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * A college with departments, programs, terms and offerings, and the
 * dean in charge of it: what the dean's screens read.
 */
final class DeanFactory
{
    /** @return array{institution: int, college: int} */
    public static function college(string $tag, ?int $institution = null): array
    {
        $db = db_connect();

        $institution ??= PeopleFactory::institution("I{$tag}");

        $db->table('colleges')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);

        return ['institution' => $institution, 'college' => (int) $db->insertID()];
    }

    public static function department(int $college, string $tag): int
    {
        db_connect()->table('departments')->insert(['college_id' => $college, 'code' => "S{$tag}", 'name' => "Department {$tag}"]);

        return (int) db_connect()->insertID();
    }

    public static function program(int $department, string $tag): int
    {
        db_connect()->table('programs')->insert(['department_id' => $department, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);

        return (int) db_connect()->insertID();
    }

    public static function term(int $institution, string $name = 'First', string $start = '2026-08-01'): int
    {
        db_connect()->table('academic_terms')->insert([
            // Ends 141 days after it starts (2026-08-01 to 2026-12-20 by default): a term ends after it starts.
            'institution_id' => $institution, 'name' => $name, 'academic_year' => '2026-2027', 'start_date' => $start, 'end_date' => date('Y-m-d', strtotime($start . ' +141 days')),
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

    public static function dean(int $institution, int $college, string $first = 'Dana'): User
    {
        $dean = PeopleFactory::person('dean', $first, 'Dean', null, $institution);

        db_connect()->table('dean_assignments')->insert([
            'college_id' => $college, 'dean_user_id' => $dean->id, 'status' => 'active',
            'active_marker' => 1, 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $dean;
    }

    public static function chair(int $department, string $first = 'Maria', string $status = 'active'): User
    {
        // A chairperson belongs to the institution of the department they chair.
        $institution = (int) db_connect()->table('departments sd')->select('d.institution_id')
            ->join('colleges d', 'd.id = sd.college_id')->where('sd.id', $department)->get()->getRow('institution_id');

        $chair = PeopleFactory::person('chairperson', $first, 'Chair', null, $institution);

        db_connect()->table('chairperson_assignments')->insert([
            'department_id' => $department, 'chairperson_user_id' => $chair->id, 'status' => $status, 'assigned_at' => date('Y-m-d H:i:s'),
            // One active chairperson per department (decision 16.0).
            'active_marker' => $status === 'active' ? 1 : null,
        ]);

        return $chair;
    }
}

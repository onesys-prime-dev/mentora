<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * One institution at the export's target scale (audit decision 12.1), to check the export by hand:
 *
 *   php spark db:seed ExportScaleSeeder
 *
 * 20,000 students, ten terms (five years), 20 courses offered every term (200 sections), 50 assessments per
 * course, each student in ten sections and with ten submissions in each: 200,000 enrolments and 2,000,000
 * submissions. Everything is made with INSERT ... SELECT, so it takes minutes, not hours. The institution is
 * read-only, so it can be exported straight away.
 *
 * Only on a throw-away database whose name contains "scale" (for example mentora_scale): it adds millions of
 * rows, so it refuses any other, and production always. Point the app at that database in .env first: .env
 * wins over anything set on the command line (docs/institution-data-export.md).
 */
class ExportScaleSeeder extends Seeder
{
    public const STUDENTS = 20000;

    public function run()
    {
        if (ENVIRONMENT === 'production') {
            throw new RuntimeException('ExportScaleSeeder never runs on production.');
        }

        if (! str_contains(strtolower((string) $this->db->getDatabase()), 'scale')) {
            throw new RuntimeException("ExportScaleSeeder runs only on a database whose name contains 'scale'; this is '{$this->db->getDatabase()}'.");
        }

        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        $db->table('institutions')->insert(['name' => 'Scale Test University', 'code' => 'SCALE' . random_int(100, 999), 'status' => 'read_only', 'created_at' => $now]);
        $institution = (int) $db->insertID();
        $db->table('departments')->insert(['institution_id' => $institution, 'code' => 'DSC', 'name' => 'College of Scale']);
        $db->table('sub_departments')->insert(['department_id' => (int) $db->insertID(), 'code' => 'SSC', 'name' => 'Scale Studies', 'is_active' => 1]);
        $db->table('programs')->insert(['sub_department_id' => (int) $db->insertID(), 'code' => 'PSC' . $institution, 'name' => 'BS Scale']);
        $program = (int) $db->insertID();

        // 0..99999, for numbering rows in one statement.
        $db->query('DROP TEMPORARY TABLE IF EXISTS scale_nums');
        $db->query('CREATE TEMPORARY TABLE scale_nums (n INT PRIMARY KEY)');
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $db->query("INSERT INTO scale_nums SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + f.d * 10000 FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e, {$digits} f");

        // The people: students, with the identity, profile, role and membership the export reads.
        $tag = 'sc' . $institution . '-';
        $db->query('INSERT INTO users (username, active, created_at) SELECT CONCAT(?, n), 1, ? FROM scale_nums WHERE n < ?', [$tag, $now, self::STUDENTS]);
        $db->query("INSERT INTO auth_identities (user_id, type, secret, secret2, created_at) SELECT id, 'email_password', CONCAT(username, '@scale.test'), 'x', ? FROM users WHERE username LIKE ?", [$now, $tag . '%']);
        $db->query("INSERT INTO auth_groups_users (user_id, `group`, created_at) SELECT id, 'student', ? FROM users WHERE username LIKE ?", [$now, $tag . '%']);
        $db->query("INSERT INTO user_profiles (user_id, first_name, last_name) SELECT id, 'Scale', username FROM users WHERE username LIKE ?", [$tag . '%']);
        $db->query('INSERT INTO institution_users (institution_id, user_id) SELECT ?, id FROM users WHERE username LIKE ?', [$institution, $tag . '%']);

        // Ten terms, 20 courses, each offered every term.
        $db->query("INSERT INTO academic_terms (institution_id, name, academic_year, start_date, end_date) SELECT ?, CONCAT('Term ', n + 1), CONCAT(2021 + FLOOR(n / 2), '-', 2022 + FLOOR(n / 2)), DATE_ADD('2021-08-01', INTERVAL n * 6 MONTH), DATE_ADD('2021-12-20', INTERVAL n * 6 MONTH) FROM scale_nums WHERE n < 10", [$institution]);
        $db->query("INSERT INTO courses (program_id, course_code, course_title, units) SELECT ?, CONCAT('SC', 100 + n), CONCAT('Scale course ', n + 1), 3 FROM scale_nums WHERE n < 20", [$program]);
        $db->query("INSERT INTO course_offerings (course_id, term_id, section_code, status) SELECT c.id, t.id, 'A', 'completed' FROM courses c JOIN academic_terms t ON t.institution_id = ? WHERE c.program_id = ?", [$institution, $program]);

        // 50 assessments per course.
        $type    = (int) $db->table('assessment_types')->select('id')->orderBy('id')->get()->getRow('id');
        $creator = (int) $db->table('users')->select('id')->like('username', $tag, 'after')->orderBy('id')->get()->getRow('id');
        $db->query("INSERT INTO assessments (course_id, assessment_type_id, title, available_from, due_date, total_points, created_by, created_at, status) SELECT c.id, ?, CONCAT('Item ', n), '2021-08-01', '2026-12-01', 10, ?, ?, 'Published' FROM courses c JOIN scale_nums ON n < 50 WHERE c.program_id = ?", [$type, $creator, $now, $program]);

        // Each student in ten sections (one course, ten terms) and ten submissions in each.
        $db->query("INSERT INTO enrollments (offering_id, student_user_id, status, enrolled_at) SELECT o.id, u.id, 'completed', ? FROM users u JOIN courses c ON c.program_id = ? AND c.course_code = CONCAT('SC', 100 + MOD(u.id, 20)) JOIN course_offerings o ON o.course_id = c.id WHERE u.username LIKE ?", [$now, $program, $tag . '%']);
        $db->query("INSERT INTO assessment_submissions (assessment_id, student_id, attempt_number, status, score, submission_text, started_at) SELECT a.id, e.student_user_id, 1 + (o.term_id - (SELECT MIN(id) FROM academic_terms WHERE institution_id = ?)), 'Graded', 7, 'A typical answer, a sentence or two long, as students write them.', ? FROM enrollments e JOIN course_offerings o ON o.id = e.offering_id JOIN assessments a ON a.course_id = o.course_id AND a.title IN ('Item 0','Item 1','Item 2','Item 3','Item 4','Item 5','Item 6','Item 7','Item 8','Item 9') JOIN courses c ON c.id = o.course_id WHERE c.program_id = ?", [$institution, $now, $program]);

        $db->query('DROP TEMPORARY TABLE IF EXISTS scale_nums');

        echo 'Institution #' . $institution . ': ' . $db->table('enrollments e')->join('course_offerings o', 'o.id = e.offering_id')->join('courses c', 'c.id = o.course_id')->where('c.program_id', $program)->countAllResults() . ' enrolments, '
            . $db->query('SELECT COUNT(*) n FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id JOIN courses c ON c.id = a.course_id WHERE c.program_id = ?', [$program])->getRow()->n . " submissions.\n";
    }
}

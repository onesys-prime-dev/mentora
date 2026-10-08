<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Builds the smallest valid rows for course tests: users in a role, an
 * offering, enrolments, and modules/units/items. Every method returns ids (or
 * a User) so tests read as the situation they set up, not as inserts.
 *
 * Only for DatabaseTestCase classes (it writes to the test database).
 */
final class CourseFactory
{
    private static int $sequence = 0;

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function next(): int
    {
        return ++self::$sequence;
    }

    /** A user in the given Shield group ('student', 'instructor', ...). */
    public static function user(string $group = 'student'): User
    {
        $db = db_connect();
        $n = self::next();

        $db->table('users')->insert([
            'username' => substr($group, 0, 8) . $n . substr(md5(uniqid('', true)), 0, 6),
            'active' => 1,
            'created_at' => self::now(),
        ]);

        $id = (int) $db->insertID();

        $db->table('auth_groups_users')->insert([
            'user_id' => $id,
            'group' => $group,
            'created_at' => self::now(),
        ]);

        return model(UserModel::class)->findById($id);
    }

    /**
     * A course offering, with the institution, department, program, term and course it
     * needs.
     *
     * @return int offering id
     */
    public static function offering(string $sectionCode = 'A'): int
    {
        $db = db_connect();
        $n = self::next();

        $db->table('institutions')->insert(['name' => "Institution {$n}", 'code' => "INS{$n}"]);
        $institutionId = (int) $db->insertID();

        $db->table('departments')->insert(['institution_id' => $institutionId, 'name' => "Department {$n}", 'code' => "DEP{$n}"]);
        $departmentId = (int) $db->insertID();

        $db->table('sub_departments')->insert(['department_id' => $departmentId, 'name' => "Sub-department {$n}", 'code' => "SUB{$n}"]);
        $subDepartmentId = (int) $db->insertID();

        $db->table('programs')->insert(['sub_department_id' => $subDepartmentId, 'name' => "Program {$n}", 'code' => "PRG{$n}"]);
        $programId = (int) $db->insertID();

        $db->table('academic_terms')->insert([
            'institution_id' => $institutionId,
            'name' => "Term {$n}",
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
        ]);
        $termId = (int) $db->insertID();

        $db->table('courses')->insert([
            'program_id' => $programId,
            'course_code' => "IT{$n}",
            'course_title' => "Course {$n}",
            'units' => 3,
        ]);
        $courseId = (int) $db->insertID();

        $db->table('course_offerings')->insert([
            'course_id' => $courseId,
            'term_id' => $termId,
            'section_code' => $sectionCode,
        ]);

        return (int) $db->insertID();
    }

    public static function enroll(int $offeringId, User $student, string $status = 'enrolled'): void
    {
        db_connect()->table('enrollments')->insert([
            'offering_id' => $offeringId,
            'student_user_id' => $student->id,
            'status' => $status,
            'enrolled_at' => self::now(),
        ]);
    }

    public static function assignInstructor(int $offeringId, User $instructor): void
    {
        db_connect()->table('instructor_assignments')->insert([
            'offering_id' => $offeringId,
            'instructor_user_id' => $instructor->id,
            'assigned_at' => self::now(),
        ]);
    }

    public static function module(int $offeringId, string $status = 'published', int $order = 1): int
    {
        $db = db_connect();

        $db->table('course_modules')->insert([
            'offering_id' => $offeringId,
            'title' => 'Module ' . self::next(),
            'order_index' => $order,
            'status' => $status,
        ]);

        return (int) $db->insertID();
    }

    public static function unit(int $moduleId, string $status = 'published', int $order = 1): int
    {
        $db = db_connect();

        $db->table('learning_units')->insert([
            'module_id' => $moduleId,
            'title' => 'Unit ' . self::next(),
            'order_index' => $order,
            'status' => $status,
        ]);

        return (int) $db->insertID();
    }

    /**
     * Learning items in a unit, in the order given.
     *
     * Each spec: ['title'?, 'type'? (reading), 'status'? (published), 'mode'? (none)].
     *
     * @param list<array{title?: string, type?: string, status?: string, mode?: string}> $specs
     *
     * @return list<int> item ids, in the same order
     */
    public static function items(int $unitId, array $specs): array
    {
        $db = db_connect();
        $ids = [];

        foreach (array_values($specs) as $index => $spec) {
            $db->table('learning_items')->insert([
                'learning_unit_id' => $unitId,
                'title' => $spec['title'] ?? 'Item ' . ($index + 1),
                'item_type' => $spec['type'] ?? 'reading',
                'status' => $spec['status'] ?? 'published',
                'prerequisite_mode' => $spec['mode'] ?? 'none',
                'order_index' => $index + 1,
            ]);

            $ids[] = (int) $db->insertID();
        }

        return $ids;
    }

    /** Lets $itemId list the given items as custom prerequisites. */
    public static function requires(int $itemId, int ...$prerequisiteIds): void
    {
        foreach ($prerequisiteIds as $prerequisiteId) {
            db_connect()->table('learning_item_prerequisites')->insert([
                'learning_item_id' => $itemId,
                'prerequisite_item_id' => $prerequisiteId,
                'created_at' => self::now(),
            ]);
        }
    }

    /** Records progress on an item for a student (completed by default). */
    public static function progress(int $itemId, User $student, string $status = 'completed', int $percent = 100): void
    {
        db_connect()->table('learning_completions')->insert([
            'learning_item_id' => $itemId,
            'student_user_id' => $student->id,
            'status' => $status,
            'progress_percent' => $percent,
            'completed_at' => $status === 'completed' ? self::now() : null,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ]);
    }

    /**
     * A learning resource attached to an item: a link (pass $url) or an
     * uploaded file (pass $fileId, from file()).
     */
    public static function resource(int $itemId, string $type, ?string $url, ?int $fileId = null): void
    {
        db_connect()->table('learning_resources')->insert([
            'learning_item_id' => $itemId,
            'file_id' => $fileId,
            'title' => 'Resource',
            'resource_type' => $type,
            'resource_url' => $url,
        ]);
    }

    /** @var list<string> disk paths created by file(), removed by removeCreatedFiles() */
    private static array $createdFiles = [];

    /**
     * An uploaded file: a row in `files` plus a real file under
     * writable/uploads/tests/, so deletion tests can check both are removed.
     * Call removeCreatedFiles() in tearDown (the database rolls back by
     * itself, the disk does not).
     *
     * @return array{id: int, path: string}
     */
    public static function file(User $owner, string $content = 'test file'): array
    {
        $key = 'tests/' . uniqid('f', true) . '.txt';
        $path = WRITEPATH . 'uploads/' . $key;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $content);
        self::$createdFiles[] = $path;

        $db = db_connect();

        $db->table('files')->insert([
            'owner_user_id' => $owner->id,
            'original_filename' => 'test.txt',
            'stored_filename' => basename($key),
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'storage_key' => $key,
            'status' => 'ready',
            'uploaded_at' => self::now(),
        ]);

        return ['id' => (int) $db->insertID(), 'path' => $path];
    }

    public static function removeCreatedFiles(): void
    {
        foreach (self::$createdFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$createdFiles = [];
    }

    /**
     * A quiz or assignment for an offering, optionally tied to a learning
     * item (as Modules creates them).
     *
     * @return int assessment id
     */
    public static function assessment(int $offeringId, User $creator, ?int $moduleId = null, ?int $itemId = null, string $type = 'Quiz'): int
    {
        $db = db_connect();

        $typeRow = $db->table('assessment_types')->where('name', $type)->get()->getRowArray();

        if ($typeRow === null) {
            $db->table('assessment_types')->insert(['name' => $type, 'created_at' => self::now()]);
            $typeId = (int) $db->insertID();
        } else {
            $typeId = (int) $typeRow['id'];
        }

        $courseId = (int) $db->table('course_offerings')->where('id', $offeringId)->get()->getRowArray()['course_id'];

        $db->table('assessments')->insert([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'learning_item_id' => $itemId,
            'assessment_type_id' => $typeId,
            'title' => $type . ' ' . self::next(),
            'available_from' => '2026-09-01 08:00:00',
            'due_date' => '2026-12-01 23:59:00',
            'created_by' => $creator->id,
            'created_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }

    /** A discussion thread, optionally backing a discussion learning item. */
    public static function thread(int $offeringId, User $author, ?int $itemId = null): int
    {
        $db = db_connect();

        $db->table('discussion_threads')->insert([
            'offering_id' => $offeringId,
            'learning_item_id' => $itemId,
            'author_id' => $author->id,
            'title' => 'Thread ' . self::next(),
            'body' => '',
            'last_activity_at' => self::now(),
        ]);

        return (int) $db->insertID();
    }
}

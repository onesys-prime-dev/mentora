<?php

declare(strict_types=1);

namespace App\Services\Sis;

use App\Models\Academic\InstitutionModel;
use App\Models\Users\UserProfileModel;
use App\Services\Courses\CourseRoleConflicts;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\ClassScheduleService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use App\Services\Flags\FeatureFlags;
use App\Services\Notifications\NotificationTriggers;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\UsernameGenerator;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use App\Services\Subscriptions\EffectiveStatus;

/**
 * The inbound sync (SA10): people, courses and enrolments from the SIS into
 * one institution. The SIS is the source of truth, so what it says replaces
 * what Mentora holds for the same record.
 *
 * What a run guarantees:
 *  - One database transaction. If anything unexpected goes wrong the whole
 *    run is rolled back and recorded as failed: no partial data.
 *  - A file that cannot be read at all (bad columns, bad signature) fails the
 *    run before anything is written.
 *  - A single bad row (invalid, a conflict, no seat left, an unknown course)
 *    is counted as failed with its reason and the rest still import.
 *  - A dry run does exactly the work of a real one, inside the same
 *    transaction, and then rolls it back: its counts are what a real run would
 *    produce and nothing is written.
 *  - A first real run needs an earlier successful run (a preview counts).
 *
 * Records are matched through `sis_links` (SIS id -> Mentora id). A person
 * with no link is matched by email, but only an account that already belongs
 * to this institution with the same role; any other clash is a failed row.
 * Things the SIS no longer lists are left alone, never deleted. People get no
 * password: they are given a link by an administrator (Users page).
 *
 * Enrolments go into the institution's active term (the latest one, if
 * several), in the section the SIS names ("A" when it names none).
 *
 * Staff assignments (staff_assignments.csv) come last, since they refer to
 * people, courses and the active term. They go through the same services
 * the screens use (DeanAssignmentService, ChairAssignmentService,
 * OfferingService), so every rule of those holds. The SIS wins: what it
 * lists replaces what is there (a dean it names replaces the department's
 * current one). What it stops listing is ended, but only what the SIS itself
 * supplied (sis_staff_assignments), counted as "ended" and not as an update:
 * an assignment made by hand is never touched, and nothing is ended when the file had rows that could not be
 * read, because what is "no longer listed" cannot then be known. Instructors
 * are only ended in the active term; past terms stay as they were. A section
 * the SIS says someone teaches is created in the active term if it is not
 * there yet, as enrolments do.
 *
 * Class times (class_schedules.csv) come after them (D9). A section the file
 * lists gets exactly its rows, through ClassScheduleService, and the SIS then
 * owns it (sis_class_schedules): locked on the Class times page while the
 * connection is enabled. A section of the active term the SIS had sent and
 * no longer lists loses those times and is handed back ("removed"). A section
 * it never sent is untouched, and without the file nothing changes. Counts
 * are per section; a failed row fails alone and the section keeps its other
 * rows. Clashes are warnings: listed with the issues, never a failure.
 *
 * Not safe to call twice at once on one instance (it keeps run counters).
 */
class SisImporter
{
    /** Issues kept with a run; the counts always cover every row. */
    public const MAX_ISSUES = 200;

    private const ROLE_OF = ['students' => 'student', 'instructors' => 'instructor'];

    private int $institutionId = 0;

    private DateTimeImmutable $now;

    /** @var array<string, array{created: int, updated: int, skipped: int, failed: int, ended?: int, removed?: int}> */
    private array $counts = [];

    /** @var list<array{dataset: string, item: string, reason: string}> */
    private array $issues = [];

    private ?int $termId = null;

    private bool $termLooked = false;

    private ?User $actor = null;

    public function __construct(
        private readonly ?SisConnectionService $connections = null,
        private readonly ?SisRunLog $runs = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException when a run is not allowed right now (nothing is recorded)
     */
    public function run(int $institutionId, ?User $actor, bool $dryRun, string $trigger = SisRunLog::MANUAL, ?DateTimeImmutable $now = null): SisImportResult
    {
        $this->now           = $now ?? new DateTimeImmutable();
        $this->institutionId = $institutionId;
        $this->counts        = [];
        $this->issues        = [];
        $this->termId        = null;
        $this->termLooked    = false;
        $this->actor         = $actor;

        $institution = (new InstitutionModel())->find($institutionId) ?? throw new InvalidArgumentException('That institution does not exist.');
        $connections = $this->connections ?? new SisConnectionService();
        $runs        = $this->runs ?? new SisRunLog();
        $view        = $connections->view($institutionId);

        if (! $view['flagOn']) {
            throw new InvalidArgumentException('The "' . SisConnectionService::FLAG . '" feature is off for this institution.');
        }

        if (! $view['configured']) {
            throw new InvalidArgumentException('Save the connection settings first.');
        }

        if (! $dryRun) {
            if (! $view['enabled']) {
                throw new InvalidArgumentException('The connection is not enabled.');
            }

            // By its dates as well, so a lapsed subscription stops syncing even before the nightly job runs.
            if (EffectiveStatus::of($institutionId, $this->now) !== InstitutionModel::ACTIVE) {
                throw new InvalidArgumentException('Only an active institution can be synced.');
            }

            if (! $this->hasSucceededBefore($institutionId)) {
                throw new InvalidArgumentException('Run a preview (dry run) first, and check it, before the first real import.');
            }
        }

        $runId = $runs->start($institutionId, SisRunLog::INBOUND, $trigger, $actor === null ? null : (int) $actor->id, $dryRun, $this->now);

        try {
            $connector = $connections->connector($institutionId);
            $batches   = [];

            foreach (SisCsvParser::datasets() as $dataset) {
                if ($connector->has($dataset)) {
                    $batches[$dataset] = $connector->read($dataset);
                }
            }

            if ($batches === []) {
                throw new SisException('The SIS has not provided any of: ' . implode(', ', array_map(static fn (string $d): string => "{$d}.csv", SisCsvParser::datasets())) . '.');
            }
        } catch (SisException $e) {
            return $this->failed($runs, $runId, $dryRun, $institution, $e->getMessage());
        }

        $db = db_connect();
        $db->transBegin();

        try {
            foreach ($batches as $dataset => $batch) {
                $this->counts[$dataset] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0]
                    + ($dataset === 'staff_assignments' ? ['ended' => 0] : [])
                    + ($dataset === 'class_schedules' ? ['removed' => 0] : []);

                foreach ($batch->rejected as $rejected) {
                    $this->fail($dataset, "row {$rejected['row']}", $rejected['reason']);
                }
            }

            foreach (['students', 'instructors'] as $dataset) {
                if (isset($batches[$dataset])) {
                    $this->people($dataset, $batches[$dataset]);
                }
            }

            if (isset($batches['courses'])) {
                $this->courses($batches['courses']);
            }

            if (isset($batches['enrollments'])) {
                $this->enrollments($batches['enrollments']);
            }

            if (isset($batches['staff_assignments'])) {
                $this->staffAssignments($batches['staff_assignments']);
            }

            if (isset($batches['class_schedules'])) {
                $this->classSchedules($batches['class_schedules']);
            }

            $result = new SisImportResult($runId, true, $dryRun, $this->counts, $this->issues, $this->summary($dryRun));

            if (! $dryRun) {
                service('auditLogger')->log('sis.import_run', $actor, [
                    'target_type'    => 'institution',
                    'target_id'      => $institutionId,
                    'institution_id' => $institutionId,
                    'new'            => $result->totals() + ['trigger' => $trigger, 'run_id' => $runId],
                ]);

                $db->transCommit();
            } else {
                $db->transRollback();
            }
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'SIS import for institution ' . $institutionId . ' failed: ' . $e->getMessage());

            return $this->failed($runs, $runId, $dryRun, $institution, 'An unexpected error stopped the import; nothing was changed. The details are in the server log.');
        }

        $runs->finish($runId, true, [
            'created' => $result->totals()['created'],
            'updated' => $result->totals()['updated'],
            'skipped' => $result->totals()['skipped'],
            'failed'  => $result->totals()['failed'],
        ], $result->message, $this->now, $this->details($result));

        if ($dryRun) {
            service('auditLogger')->log('sis.import_previewed', $actor, [
                'target_type'    => 'institution',
                'target_id'      => $institutionId,
                'institution_id' => $institutionId,
                'new'            => $result->totals() + ['run_id' => $runId],
            ]);
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    private function people(string $dataset, SisBatch $batch): void
    {
        $role = self::ROLE_OF[$dataset];

        foreach ($batch->records as $i => $record) {
            $mapped = SisMapper::person($record);
            $item   = $mapped['external_id'] . ' (row ' . ($batch->rows[$i] ?? '?') . ')';

            $this->person($dataset, $item, $mapped, $role);
        }
    }

    /**
     * @param array{external_id: string, first_name: string, last_name: string, email: string, student_number: ?string} $m
     */
    private function person(string $dataset, string $item, array $m, string $role): void
    {
        $db     = db_connect();
        $userId = $this->linked('person', $m['external_id']);

        if ($userId !== null && ! $this->isMember($userId)) {
            $this->unlink('person', $m['external_id']); // the account is gone; start again from the email
            $userId = null;
        }

        $owner = $db->table('auth_identities')->select('user_id')->where('type', 'email_password')->where('secret', $m['email'])->get()->getRowArray();

        if ($userId === null && $owner !== null) {
            $other = (int) $owner['user_id'];

            if (! $this->isMember($other) || ! $this->hasRole($other, $role) || $this->hasRole($other, 'admin')) {
                $this->fail($dataset, $item, "An account with {$m['email']} already exists and is not a {$role} of this institution.");

                return;
            }

            if ($this->linkedExternal($other) !== null) {
                $this->fail($dataset, $item, "{$m['email']} already belongs to another SIS record.");

                return;
            }

            $this->link('person', $m['external_id'], $other);
            $userId = $other;
        }

        if ($userId === null) {
            $this->createPerson($dataset, $item, $m, $role);

            return;
        }

        $profile = $db->table('user_profiles')->where('user_id', $userId)->get()->getRowArray() ?? [];
        $current = $profile + ['email' => (string) ($db->table('auth_identities')->select('secret')->where('user_id', $userId)->where('type', 'email_password')->get()->getRowArray()['secret'] ?? '')];
        $changes = SisMapper::changes($current, $m, ['first_name', 'last_name', 'email', 'student_number']);

        if ($changes === []) {
            $this->counts[$dataset]['skipped']++;

            return;
        }

        if (array_key_exists('student_number', $changes) && $this->numberTaken($m['student_number'], $userId)) {
            $this->fail($dataset, $item, "Student number {$m['student_number']} is already used by another student of this institution.");

            return;
        }

        if (isset($changes['email'])) {
            if ($owner !== null && (int) $owner['user_id'] !== $userId) {
                $this->fail($dataset, $item, "The email {$m['email']} belongs to another account.");

                return;
            }

            $db->table('auth_identities')->where('user_id', $userId)->where('type', 'email_password')->update(['secret' => $changes['email'], 'updated_at' => $this->stamp()]);
            unset($changes['email']);
        }

        if ($changes !== []) {
            if (array_key_exists('student_number', $changes)) {
                $changes['student_number_institution_id'] = $this->issuer($m['student_number']);
            }

            $profiles = new UserProfileModel();
            $saved    = $profile === [] ? $profiles->insert(['user_id' => $userId, 'first_name' => $m['first_name'], 'last_name' => $m['last_name'], 'student_number' => $m['student_number'], 'student_number_institution_id' => $this->issuer($m['student_number'])]) : $profiles->update($userId, $changes);

            if ($saved === false) {
                throw new RuntimeException('The profile could not be saved: ' . implode(' ', $profiles->errors()));
            }
        }

        $this->counts[$dataset]['updated']++;
    }

    /**
     * @param array{external_id: string, first_name: string, last_name: string, email: string, student_number: ?string} $m
     */
    private function createPerson(string $dataset, string $item, array $m, string $role): void
    {
        if ($this->numberTaken($m['student_number'], null)) {
            $this->fail($dataset, $item, "Student number {$m['student_number']} is already used by another student of this institution.");

            return;
        }

        if ($role === 'student' && ($blocked = (new PlanLimits())->seatBlock($this->institutionId)) !== null) {
            $this->fail($dataset, $item, $blocked);

            return;
        }

        $users = model(UserModel::class);

        $saved = $users->save(new User([
            'username' => (new UsernameGenerator())->from($m['first_name'], $m['last_name']),
            'email'    => $m['email'],
            'password' => bin2hex(random_bytes(24)),
            'active'   => true,
        ]));

        if (! $saved) {
            throw new RuntimeException('The account could not be created: ' . implode(' ', $users->errors()));
        }

        $user = $users->findById($users->getInsertID());
        $user->addGroup($role);

        $profiles = new UserProfileModel();

        if ($profiles->insert(['user_id' => $user->id, 'first_name' => $m['first_name'], 'last_name' => $m['last_name'], 'student_number' => $m['student_number'], 'student_number_institution_id' => $this->issuer($m['student_number'])]) === false) {
            throw new RuntimeException('The profile could not be saved: ' . implode(' ', $profiles->errors()));
        }

        $stamp = $this->stamp();

        if (! db_connect()->table('institution_users')->insert(['institution_id' => $this->institutionId, 'user_id' => $user->id, 'created_at' => $stamp, 'updated_at' => $stamp])) {
            throw new RuntimeException('The account could not be linked to the institution.');
        }

        $this->link('person', $m['external_id'], (int) $user->id);
        $this->counts[$dataset]['created']++;
    }

    /*
    |--------------------------------------------------------------------------
    | Courses
    |--------------------------------------------------------------------------
    */

    private function courses(SisBatch $batch): void
    {
        $db = db_connect();

        foreach ($batch->records as $i => $record) {
            $m    = SisMapper::course($record);
            $item = $m['external_id'] . ' (row ' . ($batch->rows[$i] ?? '?') . ')';

            $courseId = $this->linked('course', $m['external_id']);

            if ($courseId !== null && $this->courseRow($courseId) === null) {
                $this->unlink('course', $m['external_id']);
                $courseId = null;
            }

            if ($courseId === null) {
                $programId = $this->programFor($m['program_code']);

                if ($programId === null) {
                    $this->fail('courses', $item, $m['program_code'] === null ? 'program_code is required for a course Mentora has not seen yet.' : "program_code \"{$m['program_code']}\" is not a program of this institution.");

                    continue;
                }

                $existing = $db->table('courses')->where('program_id', $programId)->where('course_code', $m['course_code'])->get()->getRowArray();

                if ($existing !== null) {
                    if ($this->linkedExternalOf('course', (int) $existing['id']) !== null) {
                        $this->fail('courses', $item, "The course code {$m['course_code']} already belongs to another SIS record.");

                        continue;
                    }

                    $this->link('course', $m['external_id'], (int) $existing['id']);
                    $courseId = (int) $existing['id'];
                } else {
                    $stamp = $this->stamp();

                    if (! $db->table('courses')->insert([
                        'program_id'   => $programId,
                        'course_code'  => $m['course_code'],
                        'course_title' => $m['course_title'],
                        'units'        => $m['units'] ?? SisMapper::DEFAULT_UNITS,
                        'status'       => 'active',
                        'created_at'   => $stamp,
                        'updated_at'   => $stamp,
                    ])) {
                        throw new RuntimeException('The course could not be created.');
                    }

                    $this->link('course', $m['external_id'], (int) $db->insertID());
                    $this->counts['courses']['created']++;

                    continue;
                }
            }

            $course  = $this->courseRow($courseId);
            $changes = SisMapper::changes($course ?? [], $m, ['course_code', 'course_title', 'units']);

            if ($changes === []) {
                $this->counts['courses']['skipped']++;

                continue;
            }

            if (isset($changes['course_code']) && $db->table('courses')->where('program_id', $course['program_id'])->where('course_code', $changes['course_code'])->where('id !=', $courseId)->countAllResults() > 0) {
                $this->fail('courses', $item, "Another course in the program already has the code {$changes['course_code']}.");

                continue;
            }

            $db->table('courses')->where('id', $courseId)->update($changes + ['updated_at' => $this->stamp()]);
            $this->counts['courses']['updated']++;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Enrolments
    |--------------------------------------------------------------------------
    */

    private function enrollments(SisBatch $batch): void
    {
        $db = db_connect();

        foreach ($batch->records as $i => $record) {
            $m    = SisMapper::enrollment($record);
            $item = "{$m['student_external_id']} in {$m['course_external_id']} (row " . ($batch->rows[$i] ?? '?') . ')';

            $courseId  = $this->linked('course', $m['course_external_id']);
            $studentId = $this->linked('person', $m['student_external_id']);

            if ($courseId === null || $this->courseRow($courseId) === null) {
                $this->fail('enrollments', $item, "The course {$m['course_external_id']} is not known; list it in courses.csv.");

                continue;
            }

            if ($studentId === null || ! $this->isMember($studentId) || ! $this->hasRole($studentId, 'student')) {
                $this->fail('enrollments', $item, "{$m['student_external_id']} is not a known student; list them in students.csv.");

                continue;
            }

            $termId = $this->activeTerm();

            if ($termId === null) {
                $this->fail('enrollments', $item, 'The institution has no active term to enrol into.');

                continue;
            }

            $offeringId = $this->offeringFor($courseId, $termId, $m['section']);

            $existing = $db->table('enrollments')->where(['offering_id' => $offeringId, 'student_user_id' => $studentId])->get()->getRowArray();

            // Not a student of a course they teach, chair or lead (9.2–9.5); the row fails alone.
            if (($clash = (new CourseRoleConflicts())->forExistingEnrolment($studentId, $offeringId, $m['status'])) !== null) {
                $this->fail('enrollments', $item, "{$m['student_external_id']} {$clash}.");

                continue;
            }

            if ($existing === null) {
                $stamp = $this->stamp();

                if (! $db->table('enrollments')->insert(['offering_id' => $offeringId, 'student_user_id' => $studentId, 'status' => $m['status'], 'enrolled_at' => $stamp, 'updated_at' => $stamp])) {
                    throw new RuntimeException('The enrolment could not be created.');
                }

                $this->counts['enrollments']['created']++;
            } elseif ($existing['status'] === $m['status']) {
                $this->counts['enrollments']['skipped']++;
            } else {
                $db->table('enrollments')->where('id', $existing['id'])->update(['status' => $m['status'], 'updated_at' => $this->stamp()]);
                $this->counts['enrollments']['updated']++;
            }
        }
    }

    /** The section of a course in a term, created when it is not there yet. */
    private function offeringFor(int $courseId, int $termId, string $section): int
    {
        $db       = db_connect();
        $offering = $db->table('course_offerings')->where(['course_id' => $courseId, 'term_id' => $termId, 'section_code' => $section])->get()->getRowArray();

        if ($offering !== null) {
            return (int) $offering['id'];
        }

        $stamp = $this->stamp();

        if (! $db->table('course_offerings')->insert(['course_id' => $courseId, 'term_id' => $termId, 'section_code' => $section, 'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp])) {
            throw new RuntimeException('The course offering could not be created.');
        }

        return (int) $db->insertID();
    }

    /*
    |--------------------------------------------------------------------------
    | Staff assignments
    |--------------------------------------------------------------------------
    */

    private function staffAssignments(SisBatch $batch): void
    {
        $tracker = new SisStaffAssignments();
        $dataset = 'staff_assignments';

        /** @var array<string, true> $listed every row the SIS lists whose person and target were found */
        $listed = [];
        /** @var array<int, int> $deanOfDepartment department => the person this file made its dean */
        $deanOfDepartment = [];
        /** @var array<int, int> $deanedBy person => the department this file made them dean of */
        $deanedBy = [];
        /** @var array<int, int> $chairOfSub sub-department => the person this file made its chairperson */
        $chairOfSub = [];

        foreach ($batch->records as $i => $record) {
            $m    = SisMapper::staffAssignment($record);
            $item = "{$m['person_external_id']} as {$m['role']} (row " . ($batch->rows[$i] ?? '?') . ')';

            $personId = $this->linked('person', $m['person_external_id']);

            if ($personId === null || ! $this->isMember($personId)) {
                $this->fail($dataset, $item, "{$m['person_external_id']} is not a known person; list them in students.csv or instructors.csv.");

                continue;
            }

            // Before anything is created for the row (an instructor's section), so a refused row leaves nothing behind.
            if ($m['role'] === SisStaffAssignments::INSTRUCTOR && ! $this->hasRole($personId, 'instructor')) {
                $this->fail($dataset, $item, 'The person does not hold the instructor role. List them in instructors.csv.');

                continue;
            }

            try {
                $targetId = $this->staffTarget($m, $item);

                if ($targetId === null) {
                    continue;
                }

                $key = "{$m['role']}|{$personId}|{$targetId}";

                if (isset($listed[$key])) {
                    $this->fail($dataset, $item, 'The same assignment is listed more than once.');

                    continue;
                }

                $listed[$key] = true;

                if ($m['role'] === SisStaffAssignments::DEAN) {
                    if (isset($deanOfDepartment[$targetId]) && $deanOfDepartment[$targetId] !== $personId) {
                        $this->fail($dataset, $item, 'The department already has a dean in this file; a department has one dean.');

                        continue;
                    }

                    if (isset($deanedBy[$personId]) && $deanedBy[$personId] !== $targetId) {
                        $this->fail($dataset, $item, 'The person is already the dean of another department in this file; a person is dean of one department.');

                        continue;
                    }

                    $deanOfDepartment[$targetId] = $personId;
                    $deanedBy[$personId]         = $targetId;
                }

                if ($m['role'] === SisStaffAssignments::CHAIRPERSON) {
                    if (isset($chairOfSub[$targetId]) && $chairOfSub[$targetId] !== $personId) {
                        $this->fail($dataset, $item, 'The sub-department already has a chairperson in this file; a sub-department has one chairperson.');

                        continue;
                    }

                    $chairOfSub[$targetId] = $personId;
                }

                $this->applyStaff($tracker, $dataset, $item, $m['role'], $personId, $targetId);
            } catch (StructureException $e) {
                $this->fail($dataset, $item, $e->getMessage());
            }
        }

        // What the SIS no longer lists ends, but only if the whole file could be read: a row that could not be
        // read might have been the one that kept an assignment listed.
        if ($batch->rejected !== []) {
            $this->issues[] = ['dataset' => $dataset, 'item' => 'the whole file', 'reason' => 'Nothing was ended, because ' . count($batch->rejected) . ' row(s) of this file could not be read, so what the SIS no longer lists cannot be known.'];

            return;
        }

        $this->endUnlistedStaff($tracker, $dataset, $listed);
    }

    /*
    |--------------------------------------------------------------------------
    | Class times
    |--------------------------------------------------------------------------
    */

    private function classSchedules(SisBatch $batch): void
    {
        $dataset  = 'class_schedules';
        $schedule = new ClassScheduleService();
        $week     = [];  // offering id => its rows from the file
        $listed   = [];  // every section the file names, even by a row that failed
        $labels   = [];

        foreach ($batch->records as $i => $record) {
            $m    = SisMapper::classSchedule($record);
            $item = "{$m['course_external_id']} {$m['section']}, {$record['day_of_week']} {$m['start']} (row " . ($batch->rows[$i] ?? '?') . ')';

            $courseId = $this->linked('course', $m['course_external_id']);

            if ($courseId === null || ($course = $this->courseRow($courseId)) === null) {
                $this->fail($dataset, $item, "The course {$m['course_external_id']} is not known; list it in courses.csv.");

                continue;
            }

            $termId = $this->activeTerm();

            if ($termId === null) {
                $this->fail($dataset, $item, 'The institution has no active term to put the section in.');

                continue;
            }

            $offeringId          = $this->offeringFor($courseId, $termId, $m['section']);
            $listed[$offeringId] = true;
            $labels[$offeringId] = $course['course_code'] . ' ' . $m['section'];

            if ($m['day'] === null) {
                $this->fail($dataset, $item, "day_of_week \"{$record['day_of_week']}\" is not a day; use mon to sun, or the day's name.");

                continue;
            }

            try {
                $row = ClassScheduleService::checked(['day' => $m['day'], 'start' => $m['start'], 'end' => $m['end'], 'room' => $m['room'], 'mode' => $m['mode']]);
            } catch (StructureException $e) {
                $this->fail($dataset, $item, ucfirst($e->getMessage()));

                continue;
            }

            foreach ($week[$offeringId] ?? [] as $other) {
                if ($other['day'] === $row['day'] && $other['start'] === $row['start']) {
                    $this->fail($dataset, $item, 'The section already meets on ' . ClassScheduleService::DAYS[$row['day']] . " at {$row['start']}.");

                    continue 2;
                }
            }

            $week[$offeringId][] = $row;
        }

        foreach ($week as $offeringId => $rows) {
            $before = $schedule->forOffering($this->institutionId, $offeringId)['rows'];

            if ($this->sameWeek($before, $rows)) {
                $this->counts[$dataset]['skipped']++;
            } else {
                $saved = $schedule->save($this->institutionId, $offeringId, $rows, $this->actor, true);
                $this->counts[$dataset][$before === [] ? 'created' : 'updated']++;

                foreach ($saved['warnings'] as $warning) {
                    $this->issues[] = ['dataset' => $dataset, 'item' => $labels[$offeringId], 'reason' => "Imported, with a clash: {$warning}"];
                }
            }

            $this->trackSchedule($offeringId);
        }

        // As with staff: what the SIS no longer lists is cleared only when the whole file could be read.
        if ($batch->rejected !== []) {
            $this->issues[] = ['dataset' => $dataset, 'item' => 'the whole file', 'reason' => 'No class times were removed, because ' . count($batch->rejected) . ' row(s) of this file could not be read, so what the SIS no longer lists cannot be known.'];

            return;
        }

        $termId = $this->activeTerm();

        if ($termId === null) {
            return;
        }

        $owned = db_connect()->table('sis_class_schedules s')->select('s.offering_id')
            ->join('course_offerings o', 'o.id = s.offering_id')
            ->where(['s.institution_id' => $this->institutionId, 'o.term_id' => $termId])
            ->get()->getResultArray();

        foreach ($owned as $o) {
            $offeringId = (int) $o['offering_id'];

            if (isset($listed[$offeringId])) {
                continue;
            }

            $schedule->save($this->institutionId, $offeringId, [], $this->actor, true);
            db_connect()->table('sis_class_schedules')->where('offering_id', $offeringId)->delete();
            $this->counts[$dataset]['removed']++;
        }
    }

    /**
     * Whether two weeks hold the same meetings, in any order.
     *
     * @param list<array{day: string, start: string, end: string, room: ?string, mode: string}> $a
     * @param list<array{day: string, start: string, end: string, room: ?string, mode: string}> $b
     */
    private function sameWeek(array $a, array $b): bool
    {
        $key = static function (array $rows): array {
            $keys = array_map(static fn (array $r): string => implode('|', [$r['day'], $r['start'], $r['end'], (string) $r['room'], $r['mode']]), $rows);
            sort($keys);

            return $keys;
        };

        return $key($a) === $key($b);
    }

    private function trackSchedule(int $offeringId): void
    {
        $db = db_connect();

        if ($db->table('sis_class_schedules')->where('offering_id', $offeringId)->countAllResults() > 0) {
            $db->table('sis_class_schedules')->where('offering_id', $offeringId)->update(['updated_at' => $this->stamp()]);

            return;
        }

        $db->table('sis_class_schedules')->insert(['institution_id' => $this->institutionId, 'offering_id' => $offeringId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
    }

    /**
     * The department, sub-department or offering the row names (the offering is created when it is not there yet),
     * or null after failing the row with the reason.
     *
     * @param array{role: string, person_external_id: string, target: string, section: string} $m
     */
    private function staffTarget(array $m, string $item): ?int
    {
        $dataset = 'staff_assignments';
        $db      = db_connect();

        if ($m['role'] === SisStaffAssignments::DEAN) {
            $row = $db->table('departments')->select('id')->where(['institution_id' => $this->institutionId, 'code' => $m['target']])->get()->getRowArray();

            if ($row === null) {
                $this->fail($dataset, $item, "department_code \"{$m['target']}\" is not a department of this institution.");

                return null;
            }

            return (int) $row['id'];
        }

        if ($m['role'] === SisStaffAssignments::CHAIRPERSON) {
            $rows = $db->table('sub_departments sd')
                ->select('sd.id')
                ->join('departments d', 'd.id = sd.department_id')
                ->where(['d.institution_id' => $this->institutionId, 'sd.code' => $m['target']])
                ->get()->getResultArray();

            if ($rows === []) {
                $this->fail($dataset, $item, "sub_department_code \"{$m['target']}\" is not a sub-department of this institution.");

                return null;
            }

            if (count($rows) > 1) {
                $this->fail($dataset, $item, "sub_department_code \"{$m['target']}\" matches more than one sub-department of this institution, so it cannot say which one.");

                return null;
            }

            return (int) $rows[0]['id'];
        }

        $courseId = $this->linked('course', $m['target']);

        if ($courseId === null || $this->courseRow($courseId) === null) {
            $this->fail($dataset, $item, "The course {$m['target']} is not known; list it in courses.csv.");

            return null;
        }

        $termId = $this->activeTerm();

        if ($termId === null) {
            $this->fail($dataset, $item, 'The institution has no active term to put the section in.');

            return null;
        }

        return $this->offeringFor($courseId, $termId, $m['section']);
    }

    /**
     * Makes one listed assignment true, through the service the screens use, and records that the SIS supplies it.
     *
     * @throws StructureException when a rule of that service refuses it
     */
    private function applyStaff(SisStaffAssignments $tracker, string $dataset, string $item, string $role, int $personId, int $targetId): void
    {
        $stamp     = $this->stamp();
        $tracked   = $tracker->find($this->institutionId, $role, $personId, $targetId);
        $trackedId = $tracked === null ? null : (int) $tracked['local_id'];

        // Already in force: nothing to do but to remember that the SIS supplies it.
        $activeId = match ($role) {
            SisStaffAssignments::DEAN        => $this->activeDeanAssignment($personId, $targetId),
            SisStaffAssignments::CHAIRPERSON => $this->activeChairAssignment($personId, $targetId),
            default                          => $this->instructorAssignment($personId, $targetId),
        };

        if ($activeId !== null) {
            $tracker->track($this->institutionId, $role, $personId, $targetId, $activeId, $stamp);
            $this->counts[$dataset][$trackedId === $activeId ? 'skipped' : 'updated']++;

            return;
        }

        $replaces = false;

        if ($role === SisStaffAssignments::DEAN) {
            $service  = new DeanAssignmentService();
            $replaces = $service->currentDeanOfDepartment($targetId) !== null || $service->departmentOfDean($personId) !== null;
            $localId  = $service->assign($this->institutionId, $targetId, $personId, $this->actor, true);
        } elseif ($role === SisStaffAssignments::CHAIRPERSON) {
            if (! $this->hasRole($personId, 'chairperson')) {
                throw new StructureException('The person does not hold the chairperson role. An administrator gives them the role first (People).');
            }

            // The SIS owns its chairpersons: what it lists replaces the one there (16.0).
            $localId = (new ChairAssignmentService())->assign($this->institutionId, $targetId, $personId, $this->actor, true, true);
        } else {
            $localId = (new OfferingService())->assignInstructor($this->institutionId, $targetId, $personId, $this->actor);
        }

        $tracker->track($this->institutionId, $role, $personId, $targetId, $localId, $stamp);
        $this->counts[$dataset][$replaces || $tracked !== null ? 'updated' : 'created']++;
    }

    /**
     * Ends what the SIS supplied before and no longer lists, and forgets what is already over.
     *
     * @param array<string, true> $listed
     */
    private function endUnlistedStaff(SisStaffAssignments $tracker, string $dataset, array $listed): void
    {
        $db     = db_connect();
        $termId = $this->activeTerm();

        foreach ($tracker->listFor($this->institutionId) as $row) {
            $role    = (string) $row['role'];
            $localId = (int) $row['local_id'];

            if (isset($listed["{$role}|{$row['person_user_id']}|{$row['target_id']}"])) {
                continue;
            }

            if ($role === SisStaffAssignments::DEAN) {
                if ($db->table('dean_assignments')->where(['id' => $localId, 'status' => 'active'])->countAllResults() > 0) {
                    (new DeanAssignmentService())->end($this->institutionId, $localId, $this->actor, true);
                    $this->counts[$dataset]['ended']++;
                }
            } elseif ($role === SisStaffAssignments::CHAIRPERSON) {
                if ($db->table('chairperson_assignments')->where(['id' => $localId, 'status' => 'active'])->countAllResults() > 0) {
                    (new ChairAssignmentService())->revoke($this->institutionId, $localId, $this->actor, true);
                    $this->counts[$dataset]['ended']++;
                }
            } else {
                $assignment = $db->table('instructor_assignments ia')
                    ->select('o.term_id, o.status')
                    ->join('course_offerings o', 'o.id = ia.offering_id')
                    ->where('ia.id', $localId)
                    ->get()->getRowArray();

                // The file describes the current term. A section of an earlier term, or one already closed, stays as it was.
                if ($assignment !== null && ((int) $assignment['term_id'] !== $termId || $assignment['status'] !== 'active')) {
                    continue;
                }

                if ($assignment !== null) {
                    (new OfferingService())->unassignInstructor($this->institutionId, $localId, $this->actor, true);
                    $this->counts[$dataset]['ended']++;
                }
            }

            $tracker->forget((int) $row['id']);
        }
    }

    private function activeDeanAssignment(int $personId, int $departmentId): ?int
    {
        $row = db_connect()->table('dean_assignments')->select('id')->where(['dean_user_id' => $personId, 'department_id' => $departmentId, 'status' => 'active'])->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function activeChairAssignment(int $personId, int $subDepartmentId): ?int
    {
        $row = db_connect()->table('chairperson_assignments')->select('id')->where(['chairperson_user_id' => $personId, 'sub_department_id' => $subDepartmentId, 'status' => 'active'])->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function instructorAssignment(int $personId, int $offeringId): ?int
    {
        $row = db_connect()->table('instructor_assignments')->select('id')->where(['instructor_user_id' => $personId, 'offering_id' => $offeringId])->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups and bookkeeping
    |--------------------------------------------------------------------------
    */

    private function linked(string $entity, string $externalId): ?int
    {
        $row = db_connect()->table('sis_links')->where(['institution_id' => $this->institutionId, 'entity' => $entity, 'external_id' => $externalId])->get()->getRowArray();

        return $row === null ? null : (int) $row['local_id'];
    }

    private function linkedExternal(int $userId): ?string
    {
        return $this->linkedExternalOf('person', $userId);
    }

    private function linkedExternalOf(string $entity, int $localId): ?string
    {
        $row = db_connect()->table('sis_links')->where(['institution_id' => $this->institutionId, 'entity' => $entity, 'local_id' => $localId])->get()->getRowArray();

        return $row === null ? null : (string) $row['external_id'];
    }

    private function link(string $entity, string $externalId, int $localId): void
    {
        if (! db_connect()->table('sis_links')->insert(['institution_id' => $this->institutionId, 'entity' => $entity, 'external_id' => $externalId, 'local_id' => $localId, 'created_at' => $this->stamp()])) {
            throw new RuntimeException('The SIS link could not be saved.');
        }
    }

    private function unlink(string $entity, string $externalId): void
    {
        db_connect()->table('sis_links')->where(['institution_id' => $this->institutionId, 'entity' => $entity, 'external_id' => $externalId])->delete();
    }

    private function isMember(int $userId): bool
    {
        return db_connect()->table('institution_users')
            ->join('users', 'users.id = institution_users.user_id')
            ->where('institution_users.institution_id', $this->institutionId)
            ->where('institution_users.user_id', $userId)
            ->where('users.deleted_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    private function hasRole(int $userId, string $role): bool
    {
        return db_connect()->table('auth_groups_users')->where(['user_id' => $userId, 'group' => $role])->countAllResults() > 0;
    }

    /** @return array<string, mixed>|null a course of this institution */
    private function courseRow(int $courseId): ?array
    {
        return db_connect()->table('courses')
            ->select('courses.*')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $this->institutionId)
            ->where('courses.id', $courseId)
            ->get()->getRowArray();
    }

    private function programFor(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $row = db_connect()->table('programs')
            ->select('programs.id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $this->institutionId)
            ->where('programs.code', $code)
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function activeTerm(): ?int
    {
        if (! $this->termLooked) {
            $row = db_connect()->table('academic_terms')->select('id')->where(['institution_id' => $this->institutionId, 'status' => 'active'])->orderBy('start_date', 'desc')->get()->getRowArray();

            $this->termId     = $row === null ? null : (int) $row['id'];
            $this->termLooked = true;
        }

        return $this->termId;
    }

    private function hasSucceededBefore(int $institutionId): bool
    {
        return db_connect()->table('sis_runs')
            ->where(['institution_id' => $institutionId, 'direction' => 'inbound', 'status' => 'succeeded'])
            ->where('trigger !=', 'test')
            ->countAllResults() > 0;
    }

    private function fail(string $dataset, string $item, string $reason): void
    {
        $this->counts[$dataset]['failed']++;
        $this->issues[] = ['dataset' => $dataset, 'item' => $item, 'reason' => $reason];
    }

    /** A student number is unique within the institution that issued it (QA MISS-DB-01); the database refuses a duplicate too. */
    private function numberTaken(?string $number, ?int $userId): bool
    {
        if ($number === null) {
            return false;
        }

        $query = db_connect()->table('user_profiles')->where('student_number_institution_id', $this->institutionId)->where('student_number', $number);

        if ($userId !== null) {
            $query->where('user_id !=', $userId);
        }

        return $query->countAllResults() > 0;
    }

    /** The institution recorded beside a student number: this one, or none when there is no number. */
    private function issuer(?string $number): ?int
    {
        return $number === null ? null : $this->institutionId;
    }

    private function stamp(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    private function summary(bool $dryRun): string
    {
        $result = new SisImportResult(0, true, $dryRun, $this->counts, [], '');
        $totals = $result->totals();
        $ended  = $result->ended() > 0 ? ", ended {$result->ended()}" : '';
        $ended .= $result->removed() > 0 ? ", removed {$result->removed()}" : '';

        return ($dryRun ? 'Preview, nothing was written: ' : '') . "created {$totals['created']}, updated {$totals['updated']}, skipped {$totals['skipped']}{$ended}, failed {$totals['failed']}.";
    }

    private function details(SisImportResult $result): array
    {
        return [
            'datasets'  => $result->datasets,
            'issues'    => array_slice($result->issues, 0, self::MAX_ISSUES),
            'truncated' => count($result->issues) > self::MAX_ISSUES,
        ];
    }

    /**
     * @param array<string, mixed> $institution
     */
    private function failed(SisRunLog $runs, int $runId, bool $dryRun, array $institution, string $message): SisImportResult
    {
        $runs->finish($runId, false, [], $message, $this->now, ['datasets' => [], 'issues' => [], 'truncated' => false]);

        if (! $dryRun) {
            (new NotificationTriggers())->syncFailed((int) $institution['id'], (string) $institution['name'], $message, $runId, $this->now);
        }

        service('auditLogger')->log('sis.import_failed', null, [
            'actor_label'    => 'system',
            'target_type'    => 'institution',
            'target_id'      => (int) $institution['id'],
            'institution_id' => (int) $institution['id'],
            'new'            => ['dry_run' => $dryRun, 'run_id' => $runId, 'message' => $message],
        ]);

        return new SisImportResult($runId, false, $dryRun, [], [], $message);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\SisOwnership;
use CodeIgniter\Shield\Entities\User;

/**
 * A section's weekly class times (audit decisions 13.1, 13.3–13.5, U4).
 *
 * A week of rows: day, start, end, room and delivery mode, in the platform's
 * time zone (nothing about zones is stored). No one-off exceptions: a
 * cancelled class goes out as an announcement. Saving replaces the section's
 * week. A room or an instructor used twice at the same time in the same term
 * is a warning, never a refusal. Where the SIS sends a section's times, they
 * are its and locked here while the connection is enabled (13.2, D9).
 */
class ClassScheduleService
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public const MODES = ['onsite', 'online', 'hybrid'];

    public const SIS_LOCK = 'These class times come from your student information system. Change them there; the next sync brings them here.';

    /**
     * @return array{rows: list<array{day: string, start: string, end: string, room: ?string, mode: string}>, sisOwned: bool}
     */
    public function forOffering(int $institutionId, int $offeringId): array
    {
        InstitutionScope::forInstitution($institutionId)->require('offering', $offeringId);

        return ['rows' => $this->rowsOf($offeringId), 'sisOwned' => (new SisOwnership())->managesSchedule($offeringId)];
    }

    /**
     * Each section's week in a few words, for a list ("Mon 09:00–10:30 · Rm 204, Wed …"), in one query.
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, string> by offering; sections with no class times are left out
     */
    public function summaries(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $rows = db_connect()->table('course_schedules')->whereIn('offering_id', $offeringIds)
            ->orderBy("offering_id, FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'), start_time", '', false)
            ->get()->getResultArray();

        $parts = [];

        foreach ($rows as $r) {
            $parts[(int) $r['offering_id']][] = ucfirst((string) $r['day_of_week']) . ' ' . substr((string) $r['start_time'], 0, 5) . '–' . substr((string) $r['end_time'], 0, 5)
                . ($r['delivery_mode'] === 'online' ? ' · online' : ($r['room'] !== null && $r['room'] !== '' ? ' · ' . $r['room'] : ''));
        }

        return array_map(static fn (array $p): string => implode(', ', $p), $parts);
    }

    /**
     * Replaces the section's week with these rows (fully blank rows ignored).
     *
     * @param list<array<string, mixed>> $rows each with day, start, end, room, mode
     *
     * @return array{warnings: list<string>} the clashes, which do not stop the save
     *
     * @throws StructureException with the first problem, field `rows`
     */
    public function save(int $institutionId, int $offeringId, array $rows, ?User $actor = null, bool $ignoreSisLock = false): array
    {
        InstitutionScope::forInstitution($institutionId)->require('offering', $offeringId);

        $db = db_connect();

        if (! $ignoreSisLock) {
            if ((new SisOwnership())->managesSchedule($offeringId)) {
                throw new StructureException(self::SIS_LOCK);
            }

            // As every other change to an offering: only while it is active.
            if ($db->table('course_offerings')->where('id', $offeringId)->get()->getRow('status') !== 'active') {
                throw new StructureException('This section is closed, so its class times cannot be changed.');
            }
        }

        $week = $this->validated($rows);
        $old  = $this->rowsOf($offeringId);
        $now  = date('Y-m-d H:i:s');

        try {
            $db->transException(true)->transStart();
            $db->table('course_schedules')->where('offering_id', $offeringId)->delete();

            foreach ($week as $row) {
                $db->table('course_schedules')->insert([
                    'offering_id' => $offeringId, 'day_of_week' => $row['day'], 'start_time' => $row['start'] . ':00', 'end_time' => $row['end'] . ':00',
                    'room' => $row['room'], 'delivery_mode' => $row['mode'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Changed by hand (no lock held: no connection, or it is disabled), the times are the administrator's
            // now, so a later file that leaves the section out does not clear them (D9).
            if (! $ignoreSisLock) {
                $db->table('sis_class_schedules')->where('offering_id', $offeringId)->delete();
            }

            $db->transComplete();
        } finally {
            $db->transException(false);
        }

        service('auditLogger')->log('structure.offering_schedule_changed', $actor ?? auth()->user(), [
            'target_type' => 'offering', 'target_id' => $offeringId, 'institution_id' => $institutionId, 'old' => ['rows' => $old], 'new' => ['rows' => $week],
        ]);

        return ['warnings' => $this->clashes($offeringId, $week)];
    }

    /**
     * Other sections of the same term meeting at an overlapping time, on the same day, in the same room (not
     * online, room given) or with an instructor in common.
     *
     * @param list<array{day: string, start: string, end: string, room: ?string, mode: string}> $rows
     *
     * @return list<string>
     */
    public function clashes(int $offeringId, array $rows): array
    {
        $db       = db_connect();
        $term     = (int) $db->table('course_offerings')->select('term_id')->where('id', $offeringId)->get()->getRow('term_id');
        $teachers = array_map('intval', array_column($db->table('instructor_assignments')->select('instructor_user_id')->where('offering_id', $offeringId)->get()->getResultArray(), 'instructor_user_id'));
        $warnings = [];

        foreach ($rows as $row) {
            $others = $db->table('course_schedules s')
                ->select('s.offering_id, s.start_time, s.end_time, s.room, s.delivery_mode, c.course_code, o.section_code')
                ->join('course_offerings o', 'o.id = s.offering_id')
                ->join('courses c', 'c.id = o.course_id')
                ->where(['o.term_id' => $term, 's.day_of_week' => $row['day'], 's.offering_id !=' => $offeringId])
                ->where('s.start_time <', $row['end'] . ':00')
                ->where('s.end_time >', $row['start'] . ':00')
                ->get()->getResultArray();

            foreach ($others as $other) {
                $when = self::DAYS[$row['day']] . ' ' . substr((string) $other['start_time'], 0, 5) . '–' . substr((string) $other['end_time'], 0, 5);

                if ($row['room'] !== null && $row['mode'] !== 'online' && $other['delivery_mode'] !== 'online'
                    && $other['room'] !== null && strcasecmp(trim((string) $other['room']), $row['room']) === 0) {
                    $warnings[] = "Room {$row['room']} is also used by {$other['course_code']} {$other['section_code']} on {$when}.";
                }

                foreach ($this->sharedTeachers($teachers, (int) $other['offering_id']) as $name) {
                    $warnings[] = "{$name} also teaches {$other['course_code']} {$other['section_code']} on {$when}.";
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{day: string, start: string, end: string, room: ?string, mode: string}>
     *
     * @throws StructureException
     */
    private function validated(array $rows): array
    {
        $week  = [];
        $seen  = [];
        $index = 0;

        foreach ($rows as $row) {
            $index++;

            try {
                $clean = self::checked($row);
            } catch (StructureException $e) {
                throw StructureException::field('rows', "Row {$index}: {$e->getMessage()}");
            }

            if ($clean === null) {
                continue;
            }

            if (isset($seen[$clean['day'] . $clean['start']])) {
                throw StructureException::field('rows', "Row {$index}: the section already meets on " . self::DAYS[$clean['day']] . " at {$clean['start']}.");
            }

            $seen[$clean['day'] . $clean['start']] = true;
            $week[]                                = $clean;
        }

        return $week;
    }

    /**
     * One row, cleaned, or null when it is blank (nothing to save). The reason it is refused starts in lower case,
     * to follow "Row {n}: ".
     *
     * @param array<string, mixed> $row day, start, end, room, mode
     *
     * @return array{day: string, start: string, end: string, room: ?string, mode: string}|null
     *
     * @throws StructureException
     */
    public static function checked(array $row): ?array
    {
        $day   = strtolower(trim((string) ($row['day'] ?? '')));
        $start = trim((string) ($row['start'] ?? ''));
        $end   = trim((string) ($row['end'] ?? ''));
        $room  = trim((string) ($row['room'] ?? ''));
        $mode  = strtolower(trim((string) ($row['mode'] ?? '')));

        if ($day === '' && $start === '' && $end === '' && $room === '') {
            return null;
        }

        if (! isset(self::DAYS[$day])) {
            throw new StructureException('choose a day.');
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) !== 1) {
            throw new StructureException('enter a start and an end time, as 09:00.');
        }

        if ($end <= $start) {
            throw new StructureException('the end time must be after the start time.');
        }

        $mode = $mode === '' ? 'onsite' : $mode;

        if (! in_array($mode, self::MODES, true)) {
            throw new StructureException('choose onsite, online or hybrid.');
        }

        if (mb_strlen($room) > 50) {
            throw new StructureException('the room can be at most 50 characters.');
        }

        return ['day' => $day, 'start' => $start, 'end' => $end, 'room' => $room === '' ? null : $room, 'mode' => $mode];
    }

    /** @return list<array{day: string, start: string, end: string, room: ?string, mode: string}> Monday first, then by start */
    private function rowsOf(int $offeringId): array
    {
        $order = "FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'), start_time";

        return array_map(static fn (array $r): array => [
            'day' => (string) $r['day_of_week'], 'start' => substr((string) $r['start_time'], 0, 5), 'end' => substr((string) $r['end_time'], 0, 5),
            'room' => $r['room'] === null || $r['room'] === '' ? null : (string) $r['room'], 'mode' => (string) $r['delivery_mode'],
        ], db_connect()->table('course_schedules')->where('offering_id', $offeringId)->orderBy($order, '', false)->get()->getResultArray());
    }

    /**
     * The names of this section's instructors who also teach the other section.
     *
     * @param list<int> $teachers
     *
     * @return list<string>
     */
    private function sharedTeachers(array $teachers, int $otherOffering): array
    {
        if ($teachers === []) {
            return [];
        }

        $rows = db_connect()->table('instructor_assignments ia')
            ->select('up.first_name, up.last_name')
            ->join('user_profiles up', 'up.user_id = ia.instructor_user_id', 'left')
            ->where('ia.offering_id', $otherOffering)
            ->whereIn('ia.instructor_user_id', $teachers)
            ->get()->getResultArray();

        return array_map(static fn (array $r): string => trim($r['first_name'] . ' ' . $r['last_name']), $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Copying offerings from one term into another, so a new term does not start
 * from an empty course.
 *
 * What is copied (a new offering in the target term, same course and section):
 *  - the section's capacity and description, its weekly schedule, and
 *    optionally its instructors;
 *  - the content: modules, units, items, their prerequisites and resources;
 *  - assessments with their settings, questions and choices, rubrics and
 *    criteria, with their dates moved by the difference between the two
 *    terms' start dates;
 *  - the grading scheme: periods, categories and gradebook items, flagged for
 *    recalculation.
 *
 * What is never copied: enrolments, submissions and answers, scores and
 * grades, announcements, discussions, progress, reminders, AI generation
 * history. Uploaded files are referenced by the copy, not duplicated, so
 * copying uses no extra storage.
 *
 * Rules:
 *  - The source and target terms are the institution's own and different; the
 *    target is upcoming or active; the offerings are the source term's and
 *    not archived.
 *  - A section that already exists in the target term (same course and
 *    section) is a conflict, and the run is refused until it is unticked:
 *    one run is all or nothing, in one transaction.
 *  - Each copy remembers its source (`copied_from_offering_id`); a term that
 *    offerings were copied from can no longer be reopened (TermCloseService).
 *  - Audited with the counts.
 */
class OfferingCopyService
{
    /**
     * The terms a copy can come from and go to.
     *
     * @return array{sources: list<array<string, mixed>>, targets: list<array<string, mixed>>}
     */
    public function terms(int $institutionId): array
    {
        $db = db_connect();

        $sources = $db->table('academic_terms t')
            ->select('t.id, t.name, t.academic_year, t.status, t.start_date')
            ->select('(SELECT COUNT(*) FROM course_offerings o WHERE o.term_id = t.id AND o.status != \'archived\') AS offering_count', false)
            ->where('t.institution_id', $institutionId)
            ->orderBy('t.start_date', 'DESC')
            ->get()->getResultArray();

        $targets = array_values(array_filter($sources, static fn (array $t): bool => in_array($t['status'], ['upcoming', 'active'], true)));

        return ['sources' => $sources, 'targets' => $targets];
    }

    /**
     * The source term's offerings with what each holds and whether it clashes
     * with a section already in the target.
     *
     * @return array{source: array<string, mixed>, target: array<string, mixed>, shiftDays: int, offerings: list<array<string, mixed>>}
     *
     * @throws StructureException
     */
    public function plan(int $institutionId, int $sourceTermId, int $targetTermId): array
    {
        [$source, $target] = $this->terms2($institutionId, $sourceTermId, $targetTermId);

        $db = db_connect();

        $rows = $db->table('course_offerings o')
            ->select('o.id, o.course_id, o.section_code, o.capacity, o.status, c.course_code, c.course_title')
            ->select('(SELECT COUNT(*) FROM course_modules m WHERE m.offering_id = o.id) AS modules', false)
            ->select('(SELECT COUNT(*) FROM learning_items i JOIN learning_units u ON u.id = i.learning_unit_id JOIN course_modules m2 ON m2.id = u.module_id WHERE m2.offering_id = o.id) AS items', false)
            ->select('(SELECT COUNT(*) FROM assessments a JOIN course_modules m3 ON m3.id = a.module_id WHERE m3.offering_id = o.id) AS assessments', false)
            ->select('(SELECT COUNT(*) FROM instructor_assignments ia WHERE ia.offering_id = o.id) AS instructors', false)
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.term_id', $sourceTermId)
            ->where('o.status !=', 'archived')
            ->orderBy('c.course_code')->orderBy('o.section_code')
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $clash = $db->table('course_offerings')
                ->where(['term_id' => $targetTermId, 'course_id' => $row['course_id'], 'section_code' => $row['section_code']])
                ->countAllResults();

            $row['conflict'] = $clash > 0;
        }

        unset($row);

        return [
            'source'    => $source,
            'target'    => $target,
            'shiftDays' => $this->shiftDays($source, $target),
            'offerings' => $rows,
        ];
    }

    /**
     * Copies the chosen offerings into the target term, all or nothing.
     *
     * @param list<int> $offeringIds
     *
     * @return array{offerings: int, modules: int, items: int, assessments: int, questions: int, schedules: int, instructors: int, created: list<int>}
     *
     * @throws StructureException
     */
    public function copy(int $institutionId, int $sourceTermId, int $targetTermId, array $offeringIds, bool $withInstructors, ?User $actor = null): array
    {
        $plan = $this->plan($institutionId, $sourceTermId, $targetTermId);

        $offeringIds = array_values(array_unique(array_filter(array_map('intval', $offeringIds), static fn (int $id): bool => $id > 0)));

        if ($offeringIds === []) {
            throw new StructureException('Tick at least one offering to copy.');
        }

        $byId = [];

        foreach ($plan['offerings'] as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $chosen = [];

        foreach ($offeringIds as $id) {
            if (! isset($byId[$id])) {
                throw new StructureException('One of the offerings is not in the term you are copying from.');
            }

            $chosen[] = $byId[$id];
        }

        $clashes = array_filter($chosen, static fn (array $o): bool => $o['conflict']);

        if ($clashes !== []) {
            $names = implode(', ', array_map(static fn (array $o): string => $o['course_code'] . ' ' . $o['section_code'], $clashes));

            throw new StructureException("{$plan['target']['name']} already has a section for: {$names}. Untick them and copy again; nothing was copied.");
        }

        $totals = ['offerings' => 0, 'modules' => 0, 'items' => 0, 'assessments' => 0, 'questions' => 0, 'schedules' => 0, 'instructors' => 0, 'created' => []];

        $db = db_connect();
        $db->transStart();

        try {
            foreach ($chosen as $source) {
                $made = $this->copyOne((int) $source['id'], $targetTermId, $plan['shiftDays'], $withInstructors);

                foreach (['modules', 'items', 'assessments', 'questions', 'schedules', 'instructors'] as $key) {
                    $totals[$key] += $made[$key];
                }

                $totals['offerings']++;
                $totals['created'][] = $made['offering_id'];
            }

            service('auditLogger')->log('structure.offerings_copied', $actor ?? auth()->user(), [
                'target_type'    => 'term',
                'target_id'      => $targetTermId,
                'institution_id' => $institutionId,
                'new'            => [
                    'source_term_id' => $sourceTermId,
                    'offering_ids'   => $offeringIds,
                    'with_instructors' => $withInstructors,
                    'shift_days'     => $plan['shiftDays'],
                ] + array_diff_key($totals, ['created' => true]),
            ]);
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Copying offerings failed: ' . $e->getMessage());

            throw new StructureException('The offerings could not be copied. Nothing was changed.');
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new StructureException('The offerings could not be copied. Nothing was changed.');
        }

        return $totals;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws StructureException
     */
    private function terms2(int $institutionId, int $sourceTermId, int $targetTermId): array
    {
        $scope = InstitutionScope::forInstitution($institutionId);
        $scope->require('term', $sourceTermId);
        $scope->require('term', $targetTermId);

        if ($sourceTermId === $targetTermId) {
            throw StructureException::field('target', 'Choose a different term to copy into.');
        }

        $db     = db_connect();
        $source = $db->table('academic_terms')->where('id', $sourceTermId)->get()->getRowArray();
        $target = $db->table('academic_terms')->where('id', $targetTermId)->get()->getRowArray();

        if (! in_array($target['status'], ['upcoming', 'active'], true)) {
            throw StructureException::field('target', "{$target['name']} is closed, so nothing can be copied into it.");
        }

        return [$source, $target];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function shiftDays(array $source, array $target): int
    {
        $from = new DateTimeImmutable((string) $source['start_date']);
        $to   = new DateTimeImmutable((string) $target['start_date']);

        return (int) $from->diff($to)->format('%r%a');
    }

    /**
     * @return array{offering_id: int, modules: int, items: int, assessments: int, questions: int, schedules: int, instructors: int}
     */
    private function copyOne(int $sourceId, int $targetTermId, int $shiftDays, bool $withInstructors): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $source = $db->table('course_offerings')->where('id', $sourceId)->get()->getRowArray();

        $offeringId = $this->insert('course_offerings', [
            'course_id'               => $source['course_id'],
            'term_id'                 => $targetTermId,
            'section_code'            => $source['section_code'],
            'capacity'                => $source['capacity'],
            'description'             => $source['description'],
            'status'                  => 'active',
            'copied_from_offering_id' => $sourceId,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        $schedules = $this->cloneRows('course_schedules', ['offering_id' => $sourceId], ['offering_id' => $offeringId]);

        $instructors = 0;

        if ($withInstructors) {
            $instructors = count($this->cloneRows('instructor_assignments', ['offering_id' => $sourceId], ['offering_id' => $offeringId, 'assigned_at' => $now]));
        }

        // Content: modules > units > items > resources, prerequisites between items.
        $modules = $this->cloneRows('course_modules', ['offering_id' => $sourceId], ['offering_id' => $offeringId]);

        $units = [];
        $items = [];

        foreach ($modules as $oldModule => $newModule) {
            $units += $this->cloneRows('learning_units', ['module_id' => $oldModule], ['module_id' => $newModule]);
        }

        foreach ($units as $oldUnit => $newUnit) {
            $items += $this->cloneRows('learning_items', ['learning_unit_id' => $oldUnit], ['learning_unit_id' => $newUnit]);
        }

        foreach ($items as $oldItem => $newItem) {
            // The file is the same row: referenced, not duplicated.
            $this->cloneRows('learning_resources', ['learning_item_id' => $oldItem], ['learning_item_id' => $newItem]);
        }

        if ($items !== []) {
            $rows = $db->table('learning_item_prerequisites')->whereIn('learning_item_id', array_keys($items))->get()->getResultArray();

            foreach ($rows as $row) {
                $needs = (int) $row['prerequisite_item_id'];

                if (isset($items[$needs])) {
                    $this->insert('learning_item_prerequisites', ['learning_item_id' => $items[(int) $row['learning_item_id']], 'prerequisite_item_id' => $items[$needs], 'created_at' => $now]);
                }
            }
        }

        // Assessments hang off modules; their dates move with the term.
        $assessments = [];
        $questions   = [];

        if ($modules !== []) {
            $rows = $db->table('assessments')->whereIn('module_id', array_keys($modules))->orderBy('id')->get()->getResultArray();

            foreach ($rows as $row) {
                $old = (int) $row['id'];
                unset($row['id']);

                $row['module_id']        = $modules[(int) $row['module_id']];
                $row['learning_item_id'] = $row['learning_item_id'] === null ? null : ($items[(int) $row['learning_item_id']] ?? null);

                foreach (['available_from', 'due_date', 'available_until'] as $field) {
                    $row[$field] = $this->shift($row[$field], $shiftDays);
                }

                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $row['updated_by'] = null;

                $assessments[$old] = $this->insert('assessments', $row);
            }
        }

        foreach ($assessments as $oldAssessment => $newAssessment) {
            $this->cloneRows('assessment_settings', ['assessment_id' => $oldAssessment], ['assessment_id' => $newAssessment]);

            $copied = $this->cloneRows('assessment_questions', ['assessment_id' => $oldAssessment], ['assessment_id' => $newAssessment]);
            $questions += $copied;

            foreach ($copied as $oldQuestion => $newQuestion) {
                $this->cloneRows('assessment_question_choices', ['question_id' => $oldQuestion], ['question_id' => $newQuestion]);
            }
        }

        if ($assessments !== []) {
            $rubrics = $db->table('assessment_rubrics')->whereIn('assessment_id', array_keys($assessments))->orderBy('id')->get()->getResultArray();

            foreach ($rubrics as $row) {
                $old = (int) $row['id'];
                unset($row['id']);

                $row['assessment_id'] = $assessments[(int) $row['assessment_id']];
                $row['question_id']   = $row['question_id'] === null ? null : ($questions[(int) $row['question_id']] ?? null);
                $row['created_at']    = $now;
                $row['updated_at']    = $now;

                $new = $this->insert('assessment_rubrics', $row);

                $this->cloneRows('assessment_rubric_criteria', ['rubric_id' => $old], ['rubric_id' => $new]);
            }
        }

        $this->copyScheme($sourceId, $offeringId, $assessments);

        return [
            'offering_id' => $offeringId,
            'modules'     => count($modules),
            'items'       => count($items),
            'assessments' => count($assessments),
            'questions'   => count($questions),
            'schedules'   => count($schedules),
            'instructors' => $instructors,
        ];
    }

    /**
     * The grading scheme with its periods, categories and gradebook items
     * (no scores). It is flagged to be recalculated.
     *
     * @param array<int, int> $assessments old assessment id => new
     */
    private function copyScheme(int $sourceId, int $offeringId, array $assessments): void
    {
        $db     = db_connect();
        $scheme = $db->table('grading_schemes')->where('course_offering_id', $sourceId)->get()->getRowArray();

        if ($scheme === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $old = (int) $scheme['id'];
        unset($scheme['id']);

        $scheme['course_offering_id']  = $offeringId;
        $scheme['needs_recalculation'] = 1;
        $scheme['last_recalculated_at'] = null;
        $scheme['created_at']          = $now;
        $scheme['updated_at']          = $now;
        $scheme['updated_by']          = null;

        $newScheme = $this->insert('grading_schemes', $scheme);

        $periods    = $this->cloneRows('grading_periods', ['grading_scheme_id' => $old], ['grading_scheme_id' => $newScheme]);
        $categories = [];

        $rows = $db->table('grade_categories')->where('grading_scheme_id', $old)->orderBy('id')->get()->getResultArray();

        foreach ($rows as $row) {
            $oldCategory = (int) $row['id'];
            unset($row['id']);

            $row['grading_scheme_id'] = $newScheme;
            $row['period_id']         = $row['period_id'] === null ? null : ($periods[(int) $row['period_id']] ?? null);
            $row['created_at']        = $now;
            $row['updated_at']        = $now;

            $categories[$oldCategory] = $this->insert('grade_categories', $row);
        }

        $rows = $db->table('grade_items')->where('grading_scheme_id', $old)->orderBy('id')->get()->getResultArray();

        foreach ($rows as $row) {
            unset($row['id']);

            $row['grading_scheme_id'] = $newScheme;
            $row['category_id']       = $row['category_id'] === null ? null : ($categories[(int) $row['category_id']] ?? null);
            $row['assessment_id']     = $row['assessment_id'] === null ? null : ($assessments[(int) $row['assessment_id']] ?? null);
            $row['created_at']        = $now;
            $row['updated_at']        = $now;
            $row['updated_by']        = null;

            $this->insert('grade_items', $row);
        }
    }

    /**
     * Copies the rows of $table matching $where with $overrides applied, and
     * returns old id => new id.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $overrides
     *
     * @return array<int, int>
     */
    private function cloneRows(string $table, array $where, array $overrides): array
    {
        $db   = db_connect();
        $map  = [];
        $now  = date('Y-m-d H:i:s');
        $rows = $db->table($table)->where($where)->orderBy('id')->get()->getResultArray();

        foreach ($rows as $row) {
            $old = (int) $row['id'];
            unset($row['id']);

            foreach (['created_at', 'updated_at'] as $stamp) {
                if (array_key_exists($stamp, $row)) {
                    $row[$stamp] = $now;
                }
            }

            $map[$old] = $this->insert($table, $overrides + $row);
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $db = db_connect();

        if (! $db->table($table)->insert($row)) {
            throw new RuntimeException("Could not write to {$table}.");
        }

        return (int) $db->insertID();
    }

    private function shift(?string $stamp, int $days): ?string
    {
        if ($stamp === null || $stamp === '') {
            return $stamp;
        }

        return (new DateTimeImmutable($stamp))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d H:i:s');
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * The row rules hold in the database too (QA MISS-DB-01), not only in the screens that write the rows:
 * a percentage is 0 to 100, units are not negative, a section holds at least one student, a term ends
 * after it starts, and an assessment has one settings row.
 *
 * A row already breaking a rule is brought inside it first (a percentage to its nearest end, negative
 * units to 0, a capacity to 1, a term's end to the day after its start), and each is logged. Duplicate
 * settings rows keep the first, which is the one the app reads.
 */
class DatabaseRefusesImpossibleValues extends Migration
{
    /** @var array<string, array{table: string, check: string, repair: string}> */
    private const CHECKS = [
        'chk_terms_end_after_start'          => ['table' => 'academic_terms', 'check' => 'end_date > start_date', 'repair' => 'end_date = DATE_ADD(start_date, INTERVAL 1 DAY)'],
        'chk_courses_units'                  => ['table' => 'courses', 'check' => 'units >= 0', 'repair' => 'units = 0'],
        'chk_offerings_capacity'             => ['table' => 'course_offerings', 'check' => 'capacity >= 1', 'repair' => 'capacity = 1'],
        'chk_assessments_passing_percentage' => ['table' => 'assessments', 'check' => 'passing_percentage IS NULL OR passing_percentage BETWEEN 0 AND 100', 'repair' => 'passing_percentage = LEAST(GREATEST(passing_percentage, 0), 100)'],
        'chk_schemes_passing_percentage'     => ['table' => 'grading_schemes', 'check' => 'passing_percentage BETWEEN 0 AND 100', 'repair' => 'passing_percentage = LEAST(GREATEST(passing_percentage, 0), 100)'],
        'chk_defaults_passing_percentage'    => ['table' => 'institution_academic_defaults', 'check' => 'passing_percentage IS NULL OR passing_percentage BETWEEN 0 AND 100', 'repair' => 'passing_percentage = LEAST(GREATEST(passing_percentage, 0), 100)'],
        'chk_completions_progress_percent'   => ['table' => 'learning_completions', 'check' => 'progress_percent <= 100', 'repair' => 'progress_percent = 100'],
    ];

    public function up(): void
    {
        foreach (self::CHECKS as $name => $rule) {
            $table = $this->connection()->prefixTable($rule['table']);

            $this->db->query("UPDATE {$table} SET {$rule['repair']} WHERE NOT ({$rule['check']})");

            if (($fixed = $this->connection()->affectedRows()) > 0) {
                log_message('warning', 'Database rules: {count} row(s) of {table} broke "{check}" and were brought inside it.', [
                    'count' => $fixed, 'table' => $rule['table'], 'check' => $rule['check'],
                ]);
            }

            $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule['check']})");
        }

        $rows = $this->db->table('assessment_settings')
            ->select('s.id, s.assessment_id')
            ->from('assessment_settings s', true)
            ->join('(SELECT assessment_id FROM ' . $this->connection()->prefixTable('assessment_settings') . ' GROUP BY assessment_id HAVING COUNT(*) > 1) d', 'd.assessment_id = s.assessment_id')
            ->get()->getResultArray();

        $delete = self::duplicatesToDelete(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'assessment_id' => (int) $r['assessment_id'],
        ], $rows));

        if ($delete !== []) {
            $this->db->table('assessment_settings')->whereIn('id', $delete)->delete();
        }

        $this->db->query('ALTER TABLE ' . $this->connection()->prefixTable('assessment_settings') . ' ADD UNIQUE KEY uq_settings_assessment (assessment_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE ' . $this->connection()->prefixTable('assessment_settings') . ' DROP INDEX uq_settings_assessment');

        foreach (self::CHECKS as $name => $rule) {
            $this->db->query('ALTER TABLE ' . $this->connection()->prefixTable($rule['table']) . " DROP CONSTRAINT {$name}");
        }
    }

    /**
     * The rows to delete so each assessment keeps its first settings row (the lowest id, which the app reads).
     *
     * @param list<array{id: int, assessment_id: int}> $rows
     *
     * @return list<int>
     */
    public static function duplicatesToDelete(array $rows): array
    {
        $first  = [];
        $delete = [];

        foreach ($rows as $row) {
            $key = $row['assessment_id'];

            if (! isset($first[$key])) {
                $first[$key] = $row['id'];
            } elseif ($row['id'] < $first[$key]) {
                $delete[]    = $first[$key];
                $first[$key] = $row['id'];
            } else {
                $delete[] = $row['id'];
            }
        }

        sort($delete);

        return $delete;
    }

    /** The migration's own connection, typed so its prefix and row counts can be read. */
    private function connection(): BaseConnection
    {
        return Database::connect($this->DBGroup);
    }
}

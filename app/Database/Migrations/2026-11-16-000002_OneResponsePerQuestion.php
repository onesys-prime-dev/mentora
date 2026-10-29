<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * One saved answer per question of an attempt (audit decision 15.2).
 *
 * Two tabs saving the same question at once could each insert a row, since
 * the save checked and then inserted. Duplicates keep their newest row (by
 * `updated_at`, then id), and a unique key then keeps it so; the save becomes
 * one upsert on that key, so the last save wins.
 */
class OneResponsePerQuestion extends Migration
{
    public function up(): void
    {
        $rows = $this->db->table('assessment_question_responses')
            ->select('r.id, r.submission_id, r.question_id, r.updated_at')
            ->from('assessment_question_responses r', true)
            ->join('(SELECT submission_id, question_id FROM ' . $this->connection()->prefixTable('assessment_question_responses') . ' GROUP BY submission_id, question_id HAVING COUNT(*) > 1) d', 'd.submission_id = r.submission_id AND d.question_id = r.question_id')
            ->get()->getResultArray();

        $delete = self::duplicatesToDelete(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'submission_id' => (int) $r['submission_id'], 'question_id' => (int) $r['question_id'], 'updated_at' => (string) $r['updated_at'],
        ], $rows));

        if ($delete !== []) {
            $this->db->table('assessment_question_responses')->whereIn('id', $delete)->delete();
        }

        $this->db->query('ALTER TABLE ' . $this->connection()->prefixTable('assessment_question_responses') . ' ADD UNIQUE KEY uq_response_question (submission_id, question_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE ' . $this->connection()->prefixTable('assessment_question_responses') . ' DROP INDEX uq_response_question');
    }

    /**
     * The rows to delete so each (attempt, question) keeps its newest answer.
     *
     * @param list<array{id: int, submission_id: int, question_id: int, updated_at: string}> $rows
     *
     * @return list<int>
     */
    public static function duplicatesToDelete(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['submission_id'] . ':' . $row['question_id']][] = $row;
        }

        $delete = [];

        foreach ($groups as $answers) {
            usort($answers, static fn (array $a, array $b): int => [$b['updated_at'], $b['id']] <=> [$a['updated_at'], $a['id']]);

            foreach (array_slice($answers, 1) as $older) {
                $delete[] = $older['id'];
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

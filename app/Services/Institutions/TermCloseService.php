<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Shield\Entities\User;
use RuntimeException;

/**
 * Closing a term and reopening it.
 *
 * Closing a term (upcoming or active):
 *  - its active offerings become completed and the students still enrolled in
 *    them become completed, in one transaction (all or nothing);
 *  - the term itself becomes completed;
 *  - nothing else changes: archived offerings, and places that were already
 *    dropped, withdrawn or completed, stay as they were;
 *  - the term remembers which offerings and places the close changed, who
 *    closed it and when, so that reopening can undo exactly that.
 *
 * Offerings that are no longer active are history: instructors and students
 * can look and download but not change (ClosedOfferingFilter).
 *
 * Reopening a term that was closed here puts those offerings and places back
 * and makes the term active again. A term that became completed any other way
 * has no record of what was closed, so it is not reopened from here.
 *
 * Both steps are audited with the counts.
 */
class TermCloseService
{
    /**
     * What closing the term would change, for the confirm page.
     *
     * @return array{term: array<string, mixed>, offerings: list<array<string, mixed>>, offeringCount: int, enrolmentCount: int, untouched: int, canClose: bool, reason: ?string}
     *
     * @throws \CodeIgniter\Exceptions\PageNotFoundException
     */
    public function preview(int $institutionId, int $termId): array
    {
        InstitutionScope::forInstitution($institutionId)->require('term', $termId);

        $db   = db_connect();
        $term = $this->term($termId);

        $offerings = $db->table('course_offerings o')
            ->select('o.id, o.section_code, o.status, c.course_code, c.course_title')
            ->select('(SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = \'enrolled\') AS enrolled', false)
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.term_id', $termId)
            ->orderBy('c.course_code')->orderBy('o.section_code')
            ->get()->getResultArray();

        $closing   = array_values(array_filter($offerings, static fn (array $o): bool => $o['status'] === 'active'));
        $enrolment = array_sum(array_map(static fn (array $o): int => (int) $o['enrolled'], $closing));

        $reason = null;

        if (! in_array($term['status'], ['upcoming', 'active'], true)) {
            $reason = $term['status'] === 'completed' ? "{$term['name']} is already closed." : "{$term['name']} is archived, so it cannot be closed.";
        }

        return [
            'term'           => $term,
            'offerings'      => $closing,
            'offeringCount'  => count($closing),
            'enrolmentCount' => $enrolment,
            'untouched'      => count($offerings) - count($closing),
            'canClose'       => $reason === null,
            'reason'         => $reason,
        ];
    }

    /**
     * @return array{offerings: int, enrolments: int}
     *
     * @throws StructureException
     */
    public function close(int $institutionId, int $termId, ?User $actor = null): array
    {
        $plan = $this->preview($institutionId, $termId);

        if (! $plan['canClose']) {
            throw new StructureException((string) $plan['reason']);
        }

        $db = db_connect();

        $offeringIds = array_map(static fn (array $o): int => (int) $o['id'], $plan['offerings']);
        $placeIds    = [];

        if ($offeringIds !== []) {
            $placeIds = array_map(
                static fn (array $r): int => (int) $r['id'],
                $db->table('enrollments')->select('id')->whereIn('offering_id', $offeringIds)->where('status', 'enrolled')->get()->getResultArray(),
            );
        }

        $now = date('Y-m-d H:i:s');

        $db->transStart();

        if ($placeIds !== []) {
            foreach (array_chunk($placeIds, 500) as $chunk) {
                $db->table('enrollments')->whereIn('id', $chunk)->update(['status' => 'completed', 'updated_at' => $now]);
            }
        }

        if ($offeringIds !== []) {
            $db->table('course_offerings')->whereIn('id', $offeringIds)->update(['status' => 'completed', 'updated_at' => $now]);
        }

        $db->table('academic_terms')->where('id', $termId)->update([
            'status'            => 'completed',
            'closed_at'         => $now,
            'closed_by_user_id' => ($actor ?? auth()->user())?->id,
            'close_snapshot'    => json_encode(['offerings' => $offeringIds, 'enrolments' => $placeIds]),
            'updated_at'        => $now,
        ]);

        $this->audit('structure.term_closed', $actor, $institutionId, $termId, ['status' => $plan['term']['status']], [
            'status'     => 'completed',
            'offerings'  => count($offeringIds),
            'enrolments' => count($placeIds),
        ]);

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The term could not be closed. Nothing was changed.');
        }

        return ['offerings' => count($offeringIds), 'enrolments' => count($placeIds)];
    }

    /**
     * Whether a closed term can be reopened here, and if not why.
     *
     * @param array<string, mixed> $term
     */
    public function reopenBlock(array $term): ?string
    {
        if ($term['status'] !== 'completed') {
            return 'Only a closed term can be reopened.';
        }

        if (($term['close_snapshot'] ?? null) === null) {
            return "{$term['name']} was completed without being closed here, so there is no record of what to put back.";
        }

        $copied = db_connect()->table('course_offerings o')
            ->join('course_offerings src', 'src.id = o.copied_from_offering_id')
            ->where('src.term_id', (int) $term['id'])
            ->countAllResults();

        if ($copied > 0) {
            return "Offerings were copied from {$term['name']} into another term, so it stays closed.";
        }

        // One active term at a time (QA AMB-INST-02): a reopened term becomes active.
        $active = db_connect()->table('academic_terms')
            ->select('name')
            ->where('institution_id', (int) $term['institution_id'])
            ->where('status', 'active')
            ->where('id !=', (int) $term['id'])
            ->get()->getRowArray();

        if ($active !== null) {
            return "{$active['name']} is the active term. Close it before reopening {$term['name']}.";
        }

        return null;
    }

    /**
     * @return array{offerings: int, enrolments: int}
     *
     * @throws StructureException
     */
    public function reopen(int $institutionId, int $termId, ?User $actor = null): array
    {
        InstitutionScope::forInstitution($institutionId)->require('term', $termId);

        $term  = $this->term($termId);
        $block = $this->reopenBlock($term);

        if ($block !== null) {
            throw new StructureException($block);
        }

        $snapshot = json_decode((string) $term['close_snapshot'], true);
        $offerings = array_map('intval', (array) ($snapshot['offerings'] ?? []));
        $places    = array_map('intval', (array) ($snapshot['enrolments'] ?? []));

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $backOfferings = 0;
        $backPlaces    = 0;

        $db->transStart();

        foreach (array_chunk($places, 500) as $chunk) {
            // Only what the close completed and nobody has changed since.
            $db->table('enrollments')->whereIn('id', $chunk)->where('status', 'completed')->update(['status' => 'enrolled', 'updated_at' => $now]);
            $backPlaces += $db->affectedRows();
        }

        foreach (array_chunk($offerings, 500) as $chunk) {
            $db->table('course_offerings')->whereIn('id', $chunk)->where('status', 'completed')->update(['status' => 'active', 'updated_at' => $now]);
            $backOfferings += $db->affectedRows();
        }

        $db->table('academic_terms')->where('id', $termId)->update([
            'status'            => 'active',
            'closed_at'         => null,
            'closed_by_user_id' => null,
            'close_snapshot'    => null,
            'updated_at'        => $now,
        ]);

        $this->audit('structure.term_reopened', $actor, $institutionId, $termId, ['status' => 'completed'], [
            'status'     => 'active',
            'offerings'  => $backOfferings,
            'enrolments' => $backPlaces,
        ]);

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('The term could not be reopened. Nothing was changed.');
        }

        return ['offerings' => $backOfferings, 'enrolments' => $backPlaces];
    }

    /** @return array<string, mixed> */
    private function term(int $id): array
    {
        return db_connect()->table('academic_terms')->where('id', $id)->get()->getRowArray() ?? [];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'term',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}

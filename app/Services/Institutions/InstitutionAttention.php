<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\SisRunLog;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionStatus;
use DateTimeImmutable;

/**
 * What needs the institution's administrator's attention now (IA2). Each rule
 * is a small, separate question with the clock passed in, so the home page
 * and the notification triggers ask the same questions and agree.
 *
 * Read only, and every query is bound by the institution id given.
 */
class InstitutionAttention
{
    /** An invitation that lapses within this many days counts as about to expire. */
    public const INVITATION_DAYS = 3;

    /** A term that ends within this many days is ending soon. */
    public const TERM_DAYS = 14;

    /** A failed run older than this is history, not something to act on. */
    public const RUN_DAYS = 7;

    /**
     * The things to look at, most urgent first.
     *
     * @return list<array{key: string, tone: string, title: string, text: string, url: string, action: string}>
     */
    public function items(int $institutionId, DateTimeImmutable $now): array
    {
        $items = [];

        foreach ([$this->subscription($institutionId, $now), ...$this->limits($institutionId, $now)] as $item) {
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $failed = $this->failedRuns($institutionId, $now);

        if ($failed > 0) {
            $items[] = $this->item('sis-failed', 'danger', $failed === 1 ? 'The last SIS run failed' : "{$failed} SIS runs failed", 'Nothing was half-saved. Look at what was refused, then run it again.', 'institution/integrations', 'Open integrations');
        }

        $term = $this->activeTerm($institutionId);

        if ($term === null) {
            $items[] = $this->item('no-term', 'warning', 'No term is active', 'Offerings, enrolment and reports follow the active term. Set one.', 'institution/terms', 'Open terms');
        } else {
            $days = $this->daysUntil($term['end_date'], $now);

            if ($days >= 0 && $days <= self::TERM_DAYS) {
                $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");

                $items[] = $this->item('term-ending', 'warning', "{$term['name']} ends {$when}", 'Closing a term makes its offerings read-only and completes the enrolments that are still open.', 'institution/terms', 'Open terms');
            }

            $unstaffed = $this->offeringsWithoutInstructor($institutionId, $term['id']);

            if ($unstaffed > 0) {
                $items[] = $this->item('no-instructor', 'warning', $unstaffed === 1 ? '1 offering has no instructor' : "{$unstaffed} offerings have no instructor", 'Nobody can teach or grade them until an instructor is assigned.', 'institution/offerings?instructor=none&term=' . $term['id'], 'Show them');
            }

            $unoffered = $this->coursesWithoutOffering($institutionId, $term['id']);

            if ($unoffered > 0) {
                $items[] = $this->item('no-offering', 'info', $unoffered === 1 ? '1 course has no offering this term' : "{$unoffered} courses have no offering this term", 'Add an offering, or copy them from an earlier term.', 'institution/courses', 'Open courses');
            }
        }

        $expired = $this->expiredInvitations($institutionId, $now);

        if ($expired > 0) {
            $items[] = $this->item('invites-expired', 'warning', $expired === 1 ? '1 invitation expired' : "{$expired} invitations expired", 'They were not used in time. Send them again.', 'institution/people?view=invitations', 'Open invitations');
        }

        $expiring = count($this->expiringInvitations($institutionId, $now));

        if ($expiring > 0) {
            $items[] = $this->item('invites-expiring', 'info', $expiring === 1 ? '1 invitation is about to expire' : "{$expiring} invitations are about to expire", 'Their links stop working within ' . self::INVITATION_DAYS . ' days.', 'institution/people?view=invitations', 'Open invitations');
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];

        usort($items, static fn (array $a, array $b): int => $rank[$a['tone']] <=> $rank[$b['tone']]);

        return $items;
    }

    /** The active term (the latest started when there are several), or null. */
    public function activeTerm(int $institutionId): ?array
    {
        $row = db_connect()->table('academic_terms')
            ->select('id, name, academic_year, start_date, end_date')
            ->where(['institution_id' => $institutionId, 'status' => 'active'])
            ->orderBy('start_date', 'desc')
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'name'          => (string) $row['name'],
            'academic_year' => (string) $row['academic_year'],
            'start_date'    => (string) $row['start_date'],
            'end_date'      => (string) $row['end_date'],
        ];
    }

    /** Offerings of the term, not archived, that nobody teaches. */
    public function offeringsWithoutInstructor(int $institutionId, int $termId): int
    {
        return db_connect()->table('course_offerings o')
            ->join('academic_terms t', 't.id = o.term_id')
            ->where('t.institution_id', $institutionId)
            ->where('o.term_id', $termId)
            ->where('o.status', 'active')
            ->where('NOT EXISTS (SELECT 1 FROM instructor_assignments ia WHERE ia.offering_id = o.id)', null, false)
            ->countAllResults();
    }

    /** Active courses that are not offered in the term. */
    public function coursesWithoutOffering(int $institutionId, int $termId): int
    {
        return db_connect()->table('courses c')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('d.institution_id', $institutionId)
            ->where('c.status', 'active')
            ->where('NOT EXISTS (SELECT 1 FROM course_offerings o WHERE o.course_id = c.id AND o.term_id = ' . $termId . " AND o.status != 'archived')", null, false)
            ->countAllResults();
    }

    /**
     * Invitations still open that lapse within INVITATION_DAYS, soonest first.
     *
     * @return list<array{id: int, expires_at: string}>
     */
    public function expiringInvitations(int $institutionId, DateTimeImmutable $now): array
    {
        $rows = db_connect()->table('institution_invitations')
            ->select('id, expires_at')
            ->where('institution_id', $institutionId)
            ->where('accepted_at IS NULL', null, false)
            ->where('revoked_at IS NULL', null, false)
            ->where('expires_at >', $now->format('Y-m-d H:i:s'))
            ->where('expires_at <=', $now->modify('+' . self::INVITATION_DAYS . ' days')->format('Y-m-d H:i:s'))
            ->orderBy('expires_at')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'expires_at' => (string) $r['expires_at']], $rows);
    }

    /** Invitations that lapsed unused and were not replaced or withdrawn. */
    public function expiredInvitations(int $institutionId, DateTimeImmutable $now): int
    {
        return db_connect()->table('institution_invitations')
            ->where('institution_id', $institutionId)
            ->where('accepted_at IS NULL', null, false)
            ->where('revoked_at IS NULL', null, false)
            ->where('expires_at <=', $now->format('Y-m-d H:i:s'))
            ->countAllResults();
    }

    /**
     * Real runs (not tests, not previews) that failed in the last RUN_DAYS and
     * were not followed by a good run in the same direction.
     */
    public function failedRuns(int $institutionId, DateTimeImmutable $now): int
    {
        $since = $now->modify('-' . self::RUN_DAYS . ' days')->format('Y-m-d H:i:s');
        $db    = db_connect();
        $total = 0;

        foreach ([SisRunLog::INBOUND, SisRunLog::OUTBOUND] as $direction) {
            $base = static fn () => $db->table('sis_runs')
                ->where('institution_id', $institutionId)->where('direction', $direction)
                ->where('dry_run', 0)->where('trigger !=', SisRunLog::TEST);

            $lastGood = $base()->where('status', 'succeeded')->selectMax('started_at')->get()->getRowArray()['started_at'] ?? null;

            $failed = $base()->where('status', 'failed')->where('started_at >=', $since);

            if ($lastGood !== null) {
                $failed->where('started_at >', $lastGood);
            }

            $total += $failed->countAllResults();
        }

        return $total;
    }

    /** @return array{key: string, tone: string, title: string, text: string, url: string, action: string}|null */
    private function subscription(int $institutionId, DateTimeImmutable $now): ?array
    {
        $subscription = (new SubscriptionService())->forInstitution($institutionId);

        if ($subscription === null) {
            return null;
        }

        $status = (new SubscriptionLifecycle())->statusOf((string) $subscription['end_date'], $institutionId, $now);
        $end    = date('j M Y', strtotime($status->endDate));

        return match ($status->state) {
            SubscriptionStatus::EXPIRING  => $this->item('subscription', 'warning', 'Your subscription ends on ' . $end, 'Ask your Mentora contact to renew it.', 'institution/subscription', 'Open subscription'),
            SubscriptionStatus::GRACE     => $this->item('subscription', 'warning', 'Your subscription ended on ' . $end, 'Everything works until ' . date('j M Y', strtotime((string) $status->graceEnds)) . ', then the institution becomes read-only.', 'institution/subscription', 'Open subscription'),
            SubscriptionStatus::READ_ONLY => $this->item('subscription', 'danger', 'Your institution is read-only', 'People can look and export but not change anything until it is renewed.', 'institution/subscription', 'Open subscription'),
            SubscriptionStatus::EXPIRED   => $this->item('subscription', 'danger', 'Your subscription has ended', 'Your data is kept. You can take a full export.', 'institution/export', 'Take an export'),
            default                       => null,
        };
    }

    /** @return list<array{key: string, tone: string, title: string, text: string, url: string, action: string}|null> */
    private function limits(int $institutionId, DateTimeImmutable $now): array
    {
        $report = (new PlanLimits())->report($institutionId, $now);
        $names  = ['seats' => 'student seats', 'storage' => 'storage', 'ai' => 'AI generations'];
        $items  = [];

        foreach ($names as $kind => $label) {
            $state = $report[$kind]['state'] ?? 'unlimited';

            if ($state === 'full') {
                $items[] = $this->item("limit-{$kind}", 'danger', "Your {$label} are used up", 'Anything that would add to them is refused until the plan changes. Nothing you have is removed.', 'institution/subscription', 'Open subscription');
            } elseif ($state === 'warning') {
                $items[] = $this->item("limit-{$kind}", 'warning', "Your {$label} are nearly used up", 'You have used 90% or more of them.', 'institution/subscription', 'Open subscription');
            }
        }

        return $items;
    }

    private function daysUntil(string $date, DateTimeImmutable $now): int
    {
        return (int) $now->setTime(0, 0)->diff(new DateTimeImmutable($date . ' 00:00:00'))->format('%r%a');
    }

    /** @return array{key: string, tone: string, title: string, text: string, url: string, action: string} */
    private function item(string $key, string $tone, string $title, string $text, string $url, string $action): array
    {
        return ['key' => $key, 'tone' => $tone, 'title' => $title, 'text' => $text, 'url' => $url, 'action' => $action];
    }
}

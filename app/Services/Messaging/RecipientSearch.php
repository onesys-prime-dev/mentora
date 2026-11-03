<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * The new-message picker. People are found by first or last name only, in the
 * sender's institution, and every candidate is put through the same policy
 * check starting a thread uses, so the list cannot offer anyone a start would
 * refuse. A parent is offered once per linked child they share with the
 * person, because each child is a separate thread.
 */
class RecipientSearch
{
    public const MIN_QUERY = 2;

    /** Names scanned before the policy narrows them; the policy is the filter, not the limit. */
    private const CANDIDATES = 200;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
    }

    /**
     * @return list<array{id: int, name: string, role: string, subject_student_id: int|null, subject_name: string|null}>
     */
    public function search(User $sender, string $query, int $limit = 20): array
    {
        $query       = trim($query);
        $institution = $this->resolver->institutionOf($sender);

        if (mb_strlen($query) < self::MIN_QUERY || $institution === null) {
            return [];
        }

        $db   = db_connect();
        $like = $db->escape('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%');

        $candidates = $db->table('institution_users iu')->select('up.user_id, up.first_name, up.last_name')
            ->join('user_profiles up', 'up.user_id = iu.user_id')
            ->where('iu.institution_id', $institution)->where('iu.user_id !=', (int) $sender->id)
            ->where("(up.first_name LIKE {$like} OR up.last_name LIKE {$like} OR CONCAT(up.first_name, ' ', up.last_name) LIKE {$like})", null, false)
            ->orderBy('up.last_name')->orderBy('up.first_name')->limit(self::CANDIDATES)->get()->getResultArray();

        $senderRole = $this->resolver->activeRole($sender);
        $users      = model(UserModel::class);
        $found      = [];

        foreach ($candidates as $candidate) {
            $recipient = $users->findById((int) $candidate['user_id']);

            if ($recipient === null) {
                continue;
            }

            $role = $this->resolver->activeRole($recipient);

            foreach ($this->subjects($sender, $senderRole, $recipient, $role) as $subject) {
                $context = $this->resolver->forStart($sender, $recipient, $subject);

                if ($context === null || ! $this->policy->canStart($senderRole, $role, $context)) {
                    continue;
                }

                if ($subject !== null && ! $context->linkedChild) {
                    continue;
                }

                $found[] = [
                    'id' => (int) $recipient->id, 'name' => trim($candidate['first_name'] . ' ' . $candidate['last_name']), 'role' => $role,
                    'subject_student_id' => $subject, 'subject_name' => null,
                ];
            }

            if (count($found) >= $limit) {
                break;
            }
        }

        $found  = array_slice($found, 0, $limit);
        $titles = ConversationPresenter::names(array_values(array_filter(array_column($found, 'subject_student_id'))));

        foreach ($found as &$entry) {
            $entry['subject_name'] = $entry['subject_student_id'] === null ? null : ($titles[$entry['subject_student_id']] ?? null);
        }

        return $found;
    }

    /**
     * The children a thread between these two could be about: none named for a
     * pair without a parent, otherwise each child they share (none shared
     * means no thread, so an empty list).
     *
     * @return list<int|null>
     */
    private function subjects(User $sender, string $senderRole, User $recipient, string $recipientRole): array
    {
        if ($senderRole !== 'parent' && $recipientRole !== 'parent') {
            return [null];
        }

        [$parent, $staff, $staffRole] = $senderRole === 'parent' ? [$sender, $recipient, $recipientRole] : [$recipient, $sender, $senderRole];

        return $this->resolver->sharedLinkedChildIds($parent, $staff, $staffRole);
    }
}

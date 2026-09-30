<?php

namespace App\Services\Users;

use App\Models\Users\ReminderModel;
use CodeIgniter\Shield\Entities\User;

class ReminderService
{
    protected ReminderModel $reminderModel;

    public function __construct()
    {
        $this->reminderModel = new ReminderModel();
    }

    /** @return array{success: bool, message?: string, reminder?: array} */
    public function create(User $user, array $data): array
    {
        $payload = [
            'user_id'     => $user->id,
            'offering_id' => ! empty($data['offering_id']) ? (int) $data['offering_id'] : null,
            'title'       => trim((string) ($data['title'] ?? '')),
            'note'        => trim((string) ($data['note'] ?? '')) ?: null,
            'remind_at'   => $data['remind_at'] ?? null,
        ];

        if (! $this->reminderModel->validate($payload)) {

            return [
                'success' => false,
                'message' => implode(' ', $this->reminderModel->errors()),
            ];

        }

        $id = $this->reminderModel->insert($payload, true);

        return [
            'success'  => true,
            'reminder' => $this->reminderModel->find($id),
        ];
    }

    /** @return array{success: bool, message?: string} */
    public function markDone(User $user, int $reminderId): array
    {
        if (! $this->reminderModel->belongsTo($reminderId, (int) $user->id)) {
            return ['success' => false, 'message' => 'Reminder not found.'];
        }

        $this->reminderModel->update($reminderId, ['status' => 'done']);

        return ['success' => true];
    }

    /** @return array{success: bool, message?: string} */
    public function delete(User $user, int $reminderId): array
    {
        if (! $this->reminderModel->belongsTo($reminderId, (int) $user->id)) {
            return ['success' => false, 'message' => 'Reminder not found.'];
        }

        $this->reminderModel->delete($reminderId);

        return ['success' => true];
    }
}

<?php

namespace App\Models\Users;

use CodeIgniter\Model;

class ReminderModel extends Model
{
    protected $table = 'reminders';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'user_id',
        'offering_id',
        'title',
        'note',
        'remind_at',
        'status',
    ];

    protected $validationRules = [
        'user_id' => 'required|is_natural_no_zero',
        'title' => 'required|max_length[150]',
        'remind_at' => 'required|valid_date',
        'status' => 'permit_empty|in_list[pending,done]',
    ];

    /**
     * One user's reminders whose remind_at falls in [from, to] — what the
     * Calendar plots and the dashboard's Reminders card lists.
     */
    public function retrieveForUserInRange(int $userId, string $from, string $to): array
    {
        return $this->where('user_id', $userId)
            ->where('remind_at >=', $from)
            ->where('remind_at <=', $to)
            ->orderBy('remind_at', 'ASC')
            ->findAll();
    }

    /** Ownership check before an update/delete ever touches the row. */
    public function belongsTo(int $reminderId, int $userId): bool
    {
        return $this->where('id', $reminderId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }
}

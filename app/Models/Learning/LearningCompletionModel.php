<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningCompletionModel extends Model
{
    protected $table = 'learning_completions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'learning_item_id',
        'student_user_id',
        'completed_at',
        'status',
    ];

    public function retrieveLearningCompletion(int $learningItemId, int $userId)
    {
        $builder = $this->builder();

        return $builder
            ->select([
                'id',
                'status',
            ])
            ->where('learning_item_id', $learningItemId)
            ->where('student_user_id', $userId)
            ->get()
            ->getRowArray();
    }
}
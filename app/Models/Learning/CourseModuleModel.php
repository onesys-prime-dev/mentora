<?php

namespace App\Models\Learning;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Model;

class CourseModuleModel extends Model
{
    protected $table = 'course_modules';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'title',
        'description',
        'order_index',
        'status',
    ];

    public function retrieveCourseModules(int $offeringId, User $user): array
    {
        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('offering_id', $offeringId);

        if (! $user->inGroup('instructor')) {

            $builder->where(
                'status',
                'published'
            );

        }

        return $builder
            ->orderBy('order_index')
            ->get()
            ->getResultArray();
    }

    public function retrieveCourseModule(
        int $moduleId,
        User $user
    ): ?array {

        $builder = $this->builder();

        $builder
            ->select([
                'id',
                'offering_id',
                'title',
                'description',
                'order_index',
                'status',
            ])
            ->where('id', $moduleId);

        if (! $user->inGroup('instructor')) {

            $builder->where(
                'status',
                'published'
            );

        }

        return $builder
            ->get()
            ->getRowArray();

    }
}
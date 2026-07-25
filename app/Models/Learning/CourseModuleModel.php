<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class CourseModuleModel extends Model
{
    protected $table = 'course_modules';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

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

    public function retrieveCourseModules(int $offeringId): array
    {
        $builder = $this->builder();

        return $builder
            ->select([
                'id',
                'title',
                'description',
                'order_index'
            ])
            ->where('offering_id', $offeringId)
            ->where('status', 'published')
            ->orderBy('order_index')
            ->get()
            ->getResultArray();
    }

    public function retrieveCourseModule(int $moduleId): ?array
    {
        return $this->builder()
            ->select([
                'id',
                'offering_id',
                'title',
                'description',
                'order_index',
            ])
            ->where('id', $moduleId)
            ->where('status', 'published')
            ->get()
            ->getRowArray();
    }
}
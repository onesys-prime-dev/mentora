<?php

namespace App\Models\Files;

use CodeIgniter\Model;

class FileModel extends Model
{
    protected $table = 'files';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'uploaded_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'owner_user_id',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'storage_key',
        'status',
    ];

    /**
     * Retrieve a single uploaded file.
     */
    public function retrieveFile(
        int $fileId
    ): ?array
    {
        return $this->builder()
            ->select([
                'id',
                'owner_user_id',
                'original_filename',
                'stored_filename',
                'mime_type',
                'file_size',
                'storage_key',
            ])
            ->where('id', $fileId)
            ->where('status', 'active')
            ->get()
            ->getRowArray();
    }

    /**
     * Retrieve multiple uploaded files.
     * Used later when optimizing away N+1 queries.
     */
    public function retrieveFiles(
        array $fileIds
    ): array
    {
        if ($fileIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'id',
                'owner_user_id',
                'original_filename',
                'stored_filename',
                'mime_type',
                'file_size',
                'storage_key',
            ])
            ->whereIn('id', $fileIds)
            ->where('status', 'active')
            ->get()
            ->getResultArray();
    }
}
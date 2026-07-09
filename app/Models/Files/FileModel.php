<?php

namespace App\Models\Files;

use CodeIgniter\Model;

class FileModel extends Model
{
    protected $table = 'files';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

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
        'storage_path',
        'status',
    ];
}
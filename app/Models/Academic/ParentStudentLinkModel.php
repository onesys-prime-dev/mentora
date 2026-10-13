<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

/**
 * A parent account's link to a student of the same institution. Made and
 * removed by the institutional administrator; never edited.
 */
class ParentStudentLinkModel extends Model
{
    protected $table = 'parent_student_links';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = false;

    protected $allowedFields = ['institution_id', 'parent_user_id', 'student_user_id', 'created_by_user_id', 'created_at'];
}

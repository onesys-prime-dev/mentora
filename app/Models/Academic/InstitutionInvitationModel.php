<?php

declare(strict_types=1);

namespace App\Models\Academic;

use CodeIgniter\Model;

/**
 * Invitations to join an institution. Written by InvitationService only; see
 * the CreateInstitutionInvitationsTable migration for what the columns mean.
 * The secret in the emailed link is not a column: only its hash is stored.
 */
class InstitutionInvitationModel extends Model
{
    protected $table = 'institution_invitations';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'institution_id',
        'role',
        'email',
        'first_name',
        'last_name',
        'student_ids',
        'token_hash',
        'expires_at',
        'sent_at',
        'accepted_at',
        'accepted_user_id',
        'revoked_at',
        'invited_by_user_id',
        'invited_by_label',
    ];
}

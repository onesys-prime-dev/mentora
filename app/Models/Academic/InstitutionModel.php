<?php

namespace App\Models\Academic;

use CodeIgniter\Model;
use LogicException;

/**
 * Institutions are the platform's customers. Status is where they are in
 * their lifecycle (see AddInstitutionLifecycle); deleting one is a soft
 * delete, so it can be restored until it is purged.
 */
class InstitutionModel extends Model
{
    /** Created but setup not finished: nobody can use it yet. */
    public const PENDING = 'pending';

    /** In good standing, including the warning and grace periods. */
    public const ACTIVE = 'active';

    /** Subscription lapsed: sign in, view and export only. */
    public const READ_ONLY = 'read_only';

    /** Subscription ended or suspended: kept, read-only, can be renewed. */
    public const PAST = 'past';

    public const STATUSES = [self::PENDING, self::ACTIVE, self::READ_ONLY, self::PAST];

    protected $table = 'institutions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = true;

    protected $deletedField = 'deleted_at';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        // Only here so {id} can be used as a placeholder in the code rule below.
        'id' => [
            'rules' => 'permit_empty|is_natural_no_zero',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]|is_unique[institutions.code,id,{id}]',
        ],

        'status' => [
            'rules' => 'required|in_list[pending,active,read_only,past]',
        ],

    ];

    protected $validationMessages = [

        'name' => [
            'required' => 'Institution name is required.',
        ],

        'code' => [
            'required'  => 'Institution code is required.',
            'is_unique' => 'Institution code already exists.',
        ],

    ];

    /** An institution's code identifies it everywhere (imports, invitations, reports): it never changes. */
    protected $beforeUpdate = ['refuseCodeChange'];

    /*
    |--------------------------------------------------------------------------
    | Scopes. Deleted institutions are left out of every query unless asked
    | for with withDeleted() / onlyDeleted().
    |--------------------------------------------------------------------------
    */

    public function pending(): static
    {
        return $this->where('institutions.status', self::PENDING);
    }

    public function active(): static
    {
        return $this->where('institutions.status', self::ACTIVE);
    }

    public function readOnly(): static
    {
        return $this->where('institutions.status', self::READ_ONLY);
    }

    public function past(): static
    {
        return $this->where('institutions.status', self::PAST);
    }

    /** Brings a deleted institution back, with the status it had. False when it was not deleted. */
    public function restore(int $id): bool
    {
        $builder = $this->db->table($this->table)->where($this->primaryKey, $id)->where('deleted_at IS NOT NULL', null, false);

        if ($builder->countAllResults(false) === 0) {
            return false;
        }

        return (bool) $builder->update(['deleted_at' => null]);
    }

    /**
     * The code's uniqueness rule skips the institution's own row by reading its
     * id from the data, and update($id, ...) does not put the id there. Adding
     * it lets an edit that repeats the unchanged code pass validation instead
     * of colliding with itself. (The id is not an allowed field, so it is
     * dropped before anything is written.)
     *
     * @param array<int|string>|int|string|null $id
     * @param array<string, mixed>|object|null  $row
     *
     * @throws LogicException when the update would change an institution's code
     */
    public function update($id = null, $row = null): bool
    {
        if (is_array($row) && $id !== null && ! is_array($id) && ! array_key_exists($this->primaryKey, $row)) {
            $row[$this->primaryKey] = $id;
        }

        return parent::update($id, $row);
    }

    /**
     * @param array{id?: list<int|string>|int|string|null, data: array<string, mixed>} $event
     *
     * @return array{id?: list<int|string>|int|string|null, data: array<string, mixed>}
     *
     * @throws LogicException when the update would change an institution's code
     */
    protected function refuseCodeChange(array $event): array
    {
        if (! array_key_exists('code', $event['data'])) {
            return $event;
        }

        $ids = array_values(array_filter((array) ($event['id'] ?? []), static fn ($id): bool => $id !== null && $id !== ''));

        // Setting a code on "whatever matches" cannot be checked row by row, and a code is unique anyway.
        if ($ids === []) {
            throw new LogicException("An institution's code cannot be changed.");
        }

        // Straight to the table, so the check also sees soft-deleted institutions.
        $codes = array_column($this->db->table($this->table)->select('code')->whereIn($this->primaryKey, $ids)->get()->getResultArray(), 'code');

        foreach ($codes as $code) {
            if ($code !== $event['data']['code']) {
                throw new LogicException("An institution's code cannot be changed.");
            }
        }

        return $event;
    }

    /** The programs an institution offers, whichever department they sit in. */
    public function programs(int $institutionId): array
    {
        return $this->db->table('programs')
            ->select('programs.*')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $institutionId)
            ->orderBy('programs.name')
            ->get()
            ->getResultArray();
    }
}
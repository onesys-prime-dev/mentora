<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * The record of which deans, chairpersons and instructors an institution's SIS supplied
 * (`sis_staff_assignments`). The sync reads it to end exactly what the SIS itself stopped listing, and
 * SisOwnership reads it to lock those assignments against hand edits while the connection is enabled.
 * It holds no rules about who may be assigned: those stay in the assignment services.
 */
class SisStaffAssignments
{
    public const DEAN        = 'dean';
    public const CHAIRPERSON = 'chairperson';
    public const INSTRUCTOR  = 'instructor';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::DEAN, self::CHAIRPERSON, self::INSTRUCTOR];
    }

    /** @return array<string, mixed>|null */
    public function find(int $institutionId, string $role, int $personId, int $targetId): ?array
    {
        return db_connect()->table('sis_staff_assignments')
            ->where(['institution_id' => $institutionId, 'role' => $role, 'person_user_id' => $personId, 'target_id' => $targetId])
            ->get()->getRowArray();
    }

    /**
     * Every assignment the SIS supplied for an institution, oldest first, optionally of one role.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $institutionId, ?string $role = null): array
    {
        $builder = db_connect()->table('sis_staff_assignments')->where('institution_id', $institutionId);

        if ($role !== null) {
            $builder->where('role', $role);
        }

        return $builder->orderBy('id')->get()->getResultArray();
    }

    /** Remembers that the SIS supplies this assignment, as the assignment row $localId. */
    public function track(int $institutionId, string $role, int $personId, int $targetId, int $localId, string $stamp): void
    {
        $db       = db_connect();
        $existing = $this->find($institutionId, $role, $personId, $targetId);

        if ($existing === null) {
            $db->table('sis_staff_assignments')->insert([
                'institution_id' => $institutionId, 'role' => $role, 'person_user_id' => $personId,
                'target_id' => $targetId, 'local_id' => $localId, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);

            return;
        }

        $db->table('sis_staff_assignments')->where('id', $existing['id'])->update(['local_id' => $localId, 'updated_at' => $stamp]);
    }

    public function forget(int $id): void
    {
        db_connect()->table('sis_staff_assignments')->where('id', $id)->delete();
    }
}

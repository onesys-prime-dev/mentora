<?php

declare(strict_types=1);

namespace App\Services\Sis;

/** The student-number rule shared by the SIS import and correction requests. */
final class StudentNumbers
{
    /** A student number is unique within the institution that issued it (QA MISS-DB-01); the database refuses a duplicate too. */
    public static function taken(?string $number, ?int $institutionId, ?int $userId): bool
    {
        if ($number === null) {
            return false;
        }

        $query = db_connect()->table('user_profiles')->where('student_number_institution_id', $institutionId)->where('student_number', $number);

        if ($userId !== null) {
            $query->where('user_id !=', $userId);
        }

        return $query->countAllResults() > 0;
    }
}

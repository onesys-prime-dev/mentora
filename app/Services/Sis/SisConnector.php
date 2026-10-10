<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * What the sync needs from a student information system, whatever it is.
 *
 * Inbound the SIS is the source of truth (people, courses, enrolments);
 * outbound only final grades are sent, and Mentora is the source of truth. A
 * connector only moves data: mapping, counting and writing it into Mentora
 * belong to the import (branch 30) and the grade export (branch 31).
 *
 * A real SIS product gets its own implementation, registered in
 * SisConnectors, behind the same `sis.sync` flag.
 */
interface SisConnector
{
    /** Whether the connection works, and what it found. Never throws. */
    public function testConnection(): ConnectionResult;

    /**
     * One dataset from the SIS: students, instructors, courses or enrollments.
     * Rows that cannot be used are listed as rejected, with the reason, so
     * the rest is still usable.
     *
     * @throws SisException when the whole dataset cannot be read
     */
    public function read(string $dataset): SisBatch;

    /**
     * Sends final grades. The batch key makes the call idempotent: sending the
     * same key again replaces the earlier delivery, it does not add to it.
     *
     * @param list<array<string, scalar|null>> $rows course_external_id, student_external_id, percentage, remarks
     *
     * @throws SisException when the grades could not be delivered
     */
    public function publishGrades(string $batchKey, array $rows): void;
}

<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DEMO DATA ONLY — fills a fresh database so every feature has something to
 * show. Not client data. Run on empty tables:
 *
 *   php spark migrate:refresh --all
 *   php spark db:seed DatabaseSeeder
 *
 * Demo logins (password in DemoUsersSeeder): student1–5@mentora.test,
 * instructor1–2@mentora.test. IT101 section A is the showcase offering.
 */
class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // People and places
        $this->call(InstitutionsSeeder::class);
        $this->call(ProgramsSeeder::class);
        $this->call(DemoUsersSeeder::class);
        $this->call(InstitutionUsersSeeder::class);
        $this->call(AcademicTermsSeeder::class);

        // Courses and who teaches them
        $this->call(CoursesSeeder::class);
        $this->call(CourseOfferingsSeeder::class);
        $this->call(CourseSchedulesSeeder::class);
        $this->call(InstructorAssignmentsSeeder::class);
        $this->call(ChairpersonAssignmentsSeeder::class);

        // Content: files, then modules → units → items → linked assessments
        $this->call(FilesSeeder::class);
        $this->call(AssessmentTypesSeeder::class);
        $this->call(DemoContentSeeder::class);

        // Enrollments, attempts, submissions, progress, gradebook
        $this->call(DemoActivitySeeder::class);
    }
}

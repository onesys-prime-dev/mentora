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
 *
 * Sections: blocks A and B (class_sections) are the cohorts; A owns IT101-A and IT102-A and has students 1–3 as
 * members, B owns IT101-B and is empty, and IT201-A is a standalone section in no block.
 */
class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // People and places
        $this->call(InstitutionsSeeder::class);
        $this->call(DepartmentsSeeder::class);
        $this->call(SubDepartmentsSeeder::class);
        $this->call(ProgramsSeeder::class);
        $this->call(DemoUsersSeeder::class);
        $this->call(SystemAdminSeeder::class);
        $this->call(PlansSeeder::class);
        $this->call(InstitutionUsersSeeder::class);
        $this->call(AcademicTermsSeeder::class);

        // Courses, their block sections (cohorts) and offerings, and who teaches them. The sections come before
        // the offerings, which are linked to them; their students are enrolled in DemoActivitySeeder.
        $this->call(CoursesSeeder::class);
        $this->call(ClassSectionsSeeder::class);
        $this->call(CourseOfferingsSeeder::class);
        $this->call(CourseSchedulesSeeder::class);
        $this->call(InstructorAssignmentsSeeder::class);
        $this->call(ChairpersonAssignmentsSeeder::class);
        $this->call(DeanAssignmentsSeeder::class);

        // Content: files, then modules → units → items → linked assessments
        $this->call(FilesSeeder::class);
        $this->call(AssessmentTypesSeeder::class);
        $this->call(DemoContentSeeder::class);

        // Enrollments, attempts, submissions, progress, gradebook
        $this->call(DemoActivitySeeder::class);

        // Which students the demo parents follow
        $this->call(DemoParentLinksSeeder::class);

        // Announcements + personal reminders, for the Calendar
        $this->call(CalendarDemoSeeder::class);
    }
}

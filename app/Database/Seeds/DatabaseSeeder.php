<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call(InstitutionsSeeder::class);

        $this->call(ProgramsSeeder::class);

        $this->call(DemoUsersSeeder::class);

        $this->call(InstitutionUsersSeeder::class);

        $this->call(AcademicTermsSeeder::class);

        $this->call(CoursesSeeder::class);

        $this->call(CourseOfferingsSeeder::class);

        $this->call(EnrollmentsSeeder::class);

        $this->call(InstructorAssignmentsSeeder::class);

        $this->call(ChairpersonAssignmentsSeeder::class);

        $this->call(FilesSeeder::class);

        $this->call(CourseModulesSeeder::class);

        $this->call(LearningUnitsSeeder::class);

        $this->call(LearningItemsSeeder::class);

        $this->call(LearningResourcesSeeder::class);

        $this->call(LearningCompletionsSeeder::class);
        
        // Assessment Module
        $this->call(AssessmentTypesSeeder::class);

        $this->call(AssessmentsSeeder::class);

        $this->call(AssessmentSettingsSeeder::class);

        $this->call(AssessmentQuestionsSeeder::class);

        $this->call(AssessmentQuestionChoicesSeeder::class);

        $this->call(AssessmentRubricsSeeder::class);

        $this->call(AssessmentRubricCriteriaSeeder::class);

        $this->call(AssessmentSubmissionsSeeder::class);

        $this->call(AssessmentQuestionResponsesSeeder::class);
        
        $this->call(GradebookRecordsSeeder::class);
    }
}
<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessments')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // First available course
        $course = $this->db->table('courses')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!$course) {
            return;
        }

        // First offering of the course
        $offering = $this->db->table('course_offerings')
            ->select('id')
            ->where('course_id', $course['id'])
            ->orderBy('id', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!$offering) {
            return;
        }

        // First module under the offering
        $module = $this->db->table('course_modules')
            ->select('id')
            ->where('offering_id', $offering['id'])
            ->orderBy('order_index', 'ASC')
            ->get(1)
            ->getRowArray();

        $learningItem = null;

        if ($module) {

            $unit = $this->db->table('learning_units')
                ->select('id')
                ->where('module_id', $module['id'])
                ->orderBy('order_index', 'ASC')
                ->get(1)
                ->getRowArray();

            if ($unit) {
                $learningItem = $this->db->table('learning_items')
                    ->select('id')
                    ->where('learning_unit_id', $unit['id'])
                    ->orderBy('order_index', 'ASC')
                    ->get(1)
                    ->getRowArray();
            }
        }

        $types = $this->db->table('assessment_types')
            ->select('id,name')
            ->get()
            ->getResultArray();

        $typeMap = [];

        foreach ($types as $type) {
            $typeMap[$type['name']] = $type['id'];
        }

        $data = [];

        if (isset($typeMap['Quiz'])) {

            $data[] = [
                'courseId' => $course['id'],
                'moduleId' => null,
                'learningItemId' => null,
                'assessmentTypeId' => $typeMap['Quiz'],
                'title' => 'Course Pre-Test',
                'description' => 'Initial assessment before starting the course.',
                'instructions' => 'Answer every question.',
                'availableFrom' => $now,
                'dueDate' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'duration' => 30,
                'totalPoints' => 50,
                'weightPercentage' => 10,
                'attemptLimit' => 1,
                'passingScore' => 60,
                'createdBy' => 1,
                'createdAt' => $now,
                'updatedBy' => null,
                'updatedAt' => null,
                'status' => 'Published',
            ];
        }

        if ($module && isset($typeMap['Assignment'])) {

            $data[] = [
                'courseId' => $course['id'],
                'moduleId' => $module['id'],
                'learningItemId' => null,
                'assessmentTypeId' => $typeMap['Assignment'],
                'title' => 'Module Assignment',
                'description' => 'Programming assignment.',
                'instructions' => 'Upload your completed activity.',
                'availableFrom' => $now,
                'dueDate' => date('Y-m-d H:i:s', strtotime('+10 days')),
                'duration' => null,
                'totalPoints' => 100,
                'weightPercentage' => 30,
                'attemptLimit' => 1,
                'passingScore' => 75,
                'createdBy' => 1,
                'createdAt' => $now,
                'updatedBy' => null,
                'updatedAt' => null,
                'status' => 'Published',
            ];
        }

        if ($learningItem && isset($typeMap['Essay'])) {

            $data[] = [
                'courseId' => $course['id'],
                'moduleId' => $module['id'],
                'learningItemId' => $learningItem['id'],
                'assessmentTypeId' => $typeMap['Essay'],
                'title' => 'Reflection Essay',
                'description' => 'Reflection activity.',
                'instructions' => 'Write at least 300 words.',
                'availableFrom' => $now,
                'dueDate' => date('Y-m-d H:i:s', strtotime('+5 days')),
                'duration' => null,
                'totalPoints' => 50,
                'weightPercentage' => 20,
                'attemptLimit' => 1,
                'passingScore' => 70,
                'createdBy' => 1,
                'createdAt' => $now,
                'updatedBy' => null,
                'updatedAt' => null,
                'status' => 'Published',
            ];
        }

        if (!empty($data)) {
            $this->db->table('assessments')->insertBatch($data);
        }
    }
}
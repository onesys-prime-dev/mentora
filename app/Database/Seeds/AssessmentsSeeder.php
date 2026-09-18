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
                'course_id' => $course['id'],
                'module_id' => null,
                'learning_item_id' => null,
                'assessment_type_id' => $typeMap['Quiz'],
                'title' => 'Course Pre-Test',
                'description' => 'Initial assessment before starting the course.',
                'instructions' => 'Answer every question.',
                'available_from' => $now,
                'due_date' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'available_until' => date('Y-m-d H:i:s', strtotime('+9 days')),
                'duration' => 30,
                'total_points' => 50,
                'weight_percentage' => 10,
                'attempt_limit' => 1,
                'passing_percentage' => 60,
                'passing_score' => 30,
                'created_by' => 1,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => null,
                'status' => 'Published',
            ];
        }

        if ($module && isset($typeMap['Assignment'])) {

            $data[] = [
                'course_id' => $course['id'],
                'module_id' => $module['id'],
                'learning_item_id' => null,
                'assessment_type_id' => $typeMap['Assignment'],
                'title' => 'Module Assignment',
                'description' => 'Programming assignment.',
                'instructions' => 'Upload your completed activity.',
                'available_from' => $now,
                'due_date' => date('Y-m-d H:i:s', strtotime('+10 days')),
                'available_until' => date('Y-m-d H:i:s', strtotime('+12 days')),
                'duration' => null,
                'total_points' => 100,
                'weight_percentage' => 30,
                'attempt_limit' => 1,
                'passing_percentage' => 75,
                'passing_score' => 75,
                'created_by' => 1,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => null,
                'status' => 'Published',
            ];
        }

        if ($learningItem && isset($typeMap['Assignment'])) {

            $data[] = [
                'course_id' => $course['id'],
                'module_id' => $module['id'],
                'learning_item_id' => $learningItem['id'],
                'assessment_type_id' => $typeMap['Assignment'],
                'title' => 'Reflection Journal',
                'description' => 'Reflection activity.',
                'instructions' => 'Write at least 300 words.',
                'available_from' => $now,
                'due_date' => date('Y-m-d H:i:s', strtotime('+5 days')),
                'available_until' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'duration' => null,
                'total_points' => 50,
                'weight_percentage' => 20,
                'attempt_limit' => 1,
                'passing_percentage' => 70,
                'passing_score' => 35,
                'created_by' => 1,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => null,
                'status' => 'Published',
            ];
        }

        if (!empty($data)) {
            $this->db->table('assessments')->insertBatch($data);
        }
    }
}

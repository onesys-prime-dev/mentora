<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Block sections: a cohort of students (BSIT 1-A) for one term, in a program and
 * a year level, that takes a set of courses together.
 *
 *  - class_sections: the cohort. Its `code` is unique within the institution's
 *    term, so it can stand as the `section_code` of every offering it owns
 *    without two cohorts clashing on a shared course (a general-education
 *    course taken by two programs).
 *  - class_section_students: who is in the cohort. Being a member is what
 *    enrols a student in each of its offerings; an enrolment itself still lives
 *    in `enrollments`, so nothing downstream (gradebook, roster, courses) changes.
 *  - course_offerings.class_section_id: an offering that belongs to a cohort.
 *    Empty for every offering made before this, and for a standalone section
 *    (an irregular student's course is just an enrolment in one offering).
 */
class CreateClassSections extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'term_id'        => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'program_id'     => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'year_level'     => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 1],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 50],
            'capacity'       => ['type' => 'INT', 'constraint' => 5, 'default' => 40],
            'status'         => ['type' => 'ENUM', 'constraint' => ['active', 'archived'], 'default' => 'active'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('program_id');
        $this->forge->addKey('status');
        $this->forge->addUniqueKey(['institution_id', 'term_id', 'code'], 'uq_class_sections_code');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('term_id', 'academic_terms', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('program_id', 'programs', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('class_sections');

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'class_section_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id'  => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('student_user_id');
        $this->forge->addUniqueKey(['class_section_id', 'student_user_id'], 'uq_class_section_student');
        $this->forge->addForeignKey('class_section_id', 'class_sections', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('class_section_students');

        $this->forge->addColumn('course_offerings', [
            'class_section_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
        ]);

        $this->db->query('CREATE INDEX idx_course_offerings_class_section ON course_offerings (class_section_id)');
        $this->db->query('ALTER TABLE course_offerings ADD CONSTRAINT fk_course_offerings_class_section FOREIGN KEY (class_section_id) REFERENCES class_sections (id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE course_offerings DROP FOREIGN KEY fk_course_offerings_class_section');
        $this->db->query('DROP INDEX idx_course_offerings_class_section ON course_offerings');
        $this->forge->dropColumn('course_offerings', 'class_section_id');
        $this->forge->dropTable('class_section_students', true);
        $this->forge->dropTable('class_sections', true);
    }
}

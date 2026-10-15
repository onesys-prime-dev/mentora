<?php

declare(strict_types=1);

use App\Services\Storage\FileAccessService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Who may fetch an uploaded file. Access follows whatever references the
 * file (a learning resource, a submission, a quiz answer, a generation
 * source, an announcement or discussion attachment) and always stays inside
 * that file's own course section.
 *
 * @internal
 */
final class FileAccessServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private FileAccessService $access;

    private int $offering;

    private User $instructor;

    private User $student;

    private User $classmate;

    private User $outsider;

    private User $otherTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new FileAccessService();
        $this->offering = CourseFactory::offering();

        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        $this->classmate = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');
        $this->otherTeacher = CourseFactory::user('instructor');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::enroll($this->offering, $this->student);
        CourseFactory::enroll($this->offering, $this->classmate);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** A stored, active file owned by $owner. @return int file id */
    private function file(User $owner): int
    {
        $file = CourseFactory::file($owner);

        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);

        return $file['id'];
    }

    private function allowed(User $user, int $fileId): bool
    {
        return $this->access->authorize($user, $fileId) !== null;
    }

    /** A second section of the same course, taught by $teacher. @return int offering id */
    private function siblingSection(User $teacher): int
    {
        $db = db_connect();
        $mine = $db->table('course_offerings')->where('id', $this->offering)->get()->getRowArray();

        $db->table('course_offerings')->insert(['course_id' => $mine['course_id'], 'term_id' => $mine['term_id'], 'section_code' => 'Z']);
        $sibling = (int) $db->insertID();

        CourseFactory::assignInstructor($sibling, $teacher);

        return $sibling;
    }

    /** @return array{module: int, unit: int, item: int} */
    private function resourceIn(int $fileId, array $status = []): array
    {
        $module = CourseFactory::module($this->offering, $status['module'] ?? 'published');
        $unit = CourseFactory::unit($module, $status['unit'] ?? 'published');
        $item = CourseFactory::items($unit, [['type' => 'pdf', 'status' => $status['item'] ?? 'published']])[0];
        CourseFactory::resource($item, 'pdf', null, $fileId);

        return ['module' => $module, 'unit' => $unit, 'item' => $item];
    }

    /*
    |--------------------------------------------------------------------------
    | Basics
    |--------------------------------------------------------------------------
    */

    public function testTheOwnerAlwaysGetsTheirFile(): void
    {
        $file = $this->file($this->student);

        $this->assertSame($file, (int) $this->access->authorize($this->student, $file)['id']);
        $this->assertFalse($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($this->instructor, $file));
    }

    public function testMissingInactiveOrDeletedFilesAreNotServed(): void
    {
        $file = CourseFactory::file($this->student);

        $this->assertFalse($this->allowed($this->student, 999999));

        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'pending']);
        $this->assertFalse($this->allowed($this->student, $file['id']));

        db_connect()->table('files')->where('id', $file['id'])->update(['status' => 'active']);
        $this->assertTrue($this->allowed($this->student, $file['id']));

        unlink($file['path']);
        $this->assertFalse($this->allowed($this->student, $file['id']), 'a row whose file is gone on disk');
    }

    /*
    |--------------------------------------------------------------------------
    | Learning resources
    |--------------------------------------------------------------------------
    */

    public function testAPublishedResourceIsOpenToItsInstructorsAndEnrolledStudents(): void
    {
        $file = $this->file($this->instructor);
        $this->resourceIn($file);

        $this->assertTrue($this->allowed($this->student, $file));
        $this->assertTrue($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
        $this->assertFalse($this->allowed($this->otherTeacher, $file));
    }

    public function testStudentsCannotOpenResourcesThatAreNotPublishedYet(): void
    {
        foreach ([['item' => 'draft'], ['unit' => 'draft'], ['module' => 'draft']] as $status) {
            $file = $this->file($this->otherTeacher);
            $this->resourceIn($file, $status);
            $this->assertFalse($this->allowed($this->student, $file), json_encode($status));
        }
    }

    public function testADroppedStudentLosesAccess(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');
        $file = $this->file($this->instructor);
        $this->resourceIn($file);

        $this->assertFalse($this->allowed($dropped, $file));
    }

    public function testAResourceBehindAnUnfinishedPrerequisiteIsLocked(): void
    {
        $module = CourseFactory::module($this->offering);
        $unit = CourseFactory::unit($module);
        [$first, $second] = CourseFactory::items($unit, [['type' => 'reading'], ['type' => 'pdf', 'mode' => 'previous']]);

        $file = $this->file($this->instructor);
        CourseFactory::resource($second, 'pdf', null, $file);

        $this->assertFalse($this->allowed($this->student, $file));

        CourseFactory::progress($first, $this->student);

        $this->assertTrue($this->allowed($this->student, $file));
        $this->assertFalse($this->allowed($this->classmate, $file), 'progress is per student');
    }

    public function testAnInstructorOfAnotherSectionCannotOpenThisSectionsDraftsOrFiles(): void
    {
        $file = $this->file($this->instructor);
        $this->resourceIn($file, ['item' => 'draft']);

        $this->assertFalse($this->allowed($this->otherTeacher, $file));
        $this->assertTrue($this->allowed($this->instructor, $file));
    }

    /*
    |--------------------------------------------------------------------------
    | Student work
    |--------------------------------------------------------------------------
    */

    /** @return array{file: int, assessment: int} */
    private function submittedFile(): array
    {
        $module = CourseFactory::module($this->offering);
        $assessment = CourseFactory::assessment($this->offering, $this->instructor, $module, null, 'Assignment');
        $file = $this->file($this->student);
        AssessmentFactory::attempt($assessment, $this->student, 'Submitted', 0.0, ['submission_file_id' => $file]);

        return ['file' => $file, 'assessment' => $assessment];
    }

    public function testASubmissionFileIsOpenToItsStudentAndTheCoursesInstructor(): void
    {
        ['file' => $file] = $this->submittedFile();

        $this->assertTrue($this->allowed($this->student, $file));
        $this->assertTrue($this->allowed($this->instructor, $file));
        $this->assertFalse($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
        $this->assertFalse($this->allowed($this->otherTeacher, $file));
    }

    public function testAnInstructorOfAnotherSectionCannotOpenThisSectionsSubmissions(): void
    {
        ['file' => $file] = $this->submittedFile();
        $sectionTeacher = CourseFactory::user('instructor');
        $this->siblingSection($sectionTeacher);

        $this->assertFalse($this->allowed($sectionTeacher, $file));
    }

    public function testAQuizFileAnswerFollowsTheSameRules(): void
    {
        $module = CourseFactory::module($this->offering);
        $quiz = CourseFactory::assessment($this->offering, $this->instructor, $module);
        $question = AssessmentFactory::question($quiz, $this->instructor, 'File Upload');
        $submission = AssessmentFactory::attempt($quiz, $this->student, 'Submitted');
        $file = $this->file($this->student);
        AssessmentFactory::response($submission, $question, ['uploaded_file_id' => $file]);

        $sectionTeacher = CourseFactory::user('instructor');
        $this->siblingSection($sectionTeacher);

        $this->assertTrue($this->allowed($this->student, $file));
        $this->assertTrue($this->allowed($this->instructor, $file));
        $this->assertFalse($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($sectionTeacher, $file));
    }

    /*
    |--------------------------------------------------------------------------
    | Generation sources
    |--------------------------------------------------------------------------
    */

    public function testAGenerationSourceIsOpenToTheCoursesInstructorsOnly(): void
    {
        $file = $this->file($this->otherTeacher);
        $courseId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];

        db_connect()->table('ai_generation_batches')->insert([
            'course_id' => $courseId, 'source_type' => 'file', 'source_file_id' => $file,
            'status' => 'completed', 'created_by' => $this->instructor->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($this->allowed($this->instructor, $file));
        $this->assertFalse($this->allowed($this->student, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
    }

    /*
    |--------------------------------------------------------------------------
    | Announcement and discussion attachments
    |--------------------------------------------------------------------------
    */

    public function testAnAnnouncementAttachmentIsOpenToTheCoursesMembers(): void
    {
        $file = $this->file($this->instructor);
        db_connect()->table('announcements')->insert(['offering_id' => $this->offering, 'author_id' => $this->instructor->id, 'subject' => 'S', 'message' => '{}']);
        db_connect()->table('announcement_attachments')->insert(['announcement_id' => db_connect()->insertID(), 'file_id' => $file, 'order_index' => 1]);

        $this->assertTrue($this->allowed($this->student, $file));
        $this->assertTrue($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
        $this->assertFalse($this->allowed($this->otherTeacher, $file));
    }

    public function testADiscussionAttachmentIsOpenToTheCoursesMembers(): void
    {
        $file = $this->file($this->student);
        $thread = CourseFactory::thread($this->offering, $this->student);
        db_connect()->table('discussion_attachments')->insert(['thread_id' => $thread, 'file_id' => $file, 'order_index' => 1]);

        $this->assertTrue($this->allowed($this->classmate, $file));
        $this->assertTrue($this->allowed($this->instructor, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
        $this->assertFalse($this->allowed($this->otherTeacher, $file));
    }

    public function testADiscussionReplyAttachmentIsOpenToTheCoursesMembers(): void
    {
        $file = $this->file($this->student);
        $thread = CourseFactory::thread($this->offering, $this->student);
        db_connect()->table('discussion_replies')->insert(['thread_id' => $thread, 'author_id' => $this->student->id, 'body' => 'r', 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('discussion_attachments')->insert(['thread_id' => $thread, 'reply_id' => db_connect()->insertID(), 'file_id' => $file, 'order_index' => 1]);

        $this->assertTrue($this->allowed($this->classmate, $file));
        $this->assertFalse($this->allowed($this->outsider, $file));
    }

    /*
    |--------------------------------------------------------------------------
    | Chairperson and dean
    |--------------------------------------------------------------------------
    */

    /** @return array{sub: int, department: int} the sub-department and department of this test's offering */
    private function hierarchyOf(int $offeringId): array
    {
        $row = db_connect()->table('course_offerings co')
            ->select('sd.id AS sub, sd.department_id AS department')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->where('co.id', $offeringId)->get()->getRowArray();

        return ['sub' => (int) $row['sub'], 'department' => (int) $row['department']];
    }

    private function chairOf(int $offeringId): User
    {
        $chair = CourseFactory::user('chairperson');
        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $this->hierarchyOf($offeringId)['sub'],
            'chairperson_user_id' => $chair->id,
            'status' => 'active',
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $chair;
    }

    private function deanOf(int $offeringId): User
    {
        $dean = CourseFactory::user('dean');
        db_connect()->table('dean_assignments')->insert([
            'department_id' => $this->hierarchyOf($offeringId)['department'],
            'dean_user_id' => $dean->id,
            'status' => 'active',
            'active_marker' => 1,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $dean;
    }

    public function testTheChairpersonOfTheSubDepartmentOpensItsCourseFilesWithoutTeachingIt(): void
    {
        $chair = $this->chairOf($this->offering);
        $file = $this->file($this->instructor);
        $this->resourceIn($file, ['item' => 'draft']);

        $this->assertTrue($this->allowed($chair, $file), 'even an unpublished resource: the chairperson has instructor rights');
    }

    public function testAChairpersonOfAnotherSubDepartmentIsRefused(): void
    {
        $otherSection = CourseFactory::offering();
        $elsewhere = $this->chairOf($otherSection);
        $file = $this->file($this->instructor);
        $this->resourceIn($file);

        $this->assertFalse($this->allowed($elsewhere, $file));
    }

    public function testTheDeanOfTheDepartmentOpensItsCourseFilesAndStudentWork(): void
    {
        $dean = $this->deanOf($this->offering);
        $resource = $this->file($this->instructor);
        $this->resourceIn($resource);

        $this->assertTrue($this->allowed($dean, $resource));

        $quiz = CourseFactory::assessment($this->offering, $this->instructor);
        $work = $this->file($this->student);
        AssessmentFactory::attempt($quiz, $this->student, 'Submitted', 0.0, ['submission_file_id' => $work]);

        $this->assertTrue($this->allowed($dean, $work));
    }

    public function testADeanOfAnotherDepartmentIsRefused(): void
    {
        $other = $this->deanOf(CourseFactory::offering());
        $file = $this->file($this->instructor);
        $this->resourceIn($file);

        $this->assertFalse($this->allowed($other, $file));
    }
}

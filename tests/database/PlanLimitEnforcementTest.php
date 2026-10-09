<?php

declare(strict_types=1);

use App\Exceptions\PlanLimitExceeded;
use App\Services\Courses\AnnouncementService;
use App\Services\Courses\Assessments\QuestionGenerationService;
use App\Services\Storage\StorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Where the plan limits bite: every upload (it all passes through
 * StorageService) and every AI question generation. A refused request
 * changes nothing and says why.
 *
 * @internal
 */
final class PlanLimitEnforcementTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const MB = 1048576;

    /** @var list<string> temporary files to remove */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        auth()->logout();

        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function institutionOf(int $offering): int
    {
        return (int) db_connect()->table('course_offerings')
            ->select('departments.institution_id')
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('course_offerings.id', $offering)
            ->get()->getRowArray()['institution_id'];
    }

    private function subscribe(int $institution, ?int $seats = null, ?int $storageMb = null, ?int $ai = null): void
    {
        db_connect()->table('plans')->insert(['name' => 'Plan' . random_int(1000, 9999), 'seat_limit' => $seats, 'storage_limit_mb' => $storageMb, 'ai_generation_limit' => $ai, 'status' => 'active']);

        db_connect()->table('subscriptions')->insert(['institution_id' => $institution, 'plan_id' => (int) db_connect()->insertID(), 'start_date' => '2025-01-01', 'end_date' => '2099-12-31']);
    }

    private function join(int $institution, User $user): User
    {
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id]);

        return $user;
    }

    private function stored(User $owner, int $bytes): void
    {
        db_connect()->table('files')->insert([
            'owner_user_id' => $owner->id, 'original_filename' => 'a.pdf', 'stored_filename' => uniqid('f', true) . '.pdf',
            'mime_type' => 'application/pdf', 'file_size' => $bytes, 'storage_key' => 'x/' . uniqid('', true), 'status' => 'active',
        ]);
    }

    /** A real file on disk for StorageService to move. */
    private function tempFile(string $content = 'hello'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($path, $content);
        $this->cleanup[] = $path;

        return new UploadedFile($path, 'note.txt', 'text/plain', strlen($content), UPLOAD_ERR_OK);
    }

    /**
     * True when the upload gets past the plan check. (A file made by a test is not a genuine
     * upload, so the move that follows may fail; that is after the check and not what is tested.)
     */
    private function passesThePlanCheck(UploadedFile $file): bool
    {
        try {
            (new StorageService())->upload($file, 'plan-limit-tests');
        } catch (PlanLimitExceeded) {
            return false;
        } catch (CodeIgniter\HTTP\Exceptions\HTTPException) {
            return true;
        }

        return true;
    }

    /** @return array{User, int} an instructor in an institution on a plan, and the offering */
    private function instructorOnAPlan(?int $storageMb = null, ?int $ai = null): array
    {
        $offering    = CourseFactory::offering();
        $institution = $this->institutionOf($offering);
        $teacher     = $this->join($institution, CourseFactory::user('instructor'));
        CourseFactory::assignInstructor($offering, $teacher);
        $this->subscribe($institution, null, $storageMb, $ai);

        return [$teacher, $offering];
    }

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */

    public function testAnUploadThatFitsGetsPastTheCheck(): void
    {
        [$teacher] = $this->instructorOnAPlan(10);
        auth()->login($teacher);

        $this->assertTrue($this->passesThePlanCheck($this->tempFile('hello')));
    }

    public function testAnUploadWhenStorageIsFullIsRefusedAndNothingIsMoved(): void
    {
        [$teacher] = $this->instructorOnAPlan(5);
        $this->stored($teacher, 5 * self::MB);
        auth()->login($teacher);
        $file = $this->tempFile('hello');

        try {
            (new StorageService())->upload($file, 'plan-limit-tests');
            $this->fail('expected the upload to be refused');
        } catch (PlanLimitExceeded $e) {
            $this->assertSame('storage', $e->limit());
            $this->assertStringContainsString('storage allowance', $e->getMessage());
        }

        $this->assertFileExists($file->getTempName(), 'the file was not moved');
    }

    public function testAnUploadThatWouldGoOverIsRefusedEvenWhenSomeRoomIsLeft(): void
    {
        [$teacher] = $this->instructorOnAPlan(5);
        $this->stored($teacher, 5 * self::MB - 3);   // 3 bytes of room
        auth()->login($teacher);

        $this->expectException(PlanLimitExceeded::class);

        (new StorageService())->upload($this->tempFile('hello'), 'plan-limit-tests'); // 5 bytes
    }

    public function testAnUploadThatExactlyFillsTheLastOfTheRoomIsAllowed(): void
    {
        [$teacher] = $this->instructorOnAPlan(5);
        $this->stored($teacher, 5 * self::MB - 5);
        auth()->login($teacher);

        $this->assertTrue($this->passesThePlanCheck($this->tempFile('hello'))); // 5 bytes
    }

    public function testAnotherPersonInTheSameInstitutionIsStoppedToo(): void
    {
        [$teacher, $offering] = $this->instructorOnAPlan(5);
        $this->stored($teacher, 5 * self::MB);

        $colleague = $this->join($this->institutionOf($offering), CourseFactory::user('student'));
        auth()->login($colleague);

        $this->expectException(PlanLimitExceeded::class);

        (new StorageService())->upload($this->tempFile(), 'plan-limit-tests');
    }

    public function testAnotherInstitutionsFullStorageDoesNotStopMe(): void
    {
        [$theirTeacher] = $this->instructorOnAPlan(1);
        $this->stored($theirTeacher, 50 * self::MB);

        [$me] = $this->instructorOnAPlan(100);
        auth()->login($me);

        $this->assertTrue($this->passesThePlanCheck($this->tempFile()));
    }

    public function testNobodySignedInAndSystemAdministratorsAreNotLimited(): void
    {
        [$teacher] = $this->instructorOnAPlan(1);
        $this->stored($teacher, 50 * self::MB);

        // Nobody signed in (a seeder or a job).
        $this->assertTrue($this->passesThePlanCheck($this->tempFile()));

        // A system administrator belongs to no institution.
        auth()->login(CourseFactory::user('admin'));
        $this->assertTrue($this->passesThePlanCheck($this->tempFile()));
    }

    public function testNoLimitOnThePlanMeansUploadsAreNeverRefused(): void
    {
        [$teacher] = $this->instructorOnAPlan(null);
        $this->stored($teacher, 500 * self::MB);
        auth()->login($teacher);

        $this->assertTrue($this->passesThePlanCheck($this->tempFile()));
    }

    public function testTheAnnouncementAttachmentUploadIsStoppedToo(): void
    {
        [$teacher, $offering] = $this->instructorOnAPlan(5);
        $this->stored($teacher, 5 * self::MB);
        auth()->login($teacher);

        $file = $this->getMockBuilder(UploadedFile::class)->disableOriginalConstructor()->onlyMethods(['isValid', 'getSize', 'getMimeType'])->getMock();
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(100);
        $file->method('getMimeType')->willReturn('application/pdf');

        $before = db_connect()->table('files')->countAllResults();

        try {
            (new AnnouncementService())->uploadAttachment($teacher, $offering, $file);
            $this->fail('expected the upload to be refused');
        } catch (PlanLimitExceeded) {
            // expected
        }

        $this->assertSame($before, db_connect()->table('files')->countAllResults(), 'no file record was made');
    }

    /*
    |--------------------------------------------------------------------------
    | AI question generation
    |--------------------------------------------------------------------------
    */

    private function aiBatch(int $offering, User $teacher, int $count, string $createdAt): void
    {
        $assessment = CourseFactory::assessment($offering, $teacher);
        $course     = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];

        db_connect()->table('ai_generation_batches')->insert([
            'assessment_id' => $assessment, 'course_id' => $course, 'source_type' => 'topic', 'topic' => 'x',
            'requested_count' => $count, 'generated_count' => $count, 'status' => 'completed', 'created_by' => $teacher->id, 'created_at' => $createdAt,
        ]);
    }

    public function testAGenerationRequestBeyondTheTermAllowanceIsRefusedAndStartsNothing(): void
    {
        [$teacher, $offering] = $this->instructorOnAPlan(null, 20);
        db_connect()->table('academic_terms')->where('institution_id', $this->institutionOf($offering))->update(['start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        $this->aiBatch($offering, $teacher, 15, date('Y-m-d H:i:s'));

        $assessment = CourseFactory::assessment($offering, $teacher);
        $course     = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];
        $batches    = db_connect()->table('ai_generation_batches')->countAllResults();

        $result = (new QuestionGenerationService())->startBatch($teacher, ['assessment_id' => $assessment, 'course_id' => $course, 'source_type' => 'topic', 'topic' => 'Fractions', 'count' => 10]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('5 AI question generations left', $result['message']);
        $this->assertSame($batches, db_connect()->table('ai_generation_batches')->countAllResults(), 'no batch was created');
    }

    public function testARequestThatIsOverAnExhaustedAllowanceSaysSo(): void
    {
        [$teacher, $offering] = $this->instructorOnAPlan(null, 10);
        db_connect()->table('academic_terms')->where('institution_id', $this->institutionOf($offering))->update(['start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        $this->aiBatch($offering, $teacher, 10, date('Y-m-d H:i:s'));

        $assessment = CourseFactory::assessment($offering, $teacher);
        $course     = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];

        $result = (new QuestionGenerationService())->startBatch($teacher, ['assessment_id' => $assessment, 'course_id' => $course, 'source_type' => 'topic', 'topic' => 'Fractions', 'count' => 1]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('used all 10 AI question generations', $result['message']);
    }

    public function testTheUploadForAFileBasedGenerationIsNotStartedWhenTheAllowanceIsGone(): void
    {
        [$teacher, $offering] = $this->instructorOnAPlan(null, 5);
        db_connect()->table('academic_terms')->where('institution_id', $this->institutionOf($offering))->update(['start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        $this->aiBatch($offering, $teacher, 5, date('Y-m-d H:i:s'));

        $assessment = CourseFactory::assessment($offering, $teacher);
        $course     = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'];
        $filesBefore = db_connect()->table('files')->countAllResults();

        $file = $this->getMockBuilder(UploadedFile::class)->disableOriginalConstructor()->onlyMethods(['isValid'])->getMock();
        $file->method('isValid')->willReturn(true);

        $result = (new QuestionGenerationService())->startBatch($teacher, ['assessment_id' => $assessment, 'course_id' => $course, 'source_type' => 'file', 'count' => 3], $file);

        $this->assertFalse($result['success']);
        $this->assertSame($filesBefore, db_connect()->table('files')->countAllResults(), 'the source file was not even stored');
    }
}

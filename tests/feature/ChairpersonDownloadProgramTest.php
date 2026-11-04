<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Download program: one workbook with the class gradebook of every section of the chaired sub-departments, an
 * audit entry for each, nothing from another program, and a polite refusal when no section has grading.
 *
 * @internal
 */
final class ChairpersonDownloadProgramTest extends RouteTestCase
{
    /** @var array{institution: int, department: int} */
    private array $unit;

    private int $sub;

    private int $program;

    private int $term;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->unit    = DeanFactory::department('CDP');
        $this->sub     = DeanFactory::subDepartment($this->unit['department'], 'CDP');
        $this->program = DeanFactory::program($this->sub, 'CDP');
        $this->term    = DeanFactory::term($this->unit['institution']);
        $this->chair   = DeanFactory::chair($this->sub, 'Cara');
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** A section of $program (default: the chaired one) in the test's term. */
    private function section(string $code, ?int $program = null): int
    {
        $offering = DeanFactory::offering($program ?? $this->program, $this->term, $code, 'A');
        CourseFactory::assignInstructor($offering, PeopleFactory::person('instructor', 'Ian', 'Structor', null, $this->unit['institution']));

        return $offering;
    }

    /** A published scheme with one enrolled student, so the section has a gradebook. */
    private function graded(int $offering): void
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        $student = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->unit['institution']);
        CourseFactory::enroll($offering, $student);
    }

    private function label(int $offering): string
    {
        $row = db_connect()->table('course_offerings co')->select('c.course_code, co.section_code')
            ->join('courses c', 'c.id = co.course_id')->where('co.id', $offering)->get()->getRow();

        return $row->course_code . '-' . $row->section_code;
    }

    private function download(string $term = ''): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($this->chair);

        return $this->get('chairperson/grades/download-program?term=' . ($term !== '' ? $term : $this->term));
    }

    /** @return list<string> the sheet names of the workbook the response carries */
    private function sheets(DownloadResponse $response): array
    {
        ob_start();
        $response->sendBody();
        $path = tempnam(sys_get_temp_dir(), 'gb');
        file_put_contents($path, (string) ob_get_clean());

        $names = IOFactory::load($path)->getSheetNames();
        @unlink($path);

        return $names;
    }

    private function exports(): int
    {
        return db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->countAllResults();
    }

    public function testASheetPerProgramSectionAndAnAuditEach(): void
    {
        $first  = $this->section('IT101');
        $second = $this->section('IT102');
        $bare   = $this->section('IT103');
        $this->graded($first);
        $this->graded($second);

        $response = $this->download()->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $response->buildHeaders();
        $this->assertStringContainsString('program-gradebooks-', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString(date('Ymd') . '.xlsx', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame([$this->label($first), $this->label($second)], $this->sheets($response), 'no sheet for ' . $this->label($bare));

        $audits = db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->orderBy('id')->get()->getResultArray();
        $this->assertSame([$first, $second], array_map(static fn (array $row): int => (int) $row['target_id'], $audits));
        $this->assertSame((int) $this->chair->id, (int) $audits[0]['actor_user_id']);
    }

    public function testSectionsOutsideTheProgramAreLeftOut(): void
    {
        $mine = $this->section('IT101');

        $otherSub = DeanFactory::subDepartment($this->unit['department'], 'OTH');
        $theirs   = $this->section('CS101', DeanFactory::program($otherSub, 'OTH'));
        $this->graded($mine);
        $this->graded($theirs);

        $response = $this->download()->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertSame([$this->label($mine)], $this->sheets($response));
        $this->assertSame([$mine], array_map(
            static fn (array $row): int => (int) $row['target_id'],
            db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->get()->getResultArray(),
        ));
    }

    public function testWithoutProgramMonitorIsRefused(): void
    {
        $this->graded($this->section('IT101'));

        db_connect()->table('role_permissions')->insert(['institution_id' => $this->unit['institution'], 'role' => 'chairperson', 'permission' => 'program.monitor', 'granted' => 0]);
        PermissionMatrix::flush();
        $this->chair = model(UserModel::class)->findById($this->chair->id);

        $result = $this->download();

        $this->assertNotInstanceOf(DownloadResponse::class, $result->response());
        $this->assertSame(0, $this->exports());
    }

    public function testNoGradingYetRefusesPolitely(): void
    {
        $this->section('IT101');

        $this->download()->assertRedirect();
        $this->assertSame('None of the program\'s sections this term has grading yet, so there is nothing to download.', session('error'));

        // A term that is not the institution's, or not a number, refuses the same way.
        foreach (['999999', 'abc'] as $term) {
            $this->download($term)->assertRedirect();
        }

        $this->assertSame(0, $this->exports());
    }
}

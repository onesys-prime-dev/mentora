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
 * Download department: one workbook with the class gradebook of every section of the dean's department (every
 * program), an audit entry for each, nothing from another department, and department.monitor behind it.
 *
 * @internal
 */
final class DeanDownloadDepartmentTest extends RouteTestCase
{
    /** @var array{institution: int, department: int} */
    private array $unit;

    private int $programA;

    private int $programB;

    private int $term;

    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->unit     = DeanFactory::department('DDD');
        $this->programA = DeanFactory::program(DeanFactory::subDepartment($this->unit['department'], 'DDA'), 'DDA');
        $this->programB = DeanFactory::program(DeanFactory::subDepartment($this->unit['department'], 'DDB'), 'DDB');
        $this->term     = DeanFactory::term($this->unit['institution']);
        $this->dean     = DeanFactory::dean($this->unit['institution'], $this->unit['department']);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function section(string $code, int $program): int
    {
        $offering = DeanFactory::offering($program, $this->term, $code, 'A');
        CourseFactory::assignInstructor($offering, PeopleFactory::person('instructor', 'Ian', 'Structor', null, $this->unit['institution']));

        return $offering;
    }

    /** A published scheme with one enrolled student, so the section has a gradebook. */
    private function graded(int $offering): void
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        CourseFactory::enroll($offering, PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->unit['institution']));
    }

    private function label(int $offering): string
    {
        $row = db_connect()->table('course_offerings co')->select('c.course_code, co.section_code')
            ->join('courses c', 'c.id = co.course_id')->where('co.id', $offering)->get()->getRow();

        return $row->course_code . '-' . $row->section_code;
    }

    private function download(): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($this->dean);

        return $this->get('dean/grades/download-department?term=' . $this->term);
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

    /** @return list<int> the sections the export entries name, in order */
    private function exported(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['target_id'],
            db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->orderBy('id')->get()->getResultArray(),
        );
    }

    public function testASheetPerDepartmentSectionAndAnAuditEach(): void
    {
        $a    = $this->section('IT101', $this->programA);
        $b    = $this->section('CS101', $this->programB);
        $bare = $this->section('IT102', $this->programA);
        $this->graded($a);
        $this->graded($b);

        $response = $this->download()->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $response->buildHeaders();
        $this->assertStringContainsString('department-gradebooks-', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame([$this->label($b), $this->label($a)], $this->sheets($response), 'every program, no sheet for ' . $this->label($bare));

        $this->assertSame([$b, $a], $this->exported());
        $this->assertSame((int) $this->dean->id, (int) db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->get()->getRow()->actor_user_id);
    }

    public function testOtherDepartmentsLeftOut(): void
    {
        $mine = $this->section('IT101', $this->programA);

        $other  = DeanFactory::department('DDO', $this->unit['institution']);
        $theirs = $this->section('XX101', DeanFactory::program(DeanFactory::subDepartment($other['department'], 'DDO'), 'DDO'));
        $this->graded($mine);
        $this->graded($theirs);

        $response = $this->download()->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertSame([$this->label($mine)], $this->sheets($response));
        $this->assertSame([$mine], $this->exported());
    }

    public function testWithoutDepartmentMonitorRefused(): void
    {
        $this->graded($this->section('IT101', $this->programA));

        db_connect()->table('role_permissions')->insert(['institution_id' => $this->unit['institution'], 'role' => 'dean', 'permission' => 'department.monitor', 'granted' => 0]);
        PermissionMatrix::flush();
        $this->dean = model(UserModel::class)->findById($this->dean->id);

        $result = $this->download();

        $this->assertNotInstanceOf(DownloadResponse::class, $result->response());
        $this->assertSame(lang('Auth.notEnoughPrivilege'), session('error'));
        $this->assertSame([], $this->exported());
    }

    public function testNoGradingYetRefusesPolitely(): void
    {
        $this->section('IT101', $this->programA);

        $this->download()->assertRedirect();
        $this->assertSame("None of the department's sections this term has grading yet, so there is nothing to download.", session('error'));
        $this->assertSame([], $this->exported());
    }
}

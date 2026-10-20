<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Courses\Grades\GradeReportService;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * What the grade reports say: the class and student figures come from the same
 * grades the gradebook shows, and the files are safe to open and to share.
 *
 * @internal
 */
final class GradeReportServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GradeReportService $service;

    private int $offering;

    private int $scheme;

    private int $exam;

    private int $quiz;

    private User $teacher;

    /** @var array<string, User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GradeReportService();

        $this->offering = CourseFactory::offering();
        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $this->scheme = GradeFactory::scheme($this->offering, 75.0);
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $quizzes = GradeFactory::category($this->scheme, 'Quizzes', 40, null, 1);
        $exams = GradeFactory::category($this->scheme, 'Exams', 60, null, 2);
        $this->quiz = GradeFactory::item($this->scheme, $quizzes, 'Quiz 1', 20);
        $this->exam = GradeFactory::item($this->scheme, $exams, 'Midterm', 100);

        // Ann 90, Ben 70, Cy nothing yet.
        foreach (['Ann' => [18, 90], 'Ben' => [14, 70], 'Cy' => null] as $name => $scores) {

            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => $name, 'last_name' => 'Tester', 'student_number' => 'N-' . $name]);
            CourseFactory::enroll($this->offering, $student);
            $this->students[$name] = $student;

            if ($scores !== null) {
                GradeFactory::score($this->offering, $student, $this->quiz, $scores[0]);
                GradeFactory::score($this->offering, $student, $this->exam, $scores[1]);
            }

        }

        (new GradingEngineService())->recalculateOffering($this->offering);
    }

    /** @return array<string, array<string, mixed>> the class rows by first name */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->service->classReport($this->offering)['rows'] as $row) {
            $rows[explode(',', $row['student_name'])[1] !== '' ? trim(explode(',', $row['student_name'])[1]) : $row['student_name']] = $row;
        }

        return $rows;
    }

    public function testASectionWithoutGradingHasNoReport(): void
    {
        $this->assertNull($this->service->classReport(CourseFactory::offering('Z')));
    }

    public function testTheClassReportCarriesTheSameGradesTheGradebookStores(): void
    {
        $rows = $this->rows();

        $this->assertEquals(90.0, $rows['Ann']['percentage']);
        $this->assertEquals(70.0, $rows['Ben']['percentage']);
        $this->assertNull($rows['Cy']['percentage']);
        $this->assertSame('On track', $rows['Ann']['tag']);
        $this->assertSame('At risk', $rows['Ben']['tag']);
        $this->assertSame('Incomplete', $rows['Cy']['tag']);
        $this->assertSame('N-Ann', $rows['Ann']['student_number']);
        $this->assertEquals(18.0, $rows['Ann']['scores'][$this->quiz]);
        $this->assertNull($rows['Cy']['scores'][$this->quiz]);
    }

    public function testTheSummaryHasTheMeanMedianRangeAndWhoIsAtOrAbovePassing(): void
    {
        $summary = $this->service->classReport($this->offering)['summary'];

        $this->assertSame(3, $summary['students']);
        $this->assertSame(2, $summary['graded']);
        $this->assertEquals(80.0, $summary['mean']);
        $this->assertEquals(80.0, $summary['median']);
        $this->assertEquals(90.0, $summary['high']);
        $this->assertEquals(70.0, $summary['low']);
        $this->assertSame(1, $summary['at_or_above_passing']);
        $this->assertSame(1, $summary['tags']['On track']);
    }

    public function testTheSpreadPutsEachGradeInOneBucket(): void
    {
        $spread = array_column($this->service->classReport($this->offering)['summary']['spread'], 'count', 'label');

        $this->assertSame(1, $spread['70 to 79']);
        $this->assertSame(1, $spread['90 to 100']);
        $this->assertSame(2, array_sum($spread));
    }

    public function testStandingReadsPassedAndFailedOnceTheGradesAreFinal(): void
    {
        (new GradeFinalizationService())->finalize($this->teacher, $this->offering);

        $rows = $this->rows();

        $this->assertSame('Passed', $rows['Ann']['tag']);
        $this->assertSame('Failed', $rows['Ben']['tag']);
        $this->assertTrue($rows['Ann']['is_final']);
        $this->assertNotNull($this->service->classReport($this->offering)['finalization']);
    }

    public function testAnExcusedItemAndAnOverrideAreSaidInTheNotes(): void
    {
        (new \App\Services\Courses\Grades\GradingSchemeService())->updateManualScore($this->teacher, $this->offering, $this->quiz, (int) $this->students['Ben']->id, null, true);
        (new GradingEngineService())->overrideFinalGrade($this->teacher, $this->offering, $this->students['Ann']->id, 95.0, 'Appeal');

        $rows = $this->rows();

        $this->assertSame('EX', $rows['Ben']['scores'][$this->quiz]);
        $this->assertContains('1 item excused', $rows['Ben']['notes']);
        $this->assertContains('Grade set by the instructor', $rows['Ann']['notes']);
        $this->assertEquals(95.0, $rows['Ann']['percentage']);
    }

    public function testAStudentReportHasTheirScoresAndHowTheGradeIsMadeUp(): void
    {
        $report = $this->service->studentReport($this->offering, (int) $this->students['Ann']->id);

        $this->assertSame('N-Ann', $report['student']['student_number']);
        $this->assertEqualsCanonicalizing(['Quizzes', 'Exams'], array_column($report['categories'], 'name'));
        $this->assertEquals([18.0, 90.0], array_column($report['items'], 'score'));
        $this->assertEquals(80.0, $report['class_mean']);
    }

    public function testAStudentReportIsOnlyForSomeoneInTheSectionAndCanBeHeldBackUntilFinal(): void
    {
        $this->assertNull($this->service->studentReport($this->offering, (int) CourseFactory::user('student')->id));
        $this->assertNull($this->service->studentReport($this->offering, (int) $this->students['Ann']->id, true), 'not final yet');

        (new GradeFinalizationService())->finalize($this->teacher, $this->offering);

        $this->assertNotNull($this->service->studentReport($this->offering, (int) $this->students['Ann']->id, true));
    }

    public function testTheCsvIsSafeToOpenInASpreadsheet(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->students['Cy']->id)->update(['last_name' => '=HYPERLINK("http://x")']);

        $file = $this->service->classSheet($this->service->classReport($this->offering), 'csv');

        $csv = (string) file_get_contents($file['file_path']);
        @unlink($file['file_path']);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 byte order mark so accents open correctly');
        $this->assertStringContainsString('student_number', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'text that looks like a formula is made plain');
        $this->assertMatchesRegularExpression('/\.csv$/', $file['filename']);
    }

    /** @return list<list<string>> */
    private function csvRows(array $report): array
    {
        $file = $this->service->classSheet($report, 'csv');
        $rows = array_map('str_getcsv', file($file['file_path'], FILE_IGNORE_NEW_LINES));
        @unlink($file['file_path']);

        $rows[0][0] = ltrim($rows[0][0], "\xEF\xBB\xBF");

        return $rows;
    }

    public function testTheCsvIsDataForOtherSoftwareNotAReport(): void
    {
        $rows = $this->csvRows($this->service->classReport($this->offering));

        $this->assertSame(
            ['student_id', 'student_number', 'student_name', 'score: Quiz 1 (max 20)', 'score: Midterm (max 100)', 'category_percent: Quizzes', 'category_percent: Exams', 'final_percent', 'grade', 'standing', 'is_final', 'is_overridden', 'curve_in_force', 'excused_items', 'incomplete_until'],
            $rows[0]
        );

        $ann = array_values(array_filter($rows, static fn (array $r): bool => ($r[1] ?? '') === 'N-Ann'))[0];

        $this->assertSame((string) $this->students['Ann']->id, $ann[0], 'a stable id comes first');
        $this->assertSame('18', $ann[3], 'plain numbers, nothing formatted');
        $this->assertSame('90', $ann[7]);
        $this->assertSame(['no', 'no', 'no'], array_slice($ann, 10, 3), 'yes/no flags, not words');
        $this->assertNotContains('Notes', $rows[0], 'no prose written for a reader');
    }

    public function testInTheCsvAnExcusedItemIsABlankScoreNamedInItsOwnColumn(): void
    {
        (new \App\Services\Courses\Grades\GradingSchemeService())->updateManualScore($this->teacher, $this->offering, $this->quiz, (int) $this->students['Ben']->id, null, true);

        $rows = $this->csvRows($this->service->classReport($this->offering));

        $ben = array_values(array_filter($rows, static fn (array $r): bool => ($r[1] ?? '') === 'N-Ben'))[0];

        $this->assertSame('', $ben[3], 'no EX in a number column');
        $this->assertSame('Quiz 1', $ben[13]);
    }

    public function testTheCsvFlagsFinalOverriddenAndCurvedGradesAsYesNo(): void
    {
        (new GradeFinalizationService())->finalize($this->teacher, $this->offering);
        (new GradingEngineService())->overrideFinalGrade($this->teacher, $this->offering, $this->students['Ann']->id, 99.0, 'Appeal');

        $rows = $this->csvRows($this->service->classReport($this->offering));
        $ann = array_values(array_filter($rows, static fn (array $r): bool => ($r[1] ?? '') === 'N-Ann'))[0];

        $this->assertSame('99', $ann[7]);
        $this->assertSame(['yes', 'yes'], array_slice($ann, 10, 2));
    }

    public function testTheCsvAndTheExcelReportAreDifferentFiles(): void
    {
        $report = $this->service->classReport($this->offering);

        $csv = $this->service->classSheet($report, 'csv');
        $xlsx = $this->service->classSheet($report, 'xlsx');

        $this->assertMatchesRegularExpression('/^gradebook-data-/', $csv['filename']);
        $this->assertMatchesRegularExpression('/^gradebook-[A-Z0-9]/', $xlsx['filename']);

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx['file_path']);
        @unlink($csv['file_path']);
        @unlink($xlsx['file_path']);

        $header = $book->getSheetByName('Gradebook')->toArray()[0];

        $this->assertContains('Notes', $header, 'the report has its words');
        $this->assertContains('Student number', $header, 'and readable headers');
        $this->assertNotNull($book->getSheetByName('Summary'));
    }

    public function testTheExcelReportColoursTheStandingsAndFormatsThePercentages(): void
    {
        $xlsx = $this->service->classSheet($this->service->classReport($this->offering), 'xlsx');

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx['file_path'])->getSheetByName('Gradebook');
        @unlink($xlsx['file_path']);

        $header = $sheet->toArray()[0];
        $standing = array_search('Standing', $header, true) + 1;
        $percent = array_search('Grade %', $header, true) + 1;

        $rows = $sheet->toArray();

        foreach ($rows as $offset => $row) {
            if ($offset > 0 && $row[1] === 'Tester, Ann') {
                $style = $sheet->getStyle([$standing, $offset + 1]);
                $this->assertSame('FF2E7D4F', $style->getFont()->getColor()->getARGB(), 'On track is green');
                $this->assertTrue($style->getFont()->getBold());
                $this->assertSame('0.00', $sheet->getStyle([$percent, $offset + 1])->getNumberFormat()->getFormatCode());
            }
        }
    }

    public function testTheSpreadsheetHasTheGradebookAndASummarySheet(): void
    {
        $file = $this->service->classSheet($this->service->classReport($this->offering), 'xlsx');

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['file_path']);
        @unlink($file['file_path']);

        $this->assertSame(['Gradebook', 'Summary'], $book->getSheetNames());

        $grid = $book->getSheetByName('Gradebook')->toArray();
        $this->assertStringContainsString('Student number', (string) $grid[0][0]);
        $this->assertCount(4, $grid, 'a header and three students');

        $facts = array_column($book->getSheetByName('Summary')->toArray(), 1, 0);
        $this->assertEquals(80, $facts['Class average %']);
    }

    public function testThePdfIsAPdf(): void
    {
        $html = view('pages/courses/features/grades/reports/class-summary', ['report' => $this->service->classReport($this->offering), 'printBar' => false], ['saveData' => false]);

        $this->assertStringStartsWith('%PDF', $this->service->pdf($html));
        $this->assertStringNotContainsString('Print or save as PDF', $html, 'no print button inside the file');
    }

    public function testFileNamesAreSafe(): void
    {
        $report = $this->service->classReport($this->offering);
        $report['offering']['course_code'] = 'IT 101/../x';
        $report['offering']['section'] = 'A';

        $stem = $this->service->fileStem($report, 'gradebook');

        $this->assertDoesNotMatchRegularExpression('#[/\\\\ ]#', $stem);
        $this->assertStringStartsWith('gradebook-IT-101-x-A-', $stem);
    }
}

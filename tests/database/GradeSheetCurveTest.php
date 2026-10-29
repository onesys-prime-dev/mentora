<?php

declare(strict_types=1);

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Grades\GradeAdjustmentService;
use App\Services\Courses\Grades\GradeSheetExportService;
use App\Services\Courses\Grades\GradeSheetLayout;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The offline grade sheet with a curve in force. The score cells keep the
 * scores as entered; the calculated columns (Category %, Final %) are
 * formulas, and with a curve they must come to exactly what the grading
 * engine says. Each case opens the exported workbook, lets the spreadsheet
 * engine work the formulas out, and compares with the stored grades.
 *
 * @internal
 */
final class GradeSheetCurveTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private int $quiz;

    private int $exam;

    private User $teacher;

    private GradingEngineService $engine;

    private GradeAdjustmentService $curves;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new GradingEngineService();
        $this->curves = new GradeAdjustmentService();

        $this->offering = CourseFactory::offering();
        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $scheme = GradeFactory::scheme($this->offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $quizzes = GradeFactory::category($scheme, 'Quizzes', 40, null, 1);
        $exams = GradeFactory::category($scheme, 'Exams', 60, null, 2);

        // A manual quiz and a manual exam: the sheet treats both the same way a graded one is read.
        $this->quiz = GradeFactory::item($scheme, $quizzes, 'Quiz 1', 20);
        $this->exam = GradeFactory::item($scheme, $exams, 'Midterm', 100);

        // Ann 18/20 and 80/100, Ben 12 and 55, Cy 6 and 31, Dee only the quiz (14).
        foreach ([['Ann', 'Tester', 18, 80], ['Ben', 'Tester', 12, 55], ['Cy', 'Tester', 6, 31], ['Dee', 'Tester', 14, null]] as [$first, $last, $quiz, $exam]) {

            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => $first, 'last_name' => $last . $first, 'student_number' => 'N-' . $first]);
            CourseFactory::enroll($this->offering, $student);

            GradeFactory::score($this->offering, $student, $this->quiz, $quiz);

            if ($exam !== null) {
                GradeFactory::score($this->offering, $student, $this->exam, $exam);
            }

        }

        $this->engine->recalculateOffering($this->offering);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function layout(): GradeSheetLayout
    {
        return new GradeSheetLayout($this->engine->context($this->offering));
    }

    /** @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet the exported workbook's grade sheet */
    private function sheet(): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $result = (new GradeSheetExportService())->exportToXlsx($this->offering);

        $this->files[] = $result['file_path'];

        return IOFactory::load($result['file_path'])->getSheetByName('Grades');
    }

    /** @return array<string, ?float> the final % the spreadsheet works out, by student number */
    private function sheetFinals(): array
    {
        $sheet = $this->sheet();
        $layout = $this->layout();

        $finals = [];

        for ($row = GradeSheetLayout::FIRST_DATA_ROW; $row < GradeSheetLayout::FIRST_DATA_ROW + 4; $row++) {

            $number = (string) $sheet->getCell(GradeSheetLayout::letter(2) . $row)->getValue();

            $value = $sheet->getCell($layout->cellOf('final', $row))->getCalculatedValue();

            $finals[$number] = $value === '' || $value === null ? null : (float) $value;

        }

        return $finals;
    }

    /** @return array<string, ?float> the grade the engine stored, by student number */
    private function storedFinals(): array
    {
        $stored = [];

        foreach (db_connect()->table('gradebook_records g')->select('p.student_number, g.*')->join('user_profiles p', 'p.user_id = g.student_id')->where('g.course_offering_id', $this->offering)->get()->getResultArray() as $row) {
            $stored[(string) $row['student_number']] = GradebookRecordModel::effectivePercentage($row);
        }

        return $stored;
    }

    private function curveItem(int $item, string $method, array $input): void
    {
        $result = $this->curves->apply($this->teacher, $this->offering, 'item', $item, $method, $input);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    private function curveFinal(string $method, array $input): void
    {
        $result = $this->curves->apply($this->teacher, $this->offering, 'final', null, $method, $input);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    private function assertSheetAgreesWithTheGradebook(): void
    {
        $sheet = $this->sheetFinals();
        $stored = $this->storedFinals();

        $this->assertSame(array_keys($stored), array_keys($sheet));

        foreach ($stored as $number => $grade) {
            if ($grade === null) {
                $this->assertNull($sheet[$number], $number);
            } else {
                $this->assertEqualsWithDelta($grade, $sheet[$number], 0.011, "Final % of {$number} in the sheet");
            }
        }
    }

    public function testWithoutACurveTheSheetAgreesWithTheGradebook(): void
    {
        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testACurveOnAnItemIsInTheSheetsFormulas(): void
    {
        $this->curveItem($this->exam, 'add_points', ['points' => 10]);

        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testScalingAnItemIsInTheSheetsFormulas(): void
    {
        $this->curveItem($this->exam, 'scale_to_max', []);

        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testStretchingAnItemIsInTheSheetsFormulas(): void
    {
        $this->curveItem($this->quiz, 'linear', ['new_low' => 10, 'new_high' => 20]);

        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testACurveOnTheFinalGradeIsInTheSheetsFormulas(): void
    {
        $this->curveFinal('set_mean', ['target_mean' => 80]);

        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testACurveThatWouldPassFullMarksIsHeldThereInTheSheetToo(): void
    {
        $this->curveItem($this->exam, 'add_points', ['points' => 50]);
        $this->curveFinal('add_points', ['points' => 40]);

        $this->assertSheetAgreesWithTheGradebook();
        $this->assertLessThanOrEqual(100.0, max(array_filter($this->sheetFinals(), static fn ($v) => $v !== null)));
    }

    public function testCurvesOnTheItemsAndTheFinalGradeTogether(): void
    {
        $this->curveItem($this->quiz, 'add_points', ['points' => 2]);
        $this->curveItem($this->exam, 'scale_to_max', []);
        $this->curveFinal('add_points', ['points' => -3]);

        $this->assertSheetAgreesWithTheGradebook();
    }

    public function testTheScoreCellsKeepTheScoresAsEnteredAndTheSheetSaysSo(): void
    {
        $this->curveItem($this->exam, 'add_points', ['points' => 10]);
        $this->curveFinal('add_points', ['points' => 1]);

        $sheet = $this->sheet();
        $layout = $this->layout();

        $examColumn = GradeSheetLayout::letter(array_search('item:' . $this->exam, $layout->keys(), true) + 1);

        $this->assertEquals(80, $sheet->getCell($examColumn . GradeSheetLayout::FIRST_DATA_ROW)->getValue(), 'Ann\'s midterm as entered, not 90');
        $this->assertStringContainsString('[curved]', (string) $sheet->getCell($examColumn . '3')->getValue());
        $this->assertStringContainsString('[curved]', (string) $sheet->getCell($layout->cellOf('final', 3))->getValue());

        $instructions = IOFactory::load($this->files[0])->getSheetByName('Instructions');
        $text = '';

        foreach ($instructions->toArray() as $line) {
            $text .= ' ' . ($line[0] ?? '');
        }

        $this->assertStringContainsString('A curve is in force on Midterm', $text);
        $this->assertStringContainsString('A curve is in force on the final grade', $text);
        $this->assertStringContainsString('export a fresh sheet', $text);
    }

    public function testASheetWithNoCurveSaysNothingAboutOne(): void
    {
        $this->sheet();

        $text = '';

        foreach (IOFactory::load($this->files[0])->getSheetByName('Instructions')->toArray() as $line) {
            $text .= ' ' . ($line[0] ?? '');
        }

        $this->assertStringNotContainsString('curve', strtolower($text));
        $this->assertStringNotContainsString('[curved]', (string) $this->sheet()->getCell('A3')->getValue());
    }

    public function testApplyingOrRemovingACurveChangesTheSheetsSignature(): void
    {
        $before = $this->layout()->signature();

        $this->curveItem($this->exam, 'add_points', ['points' => 10]);
        $withCurve = $this->layout()->signature();

        $this->assertNotSame($before, $withCurve, 'a sheet exported before the curve is refused');

        $live = $this->curves->describe($this->offering)['live'];
        $this->curves->undo($this->teacher, $this->offering, (int) $live[0]['id']);

        $this->assertSame($before, $this->layout()->signature(), 'removing it puts the old signature back');
    }

    public function testAChangedCurveChangesTheSignatureToo(): void
    {
        $this->curveItem($this->exam, 'add_points', ['points' => 10]);
        $ten = $this->layout()->signature();

        $this->curveItem($this->exam, 'add_points', ['points' => 5]);

        $this->assertNotSame($ten, $this->layout()->signature());
    }

    public function testTheCsvAlreadyCarriesTheCurvedGrades(): void
    {
        $this->curveFinal('add_points', ['points' => 5]);

        $result = (new GradeSheetExportService())->exportToCsv($this->offering);
        $this->files[] = $result['file_path'];

        $rows = array_map('str_getcsv', file($result['file_path'], FILE_IGNORE_NEW_LINES));
        $header = $rows[2];
        $finalIndex = array_search('Final % [curved]', $header, true);

        $this->assertNotFalse($finalIndex, 'the Final % column says it is curved');

        $stored = $this->storedFinals();

        foreach (array_slice($rows, 3) as $row) {
            $number = $row[1];
            $this->assertEqualsWithDelta($stored[$number], (float) $row[$finalIndex], 0.011, $number);
        }
    }
}

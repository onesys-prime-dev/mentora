<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeSheetExportService;
use App\Services\Courses\Grades\GradeSheetImportService;
use App\Services\Courses\Grades\GradeSheetLayout;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The offline grade sheet end to end: export to CSV and XLSX, read it back
 * (preview), and apply the edited scores. Every refusal the import makes is
 * covered: wrong template, changed grading setup, moved columns, tampered
 * locked cells, overwritten formulas, unknown or repeated students and
 * out-of-range scores.
 *
 * Setup: Quizzes 40% (one quiz item /10, locked to an assessment) and
 * Participation 60% (Attendance /10 and Recitation /20, both manual), with
 * Ada and Bob enrolled.
 *
 * @internal
 */
final class GradeSheetTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offeringId;
    private int $schemeId;
    private int $quizItem;
    private int $attendance;
    private int $recitation;
    private User $ada;
    private User $bob;
    private User $instructor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->offeringId = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        $this->ada = $this->student('Ada', 'Adams', '2026-001');
        $this->bob = $this->student('Bob', 'Brown', '2026-002');

        $this->schemeId = GradeFactory::scheme($this->offeringId, 75.0, false, GradeFactory::scale());
        $quizzes = GradeFactory::category($this->schemeId, 'Quizzes', 40);
        $participation = GradeFactory::category($this->schemeId, 'Participation', 60, null, 2);

        $assessmentId = CourseFactory::assessment($this->offeringId, $this->instructor, CourseFactory::module($this->offeringId));
        db_connect()->table('assessments')->where('id', $assessmentId)->update(['status' => 'Published', 'total_points' => 10, 'title' => 'Quiz One']);

        $this->quizItem = GradeFactory::item($this->schemeId, $quizzes, 'Quiz One', 10, 1, $assessmentId);
        $this->attendance = GradeFactory::item($this->schemeId, $participation, 'Attendance', 10, 2);
        $this->recitation = GradeFactory::item($this->schemeId, $participation, 'Recitation', 20, 3);

        GradeFactory::score($this->offeringId, $this->ada, $this->quizItem, 8.0);
        GradeFactory::score($this->offeringId, $this->ada, $this->attendance, 9.0);
        // Bob has no scores yet.
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function student(string $first, string $last, string $number): User
    {
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert([
            'user_id' => $student->id,
            'first_name' => $first,
            'last_name' => $last,
            'student_number' => $number,
        ]);
        CourseFactory::enroll($this->offeringId, $student);

        return $student;
    }

    /*
    |--------------------------------------------------------------------------
    | File helpers
    |--------------------------------------------------------------------------
    */

    private function exportCsv(): string
    {
        $result = (new GradeSheetExportService())->exportToCsv($this->offeringId);
        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];

        return $result['file_path'];
    }

    private function exportXlsx(): string
    {
        $result = (new GradeSheetExportService())->exportToXlsx($this->offeringId);
        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];

        return $result['file_path'];
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        fread($handle, 3);

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function writeCsv(array $rows): string
    {
        $path = WRITEPATH . 'uploads/exports/edited-' . uniqid() . '.csv';
        $this->files[] = $path;
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);

        return $path;
    }

    /** Column index of a key in a CSV grid. */
    private function col(array $grid, string $key): int
    {
        $index = array_search($key, $grid[1], true);
        $this->assertNotFalse($index, "no column {$key}");

        return (int) $index;
    }

    /** Row index (in the grid) of a student. */
    private function rowOf(array $grid, User $student): int
    {
        foreach ($grid as $index => $row) {
            if ($index >= 3 && (string) $row[0] === (string) $student->id) {
                return $index;
            }
        }

        $this->fail('student not in sheet');
    }

    private function preview(string $path, string $format = 'csv'): array
    {
        return (new GradeSheetImportService())->preview($this->offeringId, $path, $format);
    }

    private function storedScore(int $itemId, User $student): ?float
    {
        $row = db_connect()->table('gradebook_item_scores')->where('grade_item_id', $itemId)->where('student_id', $student->id)->get()->getRowArray();

        return $row === null || $row['raw_score'] === null ? null : (float) $row['raw_score'];
    }

    private function signature(): string
    {
        return $this->readCsv($this->exportCsv())[0][1];
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function testTheCsvHasMachineRowsHeadersAndOneRowPerStudentByLastName(): void
    {
        $grid = $this->readCsv($this->exportCsv());

        $this->assertSame(GradeSheetLayout::META_PREFIX, $grid[0][0]);
        $this->assertSame(32, strlen($grid[0][1]));
        $this->assertSame(
            [
                'student_id', 'student_number', 'student_name',
                'item:' . $this->quizItem, 'category:' . db_connect()->table('grade_categories')->where('name', 'Quizzes')->get()->getRowArray()['id'],
                'item:' . $this->attendance, 'item:' . $this->recitation,
            ],
            array_slice($grid[1], 0, 7)
        );
        $this->assertSame(['final', 'grade', 'remarks', 'row_hash'], array_slice($grid[1], -4));
        $this->assertCount(5, $grid, 'three header rows and two students');
        $this->assertSame('Adams, Ada', $grid[3][2]);
        $this->assertSame('Brown, Bob', $grid[4][2]);
        $this->assertSame('2026-001', $grid[3][1]);
    }

    public function testTheCsvCarriesScoresAndComputedResults(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        $ada = $grid[$this->rowOf($grid, $this->ada)];

        $this->assertEquals(8, $ada[$this->col($grid, 'item:' . $this->quizItem)]);
        $this->assertEquals(9, $ada[$this->col($grid, 'item:' . $this->attendance)]);
        $this->assertSame('', $ada[$this->col($grid, 'item:' . $this->recitation)], 'not graded stays blank');

        // Quizzes 8/10 = 80%, Participation 9/10 = 90% (Recitation is not graded).
        // 80 * 40% + 90 * 60% = 86
        $this->assertEquals(86, $ada[$this->col($grid, 'final')]);
        $this->assertSame('B', $ada[$this->col($grid, 'grade')]);
        $this->assertSame('Passed', $ada[$this->col($grid, 'remarks')]);

        $bob = $grid[$this->rowOf($grid, $this->bob)];
        $this->assertSame('', $bob[$this->col($grid, 'final')]);
        $this->assertSame('Incomplete', $bob[$this->col($grid, 'remarks')]);
    }

    public function testTheCsvStartsWithAByteOrderMarkSoExcelReadsAccents(): void
    {
        $this->assertSame("\xEF\xBB\xBF", file_get_contents($this->exportCsv(), false, null, 0, 3));
    }

    public function testExportingAnOfferingWithoutASchemeCreatesTheDefaultOneFirst(): void
    {
        $offering = CourseFactory::offering('Z');
        GradeFactory::systemDefault();

        $result = (new GradeSheetExportService())->exportToCsv($offering);

        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];
        $this->assertNotNull(db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRowArray());
    }

    public function testFilenamesNameTheSectionAndFormat(): void
    {
        $result = (new GradeSheetExportService())->exportToCsv($this->offeringId);
        $this->files[] = $result['file_path'];

        $this->assertMatchesRegularExpression('/^grade-sheet-' . $this->offeringId . '-\d{8}-\d{6}\.csv$/', $result['filename']);
        $this->assertFileExists($result['file_path']);
    }

    public function testTheRosterListsOnlyEnrolledStudents(): void
    {
        $dropped = $this->student('Dan', 'Dunn', '2026-003');
        db_connect()->table('enrollments')->where('student_user_id', $dropped->id)->update(['status' => 'dropped']);

        $names = array_column(GradeSheetExportService::roster($this->offeringId), 'student_name');

        $this->assertSame(['Adams, Ada', 'Brown, Bob'], $names);
    }

    public function testTheXlsxHasLiveFormulasProtectionAndInstructions(): void
    {
        $book = IOFactory::load($this->exportXlsx());
        $sheet = $book->getSheetByName('Grades');

        $this->assertNotNull($sheet);
        $this->assertSame(['Grades', 'Instructions'], $book->getSheetNames());
        $this->assertSame(GradeSheetLayout::META_PREFIX, $sheet->getCell('A1')->getValue());
        $this->assertFalse($sheet->getRowDimension(1)->getVisible());
        $this->assertFalse($sheet->getRowDimension(2)->getVisible());
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertTrue($sheet->getProtection()->getSheet());

        $keys = $sheet->rangeToArray('A2:' . $sheet->getHighestColumn() . '2', null, false)[0];
        $final = GradeSheetLayout::letter(array_search('final', $keys, true) + 1);
        $quiz = GradeSheetLayout::letter(array_search('item:' . $this->quizItem, $keys, true) + 1);
        $attendance = GradeSheetLayout::letter(array_search('item:' . $this->attendance, $keys, true) + 1);

        $this->assertStringStartsWith('=IF(', (string) $sheet->getCell($final . '4')->getValue());
        $this->assertTrue($sheet->getStyle($attendance . '4')->getProtection()->getLocked() === 'unprotected', 'manual scores are editable');
        $this->assertNotSame('unprotected', $sheet->getStyle($quiz . '4')->getProtection()->getLocked(), 'quiz scores are locked');
        $this->assertSame('2026-001', (string) $sheet->getCell('B4')->getValue(), 'student numbers stay text');
        $this->assertStringContainsString('Template: ', (string) $book->getSheetByName('Instructions')->getCell('A10')->getValue());
    }

    /*
    |--------------------------------------------------------------------------
    | Preview: refusing a file
    |--------------------------------------------------------------------------
    */

    private function assertRefused(array $preview, string $contains): void
    {
        $this->assertTrue($preview['success']);
        $this->assertFalse($preview['templateValid']);
        $this->assertStringContainsString($contains, $preview['templateError']);
        $this->assertSame([], $preview['rows']);
        $this->assertSame(0, $preview['validCount']);
    }

    public function testAnUntouchedExportHasNothingToImport(): void
    {
        $preview = $this->preview($this->exportCsv());

        $this->assertTrue($preview['templateValid']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(0, $preview['validCount']);
        $this->assertSame(0, $preview['flaggedCount']);
        $this->assertSame([], $preview['missingStudents']);
        $this->assertSame([], $preview['rows'][0]['changes']);
    }

    public function testAFileThatIsNotAGradeSheetIsRefused(): void
    {
        $path = $this->writeCsv([['name', 'score'], ['Ada', '5']]);

        $this->assertRefused($this->preview($path), "isn't a Mentora grade sheet");
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->assertRefused($this->preview($this->writeCsv([])), "isn't a Mentora grade sheet");
    }

    public function testAFileThatIsNotASpreadsheetIsRefused(): void
    {
        $path = $this->writeCsv([['not a workbook']]);

        $this->assertRefused($this->preview($path, 'xlsx'), "couldn't be read as a XLSX");
    }

    public function testASheetFromAnotherSectionIsRefused(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        $grid[0][1] = str_repeat('0', 32);

        $this->assertRefused($this->preview($this->writeCsv($grid)), "doesn't match this section's current grading setup");
    }

    public function testASheetExportedBeforeTheGradingSetupChangedIsRefused(): void
    {
        $path = $this->exportCsv();
        db_connect()->table('grade_categories')->where('name', 'Quizzes')->update(['weight_percentage' => 30]);
        db_connect()->table('grade_categories')->where('name', 'Participation')->update(['weight_percentage' => 70]);

        $this->assertRefused($this->preview($path), "doesn't match this section's current grading setup");
    }

    public function testReorderedColumnsAreRefused(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        [$grid[1][3], $grid[1][4]] = [$grid[1][4], $grid[1][3]];

        $this->assertRefused($this->preview($this->writeCsv($grid)), 'added, removed or reordered');
    }

    public function testAnExtraColumnIsRefusedButTrailingBlanksAreNot(): void
    {
        $grid = $this->readCsv($this->exportCsv());

        $withBlank = $grid;
        $withBlank[1][] = '';
        $this->assertTrue($this->preview($this->writeCsv($withBlank))['templateValid']);

        $withExtra = $grid;
        $withExtra[1][] = 'notes';
        $this->assertRefused($this->preview($this->writeCsv($withExtra)), 'added, removed or reordered');
    }

    /*
    |--------------------------------------------------------------------------
    | Preview: scores
    |--------------------------------------------------------------------------
    */

    /** Sets a cell of a student's row in the CSV and previews the result. */
    private function previewEdit(User $student, string $key, string $value): array
    {
        $grid = $this->readCsv($this->exportCsv());
        $grid[$this->rowOf($grid, $student)][$this->col($grid, $key)] = $value;

        return $this->preview($this->writeCsv($grid));
    }

    private function rowFor(array $preview, User $student): array
    {
        foreach ($preview['rows'] as $row) {
            if ($row['studentId'] === (int) $student->id) {
                return $row;
            }
        }

        $this->fail('row not in preview');
    }

    public function testAnEditedManualScoreShowsItsOldAndNewValueAndTheFinalGradeBeforeAndAfter(): void
    {
        $preview = $this->previewEdit($this->ada, 'item:' . $this->recitation, '15');

        $row = $this->rowFor($preview, $this->ada);
        $this->assertNull($row['rowError']);
        $this->assertCount(1, $row['changes']);
        $change = $row['changes'][0];
        $this->assertSame($this->recitation, $change['gradeItemId']);
        $this->assertSame('Recitation', $change['itemName']);
        $this->assertNull($change['oldValue']);
        $this->assertSame(15.0, $change['newValue']);
        $this->assertTrue($change['valid']);

        // Participation: (9 + 15) / 30 = 80% instead of 90%; final 80*.4 + 80*.6 = 80.
        $this->assertEquals(86, $row['finalBefore']);
        $this->assertEquals(80, $row['finalAfter']);
        $this->assertSame(1, $preview['validCount']);
        $this->assertSame(0, $preview['flaggedCount']);
    }

    public function testChangingAnExistingScoreAndClearingIt(): void
    {
        $changed = $this->rowFor($this->previewEdit($this->ada, 'item:' . $this->attendance, '10'), $this->ada);
        $this->assertSame(9.0, $changed['changes'][0]['oldValue']);
        $this->assertSame(10.0, $changed['changes'][0]['newValue']);

        $cleared = $this->rowFor($this->previewEdit($this->ada, 'item:' . $this->attendance, ''), $this->ada);
        $this->assertSame(9.0, $cleared['changes'][0]['oldValue']);
        $this->assertNull($cleared['changes'][0]['newValue']);
        $this->assertTrue($cleared['changes'][0]['valid']);
    }

    public function testRetypingTheSameScoreIsNotAChange(): void
    {
        $row = $this->rowFor($this->previewEdit($this->ada, 'item:' . $this->attendance, '9.0'), $this->ada);

        $this->assertSame([], $row['changes']);
    }

    public function testAScoreOutsideZeroToMaxIsFlaggedAndNotCounted(): void
    {
        foreach (['11', '-1'] as $bad) {
            $preview = $this->previewEdit($this->ada, 'item:' . $this->attendance, $bad);
            $change = $this->rowFor($preview, $this->ada)['changes'][0];

            $this->assertFalse($change['valid'], $bad);
            $this->assertStringContainsString('is outside 0–10', $change['error']);
            $this->assertSame(0, $preview['validCount']);
            $this->assertSame(1, $preview['flaggedCount']);
        }
    }

    public function testTheMaximumAndZeroAreAllowed(): void
    {
        $this->assertTrue($this->rowFor($this->previewEdit($this->ada, 'item:' . $this->attendance, '10'), $this->ada)['changes'][0]['valid']);
        $this->assertTrue($this->rowFor($this->previewEdit($this->ada, 'item:' . $this->attendance, '0'), $this->ada)['changes'][0]['valid']);
    }

    public function testATextScoreIsFlagged(): void
    {
        $preview = $this->previewEdit($this->ada, 'item:' . $this->attendance, 'absent');
        $change = $this->rowFor($preview, $this->ada)['changes'][0];

        $this->assertFalse($change['valid']);
        $this->assertStringContainsString("isn't a number", $change['error']);
        $this->assertSame(1, $preview['flaggedCount']);
    }

    public function testEditingAQuizScoreFlagsTheRow(): void
    {
        $preview = $this->previewEdit($this->ada, 'item:' . $this->quizItem, '10');
        $row = $this->rowFor($preview, $this->ada);

        $this->assertStringContainsString('locked cells were changed', $row['rowError']);
        $this->assertSame([], $row['changes']);
        $this->assertSame(1, $preview['flaggedCount']);
    }

    public function testEditingAStudentNumberFlagsTheRow(): void
    {
        $row = $this->rowFor($this->previewEdit($this->ada, 'student_number', '2026-999'), $this->ada);

        $this->assertStringContainsString('locked cells were changed', $row['rowError']);
    }

    public function testAStudentWhoIsNotEnrolledIsFlagged(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        $grid[3][0] = '999999';

        $preview = $this->preview($this->writeCsv($grid));

        $this->assertStringContainsString("isn't enrolled", $preview['rows'][0]['rowError']);
        $this->assertContains('Adams, Ada', $preview['missingStudents']);
    }

    public function testAStudentListedTwiceIsFlaggedTheSecondTime(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        $grid[] = $grid[3];

        $rows = $this->preview($this->writeCsv($grid))['rows'];

        $this->assertNull($rows[0]['rowError']);
        $this->assertStringContainsString('listed more than once', $rows[2]['rowError']);
    }

    public function testStudentsLeftOutOfTheFileAreReportedAsMissing(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        unset($grid[4]);

        $preview = $this->preview($this->writeCsv(array_values($grid)));

        $this->assertSame(['Brown, Bob'], $preview['missingStudents']);
    }

    public function testBlankRowsAreSkipped(): void
    {
        $grid = $this->readCsv($this->exportCsv());
        $grid[] = array_fill(0, count($grid[1]), '');

        $this->assertCount(2, $this->preview($this->writeCsv($grid))['rows']);
    }

    public function testRowNumbersPointAtTheSheetRows(): void
    {
        $rows = $this->preview($this->exportCsv())['rows'];

        $this->assertSame([4, 5], array_column($rows, 'rowNumber'));
    }

    /*
    |--------------------------------------------------------------------------
    | Preview: XLSX
    |--------------------------------------------------------------------------
    */

    /** Edits cells of the exported workbook and returns its path. */
    private function editXlsx(callable $edit): string
    {
        $path = $this->exportXlsx();
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Grades');
        $keys = $sheet->rangeToArray('A2:' . $sheet->getHighestColumn() . '2', null, false)[0];

        $edit($sheet, static fn (string $key): string => GradeSheetLayout::letter(array_search($key, $keys, true) + 1));

        $edited = WRITEPATH . 'uploads/exports/edited-' . uniqid() . '.xlsx';
        $this->files[] = $edited;
        IOFactory::createWriter($book, 'Xlsx')->save($edited);

        return $edited;
    }

    public function testAnUntouchedXlsxHasNothingToImport(): void
    {
        $preview = $this->preview($this->exportXlsx(), 'xlsx');

        $this->assertTrue($preview['templateValid']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(0, $preview['flaggedCount']);
        $this->assertSame(0, $preview['validCount']);
    }

    public function testAnEditedManualScoreInAnXlsxIsPickedUp(): void
    {
        $path = $this->editXlsx(function ($sheet, $letter) {
            $sheet->setCellValue($letter('item:' . $this->recitation) . '4', 12);
        });

        $preview = $this->preview($path, 'xlsx');

        $this->assertSame(0, $preview['flaggedCount']);
        $this->assertSame(12.0, $this->rowFor($preview, $this->ada)['changes'][0]['newValue']);
    }

    public function testOverwritingACalculatedCellInAnXlsxFlagsTheRow(): void
    {
        $path = $this->editXlsx(function ($sheet, $letter) {
            $sheet->setCellValue($letter('final') . '4', 100);
        });

        $row = $this->rowFor($this->preview($path, 'xlsx'), $this->ada);

        $this->assertStringContainsString('a calculated cell (Final %) was overwritten', $row['rowError']);
    }

    public function testEditingAQuizScoreInAnXlsxFlagsTheRow(): void
    {
        $path = $this->editXlsx(function ($sheet, $letter) {
            $sheet->setCellValue($letter('item:' . $this->quizItem) . '4', 10);
        });

        $this->assertStringContainsString('locked cells were changed', $this->rowFor($this->preview($path, 'xlsx'), $this->ada)['rowError']);
    }

    public function testAWorkbookWithoutAGradesSheetIsRefused(): void
    {
        $path = $this->editXlsx(function ($sheet) {
            $sheet->setTitle('Other');
        });

        $this->assertRefused($this->preview($path, 'xlsx'), "couldn't be read as a XLSX");
    }

    /*
    |--------------------------------------------------------------------------
    | Apply
    |--------------------------------------------------------------------------
    */

    private function apply(array $changes, ?string $signature = null): array
    {
        return (new GradeSheetImportService())->apply($this->instructor, $this->offeringId, [
            'signature' => $signature ?? $this->signature(),
            'changes' => $changes,
        ]);
    }

    private function change(User $student, int $itemId, mixed $value): array
    {
        return ['student_id' => $student->id, 'grade_item_id' => $itemId, 'value' => $value];
    }

    public function testApplyingWritesTheScoresAndTellsHowManyWereImported(): void
    {
        $result = $this->apply([
            $this->change($this->bob, $this->attendance, 7),
            $this->change($this->bob, $this->recitation, '18.5'),
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringStartsWith('2 scores imported.', $result['message']);
        $this->assertSame(7.0, $this->storedScore($this->attendance, $this->bob));
        $this->assertSame(18.5, $this->storedScore($this->recitation, $this->bob));

        $row = db_connect()->table('gradebook_item_scores')->where('grade_item_id', $this->attendance)->where('student_id', $this->bob->id)->get()->getRowArray();
        $this->assertSame($this->instructor->id, (int) $row['graded_by']);
        $this->assertEquals(70.0, $row['raw_percentage']);
    }

    public function testOneScoreIsSingular(): void
    {
        $this->assertStringStartsWith('1 score imported.', $this->apply([$this->change($this->bob, $this->attendance, 7)])['message']);
    }

    public function testANullValueClearsAScore(): void
    {
        $this->apply([$this->change($this->ada, $this->attendance, null)]);

        $this->assertNull($this->storedScore($this->attendance, $this->ada));
    }

    public function testApplyDropsChangesThatAreNotValid(): void
    {
        $result = $this->apply([
            $this->change($this->bob, $this->attendance, 7),                  // fine
            $this->change($this->bob, $this->recitation, 21),                 // over the max
            $this->change($this->bob, $this->recitation, -1),                 // negative
            $this->change($this->bob, $this->recitation, 'lots'),             // not a number
            $this->change($this->bob, $this->quizItem, 5),                    // locked item
            $this->change($this->bob, 999999, 5),                             // no such item
            ['student_id' => 999999, 'grade_item_id' => $this->attendance, 'value' => 5], // not enrolled
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringStartsWith('1 score imported.', $result['message']);
        $this->assertSame(7.0, $this->storedScore($this->attendance, $this->bob));
        $this->assertNull($this->storedScore($this->recitation, $this->bob));
        $this->assertNull($this->storedScore($this->quizItem, $this->bob));
    }

    public function testApplyWithNothingValidWritesNothing(): void
    {
        $result = $this->apply([$this->change($this->bob, $this->recitation, 99)]);

        $this->assertFalse($result['success']);
        $this->assertSame('There were no valid changes to apply.', $result['message']);
        $this->assertFalse($this->apply([])['success'], 'an empty list has nothing to apply either');
    }

    public function testApplyRefusesAFilePreviewedUnderAnOldSetup(): void
    {
        $result = $this->apply([$this->change($this->bob, $this->attendance, 7)], str_repeat('a', 32));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('grading setup changed', $result['message']);
        $this->assertNull($this->storedScore($this->attendance, $this->bob));
    }

    public function testApplyRefusesAMissingSignature(): void
    {
        $result = (new GradeSheetImportService())->apply($this->instructor, $this->offeringId, ['changes' => [$this->change($this->bob, $this->attendance, 7)]]);

        $this->assertFalse($result['success']);
    }

    public function testFinalGradesAreRefreshedOnlyOnAPublishedSchemeThatIsUpToDate(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $result = $this->apply([$this->change($this->bob, $this->attendance, 10)]);

        $this->assertStringNotContainsString('Recalculate', $result['message']);
        $record = db_connect()->table('gradebook_records')->where('student_id', $this->bob->id)->get()->getRowArray();
        $this->assertNotNull($record);
        $this->assertEquals(100.0, $record['calculated_percentage']);
    }

    public function testAnUnpublishedSchemeLeavesFinalGradesUntilItIsPublished(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Active', 'needs_recalculation' => 0]);

        $result = $this->apply([$this->change($this->bob, $this->attendance, 10)]);

        $this->assertStringContainsString('Final grades update once the grading is published.', $result['message']);
        $this->assertSame(0, db_connect()->table('gradebook_records')->where('student_id', $this->bob->id)->countAllResults());
    }

    public function testASchemeWaitingOnARecalculationIsStillRefreshedPerStudent(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->schemeId)->update(['status' => 'Published', 'needs_recalculation' => 1]);

        $result = $this->apply([$this->change($this->bob, $this->attendance, 10)]);

        $this->assertStringNotContainsString('Recalculate', $result['message']);
        $this->assertSame(1, db_connect()->table('gradebook_records')->where('student_id', $this->bob->id)->countAllResults());
    }

    public function testPreviewThenApplyRoundTrip(): void
    {
        $preview = $this->previewEdit($this->bob, 'item:' . $this->attendance, '6');
        $changes = [];

        foreach ($preview['rows'] as $row) {
            foreach ($row['changes'] as $change) {
                if ($change['valid']) {
                    $changes[] = ['student_id' => $row['studentId'], 'grade_item_id' => $change['gradeItemId'], 'value' => $change['newValue']];
                }
            }
        }

        $result = $this->apply($changes, $preview['signature']);

        $this->assertTrue($result['success']);
        $this->assertSame(6.0, $this->storedScore($this->attendance, $this->bob));
        // And a fresh preview now sees nothing left to import.
        $this->assertSame(0, $this->preview($this->exportCsv())['validCount']);
    }
}

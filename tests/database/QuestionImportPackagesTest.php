<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\ImportPreviewStore;
use App\Services\Courses\Assessments\MoodleXmlParser;
use App\Services\Courses\Assessments\QtiParser;
use App\Services\Courses\Assessments\QuestionImportService;
use App\Services\Courses\Assessments\QuizPackage;
use App\Services\Courses\Assessments\XmlText;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The same 50-question exam as a Moodle XML file and as the QTI packages of
 * Blackboard, D2L, Sakai and WebCT/Vista: every one comes in with the text
 * file's stems, choices and answer key.
 *
 * @internal
 */
final class QuestionImportPackagesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const FILES = [
        'moodle' => ['onesys sample moodle.xml', 'xml', 'Moodle XML'],
        'blackboard' => ['Onesys Sample Blk.zip', 'zip', 'Blackboard (QTI)'],
        'd2l' => ['Onesys Sample D2L.zip', 'zip', 'D2L Brightspace (QTI)'],
        'sakai' => ['Onesys Sample SAKAI.zip', 'zip', 'Sakai (QTI)'],
        'webct' => ['Onesys SampleQTI.zip', 'zip', 'WebCT / Blackboard Vista (QTI)'],
    ];

    private QuestionImportService $import;
    private string $storeDirectory;
    private int $quiz;
    private User $instructor;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeDirectory = sys_get_temp_dir() . '/pk-test-' . bin2hex(random_bytes(4));
        $this->import = new QuestionImportService(new ImportPreviewStore($this->storeDirectory));

        $offering = CourseFactory::offering();
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $this->instructor);
        $this->quiz = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->temp, glob($this->storeDirectory . '/*') ?: []) as $file) {
            @unlink($file);
        }

        @rmdir($this->storeDirectory);

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return TESTPATH . '_support/fixtures/exam-samples/' . $name;
    }

    /** @return array<string, mixed> */
    private function preview(string $path, string $extension): array
    {
        return $this->import->previewFile($this->instructor, $this->quiz, $path, $extension);
    }

    private function expectedKey(): string
    {
        $text = (string) file_get_contents($this->fixture('onesys sample.txt'));
        preg_match_all('/^(\d{1,2})\) ([A-F])\s*$/m', $text, $m, PREG_SET_ORDER);
        $key = [];

        foreach ($m as $row) {
            $key[(int) $row[1]] = $row[2];
        }

        ksort($key);

        return implode('', $key);
    }

    private function letters(array $preview): string
    {
        $out = '';

        foreach ($preview['diff']['added'] as $item) {
            foreach ($item['choices'] as $i => $c) {
                if ($c['is_correct']) {
                    $out .= chr(65 + $i);
                }
            }
        }

        return $out;
    }

    /** @param list<array<string, string>> $files name => content */
    private function zip(array $files): string
    {
        $path = sys_get_temp_dir() . '/pk-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $this->temp[] = $path;
    }

    /** @dataProvider samples */
    public function testEachSystemsFileGivesTheFiftyQuestionsAndTheirKey(string $label): void
    {
        [$name, $extension, $detected] = self::FILES[$label];

        $preview = $this->preview($this->fixture($name), $extension);

        $this->assertTrue($preview['success'], $label . ': ' . ($preview['message'] ?? ''));
        $this->assertSame(50, $preview['counts']['added'], $label);
        $this->assertSame(0, $preview['counts']['problems'], $label);
        $this->assertSame($this->expectedKey(), $this->letters($preview), $label);
        $this->assertSame($detected, $preview['detected'], $label);
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        foreach (array_keys(self::FILES) as $label) {
            yield $label => [$label];
        }
    }

    public function testEverySystemReadsTheSameQuestionsAsTheTextFile(): void
    {
        $text = $this->preview($this->fixture('onesys sample.txt'), 'txt')['diff']['added'];
        $norm = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));

        foreach (self::FILES as $label => [$name, $extension]) {
            $items = $this->preview($this->fixture($name), $extension)['diff']['added'];

            $this->assertCount(50, $items, $label);

            foreach ($items as $i => $item) {
                // The printed text wraps a code line into the stem; the XML keeps it on its own line.
                $this->assertSame($norm($text[$i]['question_text']), $norm($item['question_text']), "{$label} stem " . ($i + 1));
                $this->assertSame(
                    array_map($norm, array_column($text[$i]['choices'], 'text')),
                    array_map($norm, array_column($item['choices'], 'text')),
                    "{$label} choices of " . ($i + 1)
                );
            }
        }
    }

    public function testApplyingAPackageAddsItsQuestions(): void
    {
        $preview = $this->preview($this->fixture('Onesys Sample Blk.zip'), 'zip');
        $applied = $this->import->applyPreview($this->instructor, $this->quiz, $preview['token']);

        $this->assertTrue($applied['success']);
        $this->assertSame(50, db_connect()->table('assessment_questions')->where('assessment_id', $this->quiz)->countAllResults());
    }

    public function testMoodleKindsAreReadAndWhatItCannotHoldIsListed(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <quiz>
          <question type="category"><category><text>$course$/Top</text></category></question>
          <question type="multichoice"><name><text>Q1</text></name><questiontext format="html"><text><![CDATA[<p>Pick <b>two</b></p>]]></text></questiontext>
            <defaultgrade>2</defaultgrade><single>false</single>
            <answer fraction="50"><text>One</text></answer><answer fraction="50"><text>Two</text></answer><answer fraction="0"><text>Three</text></answer></question>
          <question type="truefalse"><name><text>Q2</text></name><questiontext><text>The sky is blue.</text></questiontext>
            <answer fraction="100"><text>true</text></answer><answer fraction="0"><text>false</text></answer></question>
          <question type="shortanswer"><name><text>Q3</text></name><questiontext><text>Capital of France?</text></questiontext>
            <answer fraction="100"><text>Paris</text></answer><answer fraction="0"><text>Lyon</text></answer></question>
          <question type="essay"><name><text>Q4</text></name><questiontext><text>Discuss.</text></questiontext></question>
          <question type="matching"><name><text>Q5</text></name><questiontext><text>Match.</text></questiontext></question>
        </quiz>
        XML;

        $read = (new MoodleXmlParser())->parse($xml);

        $this->assertCount(4, $read['questions']);
        $this->assertSame('Pick two', $read['questions'][0]['question_text']);
        $this->assertTrue($read['questions'][0]['allow_multiple_answers']);
        $this->assertSame(2.0, $read['questions'][0]['points']);
        $this->assertTrue($read['questions'][1]['choices'][0]['is_correct']);
        $this->assertSame(['Paris'], $read['questions'][2]['accepted_answers']);
        $this->assertSame('Essay', $read['questions'][3]['question_type']);
        $this->assertStringContainsString('matching', $read['skipped'][0]['reason']);
    }

    public function testQtiTrueFalseShortAnswerAndEssayItemsAreRead(): void
    {
        $item = static fn (string $kind, string $body): string => '<item title="' . $kind . '"><itemmetadata><qtimetadata><qtimetadatafield><fieldlabel>qmd_itemtype</fieldlabel><fieldentry>' . $kind . '</fieldentry></qtimetadatafield></qtimetadata></itemmetadata><presentation><flow><material><mattext>Stem for ' . $kind . '</mattext></material>' . $body . '</flow></presentation></item>';

        $xml = '<?xml version="1.0"?><questestinterop><assessment><section>'
            . $item('Multiple Choice', '<response_lid ident="r" rcardinality="Single"><render_choice><response_label ident="a"><material><mattext>X</mattext></material></response_label><response_label ident="b"><material><mattext>Y</mattext></material></response_label></render_choice></response_lid>')
            . '</section></assessment></questestinterop>';

        $read = (new QtiParser())->parse($xml);

        $this->assertCount(1, $read['questions']);
        $this->assertSame(['X', 'Y'], array_column($read['questions'][0]['choices'], 'text'));
        $this->assertSame([false, false], array_column($read['questions'][0]['choices'], 'is_correct'), 'no condition, so the answer is left to choose');
    }

    public function testNonQuizFilesAreRefusedInPlainWords(): void
    {
        $empty = $this->zip(['notes.xml' => '<notes>hello</notes>', 'imsmanifest.xml' => '<manifest/>']);
        $result = $this->preview($empty, 'zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No quiz questions were found', $result['message']);

        $path = sys_get_temp_dir() . '/other-' . bin2hex(random_bytes(3)) . '.xml';
        file_put_contents($path, '<catalog><book/></catalog>');
        $this->temp[] = $path;

        $this->assertStringContainsString('not a quiz export', $this->preview($path, 'xml')['message']);
    }

    public function testAnXmlFileThatDeclaresEntitiesIsNotRead(): void
    {
        $this->assertNull(XmlText::load('<?xml version="1.0"?><!DOCTYPE q [<!ENTITY x SYSTEM "file:///etc/passwd">]><q>&x;</q>'));
    }

    public function testAPackageWithUnsafePathsOrTooManyFilesIsRefused(): void
    {
        $unsafe = $this->zip(['../evil.xml' => '<quiz/>']);
        $this->assertStringContainsString('unsafe', (new QuizPackage())->open($unsafe)['error']);

        $many = [];

        for ($i = 0; $i <= QuizPackage::MAX_ENTRIES; $i++) {
            $many["f{$i}.xml"] = '<a/>';
        }

        $this->assertStringContainsString('more than', (new QuizPackage())->open($this->zip($many))['error']);
    }

    public function testASchemaFileInAPackageIsIgnored(): void
    {
        $zip = $this->zip([
            'imsmanifest.xml' => '<manifest/>',
            'cisv3.xsd' => '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>',
            'item.xml' => '<questestinterop><item title="T"><presentation><flow><material><mattext>Stem?</mattext></material>'
                . '<response_lid ident="r" rcardinality="Single"><render_choice><response_label ident="a"><material><mattext>Yes</mattext></material></response_label>'
                . '<response_label ident="b"><material><mattext>No</mattext></material></response_label></render_choice></response_lid></flow></presentation>'
                . '<resprocessing><respcondition><conditionvar><varequal respident="r">a</varequal></conditionvar><setvar action="Set">100</setvar></respcondition></resprocessing></item></questestinterop>',
        ]);

        $preview = $this->preview($zip, 'zip');

        $this->assertTrue($preview['success'], $preview['message'] ?? '');
        $this->assertSame(1, $preview['counts']['added']);
        $this->assertSame('a', $preview['diff']['added'][0]['choices'][0]['is_correct'] ? 'a' : 'b');
    }

    public function testATestGenFileIsRecognisedAndExplainedNotGuessedAt(): void
    {
        $result = $this->preview($this->fixture('Onesys Sample.tst'), 'tst');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('TestGen', $result['message']);
        $this->assertStringContainsString('50 questions', $result['message']);
        $this->assertStringContainsString('Moodle XML', $result['message']);

        // Zipped up the way it is downloaded, it is still recognised.
        $this->assertSame($result['message'], $this->preview($this->fixture('Onesys Sample.tst'), 'zip')['message']);
    }

    public function testAnExportProblemReportIsNotTakenForAQuiz(): void
    {
        $result = $this->preview($this->fixture('Error in Moodle.TXT'), 'txt');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a quiz', $result['message']);
    }

    public function testAnEmptyOrDamagedTstIsRefusedPlainly(): void
    {
        $path = sys_get_temp_dir() . '/bad-' . bin2hex(random_bytes(3)) . '.tst';
        file_put_contents($path, 'not a zip');
        $this->temp[] = $path;

        $this->assertStringContainsString('not a TestGen test file', $this->preview($path, 'tst')['message']);
    }
}

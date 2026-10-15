<?php

namespace App\Services\Courses\Assessments;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentQuestionModel;

class QuestionExportService
{
    /**
     * Every question block ends with a plain, clearly-labeled metadata
     * line (not hidden/white text) so re-importing this exact export is
     * deterministic — see QuestionImportService::META_PATTERN, which this
     * format must stay in sync with.
     */
    public const META_PREFIX = '[machine-readable — do not edit]';

    /**
     * Visible label of a Short Answer's answer key line ("Accepted answers:
     * CPU | Central Processing Unit") — editable like choice text, and read
     * back by QuestionImportService::ACCEPTED_ANSWERS_PATTERN.
     */
    public const ACCEPTED_ANSWERS_LABEL = 'Accepted answers:';

    public const ACCEPTED_ANSWERS_SEPARATOR = ' | ';

    protected AssessmentModel $assessmentModel;
    protected AssessmentQuestionModel $questionModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->questionModel = new AssessmentQuestionModel();
    }

    public function exportToDocx(int $assessmentId): array
    {
        return $this->export($assessmentId, 'Word2007', 'docx');
    }

    public function exportToRtf(int $assessmentId): array
    {
        return $this->export($assessmentId, 'RTF', 'rtf');
    }

    /**
     * The quiz as a paper test: questions to hand out, then the answer key on
     * pages of their own. Not meant to be imported back (use exportToDocx for
     * that).
     *
     * @param string $format pdf, docx or rtf
     * @param array{versions?: int, shuffle_questions?: bool, shuffle_choices?: bool, key_only?: bool, paper?: string} $options
     */
    public function exportPrintable(int $assessmentId, string $format, array $options = []): array
    {
        $format = strtolower($format);

        if (!in_array($format, PrintableTestExport::FORMATS, true)) {
            return ['success' => false, 'message' => 'That format is not available for a printable test.'];
        }

        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        $printable = new PrintableTestExport();
        $models = $printable->versions($assessment, $this->questionModel->listForAssessment($assessmentId), $options);

        $filename = 'assessment-' . $assessmentId . '-test-' . date('Ymd-His') . '.' . $format;
        $path = $this->exportDirectory() . '/' . $filename;

        $printable->saveMany($models, $format, $path, $options);

        return ['success' => true, 'file_path' => $path, 'filename' => $filename];
    }

    private function exportDirectory(): string
    {
        $directory = (new \App\Services\Storage\StorageService())->root() . 'exports';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        // Exported files are only needed for the download that follows, so a
        // day-old one is cleared out whenever a new one is written.
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }

        return $directory;
    }

    private function export(int $assessmentId, string $writerType, string $extension): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        $questions = $this->questionModel->listForAssessment($assessmentId);

        $phpWord = $this->buildDocument($assessment, $questions);

        $filename = 'assessment-' . $assessmentId . '-' . date('Ymd-His') . '.' . $extension;

        $path = $this->exportDirectory() . '/' . $filename;

        $writer = IOFactory::createWriter($phpWord, $writerType);
        $writer->save($path);

        return [
            'success' => true,
            'file_path' => $path,
            'filename' => $filename,
        ];
    }

    private function buildDocument(array $assessment, array $questions): PhpWord
    {
        $phpWord = new PhpWord();

        $titleStyle = ['bold' => true, 'size' => 16, 'color' => '0040A6'];
        $subtitleStyle = ['size' => 9, 'color' => '999999'];
        $questionStyle = ['bold' => true, 'size' => 11];
        $choiceStyle = ['size' => 10];
        $correctChoiceStyle = ['size' => 10, 'bold' => true, 'color' => '1B7A43'];
        $metaStyle = ['size' => 8, 'color' => '999999', 'italic' => true];

        $section = $phpWord->addSection();

        $section->addText($assessment['title'], $titleStyle);

        $section->addText(
            'Exported ' . date('F j, Y') . ' — edit question text, choices, or points below, then re-import.',
            $subtitleStyle
        );

        $section->addTextBreak();

        foreach ($questions as $index => $question) {

            $section->addText(
                'Q' . ($index + 1) . '. ' . $question['question_text'],
                $questionStyle
            );

            $correctLetters = [];

            if ($question['question_type'] === 'Multiple Choice') {

                $letters = range('a', 'z');

                foreach ($question['choices'] as $choiceIndex => $choice) {

                    $prefix = $letters[$choiceIndex] ?? (string) ($choiceIndex + 1);

                    $isCorrect = !empty($choice['is_correct']);

                    if ($isCorrect) {
                        $correctLetters[] = $prefix;
                    }

                    // The correct choice is marked in the metadata line
                    // below (correct=...), not with visible "(correct)"
                    // text — bold/green styling is enough for a human
                    // reader, and it keeps the choice text itself clean.
                    $label = $prefix . ') ' . $choice['choice_text'];

                    $section->addText($label, $isCorrect ? $correctChoiceStyle : $choiceStyle);

                }

            }

            // Short Answer: the answer key on its own visible line; its
            // case sensitivity rides along in the metadata line (case=...).
            $caseSuffix = '';

            // A multiple-answers question says so (and how many to choose), so
            // importing it back keeps it a multiple-answers question.
            $multiSuffix = '';

            if ($question['question_type'] === 'Multiple Choice' && !empty($question['allow_multiple_answers'])) {

                $multiSuffix = '; multi=yes' . (!empty($question['answers_count']) ? '; answers=' . (int) $question['answers_count'] : '');

            }

            if ($question['question_type'] === 'Short Answer') {

                $section->addText(
                    self::ACCEPTED_ANSWERS_LABEL . ' ' . implode(self::ACCEPTED_ANSWERS_SEPARATOR, $question['accepted_answers'] ?? []),
                    $correctChoiceStyle
                );

                $caseSuffix = '; case=' . (!empty($question['case_sensitive']) ? 'yes' : 'no');

            }

            $section->addText(
                sprintf(
                    '%s id=%d; type=%s; points=%s; required=%s; correct=%s%s%s',
                    self::META_PREFIX,
                    $question['id'],
                    $question['question_type'],
                    rtrim(rtrim(number_format((float) $question['points'], 2, '.', ''), '0'), '.'),
                    !empty($question['is_required']) ? 'yes' : 'no',
                    implode(',', $correctLetters),
                    $multiSuffix,
                    $caseSuffix
                ),
                $metaStyle
            );

            $section->addTextBreak();

        }

        if (empty($questions)) {
            $section->addText('This assessment has no questions yet.', $subtitleStyle);
        }

        return $phpWord;
    }
}

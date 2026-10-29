<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSettingsModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentTypeModel;
use App\Models\Files\FileModel;
use App\Services\Courses\CourseMembership;
use App\Services\Courses\LearningProgressService;
use App\Services\Storage\StorageService;

/**
 * Student-side assignment submissions: file upload, text entry and/or a
 * link, per the assignment's allowed types. Every (re)submission is a new
 * attempt row, bounded by the assessment's attempt_limit. Quizzes have
 * their own flow (QuizAttemptService) — its scoring would mark a
 * question-less assignment "Graded 0", so it must not be reused here.
 */
class AssignmentSubmissionService
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    protected AssessmentModel $assessmentModel;
    protected AssessmentSettingsModel $settingsModel;
    protected AssessmentSubmissionModel $submissionModel;
    protected AssessmentTypeModel $typeModel;
    protected FileModel $fileModel;
    protected StorageService $storageService;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->settingsModel = new AssessmentSettingsModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->typeModel = new AssessmentTypeModel();
        $this->fileModel = new FileModel();
        $this->storageService = new StorageService();
    }

    /**
     * @return array{
     *     attempts: array, latest: ?array, attemptsUsed: int, attemptLimit: int,
     *     allowed: array{file: bool, text: bool, link: bool},
     *     canSubmit: bool, reason: ?string
     * }
     */
    public function getState(User $user, int $assessmentId): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);
        $settings = $this->settingsModel->where('assessment_id', $assessmentId)->first();

        $allowed = [
            'file' => (bool) ($settings['allow_file_upload'] ?? false),
            'text' => (bool) ($settings['allow_text_entry'] ?? false),
            'link' => (bool) ($settings['allow_link_entry'] ?? false),
        ];

        $attempts = $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $user->id)
            ->where('status !=', 'In Progress')
            ->orderBy('attempt_number', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        foreach ($attempts as &$attempt) {
            $attempt['file'] = !empty($attempt['submission_file_id'])
                ? $this->fileModel->retrieveFile((int) $attempt['submission_file_id'])
                : null;
        }
        unset($attempt);

        $attemptLimit = max(1, (int) ($assessment['attempt_limit'] ?? 1));

        // The Modules lock (prerequisite items) applies here too, so the
        // Assessments tab can't be used to skip ahead.
        $lock = !empty($assessment['learning_item_id'])
            ? (new LearningProgressService())->lockFor($user, (int) $assessment['learning_item_id'])
            : null;

        $reason = match (true) {
            !$assessment => 'This assignment could not be found.',
            $lock !== null => $lock['message'],
            default => $this->blockingReason($assessment, $settings, $allowed, count($attempts), $attemptLimit),
        };

        // The latest graded submission is the one that counts.
        $result = (new AssessmentResultService())->resultFor(false, $attempts);

        return [
            'attempts' => $attempts,
            'latest' => $attempts[0] ?? null,
            'counted' => $result['counted'],
            'result' => $result,
            'attemptsUsed' => count($attempts),
            'attemptLimit' => $attemptLimit,
            'allowed' => $allowed,
            'canSubmit' => $reason === null,
            'reason' => $reason,
        ];
    }

    public function submit(User $user, int $assessmentId, array $data, ?UploadedFile $file = null): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment || !$this->isAssignment($assessment)) {
            return ['success' => false, 'message' => 'Assignment not found.'];
        }

        // A student of the assignment's section (9.1); one in no module falls back to any section of the course.
        $offeringId = $this->assessmentModel->offeringIdOf($assessmentId);
        $isStudent  = $offeringId === null
            ? $user->inGroup('student') && $this->isEnrolled((int) $user->id, (int) $assessment['course_id'])
            : (new CourseMembership())->roleIn($user, $offeringId) === 'student';

        if (!$isStudent) {
            return ['success' => false, 'message' => 'Only students enrolled in this course can submit.'];
        }

        $state = $this->getState($user, $assessmentId);

        if (!$state['canSubmit']) {
            return ['success' => false, 'message' => $state['reason']];
        }

        $text = trim((string) ($data['submission_text'] ?? ''));
        $link = trim((string) ($data['submission_link'] ?? ''));

        $fileProvided = $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;

        if ($fileProvided && !$file->isValid()) {
            return ['success' => false, 'message' => 'The file could not be uploaded: ' . $file->getErrorString()];
        }

        foreach ([
            'text' => [$text !== '', 'Text entry'],
            'link' => [$link !== '', 'Link submission'],
            'file' => [$fileProvided, 'File upload'],
        ] as $type => [$provided, $label]) {
            if ($provided && !$state['allowed'][$type]) {
                return ['success' => false, 'message' => "{$label} isn't allowed for this assignment."];
            }
        }

        if ($text === '' && $link === '' && !$fileProvided) {
            return ['success' => false, 'message' => 'Add your work before submitting.'];
        }

        if ($link !== '' && !$this->isHttpUrl($link)) {
            return ['success' => false, 'message' => 'Enter a full link starting with http:// or https://.'];
        }

        if ($fileProvided && $file->getSize() > self::MAX_FILE_BYTES) {
            return ['success' => false, 'message' => 'The file is larger than the 20 MB limit.'];
        }

        $fileId = null;

        if ($fileProvided) {

            $stored = $this->storageService->upload($file, 'assignment-submissions');

            $fileId = $this->fileModel->insert([
                'owner_user_id' => $user->id,
                'original_filename' => $stored['original_name'],
                'stored_filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['size'],
                'storage_key' => $stored['storage_key'],
                'status' => 'active',
            ], true);

        }

        $lastAttemptNumber = (int) ($this->submissionModel
            ->selectMax('attempt_number')
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $user->id)
            ->first()['attempt_number'] ?? 0);

        $now = date('Y-m-d H:i:s');

        $code = 0;

        try {
            $saved = $this->submissionModel->insert([
                'assessment_id' => $assessmentId,
                'student_id' => $user->id,
                'attempt_number' => $lastAttemptNumber + 1,
                'started_at' => $now,
                'submitted_at' => $now,
                'submission_text' => $text !== '' ? $text : null,
                'submission_link' => $link !== '' ? $link : null,
                'submission_file_id' => $fileId,
                'is_late_submission' => !empty($assessment['due_date']) && strtotime($now) > strtotime($assessment['due_date']),
                'status' => 'Submitted',
            ]);
        } catch (DatabaseException $e) {
            $saved = false;
            $code = $e->getCode();
        }

        if ($saved === false) {
            if ($fileId !== null) {
                $this->fileModel->delete($fileId);
                $this->storageService->delete($stored['storage_key']);
            }

            // 1062 = duplicate key: a request sent at the same moment took this attempt number first.
            // Inside a transaction the driver returns false instead of throwing, so read the code from it.
            $code = $code ?: db_connect()->error()['code'];

            return ['success' => false, 'message' => $code === 1062
                ? "You've already submitted this assignment."
                : 'Your work could not be saved. Try again.'];
        }

        // Handing the work in completes its item in the module.
        (new LearningProgressService())->completeOnSubmission((int) $user->id, $assessmentId);

        return ['success' => true, 'message' => 'Your work was submitted.'];
    }

    private function blockingReason(array $assessment, ?array $settings, array $allowed, int $attemptsUsed, int $attemptLimit): ?string
    {
        $now = time();

        if ($assessment['status'] === 'Closed') {
            return 'This assignment is closed.';
        }

        if ($assessment['status'] !== 'Published') {
            return "This assignment isn't open for submissions yet.";
        }

        if (!empty($assessment['available_from']) && $now < strtotime($assessment['available_from'])) {
            return 'Submissions open ' . $this->formatDate($assessment['available_from']) . '.';
        }

        if (!empty($assessment['available_until']) && $now > strtotime($assessment['available_until'])) {
            return 'Submissions closed ' . $this->formatDate($assessment['available_until']) . '.';
        }

        if (!empty($assessment['due_date']) && $now > strtotime($assessment['due_date']) && empty($settings['allow_late_submission'])) {
            return "The due date has passed and late submissions aren't allowed.";
        }

        if ($attemptsUsed >= $attemptLimit) {
            return $attemptLimit === 1
                ? "You've already submitted this assignment."
                : "You've used all {$attemptLimit} submissions.";
        }

        if (!in_array(true, $allowed, true)) {
            return "Your instructor hasn't turned on a way to submit yet.";
        }

        return null;
    }

    private function isAssignment(array $assessment): bool
    {
        $type = $this->typeModel->find($assessment['assessment_type_id']);

        return strtolower($type['name'] ?? '') === 'assignment';
    }

    private function isEnrolled(int $studentId, int $courseId): bool
    {
        return db_connect()->table('enrollments')
            ->join('course_offerings', 'course_offerings.id = enrollments.offering_id')
            ->where('enrollments.student_user_id', $studentId)
            ->whereIn('enrollments.status', ['enrolled', 'completed'])
            ->where('course_offerings.course_id', $courseId)
            ->countAllResults() > 0;
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function formatDate(string $datetime): string
    {
        return date('M j, Y g:i A', strtotime($datetime));
    }
}

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
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupExtensionService;
use App\Services\Groups\GroupSubmissionResolver;
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
    protected GroupSubmissionResolver $resolver;

    public function __construct()
    {
        $this->resolver = new GroupSubmissionResolver();
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
     *     canSubmit: bool, reason: ?string,
     *     group?: array{name: ?string, canSubmit: bool, blockedReason: ?string, blockedCode: ?string, submittedBy: ?string, extensionUntil: ?string, extensionReason: ?string, leaderName: ?string}
     * } `group` only on a group activity; attempts carry `submitted_by` for a shared group submission
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

        // A shared group submission belongs to the whole group: its rows, attempts and deadline are the group's.
        $shared = $this->resolver->sharedGroup($assessmentId, (int) $user->id);

        $attempts = $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where($shared ? 'group_id' : 'student_id', $shared ? $shared['id'] : $user->id)
            ->where('status !=', 'In Progress')
            ->orderBy('attempt_number', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        foreach ($attempts as &$attempt) {
            $attempt['file'] = !empty($attempt['submission_file_id'])
                ? $this->fileModel->retrieveFile((int) $attempt['submission_file_id'])
                : null;

            if ($shared) {
                $attempt['submitted_by'] = $this->nameOf((int) ($attempt['submitted_by_user_id'] ?? $attempt['student_id']));
            }
        }
        unset($attempt);

        $config = $assessment ? (new ActivityGroupService())->findByAssessment($assessmentId) : null;
        $verdict = $this->resolver->canSubmit($assessmentId, (int) $user->id);

        if ($assessment) {
            $assessment['due_date'] = $this->resolver->effectiveDueDate($assessmentId, (int) $user->id);
        }

        $attemptLimit = max(1, (int) ($assessment['attempt_limit'] ?? 1));

        // The Modules lock (prerequisite items) applies here too, so the
        // Assessments tab can't be used to skip ahead.
        $lock = !empty($assessment['learning_item_id'])
            ? (new LearningProgressService())->lockFor($user, (int) $assessment['learning_item_id'])
            : null;

        $reason = match (true) {
            !$assessment => 'This assignment could not be found.',
            $lock !== null => $lock['message'],
            default => $this->blockingReason($assessment, $settings, $allowed, count($attempts), $attemptLimit) ?? $verdict['reason'],
        };

        // The latest graded submission is the one that counts.
        // On a group activity each attempt carries this student's own final (never a teammate's).
        $results = new AssessmentResultService();
        $attemptsUsed = count($attempts);
        $attempts = $results->memberAttempts($assessmentId, (int) $user->id, $attempts);
        $result = $results->resultFor(false, $attempts);

        $extension = $shared ? (new GroupExtensionService())->extensionFor((int) $shared['id'], $assessmentId) : null;

        $group = $config === null ? [] : ['group' => [
            'name' => $this->resolver->groupFor($assessmentId, (int) $user->id)['name'] ?? null,
            'canSubmit' => $verdict['allowed'],
            'blockedReason' => $verdict['reason'],
            'blockedCode' => $verdict['allowed'] ? null : $verdict['code'],
            'submittedBy' => $attempts[0]['submitted_by'] ?? null,
            'extensionUntil' => $extension['until_at'] ?? null,
            'extensionReason' => $extension['reason'] ?? null,
            'leaderName' => $verdict['code'] === 'leader_only' ? $this->nameOf((int) ($this->resolver->groupFor($assessmentId, (int) $user->id)['leader_user_id'] ?? 0)) : null,
        ]];

        return [
            'attempts' => $attempts,
            'latest' => $attempts[0] ?? null,
            'counted' => $result['counted'],
            'result' => $result,
            'attemptsUsed' => $attemptsUsed,
            'attemptLimit' => $attemptLimit,
            'allowed' => $allowed,
            'canSubmit' => $reason === null,
            'reason' => $reason,
        ] + $group;
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

        if (!(new AssessmentService())->isAssignedTo($assessmentId, (int) $user->id)) {
            return ['success' => false, 'message' => 'Assignment not found.'];
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

        $shared = $this->resolver->sharedGroup($assessmentId, (int) $user->id);
        $dueDate = $this->resolver->effectiveDueDate($assessmentId, (int) $user->id);

        $now = date('Y-m-d H:i:s');

        $code = 0;

        try {
            $saved = $this->submissionModel->insert([
                'assessment_id' => $assessmentId,
                'student_id' => $user->id,
                'group_id' => $shared ? $shared['id'] : null,
                'submitted_by_user_id' => $shared ? $user->id : null,
                'attempt_number' => $this->resolver->attemptNumberFor($assessmentId, (int) $user->id),
                'started_at' => $now,
                'submitted_at' => $now,
                'submission_text' => $text !== '' ? $text : null,
                'submission_link' => $link !== '' ? $link : null,
                'submission_file_id' => $fileId,
                'is_late_submission' => !empty($dueDate) && strtotime($now) > strtotime($dueDate),
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

        // The group's first hand-in (any style) locks its membership.
        if ($group = $this->resolver->groupFor($assessmentId, (int) $user->id)) {
            (new ActivityGroupMembershipService())->freezeGroup((int) $group['id']);
        }

        // Handing the work in completes its item in the module, for every member when the group submits as one.
        $members = $shared
            ? array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $shared['id'])->get()->getResultArray(), 'student_user_id')
            : [$user->id];

        foreach ($members as $memberId) {
            (new LearningProgressService())->completeOnSubmission((int) $memberId, $assessmentId);
        }

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

    private function nameOf(int $userId): string
    {
        $row = db_connect()->table('users u')->select('u.username, up.first_name, up.last_name')->join('user_profiles up', 'up.user_id = u.id', 'left')->where('u.id', $userId)->get()->getRowArray();

        return $row === null ? '' : (trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) $row['username']);
    }

    private function formatDate(string $datetime): string
    {
        return date('M j, Y g:i A', strtotime($datetime));
    }
}

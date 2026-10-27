<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;

use App\Models\Courses\DiscussionThreadModel;
use App\Models\Courses\DiscussionReplyModel;
use App\Models\Courses\DiscussionReactionModel;
use App\Models\Courses\DiscussionAttachmentModel;
use App\Models\Courses\DiscussionReadModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Files\FileModel;
use App\Services\Storage\StorageService;

/**
 * Course discussions: threads anyone in the offering can start, flat
 * replies, one reaction per person per post, attachments, mark-an-answer,
 * pin/lock for instructors, and per-user unread tracking. A thread may
 * back a `discussion` learning item (learning_item_id); the item side is
 * wired up separately.
 */
class DiscussionService
{
    /** Images, PDFs and common office docs; nothing executable. */
    private const ALLOWED_ATTACHMENT_MIMES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;
    private const PAGE_SIZE = 10;
    private const SORTS = ['activity', 'newest', 'replies'];
    private const FILTERS = ['all', 'pinned', 'mine', 'unanswered', 'modules', 'unread'];

    protected DiscussionThreadModel $threadModel;
    protected DiscussionReplyModel $replyModel;
    protected DiscussionReactionModel $reactionModel;
    protected DiscussionAttachmentModel $attachmentModel;
    protected DiscussionReadModel $readModel;
    protected EnrollmentModel $enrollmentModel;
    protected InstructorAssignmentModel $instructorAssignmentModel;
    protected FileModel $fileModel;
    protected StorageService $storageService;

    public function __construct()
    {
        $this->threadModel = new DiscussionThreadModel();
        $this->replyModel = new DiscussionReplyModel();
        $this->reactionModel = new DiscussionReactionModel();
        $this->attachmentModel = new DiscussionAttachmentModel();
        $this->readModel = new DiscussionReadModel();
        $this->enrollmentModel = new EnrollmentModel();
        $this->instructorAssignmentModel = new InstructorAssignmentModel();
        $this->fileModel = new FileModel();
        $this->storageService = new StorageService();
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    /** Instructor here, or the chairperson of the program it belongs to — same posting rights either way. */
    private function isInstructorHere(User $user, int $offeringId): bool
    {
        return in_array((new CourseMembership())->roleIn($user, $offeringId), ['instructor', 'chairperson'], true);
    }

    /**
     * The instructor (or chairperson) here who may moderate: pin, lock, delete someone else's post, mark
     * someone else's answer. Their institution can take that away (discussions.manage, decision D6).
     */
    private function canModerate(User $user, int $offeringId): bool
    {
        return $this->isInstructorHere($user, $offeringId) && ! \App\Services\Courses\CourseAbilities::viewOnly($user, 'discussions');
    }

    private function canViewOffering(User $user, int $offeringId): bool
    {
        // The institution's administrator may look, never post: access only, no posting rights.
        if (\App\Services\Courses\CourseViewer::isReadOnlyViewer($user)) {
            return (new \App\Services\Courses\CourseReadAccess())->canAccess($user, $offeringId);
        }

        if ($this->isInstructorHere($user, $offeringId)) {
            return true;
        }

        return (bool) $this->enrollmentModel->retrieveStudentCourseOffering($user->id, $offeringId);
    }

    /** The thread, only if it belongs to this offering. */
    private function threadIn(int $offeringId, int $threadId, int $userId = 0, bool $hideUnpublished = false): ?array
    {
        $thread = $this->threadModel->retrieveOne($threadId, $userId, $hideUnpublished);

        return $thread && (int) $thread['offering_id'] === $offeringId ? $thread : null;
    }

    private function deny(string $message = 'Not authorized.'): array
    {
        return ['success' => false, 'message' => $message];
    }

    /** What the viewer may do with a thread — computed server-side so the client never receives controls it can't use. */
    /** @param bool $moderates the instructor here, allowed to moderate (discussions.manage, decision D6) */
    private function threadPermissions(array $thread, User $user, bool $moderates): array
    {
        $isAuthor = (int) $thread['author_id'] === (int) $user->id;

        return [
            'can_edit' => $isAuthor,
            'can_delete' => $isAuthor || $moderates,
            'can_pin' => $moderates,
            'can_lock' => $moderates,
            'can_mark_answer' => $isAuthor || $moderates,
            'can_reply' => !$thread['is_locked'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Read
    |--------------------------------------------------------------------------
    */

    /**
     * The tab's screen: header, toolbar and the first page of threads.
     *
     * @param array{search?: string, sort?: string, filter?: string, page?: int} $filters
     */
    public function show(User $user, int $offeringId, array $filters = []): array
    {
        $result = $this->listThreads($user, $offeringId, $filters);

        return [
            'html' => view('pages/courses/features/discussions/index', [
                'threads' => $result['threads'],
                'hasMore' => $result['has_more'],
                'nextPage' => $result['page'] + 1,
                'isInstructor' => $result['is_instructor'],
                'filters' => $this->normalizeFilters($filters),
            ], ['saveData' => false]),
        ];
    }

    /** One thread's screen: breadcrumb, the opening post, replies and the reply box. */
    public function showThread(User $user, int $offeringId, int $threadId): array
    {
        $data = $this->getThread($user, $offeringId, $threadId);

        return ['html' => view('pages/courses/features/discussions/thread', $data, ['saveData' => false])];
    }

    /** Just the thread rows for the next page. */
    public function loadMore(User $user, int $offeringId, array $filters = []): array
    {
        $result = $this->listThreads($user, $offeringId, $filters);

        $html = '';

        foreach ($result['threads'] as $thread) {
            $html .= view('pages/courses/features/discussions/partials/thread-row', ['thread' => $thread], ['saveData' => false]);
        }

        return ['success' => true, 'html' => $html, 'has_more' => $result['has_more'], 'next_page' => $result['page'] + 1];
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'activity',
            'filter' => in_array($filters['filter'] ?? '', self::FILTERS, true) ? $filters['filter'] : 'all',
        ];
    }

    /**
     * @param array{search?: string, sort?: string, filter?: string, page?: int} $filters
     * @return array{threads: list<array>, has_more: bool, page: int, is_instructor: bool}
     */
    public function listThreads(User $user, int $offeringId, array $filters = []): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isInstructor = $this->isInstructorHere($user, $offeringId);

        $page = max(1, (int) ($filters['page'] ?? 1));

        $rows = $this->threadModel->retrieveForOffering($offeringId, (int) $user->id, [
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'activity',
            'filter' => in_array($filters['filter'] ?? '', self::FILTERS, true) ? $filters['filter'] : 'all',
            'hide_unpublished' => !$isInstructor,
            'limit' => self::PAGE_SIZE + 1,
            'offset' => self::PAGE_SIZE * ($page - 1),
        ]);

        $hasMore = count($rows) > self::PAGE_SIZE;

        $moderates = $isInstructor && ! \App\Services\Courses\CourseAbilities::viewOnly($user, 'discussions');

        $threads = array_map(
            fn (array $t) => $t + $this->threadPermissions($t, $user, $moderates),
            array_slice($rows, 0, self::PAGE_SIZE)
        );

        return ['threads' => $threads, 'has_more' => $hasMore, 'page' => $page, 'is_instructor' => $isInstructor];
    }

    /** Opens a thread — and marks it read for this viewer. */
    public function getThread(User $user, int $offeringId, int $threadId): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isInstructor = $this->isInstructorHere($user, $offeringId);

        $thread = $this->threadIn($offeringId, $threadId, (int) $user->id, !$isInstructor);

        if (!$thread) {
            throw PageNotFoundException::forPageNotFound();
        }

        $moderates = $isInstructor && ! \App\Services\Courses\CourseAbilities::viewOnly($user, 'discussions');

        $replies = $this->replyModel->retrieveForThread($threadId);
        $counts = $this->reactionModel->retrieveCounts($threadId);
        $own = $this->reactionModel->retrieveOwn($threadId, (int) $user->id);
        $attachments = $this->attachmentModel->retrieveForThread($threadId);

        $withUrl = fn (array $files) => array_map(
            fn (array $f) => $f + ['url' => $this->storageService->url((int) $f['id'])],
            $files
        );

        foreach ($replies as &$reply) {

            $id = (int) $reply['id'];
            $isAuthor = (int) $reply['author_id'] === (int) $user->id;

            $reply['reaction_counts'] = $counts['replies'][$id] ?? [];
            $reply['own_reaction'] = $own['replies'][$id] ?? null;
            $reply['attachments'] = $withUrl($attachments['replies'][$id] ?? []);
            $reply['is_answer'] = (int) $thread['answer_reply_id'] === $id;
            $reply['can_delete'] = $isAuthor || $moderates;

        }
        unset($reply);

        $thread = $thread + $this->threadPermissions($thread, $user, $moderates) + [
            'reaction_counts' => $counts['thread'],
            'own_reaction' => $own['thread'],
            'attachments' => $withUrl($attachments['thread']),
        ];

        // Opening it is what clears "unread" — the view above already carries
        // the pre-read flag so the first render can still show it.
        $this->readModel->markRead($threadId, (int) $user->id);

        return ['thread' => $thread, 'replies' => $replies, 'is_instructor' => $isInstructor];
    }

    /*
    |--------------------------------------------------------------------------
    | Threads
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{title?: string, body?: string, attachment_file_ids?: array} $data
     */
    public function createThread(User $user, int $offeringId, array $data): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            return $this->deny();
        }

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return $this->deny('Add a title before posting.');
        }

        if (mb_strlen($title) > 255) {
            return $this->deny('Titles can be at most 255 characters.');
        }

        $now = $this->microNow();

        $this->threadModel->insert([
            'offering_id' => $offeringId,
            'author_id' => $user->id,
            'title' => $title,
            'body' => (string) ($data['body'] ?? ''),
            'last_activity_at' => $now,
        ]);

        $threadId = (int) $this->threadModel->getInsertID();

        $this->attachmentModel->attach($threadId, null, $data['attachment_file_ids'] ?? []);

        return ['success' => true, 'message' => 'Discussion started.', 'thread_id' => $threadId];
    }

    public function updateThread(User $user, int $offeringId, int $threadId, array $data): array
    {
        $thread = $this->threadIn($offeringId, $threadId);

        if (!$thread || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Discussion not found.');
        }

        if ((int) $thread['author_id'] !== (int) $user->id) {
            return $this->deny('You can only edit your own discussion.');
        }

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return $this->deny('Add a title before saving.');
        }

        $this->threadModel->update($threadId, [
            'title' => mb_substr($title, 0, 255),
            'body' => (string) ($data['body'] ?? ''),
        ]);

        if (array_key_exists('attachment_file_ids', $data)) {

            $this->replaceThreadAttachments($threadId, (array) $data['attachment_file_ids']);

        }

        return ['success' => true, 'message' => 'Discussion updated.'];
    }

    /**
     * Makes the opening post's attachments exactly these files. Also used by
     * a discussion learning item, whose editor shares the composer.
     *
     * @param list<int|string> $fileIds
     */
    public function replaceThreadAttachments(int $threadId, array $fileIds): void
    {
        $existing = $this->db()->table('discussion_attachments')
            ->where('thread_id', $threadId)->where('reply_id IS NULL', null, false)
            ->get()->getResultArray();

        $this->db()->table('discussion_attachments')
            ->where('thread_id', $threadId)->where('reply_id IS NULL', null, false)->delete();

        $this->attachmentModel->attach($threadId, null, $fileIds);

        $this->storageService->purgeFiles(array_column($existing, 'file_id'));
    }

    public function deleteThread(User $user, int $offeringId, int $threadId): array
    {
        $thread = $this->threadIn($offeringId, $threadId);

        if (!$thread || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Discussion not found.');
        }

        if ((int) $thread['author_id'] !== (int) $user->id && !$this->canModerate($user, $offeringId)) {
            return $this->deny('You can only delete your own discussion.');
        }

        $fileIds = $this->attachmentModel->fileIdsForThread($threadId);

        $this->threadModel->delete($threadId);

        $this->storageService->purgeFiles($fileIds);

        return ['success' => true, 'message' => 'Discussion deleted.'];
    }

    public function setPinned(User $user, int $offeringId, int $threadId, bool $pinned): array
    {
        return $this->moderate($user, $offeringId, $threadId, ['is_pinned' => $pinned], 'pin discussions');
    }

    public function setLocked(User $user, int $offeringId, int $threadId, bool $locked): array
    {
        return $this->moderate($user, $offeringId, $threadId, ['is_locked' => $locked], 'lock discussions');
    }

    private function moderate(User $user, int $offeringId, int $threadId, array $change, string $what): array
    {
        if (!$this->canModerate($user, $offeringId)) {
            return $this->deny("Only the instructor can {$what}.");
        }

        if (!$this->threadIn($offeringId, $threadId)) {
            return $this->deny('Discussion not found.');
        }

        $this->threadModel->update($threadId, $change);

        return ['success' => true] + array_map('boolval', $change);
    }

    /*
    |--------------------------------------------------------------------------
    | Replies
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{success: bool, message?: string, reply_id?: int}
     */
    public function reply(User $user, int $offeringId, int $threadId, string $body, array $attachmentFileIds = []): array
    {
        $thread = $this->threadIn($offeringId, $threadId);

        if (!$thread || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Discussion not found.');
        }

        if ($thread['is_locked']) {
            return $this->deny('This discussion is locked.');
        }

        if (trim($body) === '') {
            return $this->deny('Write a reply first.');
        }

        $this->replyModel->insert([
            'thread_id' => $threadId,
            'author_id' => $user->id,
            'body' => $body,
        ]);

        $replyId = (int) $this->replyModel->getInsertID();

        $this->attachmentModel->attach($threadId, $replyId, $attachmentFileIds);

        $this->threadModel->update($threadId, [
            'reply_count' => (int) $thread['reply_count'] + 1,
            'last_activity_at' => $this->microNow(),
        ]);

        $completesItem = !empty($thread['learning_item_id']) && !$this->isInstructorHere($user, $offeringId);

        // The author has, by definition, seen their own reply.
        $this->readModel->markRead($threadId, (int) $user->id);

        // Rendered here so the client can append the new reply in place.
        $row = $this->replyModel->retrieveOne($replyId);
        $files = $this->attachmentModel->retrieveForThread($threadId)['replies'][$replyId] ?? [];

        $row['reaction_counts'] = [];
        $row['own_reaction'] = null;
        $row['is_answer'] = false;
        $row['can_delete'] = true;
        $row['attachments'] = array_map(
            fn (array $f) => $f + ['url' => $this->storageService->url((int) $f['id'])],
            $files
        );

        return [
            'success' => true,
            'reply_id' => $replyId,
            'completes_item' => $completesItem,
            'reply_count' => (int) $thread['reply_count'] + 1,
            'html' => view('pages/courses/features/discussions/partials/reply', [
                'reply' => $row,
                'canMarkAnswer' => (int) $thread['author_id'] === (int) $user->id || $this->canModerate($user, $offeringId),
            ], ['saveData' => false]),
        ];
    }

    public function deleteReply(User $user, int $offeringId, int $replyId): array
    {
        $reply = $this->replyModel->find($replyId);
        $thread = $reply ? $this->threadIn($offeringId, (int) $reply['thread_id']) : null;

        if (!$reply || !$thread || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Reply not found.');
        }

        if ((int) $reply['author_id'] !== (int) $user->id && !$this->canModerate($user, $offeringId)) {
            return $this->deny('You can only delete your own reply.');
        }

        $fileIds = $this->attachmentModel->fileIdsForReply($replyId);

        $this->replyModel->delete($replyId);

        $change = ['reply_count' => max(0, (int) $thread['reply_count'] - 1)];

        if ((int) $thread['answer_reply_id'] === $replyId) {
            $change['answer_reply_id'] = null;
        }

        $this->threadModel->update((int) $thread['id'], $change);

        $this->storageService->purgeFiles($fileIds);

        return ['success' => true, 'reply_count' => $change['reply_count']];
    }

    /** Marks a reply as the thread's answer; marking the current answer again clears it. */
    public function markAnswer(User $user, int $offeringId, int $threadId, int $replyId): array
    {
        $thread = $this->threadIn($offeringId, $threadId);

        if (!$thread || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Discussion not found.');
        }

        if ((int) $thread['author_id'] !== (int) $user->id && !$this->canModerate($user, $offeringId)) {
            return $this->deny('Only the discussion\'s author or the instructor can mark an answer.');
        }

        $reply = $this->replyModel->find($replyId);

        if (!$reply || (int) $reply['thread_id'] !== $threadId) {
            return $this->deny('Reply not found.');
        }

        $answerId = (int) $thread['answer_reply_id'] === $replyId ? null : $replyId;

        $this->threadModel->update($threadId, ['answer_reply_id' => $answerId]);

        return ['success' => true, 'answer_reply_id' => $answerId];
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    /** React to the thread itself ($replyId null) or to one of its replies. */
    public function react(User $user, int $offeringId, int $threadId, ?int $replyId, string $emoji): array
    {
        if (!$this->threadIn($offeringId, $threadId) || !$this->canViewOffering($user, $offeringId)) {
            return $this->deny('Discussion not found.');
        }

        if ($replyId !== null) {

            $reply = $this->replyModel->find($replyId);

            if (!$reply || (int) $reply['thread_id'] !== $threadId) {
                return $this->deny('Reply not found.');
            }

        }

        if (trim($emoji) === '' || mb_strlen($emoji) > 32) {
            return $this->deny('Pick a reaction.');
        }

        $result = $this->reactionModel->toggle($threadId, $replyId, (int) $user->id, $emoji);

        $counts = $this->reactionModel->retrieveCounts($threadId);

        return $result + [
            'success' => true,
            'reaction_counts' => $replyId === null ? $counts['thread'] : ($counts['replies'][$replyId] ?? []),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    /** One file at a time — the composer uploads each as it's added, then posts the collected ids. */
    public function uploadAttachment(User $user, int $offeringId, UploadedFile $file): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            return $this->deny();
        }

        if (!$file->isValid()) {
            return $this->deny('That file could not be uploaded.');
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            return $this->deny('Files must be 25MB or smaller.');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_ATTACHMENT_MIMES, true)) {
            return $this->deny('That file type is not allowed. Use images, PDFs, or office documents.');
        }

        $stored = $this->storageService->upload($file, 'discussions');

        $fileId = $this->fileModel->insert([
            'owner_user_id' => $user->id,
            'original_filename' => $stored['original_name'],
            'stored_filename' => $stored['filename'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['size'],
            'storage_key' => $stored['storage_key'],
            'status' => 'active',
        ], true);

        return [
            'success' => true,
            'file_id' => $fileId,
            'original_filename' => $stored['original_name'],
            'file_size' => $stored['size'],
            'mime_type' => $stored['mime_type'],
            'url' => $this->storageService->url((int) $fileId),
        ];
    }

    /** Sub-second timestamp for the unread comparison (see the migration). */
    private function microNow(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s.u');
    }

    private function db()
    {
        return db_connect();
    }
}

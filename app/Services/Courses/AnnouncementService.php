<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Courses\AnnouncementModel;
use App\Models\Courses\AnnouncementReplyModel;
use App\Models\Courses\AnnouncementReactionModel;
use App\Models\Courses\AnnouncementAttachmentModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Files\FileModel;
use App\Services\Storage\StorageService;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Teams-channel-style announcements for one course offering: instructor
 * posts (subject + rich-text message + attachments), a flat reply thread
 * either role can post to, and one reaction per viewer per post.
 */
class AnnouncementService
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
    private const MAX_ATTACHMENTS = 10;
    private const PAGE_SIZE = 10;
    private const PINNED_SIDEBAR_LIMIT = 20;

    protected AnnouncementModel $announcementModel;
    protected AnnouncementReplyModel $replyModel;
    protected AnnouncementReactionModel $reactionModel;
    protected AnnouncementAttachmentModel $attachmentModel;
    protected EnrollmentModel $enrollmentModel;
    protected InstructorAssignmentModel $instructorAssignmentModel;
    protected FileModel $fileModel;
    protected StorageService $storageService;

    public function __construct()
    {
        $this->announcementModel = new AnnouncementModel();
        $this->replyModel = new AnnouncementReplyModel();
        $this->reactionModel = new AnnouncementReactionModel();
        $this->attachmentModel = new AnnouncementAttachmentModel();
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
        if ($user->inGroup('instructor')) {
            return (bool) $this->instructorAssignmentModel->retrieveInstructorCourseOffering($user->id, $offeringId);
        }

        if ($user->inGroup('chairperson')) {
            return (new ChairpersonAccessService())->canAccess($user, $offeringId);
        }

        return false;
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

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{search?: string, sort?: string, pinned?: bool, page?: int, focus?: int} $filters
     *        focus: a post to land on (e.g. from a Calendar link) — loads as many
     *        pages as it takes to include it, so it's on screen without paging down.
     */
    public function show(User $user, int $offeringId, array $filters = []): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            return ['success' => false, 'message' => 'Not authorized.'];
        }

        $isInstructor = $this->isInstructorHere($user, $offeringId);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $queryFilters = $this->normalizeFilters($filters);

        $pages = 1;
        $focusId = (int) ($filters['focus'] ?? 0);

        if ($focusId > 0 && $page === 1) {

            $position = array_search(
                $focusId,
                array_map('intval', array_column($this->announcementModel->retrieveForOffering($offeringId, $queryFilters), 'id')),
                true
            );

            if ($position !== false) {
                $pages = (int) ceil(($position + 1) / self::PAGE_SIZE);
            }

        }

        $window = self::PAGE_SIZE * $pages;

        $rows = $this->announcementModel->retrieveForOffering($offeringId, $queryFilters + [
            'limit' => $window + 1,
            'offset' => self::PAGE_SIZE * ($page - 1),
        ]);

        $hasMore = count($rows) > $window;
        $announcements = $this->hydrate(array_slice($rows, 0, $window), $user, $isInstructor);
        $page += $pages - 1;

        $pinned = $this->announcementModel->retrieveForOffering($offeringId, [
            'pinned_only' => true,
            'limit' => self::PINNED_SIDEBAR_LIMIT,
        ]);

        return [
            'html' => view('pages/courses/features/announcements/index', [
                'user' => $user,
                'isInstructor' => $isInstructor,
                'announcements' => $announcements,
                'pinned' => $pinned,
                'filters' => $queryFilters,
                'hasMore' => $hasMore,
                'nextPage' => $page + 1,
            ]),
        ];
    }

    /**
     * Returns a card-only HTML fragment for infinite scroll — not the full
     * page (sidebar/composer would be redundant work on every scroll tick).
     *
     * @param array{search?: string, sort?: string, pinned?: bool, page?: int} $filters
     */
    public function loadMore(User $user, int $offeringId, array $filters = []): array
    {
        if (!$this->canViewOffering($user, $offeringId)) {
            return ['success' => false, 'message' => 'Not authorized.'];
        }

        $isInstructor = $this->isInstructorHere($user, $offeringId);

        $page = max(1, (int) ($filters['page'] ?? 2));
        $queryFilters = $this->normalizeFilters($filters);

        $rows = $this->announcementModel->retrieveForOffering($offeringId, $queryFilters + [
            'limit' => self::PAGE_SIZE + 1,
            'offset' => self::PAGE_SIZE * ($page - 1),
        ]);

        $hasMore = count($rows) > self::PAGE_SIZE;
        $announcements = $this->hydrate(array_slice($rows, 0, self::PAGE_SIZE), $user, $isInstructor);

        $html = '';
        foreach ($announcements as $announcement) {
            $html .= view('pages/courses/features/announcements/partials/announcement-card', [
                'announcement' => $announcement,
                'userId' => (int) $user->id,
                'isInstructor' => $isInstructor,
            ]);
        }

        return [
            'success' => true,
            'html' => $html,
            'has_more' => $hasMore,
            'next_page' => $page + 1,
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort' => ($filters['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest',
            'pinned_only' => !empty($filters['pinned']),
        ];
    }

    /** Attaches replies, reaction state, attachments, and per-row permission flags. */
    private function hydrate(array $announcements, User $user, bool $isInstructor): array
    {
        $ids = array_column($announcements, 'id');

        $repliesByAnnouncement = $this->replyModel->retrieveForAnnouncements($ids);
        $reactionCounts = $this->reactionModel->retrieveCounts($ids);
        $ownReactions = $this->reactionModel->retrieveOwnReactions($ids, (int) $user->id);
        $attachmentsByAnnouncement = $this->attachmentModel->retrieveForAnnouncements($ids);

        foreach ($announcements as &$announcement) {

            $id = (int) $announcement['id'];
            $isOwner = (int) $announcement['author_id'] === (int) $user->id;

            $announcement['replies'] = $repliesByAnnouncement[$id] ?? [];
            $announcement['reaction_counts'] = $reactionCounts[$id] ?? [];
            $announcement['own_reaction'] = $ownReactions[$id] ?? null;
            $announcement['attachments'] = array_map(
                fn (array $file) => $file + ['url' => $this->storageService->url((int) $file['id'])],
                $attachmentsByAnnouncement[$id] ?? []
            );

            // Computed server-side so a student never receives markup for
            // actions they can't take, not just a hidden button client-side.
            $announcement['can_edit'] = $isInstructor && $isOwner;
            $announcement['can_delete'] = $isInstructor && $isOwner;
            $announcement['can_pin'] = $isInstructor;

        }
        unset($announcement);

        return $announcements;
    }

    /*
    |--------------------------------------------------------------------------
    | Create / Update / Delete
    |--------------------------------------------------------------------------
    */

    public function create(User $user, int $offeringId, array $data): array
    {
        if (!$this->isInstructorHere($user, $offeringId)) {
            return ['success' => false, 'message' => 'Only the instructor can post announcements.'];
        }

        $subject = trim((string) ($data['subject'] ?? ''));

        if ($subject === '') {
            return ['success' => false, 'message' => 'Add a subject before posting.'];
        }

        // `created_at` is only given when posting for a chosen day (the calendar).
        $this->announcementModel->insert(array_filter([
            'offering_id' => $offeringId,
            'author_id' => $user->id,
            'subject' => $subject,
            'message' => (string) ($data['message'] ?? ''),
            'created_at' => $data['created_at'] ?? null,
        ], static fn ($value) => $value !== null));

        $announcementId = (int) $this->announcementModel->getInsertID();

        $this->attachFiles($announcementId, (int) $user->id, $data['attachment_file_ids'] ?? []);

        return ['success' => true, 'message' => 'Announcement posted.', 'announcement_id' => $announcementId];
    }

    public function update(User $user, int $offeringId, int $announcementId, array $data): array
    {
        $announcement = $this->announcementModel->find($announcementId);

        if (!$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }

        if (!$this->isInstructorHere($user, $offeringId) || (int) $announcement['author_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'You can only edit your own announcements.'];
        }

        $subject = trim((string) ($data['subject'] ?? ''));

        if ($subject === '') {
            return ['success' => false, 'message' => 'Add a subject before posting.'];
        }

        $this->announcementModel->update($announcementId, [
            'subject' => $subject,
            'message' => (string) ($data['message'] ?? ''),
        ]);

        if (array_key_exists('attachment_file_ids', $data)) {

            $existing = $this->attachmentModel->where('announcement_id', $announcementId)->findColumn('file_id') ?? [];

            $this->attachmentModel->where('announcement_id', $announcementId)->delete();
            $this->attachFiles($announcementId, (int) $user->id, $data['attachment_file_ids']);

            $this->storageService->purgeFiles($existing);

        }

        return ['success' => true, 'message' => 'Announcement updated.'];
    }

    public function delete(User $user, int $offeringId, int $announcementId): array
    {
        $announcement = $this->announcementModel->find($announcementId);

        if (!$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }

        if (!$this->isInstructorHere($user, $offeringId) || (int) $announcement['author_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'You can only delete your own announcements.'];
        }

        $fileIds = $this->attachmentModel->where('announcement_id', $announcementId)->findColumn('file_id') ?? [];

        $this->announcementModel->delete($announcementId);

        $this->storageService->purgeFiles($fileIds);

        return ['success' => true, 'message' => 'Announcement deleted.'];
    }

    public function togglePin(User $user, int $offeringId, int $announcementId): array
    {
        $announcement = $this->announcementModel->find($announcementId);

        if (!$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }

        if (!$this->isInstructorHere($user, $offeringId)) {
            return ['success' => false, 'message' => 'Only the instructor can pin announcements.'];
        }

        $this->announcementModel->update($announcementId, ['is_pinned' => !$announcement['is_pinned']]);

        return ['success' => true, 'is_pinned' => !$announcement['is_pinned']];
    }

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    /** Only files the author uploaded themselves, at most MAX_ATTACHMENTS, so an id can't pull in (or later purge) someone else's file. */
    private function attachFiles(int $announcementId, int $authorId, array $fileIds): void
    {
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));

        $owned = $fileIds === [] ? [] : array_map('intval', $this->fileModel
            ->where('owner_user_id', $authorId)
            ->whereIn('id', $fileIds)
            ->findColumn('id') ?? []);

        $fileIds = array_slice(array_values(array_intersect($fileIds, $owned)), 0, self::MAX_ATTACHMENTS);

        $order = 1;

        foreach ($fileIds as $fileId) {

            $this->attachmentModel->insert([
                'announcement_id' => $announcementId,
                'file_id' => (int) $fileId,
                'order_index' => $order++,
            ]);

        }
    }

    /** One file at a time — the composer uploads each attachment as it's added, then posts the collected ids. */
    public function uploadAttachment(User $user, int $offeringId, UploadedFile $file): array
    {
        if (!$this->isInstructorHere($user, $offeringId)) {
            return ['success' => false, 'message' => 'Only the instructor can attach files.'];
        }

        if (!$file->isValid()) {
            return ['success' => false, 'message' => 'That file could not be uploaded.'];
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            return ['success' => false, 'message' => 'Files must be 25MB or smaller.'];
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_ATTACHMENT_MIMES, true)) {
            return ['success' => false, 'message' => 'That file type is not allowed. Use images, PDFs, or office documents.'];
        }

        $stored = $this->storageService->upload($file, 'announcements');

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

    /*
    |--------------------------------------------------------------------------
    | Replies
    |--------------------------------------------------------------------------
    */

    public function reply(User $user, int $offeringId, int $announcementId, string $message): array
    {
        $announcement = $this->announcementModel->find($announcementId);

        if (!$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }

        if (!$this->canViewOffering($user, $offeringId)) {
            return ['success' => false, 'message' => 'Not authorized.'];
        }

        $message = trim($message);

        if ($message === '') {
            return ['success' => false, 'message' => 'Write a reply first.'];
        }

        $this->replyModel->insert([
            'announcement_id' => $announcementId,
            'author_id' => $user->id,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $replyId = (int) $this->replyModel->getInsertID();

        // Rendered HTML for just the new reply, not the whole thread — the
        // caller appends it directly for an instant, no-reload reply.
        return [
            'success' => true,
            'reply_id' => $replyId,
            'html' => view('pages/courses/features/announcements/partials/reply', [
                'reply' => $this->replyModel->retrieveOne($replyId),
                'userId' => (int) $user->id,
            ]),
        ];
    }

    public function deleteReply(User $user, int $offeringId, int $replyId): array
    {
        $reply = $this->replyModel->find($replyId);

        $announcement = $reply ? $this->announcementModel->find($reply['announcement_id']) : null;

        if (!$reply || !$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Reply not found.'];
        }

        if ((int) $reply['author_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'You can only delete your own reply.'];
        }

        $this->replyModel->delete($replyId);

        return ['success' => true];
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    public function react(User $user, int $offeringId, int $announcementId, string $emoji): array
    {
        $announcement = $this->announcementModel->find($announcementId);

        if (!$announcement || (int) $announcement['offering_id'] !== $offeringId) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }

        if (!$this->canViewOffering($user, $offeringId)) {
            return ['success' => false, 'message' => 'Not authorized.'];
        }

        $emoji = trim($emoji);

        if ($emoji === '' || mb_strlen($emoji) > 32) {
            return ['success' => false, 'message' => 'Pick a reaction.'];
        }

        $result = $this->reactionModel->toggle($announcementId, (int) $user->id, $emoji);

        return $result + [
            'success' => true,
            'reaction_counts' => $this->reactionModel->retrieveCounts([$announcementId])[$announcementId] ?? [],
        ];
    }
}

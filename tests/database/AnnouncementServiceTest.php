<?php

declare(strict_types=1);

use App\Services\Courses\AnnouncementService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Course announcements: who may post, edit, delete and pin, who may read,
 * reply and react, how attachments are accepted and cleaned up, and what the
 * feed (search, sort, pinned, paging) hands the page.
 *
 * @internal
 */
final class AnnouncementServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AnnouncementService $service;

    private int $offering;

    private User $instructor;

    private User $coTeacher;

    private User $student;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AnnouncementService();
        $this->offering = CourseFactory::offering();

        $this->instructor = CourseFactory::user('instructor');
        $this->coTeacher = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
        CourseFactory::assignInstructor($this->offering, $this->coTeacher);
        CourseFactory::enroll($this->offering, $this->student);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** @return int announcement id */
    private function post(string $subject = 'Welcome', array $extra = [], ?User $by = null, ?int $offering = null): int
    {
        $result = $this->service->create($by ?? $this->instructor, $offering ?? $this->offering, $extra + ['subject' => $subject, 'message' => '{}']);

        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result['announcement_id'];
    }

    private function row(int $id): ?array
    {
        return db_connect()->table('announcements')->where('id', $id)->get()->getRowArray();
    }

    private function attachedFiles(int $announcementId): array
    {
        return array_map('intval', array_column(
            db_connect()->table('announcement_attachments')->where('announcement_id', $announcementId)->orderBy('order_index')->get()->getResultArray(),
            'file_id'
        ));
    }

    private function fileRowExists(int $fileId): bool
    {
        return db_connect()->table('files')->where('id', $fileId)->countAllResults() === 1;
    }

    /** @return list<int> ids in feed order */
    private function feedIds(array $announcements): array
    {
        return array_map('intval', array_column($announcements, 'id'));
    }

    /** Feed rows the page would render, read straight from the model for ordering checks. */
    private function feed(array $options = []): array
    {
        return (new \App\Models\Courses\AnnouncementModel())->retrieveForOffering($this->offering, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function testTheInstructorPostsAnAnnouncement(): void
    {
        $result = $this->service->create($this->instructor, $this->offering, ['subject' => '  Quiz Friday  ', 'message' => '{"x":1}']);

        $this->assertTrue($result['success']);

        $row = $this->row($result['announcement_id']);
        $this->assertSame('Quiz Friday', $row['subject']);
        $this->assertSame('{"x":1}', $row['message']);
        $this->assertSame($this->offering, (int) $row['offering_id']);
        $this->assertSame((int) $this->instructor->id, (int) $row['author_id']);
        $this->assertSame(0, (int) $row['is_pinned']);
    }

    public function testStudentsAndOtherCoursesInstructorsCannotPost(): void
    {
        $otherTeacher = CourseFactory::user('instructor');

        foreach ([$this->student, $this->outsider, $otherTeacher] as $who) {
            $result = $this->service->create($who, $this->offering, ['subject' => 'Hi']);
            $this->assertFalse($result['success']);
        }

        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    public function testASubjectIsRequired(): void
    {
        $this->assertFalse($this->service->create($this->instructor, $this->offering, ['subject' => '   '])['success']);
        $this->assertFalse($this->service->create($this->instructor, $this->offering, [])['success']);
        $this->assertSame(0, db_connect()->table('announcements')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Update / delete / pin
    |--------------------------------------------------------------------------
    */

    public function testTheAuthorEditsTheirAnnouncement(): void
    {
        $id = $this->post('Old');

        $result = $this->service->update($this->instructor, $this->offering, $id, ['subject' => 'New', 'message' => '{"v":2}']);

        $this->assertTrue($result['success']);
        $this->assertSame('New', $this->row($id)['subject']);
        $this->assertSame('{"v":2}', $this->row($id)['message']);
    }

    public function testANewSubjectIsRequiredWhenEditing(): void
    {
        $id = $this->post('Keep');

        $this->assertFalse($this->service->update($this->instructor, $this->offering, $id, ['subject' => ' '])['success']);
        $this->assertSame('Keep', $this->row($id)['subject']);
    }

    public function testOnlyTheAuthorCanEditOrDelete(): void
    {
        $id = $this->post('Mine');

        foreach ([$this->coTeacher, $this->student, $this->outsider] as $who) {
            $this->assertFalse($this->service->update($who, $this->offering, $id, ['subject' => 'Hacked'])['success']);
            $this->assertFalse($this->service->delete($who, $this->offering, $id)['success']);
        }

        $this->assertSame('Mine', $this->row($id)['subject']);
    }

    public function testAnAnnouncementCannotBeTouchedThroughAnotherOffering(): void
    {
        $id = $this->post('Mine');
        $other = CourseFactory::offering('B');
        CourseFactory::assignInstructor($other, $this->instructor);

        $this->assertFalse($this->service->update($this->instructor, $other, $id, ['subject' => 'X'])['success']);
        $this->assertFalse($this->service->delete($this->instructor, $other, $id)['success']);
        $this->assertFalse($this->service->togglePin($this->instructor, $other, $id)['success']);
        $this->assertFalse($this->service->reply($this->instructor, $other, $id, 'hi')['success']);
        $this->assertFalse($this->service->react($this->instructor, $other, $id, 'like')['success']);
        $this->assertSame('Mine', $this->row($id)['subject']);
    }

    public function testMissingAnnouncementsAreReportedNotFound(): void
    {
        $this->assertFalse($this->service->update($this->instructor, $this->offering, 999999, ['subject' => 'X'])['success']);
        $this->assertFalse($this->service->delete($this->instructor, $this->offering, 999999)['success']);
        $this->assertFalse($this->service->togglePin($this->instructor, $this->offering, 999999)['success']);
    }

    public function testDeletingRemovesTheRepliesReactionsAndAttachmentFiles(): void
    {
        $file = CourseFactory::file($this->instructor);
        $id = $this->post('Gone', ['attachment_file_ids' => [$file['id']]]);
        $this->service->reply($this->student, $this->offering, $id, 'reply');
        $this->service->react($this->student, $this->offering, $id, 'like');

        $this->assertTrue($this->service->delete($this->instructor, $this->offering, $id)['success']);

        $this->assertNull($this->row($id));
        $this->assertSame(0, db_connect()->table('announcement_replies')->countAllResults());
        $this->assertSame(0, db_connect()->table('announcement_reactions')->countAllResults());
        $this->assertFalse($this->fileRowExists($file['id']));
        $this->assertFileDoesNotExist($file['path']);
    }

    public function testAnyAssignedInstructorCanTogglePinAndStudentsCannot(): void
    {
        $id = $this->post('Pin me');

        $this->assertTrue($this->service->togglePin($this->coTeacher, $this->offering, $id)['is_pinned']);
        $this->assertSame(1, (int) $this->row($id)['is_pinned']);

        $this->assertFalse($this->service->togglePin($this->instructor, $this->offering, $id)['is_pinned']);
        $this->assertSame(0, (int) $this->row($id)['is_pinned']);

        $this->assertFalse($this->service->togglePin($this->student, $this->offering, $id)['success']);
        $this->assertSame(0, (int) $this->row($id)['is_pinned']);
    }

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    public function testAttachmentsAreStoredInOrderAndBlankIdsSkipped(): void
    {
        $a = CourseFactory::file($this->instructor);
        $b = CourseFactory::file($this->instructor);

        $id = $this->post('Files', ['attachment_file_ids' => [$a['id'], 0, '', $b['id']]]);

        $this->assertSame([$a['id'], $b['id']], $this->attachedFiles($id));
    }

    public function testEditingReplacesAttachmentsAndRemovesDroppedFiles(): void
    {
        $keep = CourseFactory::file($this->instructor);
        $drop = CourseFactory::file($this->instructor);
        $add = CourseFactory::file($this->instructor);
        $id = $this->post('Files', ['attachment_file_ids' => [$keep['id'], $drop['id']]]);

        $this->service->update($this->instructor, $this->offering, $id, ['subject' => 'Files', 'attachment_file_ids' => [$keep['id'], $add['id']]]);

        $this->assertSame([$keep['id'], $add['id']], $this->attachedFiles($id));
        $this->assertTrue($this->fileRowExists($keep['id']), 'a file that stays attached is not purged');
        $this->assertFileExists($keep['path']);
        $this->assertFalse($this->fileRowExists($drop['id']));
        $this->assertFileDoesNotExist($drop['path']);
    }

    public function testEditingWithoutAnAttachmentListLeavesAttachmentsAlone(): void
    {
        $file = CourseFactory::file($this->instructor);
        $id = $this->post('Files', ['attachment_file_ids' => [$file['id']]]);

        $this->service->update($this->instructor, $this->offering, $id, ['subject' => 'Renamed']);

        $this->assertSame([$file['id']], $this->attachedFiles($id));
    }

    public function testAnotherUsersFileCannotBeAttached(): void
    {
        $theirs = CourseFactory::file($this->student);
        $mine = CourseFactory::file($this->instructor);

        $id = $this->post('Sneaky', ['attachment_file_ids' => [$theirs['id'], $mine['id']]]);

        $this->assertSame([$mine['id']], $this->attachedFiles($id));

        $this->service->update($this->instructor, $this->offering, $id, ['subject' => 'Sneaky', 'attachment_file_ids' => [$theirs['id']]]);

        $this->assertSame([], $this->attachedFiles($id));
        $this->assertTrue($this->fileRowExists($theirs['id']), "someone else's file survives");
    }

    public function testDeletingNeverDestroysAFileThatIsNotTheAuthors(): void
    {
        $theirs = CourseFactory::file($this->student);
        $id = $this->post('Sneaky', ['attachment_file_ids' => [$theirs['id']]]);

        $this->service->delete($this->instructor, $this->offering, $id);

        $this->assertTrue($this->fileRowExists($theirs['id']));
        $this->assertFileExists($theirs['path']);
    }

    public function testAnAnnouncementKeepsAtMostTenAttachments(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = CourseFactory::file($this->instructor)['id'];
        }

        $id = $this->post('Many', ['attachment_file_ids' => $ids]);

        $this->assertSame(array_slice($ids, 0, 10), $this->attachedFiles($id));
    }

    private function upload(User $who, string $mime = 'application/pdf', int $size = 100, bool $valid = true): array
    {
        $file = $this->getMockBuilder(\CodeIgniter\HTTP\Files\UploadedFile::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isValid', 'getSize', 'getMimeType'])
            ->getMock();
        $file->method('isValid')->willReturn($valid);
        $file->method('getSize')->willReturn($size);
        $file->method('getMimeType')->willReturn($mime);

        return $this->service->uploadAttachment($who, $this->offering, $file);
    }

    public function testUploadsAreRefusedForStudentsAndBadFiles(): void
    {
        $this->assertFalse($this->upload($this->student)['success']);
        $this->assertFalse($this->upload($this->instructor, 'application/pdf', 100, false)['success']);
        $this->assertStringContainsString('25MB', $this->upload($this->instructor, 'application/pdf', 25 * 1024 * 1024 + 1)['message']);
        $this->assertStringContainsString('not allowed', $this->upload($this->instructor, 'application/x-php')['message']);
        $this->assertStringContainsString('not allowed', $this->upload($this->instructor, 'text/html')['message']);
        $this->assertSame(0, db_connect()->table('files')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Replies
    |--------------------------------------------------------------------------
    */

    public function testEnrolledStudentsAndInstructorsCanReply(): void
    {
        $id = $this->post();

        $fromStudent = $this->service->reply($this->student, $this->offering, $id, '  Thanks!  ');
        $fromTeacher = $this->service->reply($this->coTeacher, $this->offering, $id, 'Welcome');

        $this->assertTrue($fromStudent['success']);
        $this->assertTrue($fromTeacher['success']);
        $this->assertStringContainsString('Thanks!', $fromStudent['html']);

        $messages = array_column(db_connect()->table('announcement_replies')->orderBy('id')->get()->getResultArray(), 'message');
        $this->assertSame(['Thanks!', 'Welcome'], $messages);
    }

    public function testOutsidersAndBlankRepliesAreRefused(): void
    {
        $id = $this->post();
        $otherTeacher = CourseFactory::user('instructor');

        $this->assertFalse($this->service->reply($this->outsider, $this->offering, $id, 'hi')['success']);
        $this->assertFalse($this->service->reply($otherTeacher, $this->offering, $id, 'hi')['success']);
        $this->assertFalse($this->service->reply($this->student, $this->offering, $id, '   ')['success']);
        $this->assertSame(0, db_connect()->table('announcement_replies')->countAllResults());
    }

    public function testAStudentWhoDroppedTheCourseCannotReplyOrReact(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');
        $id = $this->post();

        $this->assertFalse($this->service->reply($dropped, $this->offering, $id, 'hi')['success']);
        $this->assertFalse($this->service->react($dropped, $this->offering, $id, 'like')['success']);
    }

    public function testOnlyTheAuthorCanDeleteAReply(): void
    {
        $id = $this->post();
        $replyId = $this->service->reply($this->student, $this->offering, $id, 'mine')['reply_id'];

        $this->assertFalse($this->service->deleteReply($this->instructor, $this->offering, $replyId)['success']);
        $this->assertFalse($this->service->deleteReply($this->outsider, $this->offering, $replyId)['success']);
        $this->assertSame(1, db_connect()->table('announcement_replies')->countAllResults());

        $this->assertTrue($this->service->deleteReply($this->student, $this->offering, $replyId)['success']);
        $this->assertSame(0, db_connect()->table('announcement_replies')->countAllResults());
        $this->assertFalse($this->service->deleteReply($this->student, $this->offering, $replyId)['success']);
    }

    public function testAReplyCannotBeDeletedThroughAnotherOffering(): void
    {
        $id = $this->post();
        $replyId = $this->service->reply($this->student, $this->offering, $id, 'mine')['reply_id'];
        $other = CourseFactory::offering('B');

        $this->assertFalse($this->service->deleteReply($this->student, $other, $replyId)['success']);
        $this->assertSame(1, db_connect()->table('announcement_replies')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    public function testReactionsToggleAddChangeAndRemove(): void
    {
        $id = $this->post();

        $added = $this->service->react($this->student, $this->offering, $id, 'like');
        $this->assertSame('added', $added['action']);
        $this->assertSame(['like' => 1], $added['reaction_counts']);

        $changed = $this->service->react($this->student, $this->offering, $id, 'heart');
        $this->assertSame('changed', $changed['action']);
        $this->assertSame(['heart' => 1], $changed['reaction_counts']);

        $removed = $this->service->react($this->student, $this->offering, $id, 'heart');
        $this->assertSame('removed', $removed['action']);
        $this->assertSame([], $removed['reaction_counts']);
    }

    public function testEveryoneInTheCourseCanReactAndCountsAdd(): void
    {
        $id = $this->post();

        $this->service->react($this->student, $this->offering, $id, 'like');
        $result = $this->service->react($this->instructor, $this->offering, $id, 'like');

        $this->assertSame(['like' => 2], $result['reaction_counts']);
        $this->assertFalse($this->service->react($this->outsider, $this->offering, $id, 'like')['success']);
    }

    public function testNonsenseReactionsAreRefused(): void
    {
        $id = $this->post();

        $this->assertFalse($this->service->react($this->student, $this->offering, $id, '')['success']);
        $this->assertFalse($this->service->react($this->student, $this->offering, $id, str_repeat('x', 40))['success']);
        $this->assertSame(0, db_connect()->table('announcement_reactions')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    */

    public function testPinnedPostsLeadThenNewestFirst(): void
    {
        $old = $this->post('Old');
        $mid = $this->post('Mid');
        $new = $this->post('New');
        $this->service->togglePin($this->instructor, $this->offering, $mid);

        $this->assertSame([$mid, $new, $old], $this->feedIds($this->feed()));
        $this->assertSame([$mid, $old, $new], $this->feedIds($this->feed(['sort' => 'oldest'])));
        $this->assertSame([$mid], $this->feedIds($this->feed(['pinned_only' => true])));
    }

    public function testSearchMatchesSubjectOrMessage(): void
    {
        $a = $this->post('Midterm schedule', ['message' => '{"t":"room 4"}']);
        $b = $this->post('Other', ['message' => '{"t":"bring a midterm booklet"}']);
        $this->post('Unrelated');

        $this->assertEqualsCanonicalizing([$a, $b], $this->feedIds($this->feed(['search' => ' midterm '])));
    }

    public function testTheFeedOnlyHoldsThisOfferingsPosts(): void
    {
        $mine = $this->post('Mine');
        $other = CourseFactory::offering('B');
        CourseFactory::assignInstructor($other, $this->instructor);
        $this->post('Elsewhere', [], null, $other);

        $this->assertSame([$mine], $this->feedIds($this->feed()));
    }

    public function testShowRendersThePageForMembersAndRefusesOutsiders(): void
    {
        $this->post('Visible subject');

        $asStudent = $this->service->show($this->student, $this->offering);
        $asTeacher = $this->service->show($this->instructor, $this->offering);

        $this->assertStringContainsString('Visible subject', $asStudent['html']);
        $this->assertStringContainsString('Visible subject', $asTeacher['html']);

        $denied = $this->service->show($this->outsider, $this->offering);
        $this->assertArrayNotHasKey('html', $denied);
        $this->assertFalse($denied['success']);
    }

    public function testLoadMoreRefusesOutsidersAndPagesTenAtATime(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->post("Post {$i}");
        }

        $second = $this->service->loadMore($this->student, $this->offering, ['page' => 2]);

        $this->assertTrue($second['success']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(3, $second['next_page']);
        $this->assertStringContainsString('Post 2', $second['html']);
        $this->assertStringContainsString('Post 1', $second['html']);
        $this->assertStringNotContainsString('Post 12', $second['html']);

        $this->assertFalse($this->service->loadMore($this->outsider, $this->offering, ['page' => 2])['success']);
    }

    public function testFocusingAnOldPostLoadsEnoughPagesToShowIt(): void
    {
        $first = $this->post('Very first post');
        for ($i = 1; $i <= 14; $i++) {
            $this->post("Later {$i}");
        }

        $without = $this->service->show($this->student, $this->offering);
        $with = $this->service->show($this->student, $this->offering, ['focus' => $first]);

        $this->assertStringNotContainsString('Very first post', $without['html']);
        $this->assertStringContainsString('Very first post', $with['html']);
    }

    public function testPostersSeeEditDeleteAndPinControlsOnlyOnTheirOwnPosts(): void
    {
        $mine = $this->post('By instructor');
        $theirs = $this->post('By co-teacher', [], $this->coTeacher);

        $rows = $this->hydrated($this->instructor);

        $this->assertTrue($rows[$mine]['can_edit']);
        $this->assertTrue($rows[$mine]['can_delete']);
        $this->assertFalse($rows[$theirs]['can_edit']);
        $this->assertFalse($rows[$theirs]['can_delete']);
        $this->assertTrue($rows[$theirs]['can_pin']);

        $asStudent = $this->hydrated($this->student);
        $this->assertFalse($asStudent[$mine]['can_edit']);
        $this->assertFalse($asStudent[$mine]['can_pin']);
    }

    /** Calls the private hydrate() the way show() does. @return array<int, array> keyed by id */
    private function hydrated(User $viewer): array
    {
        $method = new ReflectionMethod($this->service, 'hydrate');
        $method->setAccessible(true);

        $rows = $method->invoke($this->service, $this->feed(), $viewer, $viewer->inGroup('instructor'));

        return array_column($rows, null, 'id');
    }
}

<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationPresenter;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\MessagingScenario;
use Tests\Support\PeopleFactory;

/**
 * What the Messages list shows for each conversation: a name, a one-line
 * preview and whether the person can post. Never ciphertext.
 *
 * @internal
 */
final class ConversationPresenterTest extends DatabaseTestCase
{
    use MessagingScenario;

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        $this->releaseScenario();

        parent::tearDown();
    }

    private function say(string $text): void
    {
        $result = (new MessageService())->send($this->instructor, $this->conversation, $text, $this->uuid());
        $this->assertTrue($result['ok']);
    }

    /** @return array<string, mixed> the student's only list row, presented */
    private function row(): array
    {
        $rows = (new ConversationPresenter())->present($this->student, (new ConversationService())->listFor($this->student));
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    public function testADirectRowIsNamedAfterTheOtherPersonWithInitials(): void
    {
        $row = $this->row();

        $this->assertSame('Ins Tructor', $row['name']);
        $this->assertSame('IT', $row['initials']);
        $this->assertNull($row['subtitle']);
    }

    public function testThePreviewIsTheLastMessageDecrypted(): void
    {
        $this->say('first');
        $this->say('See you at the library');

        $this->assertSame('See you at the library', $this->row()['preview']);
    }

    public function testALongPreviewIsCutAtEightyCharacters(): void
    {
        $this->say(str_repeat('é', 200));

        $preview = $this->row()['preview'];

        $this->assertSame(81, mb_strlen($preview));
        $this->assertStringEndsWith('…', $preview);
    }

    public function testARemovedMessageShowsAsRemovedAndATamperedOneAsUnavailable(): void
    {
        $this->say('secret words');
        $id = (int) db_connect()->table('messages')->selectMax('id')->get()->getRow('id');

        db_connect()->table('messages')->where('id', $id)->update(['removed_at' => date('Y-m-d H:i:s')]);
        $this->assertSame('Message removed', $this->row()['preview']);

        db_connect()->table('messages')->where('id', $id)->update(['removed_at' => null, 'body_enc' => 'not-a-ciphertext']);
        $this->assertSame('Message unavailable', $this->row()['preview']);
    }

    public function testAConversationWithNoMessagesHasNoPreview(): void
    {
        $this->assertNull($this->row()['preview']);
    }

    public function testCanPostIsFalseInAReadOnlyInstitution(): void
    {
        $this->assertTrue($this->row()['can_post']);

        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertFalse($this->row()['can_post']);
    }

    public function testAParentThreadCarriesTheChildsNameAsSubtitle(): void
    {
        $parent = PeopleFactory::person('parent', 'Pat', 'Parent', null, $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $this->student->id, 'created_at' => date('Y-m-d H:i:s')]);
        $started = (new ConversationService())->startDirect($parent, (int) $this->instructor->id, (int) $this->student->id);
        $this->assertNotNull($started);

        $rows = (new ConversationPresenter())->present($parent, (new ConversationService())->listFor($parent));

        $this->assertSame('Ins Tructor', $rows[0]['name']);
        $this->assertSame('Stu Dent', $rows[0]['subtitle']);
    }

    public function testACourseTitleEndingInAThaiVowelSurvivesAndTheListStillEncodes(): void
    {
        $title    = 'วิทยาศาสตร์ื';
        $offering = DeanFactory::offering($this->program, $this->term, 'TH1', 'A', 'active', $title);
        CourseFactory::enroll($offering, $this->student);
        $db = db_connect();
        $db->table('conversations')->insert(['institution_id' => $this->institution, 'type' => 'course', 'offering_id' => $offering, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('conversation_participants')->insert(['conversation_id' => $db->insertID(), 'user_id' => $this->student->id, 'joined_at' => date('Y-m-d H:i:s')]);

        $rows = (new ConversationPresenter())->present($this->student, (new ConversationService())->listFor($this->student));

        $this->assertNotFalse(json_encode($rows));
        $this->assertSame('TH1 · ' . $title, $rows[0]['name']);
    }

    public function testNoCiphertextLeavesThePresenter(): void
    {
        $this->say('plain words here');
        $stored = (string) db_connect()->table('messages')->get()->getRow('body_enc');

        $json = json_encode($this->row());

        $this->assertStringNotContainsString('body_enc', $json);
        $this->assertStringNotContainsString($stored, $json);
    }

    public function testARowCarriesThePersonalFlagsAndWhatTheChatAllows(): void
    {
        $row = $this->row();

        $this->assertFalse($row['pinned']);
        $this->assertFalse($row['archived']);
        $this->assertFalse($row['muted']);
        $this->assertFalse($row['marked_unread']);
        $this->assertTrue($row['can_pin_chat']);
        $this->assertTrue($row['can_delete']);

        $state = new \App\Services\Messaging\ChatStateService();
        $state->pin($this->student, $this->conversation);
        $state->setMuted($this->student, $this->conversation, true);
        $state->markUnread($this->student, $this->conversation);

        $row = $this->row();

        $this->assertTrue($row['pinned']);
        $this->assertTrue($row['muted']);
        $this->assertTrue($row['marked_unread']);
        $this->assertFalse($row['archived']);
    }

    public function testACourseChatCannotBePinnedOrDeleted(): void
    {
        $offering = (int) db_connect()->table('enrollments')->get()->getRow('offering_id');
        (new \App\Services\Messaging\CourseChatSync())->syncOffering($offering);

        foreach ((new ConversationPresenter())->present($this->student, (new ConversationService())->listFor($this->student)) as $row) {
            if ($row['type'] === 'course') {
                $this->assertFalse($row['can_pin_chat']);
                $this->assertFalse($row['can_delete']);

                return;
            }
        }

        $this->fail('no course row');
    }
}

<?php

declare(strict_types=1);

use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;

/**
 * A parent thread is about a child. When the parent's link to the child ends,
 * the old messages stay readable but nobody can post any more.
 *
 * @internal
 */
final class ParentThreadLinkTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    private int $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('PL');
        $this->thread = (int) (new ConversationService())->startDirect($this->parent, (int) $this->instructor->id)['id'];
    }

    private function messages(): MessageService
    {
        return new MessageService($this->parentCipher());
    }

    public function testBothSidesPostWhileTheParentIsLinked(): void
    {
        $this->assertTrue($this->messages()->send($this->parent, $this->thread, 'How is my child doing?', 'uuid-parent-1')['ok']);
        $this->assertTrue($this->messages()->send($this->instructor, $this->thread, 'Very well.', 'uuid-teacher-1')['ok']);
    }

    public function testWhenTheLinkEndsNobodyCanPostButTheHistoryStaysReadable(): void
    {
        $this->messages()->send($this->parent, $this->thread, 'Before', 'uuid-parent-1');
        $this->unlinkParent($this->parent, $this->child);

        $this->assertSame('not_allowed', $this->messages()->send($this->parent, $this->thread, 'After', 'uuid-parent-2')['reason']);
        $this->assertSame('not_allowed', $this->messages()->send($this->instructor, $this->thread, 'After', 'uuid-teacher-2')['reason']);

        foreach ([$this->parent, $this->instructor] as $reader) {
            $this->assertCount(1, $this->messages()->page($reader, $this->thread)['messages']);
        }
    }

    public function testRelinkingRestoresPosting(): void
    {
        $this->unlinkParent($this->parent, $this->child);
        $this->linkParent($this->parent, $this->child);

        $this->assertTrue($this->messages()->send($this->instructor, $this->thread, 'Back', 'uuid-teacher-3')['ok']);
    }

    public function testADirectThreadWithNoChildIsUnaffected(): void
    {
        $other = \Tests\Support\PeopleFactory::person('instructor', 'Oth', 'Er', null, $this->institution);
        $direct = db_connect()->table('conversations');
        $now    = date('Y-m-d H:i:s');
        $direct->insert(['institution_id' => $this->institution, 'type' => 'direct', 'subject_student_id' => null, 'created_by' => $this->instructor->id, 'is_locked' => 0, 'direct_key' => hash('sha256', 'plain-pair'), 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) db_connect()->insertID();

        foreach ([$this->instructor, $other] as $user) {
            db_connect()->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $user->id, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $this->unlinkParent($this->parent, $this->child);

        $this->assertTrue($this->messages()->send($this->instructor, $id, 'Still fine', 'uuid-teacher-4')['ok']);
    }
}

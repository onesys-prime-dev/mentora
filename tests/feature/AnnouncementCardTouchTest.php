<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The course announcement card's reaction overlay: revealed on hover or focus for a mouse, always shown with
 * 44px buttons for a touch pointer (no database needed).
 *
 * @internal
 */
final class AnnouncementCardTouchTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['form', 'url', 'icon', 'announcement']);
    }

    public function testOverlayIsAlwaysShownWithTouchSizedButtonsOnACoarsePointer(): void
    {
        $html = view('pages/courses/features/announcements/partials/announcement-card', [
            'announcement' => [
                'id' => 7, 'author_id' => 2, 'author_name' => 'Ana Cruz', 'author_role' => 'instructor', 'subject' => 'Quiz',
                'message' => null, 'created_at' => '2026-11-12 08:00:00', 'attachments' => [], 'replies' => [],
                'is_pinned' => false, 'own_reaction' => null, 'reaction_counts' => [],
                'can_edit' => true, 'can_delete' => true, 'can_pin' => true,
            ],
            'userId' => 2,
        ], ['saveData' => false]);

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $menu = $xpath->query('//*[@data-announcement-hover-menu]')->item(0);
        $this->assertNotNull($menu);
        $classes = explode(' ', $menu->getAttribute('class'));

        // Mouse: hidden until hover (Tailwind gates group-hover on hover:hover) or keyboard focus.
        foreach (['opacity-0', 'group-hover:opacity-100', 'group-focus-within:opacity-100', 'absolute'] as $class) {
            $this->assertContains($class, $classes);
        }
        // Touch: always shown, in the flow (no overlap with the author line), wraps instead of overflowing.
        foreach (['pointer-coarse:opacity-100', 'pointer-coarse:static', 'pointer-coarse:flex-wrap', 'pointer-coarse:max-w-[calc(100%-2rem)]'] as $class) {
            $this->assertContains($class, $classes);
        }

        // 4 quick reactions, More reactions, More options (not the items inside the options panel).
        $buttons = $xpath->query('./button | ./div/button', $menu);
        $this->assertSame(6, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertStringContainsString('pointer-coarse:min-h-11 pointer-coarse:min-w-11', $button->getAttribute('class'));
        }
    }

    public function testTheReplyTriggerHoldsNoInteractiveAvatar(): void
    {
        $html = view('pages/courses/features/announcements/partials/announcement-card', [
            'announcement' => [
                'id' => 7, 'author_id' => 2, 'author_name' => 'Ana Cruz', 'author_role' => 'instructor', 'subject' => 'Quiz',
                'message' => null, 'created_at' => '2026-11-12 08:00:00', 'attachments' => [], 'replies' => [],
                'is_pinned' => false, 'own_reaction' => null, 'reaction_counts' => [],
                'can_edit' => true, 'can_delete' => true, 'can_pin' => true,
                // What AvatarData::forViewer gives for the viewer's own avatar: an interactive button.
                'viewer_avatar' => ['person' => ['id' => 2, 'name' => 'Ana Cruz'], 'interactive' => true],
            ],
            'userId' => 2,
        ], ['saveData' => false]);

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $trigger = $xpath->query('//button[@data-reply-trigger]')->item(0);
        $this->assertNotNull($trigger);
        $this->assertSame(0, $xpath->query('.//*[@data-person-avatar] | .//button', $trigger)->length);
        $this->assertStringContainsString('Reply in thread', $trigger->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-reply-form]//*[@data-person-avatar]')->length);
    }
}

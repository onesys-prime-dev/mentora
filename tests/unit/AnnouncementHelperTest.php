<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The announcement message helpers. A message is JSON an instructor's editor
 * produced, rendered for every student who opens the feed, so what it can put
 * on the page is limited to a few safe tags and nothing in it may break out
 * of them.
 *
 * @internal
 */
final class AnnouncementHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('announcement');
    }

    private function message(array $blocks): string
    {
        return json_encode(['version' => 1, 'blocks' => $blocks]);
    }

    private function text(string $text, array $marks = []): array
    {
        return ['text' => $text, 'marks' => $marks];
    }

    /*
    |--------------------------------------------------------------------------
    | render_announcement_message
    |--------------------------------------------------------------------------
    */

    public function testEmptyOrUnreadableMessagesRenderNothing(): void
    {
        foreach ([null, '', 'not json', '{}', '{"blocks":[]}', '{"blocks":"oops"}', '[]', '42'] as $input) {
            $this->assertSame('', render_announcement_message($input), var_export($input, true));
        }
    }

    public function testAParagraphIsTheDefaultBlock(): void
    {
        $html = render_announcement_message($this->message([['content' => [$this->text('Hello')]]]));

        $this->assertSame('<p class="">Hello</p>', $html);
    }

    public function testHeadingsAreLevelOneOrTwo(): void
    {
        $html = render_announcement_message($this->message([
            ['type' => 'heading', 'level' => 1, 'content' => [$this->text('Big')]],
            ['type' => 'heading', 'level' => 2, 'content' => [$this->text('Small')]],
            ['type' => 'heading', 'level' => 6, 'content' => [$this->text('Clamped')]],
            ['type' => 'heading', 'content' => [$this->text('Default')]],
        ]));

        $this->assertSame(
            '<h1 class="font-bold">Big</h1><h2 class="font-bold">Small</h2><h2 class="font-bold">Clamped</h2><h2 class="font-bold">Default</h2>',
            $html
        );
    }

    public function testListsRenderTheirItems(): void
    {
        $html = render_announcement_message($this->message([
            ['type' => 'unordered-list', 'items' => [[$this->text('one')], [$this->text('two')]]],
            ['type' => 'ordered-list', 'items' => [[$this->text('first')]]],
        ]));

        $this->assertSame(
            '<ul class="list-disc pl-5"><li>one</li><li>two</li></ul><ol class="list-decimal pl-5"><li>first</li></ol>',
            $html
        );
    }

    public function testMarksWrapTextAndStack(): void
    {
        $html = render_announcement_message($this->message([['content' => [
            $this->text('b', ['bold']),
            $this->text('i', ['italic']),
            $this->text('u', ['underline']),
            $this->text('all', ['bold', 'italic', 'underline']),
            $this->text('unknown', ['blink']),
        ]]]));

        $this->assertSame('<p class=""><strong>b</strong><em>i</em><u>u</u><u><em><strong>all</strong></em></u>unknown</p>', $html);
    }

    public function testAlignmentAddsAClassUnlessLeft(): void
    {
        $html = render_announcement_message($this->message([
            ['align' => 'center', 'content' => [$this->text('c')]],
            ['align' => 'left', 'content' => [$this->text('l')]],
            ['type' => 'heading', 'level' => 1, 'align' => 'right', 'content' => [$this->text('r')]],
        ]));

        $this->assertSame('<p class=" text-center">c</p><p class="">l</p><h1 class="font-bold text-right">r</h1>', $html);
    }

    public function testTextIsEscaped(): void
    {
        $html = render_announcement_message($this->message([['content' => [$this->text('<script>alert(1)</script> & "quotes"')]]]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testAnEmptyParagraphKeepsItsHeight(): void
    {
        $this->assertSame('<p class="">&nbsp;</p>', render_announcement_message($this->message([['content' => []]])));
    }

    public function testAnAlignmentValueCannotBreakOutOfTheClassAttribute(): void
    {
        $html = render_announcement_message($this->message([
            ['align' => '" onmouseover="alert(1)', 'content' => [$this->text('x')]],
            ['type' => 'heading', 'align' => '"><img src=x onerror=alert(1)>', 'content' => [$this->text('y')]],
        ]));

        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('x', $html);
        $this->assertStringContainsString('y', $html);
    }

    public function testOnlyKnownAlignmentsAreAccepted(): void
    {
        $html = render_announcement_message($this->message([
            ['align' => 'justify', 'content' => [$this->text('j')]],
            ['align' => 'sideways', 'content' => [$this->text('s')]],
        ]));

        $this->assertSame('<p class=" text-justify">j</p><p class="">s</p>', $html);
    }

    public function testMalformedBlocksAreSkippedInsteadOfBreakingThePage(): void
    {
        $html = render_announcement_message($this->message([
            'just a string',
            42,
            null,
            ['content' => 'not a list'],
            ['type' => 'unordered-list', 'items' => 'not a list'],
            ['type' => 'ordered-list', 'items' => ['not a node list', [$this->text('ok')]]],
            ['content' => ['plain string node', $this->text('kept')]],
            ['content' => [['marks' => 'bold', 'text' => 'odd marks']]],
        ]));

        $this->assertStringContainsString('kept', $html);
        $this->assertStringContainsString('ok', $html);
        $this->assertStringContainsString('odd marks', $html);
        $this->assertStringNotContainsString('just a string', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | announcement_plain_text
    |--------------------------------------------------------------------------
    */

    public function testPlainTextFlattensEverythingToOneLine(): void
    {
        $text = announcement_plain_text($this->message([
            ['type' => 'heading', 'content' => [$this->text('Title')]],
            ['content' => [$this->text('Some '), $this->text('bold', ['bold']), $this->text("  text\nhere")]],
            ['type' => 'unordered-list', 'items' => [[$this->text('one')], [$this->text('two')]]],
        ]));

        $this->assertSame('Title Some bold text here one two', $text);
    }

    public function testPlainTextIsNotEscapedBecauseItIsNotHtml(): void
    {
        $this->assertSame('a < b & c', announcement_plain_text($this->message([['content' => [$this->text('a < b & c')]]])));
    }

    public function testPlainTextOfNothingIsEmpty(): void
    {
        foreach ([null, '', 'garbage', '{}', '{"blocks":"x"}', $this->message([])] as $input) {
            $this->assertSame('', announcement_plain_text($input), var_export($input, true));
        }
    }

    public function testPlainTextSkipsMalformedBlocks(): void
    {
        $text = announcement_plain_text($this->message(['junk', 7, ['content' => [$this->text('fine')]], ['items' => 'x']]));

        $this->assertSame('fine', $text);
    }

    /*
    |--------------------------------------------------------------------------
    | announcement_message_from_text
    |--------------------------------------------------------------------------
    */

    public function testPlainTextBecomesOneParagraphPerLine(): void
    {
        $json = announcement_message_from_text("first line\r\nsecond\n\nfourth");

        $data = json_decode($json, true);

        $this->assertSame(1, $data['version']);
        $this->assertSame(['first line', 'second', '', 'fourth'], array_map(static fn (array $b) => $b['content'][0]['text'], $data['blocks']));
        $this->assertSame(['paragraph'], array_unique(array_column($data['blocks'], 'type')));
    }

    public function testTheTextIsTrimmedAndEmptyTextIsOneEmptyParagraph(): void
    {
        $this->assertSame('hi', json_decode(announcement_message_from_text("  \n hi \n  "), true)['blocks'][0]['content'][0]['text']);
        $this->assertCount(1, json_decode(announcement_message_from_text(''), true)['blocks']);
    }

    public function testTextRoundTripsThroughTheRenderer(): void
    {
        $html = render_announcement_message(announcement_message_from_text("Room <b>4</b>\nBring ID"));

        $this->assertSame('<p class="">Room &lt;b&gt;4&lt;/b&gt;</p><p class="">Bring ID</p>', $html);
        $this->assertSame('Room <b>4</b> Bring ID', announcement_plain_text(announcement_message_from_text("Room <b>4</b>\nBring ID")));
    }
}

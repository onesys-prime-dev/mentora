<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The publish chip on modules, learning units and learning items: a published
 * child of an unpublished parent must not read as plainly "Published".
 *
 * @internal
 */
final class PublishChipViewTest extends CIUnitTestCase
{
    private function chip(array $data): string
    {
        return view('pages/courses/features/modules/partials/publish-chip', $data, ['saveData' => false]);
    }

    public function testAPublishedItemUnderPublishedParentsIsPublished(): void
    {
        $html = $this->chip(['status' => 'published', 'hiddenBy' => null]);

        $this->assertStringContainsString('data-publish-chip="published"', $html);
        $this->assertStringContainsString('Published', $html);
    }

    public function testADraftIsADraftWhateverItsParentIs(): void
    {
        $html = $this->chip(['status' => 'draft', 'hiddenBy' => 'module']);

        $this->assertStringContainsString('data-publish-chip="draft"', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    public function testAPublishedItemUnderAnUnpublishedModuleSaysItIsHidden(): void
    {
        $html = $this->chip(['status' => 'published', 'hiddenBy' => 'module']);

        $this->assertStringContainsString('data-publish-chip="hidden"', $html);
        $this->assertStringContainsString('Hidden · module is a draft', $html);
        $this->assertStringNotContainsString('data-publish-chip="published"', $html);
    }

    public function testAPublishedItemUnderAnUnpublishedUnitNamesTheUnit(): void
    {
        $html = $this->chip(['status' => 'published', 'hiddenBy' => 'unit']);

        $this->assertStringContainsString('Hidden · unit is a draft', $html);
    }
}

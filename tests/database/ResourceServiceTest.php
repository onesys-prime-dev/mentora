<?php

declare(strict_types=1);

use App\Services\Courses\ResourceService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The Resources browser: module > unit > files and links, what each role
 * sees (drafts are for instructors only), search, sorting, type filters and
 * how a file or link is described to the page.
 *
 * @internal
 */
final class ResourceServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ResourceService $service;

    private int $offering;

    private User $instructor;

    private User $student;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResourceService();
        $this->offering = CourseFactory::offering();

        $this->instructor = CourseFactory::user('instructor');
        $this->student = CourseFactory::user('student');
        $this->outsider = CourseFactory::user('student');

        CourseFactory::assignInstructor($this->offering, $this->instructor);
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

    private function module(string $title, string $status = 'published', ?int $offering = null): int
    {
        $id = CourseFactory::module($offering ?? $this->offering, $status);
        db_connect()->table('course_modules')->where('id', $id)->update(['title' => $title]);

        return $id;
    }

    private function unit(int $module, string $title, string $status = 'published'): int
    {
        $id = CourseFactory::unit($module, $status);
        db_connect()->table('learning_units')->where('id', $id)->update(['title' => $title]);

        return $id;
    }

    /** A file resource: item title, optional resource title, mime type and size. @return int item id */
    private function file(int $unit, string $itemTitle, string $mime = 'application/pdf', int $size = 2048, string $status = 'published', ?string $resourceTitle = ''): int
    {
        $item = CourseFactory::items($unit, [['type' => 'pdf', 'title' => $itemTitle, 'status' => $status]])[0];
        $file = CourseFactory::file($this->instructor);
        CourseFactory::resource($item, 'pdf', null, $file['id']);

        db_connect()->table('files')->where('id', $file['id'])->update(['mime_type' => $mime, 'file_size' => $size]);
        db_connect()->table('learning_resources')->where('learning_item_id', $item)->update(['title' => $resourceTitle]);

        return $item;
    }

    private function link(int $unit, string $itemTitle, string $url = 'https://example.test/x'): int
    {
        $item = CourseFactory::items($unit, [['type' => 'link', 'title' => $itemTitle]])[0];
        CourseFactory::resource($item, 'link', $url);
        db_connect()->table('learning_resources')->where('learning_item_id', $item)->update(['title' => '']);

        return $item;
    }

    /** Calls show() and hands back what the page was given, by capturing the view's data. */
    private function html(User $user, array $params = [], ?int $offering = null): string
    {
        return $this->service->show($user, $offering ?? $this->offering, $params)['html'];
    }

    private function decorated(array $resource): array
    {
        $method = new ReflectionMethod($this->service, 'decorate');
        $method->setAccessible(true);

        return $method->invoke($this->service, $resource);
    }

    /** Positions of each needle in $html, so order can be asserted. @return list<int> */
    private function positions(string $html, array $needles): array
    {
        return array_map(static function (string $needle) use ($html): int {
            $position = strpos($html, $needle);

            return $position === false ? -1 : $position;
        }, $needles);
    }

    private function assertInOrder(string $html, array $needles): void
    {
        $positions = $this->positions($html, $needles);

        $this->assertNotContains(-1, $positions, 'every item is on the page');

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'order: ' . implode(' < ', $needles));
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function testPeopleOutsideTheCourseAreTurnedAway(): void
    {
        $otherTeacher = CourseFactory::user('instructor');

        foreach ([$this->outsider, $otherTeacher] as $user) {
            try {
                $this->service->show($user, $this->offering);
                $this->fail('expected a 404');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testADroppedStudentIsTurnedAway(): void
    {
        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($this->offering, $dropped, 'dropped');

        $this->expectException(PageNotFoundException::class);

        $this->service->show($dropped, $this->offering);
    }

    /*
    |--------------------------------------------------------------------------
    | Locked items
    |--------------------------------------------------------------------------
    */

    /** A unit with a reading, then a PDF that needs it finished first. @return array{unit: int, intro: int, handout: int} */
    private function gatedHandout(): array
    {
        $unit = $this->unit($this->module('Week One'), 'Basics Unit');

        [$intro, $handout] = CourseFactory::items($unit, [
            ['type' => 'reading', 'title' => 'Intro'],
            ['type' => 'pdf', 'title' => 'Gated Handout', 'mode' => 'previous'],
        ]);

        $file = CourseFactory::file($this->instructor);
        CourseFactory::resource($handout, 'pdf', null, $file['id']);
        db_connect()->table('learning_resources')->where('learning_item_id', $handout)->update(['title' => '']);

        return ['unit' => $unit, 'intro' => $intro, 'handout' => $handout];
    }

    public function testALockedResourceHasNoViewOrDownloadAddressForAStudent(): void
    {
        $gate = $this->gatedHandout();

        $html = $this->html($this->student, ['unit' => $gate['unit']]);

        $this->assertStringContainsString('Gated Handout', $html);
        $this->assertStringContainsString('data-resource-locked', $html);
        $this->assertStringContainsString('Complete “Intro” first.', $html);
        $this->assertStringNotContainsString('data-resource-menu-toggle', $html);
        $this->assertStringNotContainsString('data-resource-share', $html);
        $this->assertStringNotContainsString('Download', $html);
    }

    public function testTheResourceOpensOnceThePrerequisiteIsDone(): void
    {
        $gate = $this->gatedHandout();

        CourseFactory::progress($gate['intro'], $this->student);

        $html = $this->html($this->student, ['unit' => $gate['unit']]);

        $this->assertStringNotContainsString('data-resource-locked', $html);
        $this->assertStringContainsString('data-resource-menu-toggle', $html);
        $this->assertStringContainsString('Download', $html);
    }

    public function testInstructorsAreNeverLockedOutOfAResource(): void
    {
        $gate = $this->gatedHandout();

        $html = $this->html($this->instructor, ['unit' => $gate['unit']]);

        $this->assertStringNotContainsString('data-resource-locked', $html);
        $this->assertStringContainsString('data-resource-menu-toggle', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    */

    public function testTheTopLevelListsModulesAndStudentsDoNotSeeDrafts(): void
    {
        $this->module('Week One');
        $this->module('Secret Draft Module', 'draft');
        $this->module('Elsewhere Module', 'published', CourseFactory::offering('B'));

        $student = $this->html($this->student);
        $teacher = $this->html($this->instructor);

        $this->assertStringContainsString('Week One', $student);
        $this->assertStringNotContainsString('Secret Draft Module', $student);
        $this->assertStringContainsString('Secret Draft Module', $teacher);
        $this->assertStringNotContainsString('Elsewhere Module', $student . $teacher);
    }

    public function testAModuleListsItsUnitsAndStudentsDoNotSeeDraftUnits(): void
    {
        $module = $this->module('Week One');
        $this->unit($module, 'Basics Unit');
        $this->unit($module, 'Hidden Unit', 'draft');

        $student = $this->html($this->student, ['module' => $module]);
        $teacher = $this->html($this->instructor, ['module' => $module]);

        $this->assertStringContainsString('Basics Unit', $student);
        $this->assertStringNotContainsString('Hidden Unit', $student);
        $this->assertStringContainsString('Hidden Unit', $teacher);
    }

    public function testFoldersAreSearchedAndSortedByName(): void
    {
        $this->module('Banana Module');
        $this->module('Apple Module');
        $this->module('Cherry Module');

        $asc = $this->html($this->student);
        $desc = $this->html($this->student, ['sort' => 'name_desc']);
        $found = $this->html($this->student, ['search' => 'cherry']);

        $this->assertInOrder($asc, ['Apple Module', 'Banana Module', 'Cherry Module']);
        $this->assertInOrder($desc, ['Cherry Module', 'Banana Module', 'Apple Module']);
        $this->assertStringContainsString('Cherry Module', $found);
        $this->assertStringNotContainsString('Apple Module', $found);
    }

    public function testFoldersCanBeSortedByTheirCourseOrder(): void
    {
        $first = CourseFactory::module($this->offering, 'published', 1);
        $second = CourseFactory::module($this->offering, 'published', 2);
        db_connect()->table('course_modules')->where('id', $first)->update(['title' => 'Zed First']);
        db_connect()->table('course_modules')->where('id', $second)->update(['title' => 'Alpha Second']);

        $this->assertInOrder($this->html($this->student, ['sort' => 'oldest']), ['Zed First', 'Alpha Second']);
        $this->assertInOrder($this->html($this->student, ['sort' => 'newest']), ['Alpha Second', 'Zed First']);
    }

    public function testAModuleOfAnotherSectionOrAnUnknownOneIsNotFound(): void
    {
        $other = CourseFactory::offering('B');
        $foreign = $this->module('Foreign', 'published', $other);
        $draft = $this->module('Draft', 'draft');

        foreach ([$foreign, 999999] as $module) {
            foreach ([$this->student, $this->instructor] as $user) {
                try {
                    $this->html($user, ['module' => $module]);
                    $this->fail("module {$module} should be a 404");
                } catch (PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->expectException(PageNotFoundException::class);
        $this->html($this->student, ['module' => $draft]);
    }

    public function testAUnitOfAnotherSectionOrADraftOneIsNotFound(): void
    {
        $other = CourseFactory::offering('B');
        $foreignUnit = $this->unit($this->module('Foreign', 'published', $other), 'Foreign Unit');
        $draftUnit = $this->unit($this->module('Mine'), 'Draft Unit', 'draft');

        foreach ([$this->student, $this->instructor] as $user) {
            try {
                $this->html($user, ['unit' => $foreignUnit]);
                $this->fail('a unit of another section was served');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(PageNotFoundException::class);
        $this->html($this->student, ['unit' => $draftUnit]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resources in a unit
    |--------------------------------------------------------------------------
    */

    public function testAUnitListsItsFilesAndLinksAndStudentsDoNotSeeDraftItems(): void
    {
        $unit = $this->unit($this->module('Week One'), 'Basics');
        $this->file($unit, 'Syllabus Doc');
        $this->link($unit, 'Reading Link');
        $this->file($unit, 'Unreleased Slides', 'application/pdf', 100, 'draft');
        $this->unit($this->module('Other'), 'Other Unit');

        $student = $this->html($this->student, ['unit' => $unit]);
        $teacher = $this->html($this->instructor, ['unit' => $unit]);

        $this->assertStringContainsString('Syllabus Doc', $student);
        $this->assertStringContainsString('Reading Link', $student);
        $this->assertStringNotContainsString('Unreleased Slides', $student);
        $this->assertStringContainsString('Unreleased Slides', $teacher);
    }

    public function testAnEmptyUnitSaysSo(): void
    {
        $unit = $this->unit($this->module('Week One'), 'Empty Unit');

        $this->assertStringContainsString('No resources yet', $this->html($this->student, ['unit' => $unit]));
    }

    public function testResourcesAreSearchedSortedAndFilteredByType(): void
    {
        $unit = $this->unit($this->module('Week One'), 'Basics');
        $this->file($unit, 'Bravo Notes', 'application/pdf');
        $this->file($unit, 'Alpha Sheet', 'application/vnd.ms-excel');
        $this->file($unit, 'Charlie Photo', 'image/png');
        $this->link($unit, 'Delta Link');

        $by = fn (array $p) => $this->html($this->student, ['unit' => $unit] + $p);

        $this->assertInOrder($by([]), ['Alpha Sheet', 'Bravo Notes', 'Charlie Photo', 'Delta Link']);
        $this->assertInOrder($by(['sort' => 'name_desc']), ['Delta Link', 'Charlie Photo', 'Bravo Notes', 'Alpha Sheet']);

        $pdfs = $by(['type' => 'pdf']);
        $this->assertStringContainsString('Bravo Notes', $pdfs);
        $this->assertStringNotContainsString('Alpha Sheet', $pdfs);

        $this->assertStringContainsString('Delta Link', $by(['type' => 'link']));
        $this->assertStringNotContainsString('Bravo Notes', $by(['type' => 'link']));
        $this->assertStringContainsString('Charlie Photo', $by(['type' => 'image']));
        $this->assertStringContainsString('Alpha Sheet', $by(['type' => 'excel']));
        $this->assertStringContainsString('Bravo Notes', $by(['type' => 'all']));

        $searched = $by(['search' => 'charlie']);
        $this->assertStringContainsString('Charlie Photo', $searched);
        $this->assertStringNotContainsString('Bravo Notes', $searched);
    }

    public function testASearchAlsoMatchesTheResourcesOwnTitle(): void
    {
        $unit = $this->unit($this->module('Week One'), 'Basics');
        $this->file($unit, 'Item Title One', 'application/pdf', 100, 'published', 'Quirky Handout');
        $this->file($unit, 'Item Title Two');

        $html = $this->html($this->student, ['unit' => $unit, 'search' => 'quirky']);

        $this->assertStringContainsString('Quirky Handout', $html);
        $this->assertStringNotContainsString('Item Title Two', $html);
    }

    public function testTheViewParameterOnlyAcceptsListOrGrid(): void
    {
        $unit = $this->unit($this->module('Week One'), 'Basics');
        $this->file($unit, 'Syllabus Doc');

        $this->assertStringContainsString('Syllabus Doc', $this->html($this->student, ['unit' => $unit, 'view' => 'grid']));
        $this->assertStringContainsString('Syllabus Doc', $this->html($this->student, ['unit' => $unit, 'view' => '<script>']));
    }

    /*
    |--------------------------------------------------------------------------
    | How a resource is described
    |--------------------------------------------------------------------------
    */

    private function describe(array $overrides): array
    {
        return $this->decorated($overrides + [
            'resource_title' => '', 'item_title' => 'Item', 'resource_type' => 'pdf', 'resource_url' => null,
            'file_id' => 7, 'mime_type' => 'application/pdf', 'file_size' => 2048, 'item_type' => 'pdf', 'created_at' => '2026-03-05 10:00:00',
        ]);
    }

    public function testAFileGetsProtectedViewAndDownloadUrlsAndALabel(): void
    {
        $r = $this->describe([]);

        $this->assertSame(site_url('files/7'), $r['view_url']);
        $this->assertSame(site_url('files/7') . '?download=1', $r['download_url']);
        $this->assertSame('pdf', $r['type_bucket']);
        $this->assertSame('PDF', $r['type_label']);
        $this->assertSame('2 KB', $r['size_label']);
        $this->assertSame('Mar 5, 2026', $r['added_label']);
    }

    public function testALinkOpensItsAddressAndHasNoDownloadOrSize(): void
    {
        $r = $this->describe(['resource_type' => 'link', 'file_id' => null, 'resource_url' => 'https://example.test/x', 'mime_type' => null, 'file_size' => null]);

        $this->assertSame('link', $r['type_bucket']);
        $this->assertSame('Link', $r['type_label']);
        $this->assertSame('https://example.test/x', $r['view_url']);
        $this->assertNull($r['download_url']);
        $this->assertNull($r['size_label']);
    }

    public function testAResourceWithoutAFileButWithAnAddressIsALink(): void
    {
        $r = $this->describe(['resource_type' => 'pdf', 'file_id' => null, 'resource_url' => 'https://example.test/doc']);

        $this->assertSame('link', $r['type_bucket']);
    }

    public function testTheDisplayTitlePrefersTheResourceTitleThenTheItemTitle(): void
    {
        $this->assertSame('Resource name', $this->describe(['resource_title' => 'Resource name'])['display_title']);
        $this->assertSame('Item', $this->describe(['resource_title' => ''])['display_title']);
        $this->assertSame('Item', $this->describe(['resource_title' => null])['display_title']);
    }

    public static function mimeTypes(): array
    {
        return [
            ['application/pdf', 'pdf', 'PDF'],
            ['application/msword', 'word', 'Word Document'],
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word', 'Word Document'],
            ['application/vnd.ms-powerpoint', 'powerpoint', 'PowerPoint'],
            ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'powerpoint', 'PowerPoint'],
            ['application/vnd.ms-excel', 'excel', 'Excel'],
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'excel', 'Excel'],
            ['image/jpeg', 'image', 'Image'],
            ['video/mp4', 'video', 'Video'],
            ['text/plain', 'other', 'File'],
            ['', 'other', 'File'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('mimeTypes')]
    public function testFileTypesAreBucketedByMimeType(string $mime, string $bucket, string $label): void
    {
        $r = $this->describe(['mime_type' => $mime, 'item_type' => 'reading']);

        $this->assertSame($bucket, $r['type_bucket']);
        $this->assertSame($label, $r['type_label']);
    }

    public function testAVideoItemWithAnUnknownMimeTypeIsStillAVideo(): void
    {
        $this->assertSame('video', $this->describe(['mime_type' => 'application/octet-stream', 'item_type' => 'video'])['type_bucket']);
    }

    public static function sizes(): array
    {
        return [[512, '512 B'], [1024, '1 KB'], [1536, '1.5 KB'], [1024 * 1024, '1 MB'], [5 * 1024 * 1024 + 524288, '5.5 MB']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function testSizesAreReadable(int $bytes, string $label): void
    {
        $this->assertSame($label, $this->describe(['file_size' => $bytes])['size_label']);
    }
}

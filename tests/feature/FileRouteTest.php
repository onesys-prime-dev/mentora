<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * files/{id}: the one way an upload reaches a browser. Allowed people get the
 * bytes (whole or by Range), everyone else gets a 404 that doesn't reveal
 * whether the file exists, and visitors who are logged out are sent to log in.
 *
 * @internal
 */
final class FileRouteTest extends RouteTestCase
{
    private User $owner;

    private User $other;

    private int $fileId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = CourseFactory::user('student');
        $this->other = CourseFactory::user('student');

        $file = CourseFactory::file($this->owner, '0123456789');
        $this->fileId = $file['id'];
        db_connect()->table('files')->where('id', $this->fileId)->update(['status' => 'active', 'mime_type' => 'application/pdf', 'original_filename' => 'Résumé "v2".pdf']);
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    private function fetch(User $user, string $query = '', array $headers = [])
    {
        $this->loggedInAs($user);

        return $this->withHeaders($headers)->get("files/{$this->fileId}{$query}");
    }

    public function testTheOwnerGetsTheWholeFileInline(): void
    {
        $result = $this->fetch($this->owner);
        $response = $result->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('inline', $response->getHeaderLine('Content-Disposition'));
    }

    public function testTheDownloadFlagAsksForAnAttachment(): void
    {
        $response = $this->fetch($this->owner, '?download=1')->response();
        $response->buildHeaders();

        $this->assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    public function testARangeRequestGetsJustThatSlice(): void
    {
        $response = $this->fetch($this->owner, '', ['Range' => 'bytes=2-5'])->response();

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
        $this->assertSame('4', $response->getHeaderLine('Content-Length'));
        $this->assertSame('2345', $response->getBody());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAnUnsatisfiableRangeIs416(): void
    {
        $response = $this->fetch($this->owner, '', ['Range' => 'bytes=50-60'])->response();

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
    }

    public function testTheSliceNamesTheFileSafelyEvenWithQuotesAndAccents(): void
    {
        $header = $this->fetch($this->owner, '', ['Range' => 'bytes=0-1'])->response()->getHeaderLine('Content-Disposition');

        $this->assertStringContainsString('filename="R__sum__ _v2_.pdf"', $header);
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('Résumé "v2".pdf'), $header);
    }

    public function testSomeoneWithNoRightToTheFileGetsA404(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->fetch($this->other);
    }

    public function testAnUnknownFileLooksTheSameAsAForbiddenOne(): void
    {
        $this->loggedInAs($this->owner);

        $this->expectException(PageNotFoundException::class);

        $this->get('files/999999');
    }

    public function testALoggedOutVisitorIsSentToLogin(): void
    {
        $response = $this->get("files/{$this->fileId}")->response();

        $this->assertContains($response->getStatusCode(), [301, 302, 303, 401, 403]);
    }
}

<?php

namespace App\Services\Storage;

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Files\File;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The one place that knows where uploaded files live.
 *
 * Every upload is stored under writable/uploads/{storage_key}, outside the
 * web root, and is only ever served through FileController (files/{id}),
 * which checks FileAccessService first. Nothing should build a path or URL
 * to an upload by hand — use path() / url().
 */
class StorageService
{
    /** Largest slice sent for one Range request (video seeking, PDF.js). */
    public const RANGE_CHUNK_BYTES = 2 * 1024 * 1024;

    /** Tables/columns that point at files.id — a file is in use while any row does. */
    private const FILE_REFERENCES = [
        'learning_resources' => 'file_id',
        'assessment_submissions' => 'submission_file_id',
        'assessment_question_responses' => 'uploaded_file_id',
        'ai_generation_batches' => 'source_file_id',
        'announcement_attachments' => 'file_id',
        'discussion_attachments' => 'file_id',
        'institutions' => 'logo_file_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Upload File
    |--------------------------------------------------------------------------
    */

    public function upload(
        File $file,
        string $directory
    ): array {

        // The signed-in person's institution may be out of storage on its plan. Checked here, in the one
        // place every upload passes through; when it is full this throws and the request is answered.
        (new PlanLimits())->assertCanStore(auth()->user(), (int) $file->getSize());

        $filename =

            $file->getRandomName();

        $path =

            rtrim(
                $directory,
                '/'
            );

        // Captured before move() — the temp upload path is gone once the
        // file has been relocated, so getMimeType()/getExtension()/getSize()
        // can no longer read it afterward (they re-open the file from disk).
        $originalName = $file->getClientName();
        $mimeType = $file->getMimeType();
        $extension = $file->getExtension();
        $size = $file->getSize();

        $file->move(

            $this->root() . $path,

            $filename

        );

        return [

            'storage_key' =>
                "{$path}/{$filename}",

            'original_name' =>
                $originalName,

            'filename' =>
                $filename,

            'mime_type' =>
                $mimeType,

            'extension' =>
                $extension,

            'size' =>
                $size,

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Locate
    |--------------------------------------------------------------------------
    */

    public function root(): string
    {
        return WRITEPATH . 'uploads/';
    }

    /**
     * Absolute disk path for a storage key.
     */
    public function path(string $storageKey): string
    {
        return $this->root() . ltrim($storageKey, '/');
    }

    /**
     * The protected URL a browser uses to fetch a file.
     */
    public function url(int $fileId, bool $download = false): string
    {
        return site_url("files/{$fileId}") . ($download ? '?download=1' : '');
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function delete(
        string $storageKey
    ): void {

        $path = $this->path($storageKey);

        if (

            is_file($path)

        ) {

            unlink($path);

        }

    }

    /**
     * Deletes the given files (row + disk) that nothing references any
     * more. Callers pass every file id they might have orphaned; ones still
     * in use elsewhere are left alone, so this is always safe to call after
     * deleting or replacing whatever pointed at them.
     *
     * @param list<int|string|null> $fileIds
     */
    public function purgeFiles(array $fileIds): void
    {
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));

        if ($fileIds === []) {
            return;
        }

        $db = db_connect();

        $inUse = [];

        foreach (self::FILE_REFERENCES as $table => $column) {

            foreach ($db->table($table)->select($column)->whereIn($column, $fileIds)->get()->getResultArray() as $row) {
                $inUse[(int) $row[$column]] = true;
            }

        }

        $orphans = array_values(array_filter($fileIds, static fn (int $id) => ! isset($inUse[$id])));

        if ($orphans === []) {
            return;
        }

        $files = $db->table('files')->select(['id', 'storage_key'])->whereIn('id', $orphans)->get()->getResultArray();

        $db->table('files')->whereIn('id', $orphans)->delete();

        foreach ($files as $file) {
            $this->delete($file['storage_key']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Serve
    |--------------------------------------------------------------------------
    */

    /**
     * Sends a stored file. Range requests (video seeking, PDF.js) get a 206
     * slice of at most RANGE_CHUNK_BYTES; everything else is streamed whole
     * through CI4's DownloadResponse, which reads the file in 1 MB chunks.
     */
    public function stream(
        array $file,
        RequestInterface $request,
        ResponseInterface $response,
        bool $download = false
    ): ResponseInterface {

        $path = $this->path($file['storage_key']);

        $size = (int) filesize($path);

        $mimeType = $file['mime_type'] ?: 'application/octet-stream';

        // Students upload anything as assignment work; HTML or SVG shown inline would run as this site.
        $download = $download || ! preg_match('#^(image/(png|jpeg|gif|webp)|application/pdf|video/|audio/)#', $mimeType);

        $range = $this->parseRange($request->getHeaderLine('Range'), $size);

        if ($range === false) {

            return $response
                ->setStatusCode(416)
                ->setHeader('Content-Range', "bytes */{$size}")
                ->setHeader('Accept-Ranges', 'bytes');

        }

        if ($range === null) {

            $whole = $response->download($path, null)
                ->setFileName($file['original_filename'])
                ->setContentType($mimeType, '')
                ->setHeader('Accept-Ranges', 'bytes')
                ->setHeader('Cache-Control', 'private, max-age=3600')
                ->setHeader('X-Content-Type-Options', 'nosniff');

            return $download ? $whole : $whole->inline();

        }

        [$start, $end] = $range;

        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $body = fread($handle, $end - $start + 1);
        fclose($handle);

        return $response
            ->setStatusCode(206)
            ->setContentType($mimeType, '')
            ->setHeader('Content-Range', "bytes {$start}-{$end}/{$size}")
            ->setHeader('Content-Length', (string) ($end - $start + 1))
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Content-Disposition', $this->contentDisposition($file['original_filename'], $download))
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body);
    }

    /**
     * Parses a single-range "Range: bytes=…" header.
     *
     * @return array{0: int, 1: int}|false|null [start, end] (inclusive, capped
     *         to RANGE_CHUNK_BYTES), false when unsatisfiable (416), null when
     *         there's no usable range and the whole file should be sent
     */
    public function parseRange(string $header, int $size): array|false|null
    {
        if (! preg_match('/^bytes=(\d*)-(\d*)(?:,.*)?$/', trim($header), $match)) {
            return null;
        }

        [, $from, $to] = $match;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($size === 0) {
            return false;
        }

        if ($from === '') {
            // Suffix range: the last N bytes.
            $start = max(0, $size - (int) $to);
            $end = $size - 1;
        } else {
            $start = (int) $from;
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, min($end, $start + self::RANGE_CHUNK_BYTES - 1)];
    }

    private function contentDisposition(string $filename, bool $download): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);

        return ($download ? 'attachment' : 'inline')
            . "; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($filename);
    }

}

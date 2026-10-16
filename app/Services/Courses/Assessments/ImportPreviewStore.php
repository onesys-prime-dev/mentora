<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

/**
 * Holds what an import preview found, on the server, under a random token.
 *
 * The browser only ever sends the token back with the teacher's choices
 * (which items to skip, which missing questions to remove), so what is
 * applied is exactly what was previewed and nothing a client invents. An
 * entry belongs to one person and one quiz, expires after an hour, and after
 * it is applied it is kept for a short while only so the import can be undone.
 */
final class ImportPreviewStore
{
    /** A preview that was never applied is forgotten after this long. */
    public const PREVIEW_SECONDS = 3600;

    /** An applied import can be undone for this long. */
    public const UNDO_SECONDS = 600;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? WRITEPATH . 'cache/question-imports', '/\\');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string the token
     */
    public function put(int $userId, int $assessmentId, array $payload, ?int $now = null): string
    {
        $this->purge($now);

        $token = bin2hex(random_bytes(16));

        $this->write($token, [
            'user_id'       => $userId,
            'assessment_id' => $assessmentId,
            'created_at'    => $now ?? time(),
            'applied_at'    => null,
            'payload'       => $payload,
        ]);

        return $token;
    }

    /**
     * The stored preview for this person and quiz, or null when the token is
     * unknown, expired, or someone else's.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $token, int $userId, int $assessmentId, ?int $now = null): ?array
    {
        $entry = $this->read($token);

        if ($entry === null || (int) $entry['user_id'] !== $userId || (int) $entry['assessment_id'] !== $assessmentId) {
            return null;
        }

        return $this->alive($entry, $now ?? time()) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function save(string $token, array $entry): void
    {
        $this->write($token, $entry);
    }

    public function forget(string $token): void
    {
        $path = $this->path($token);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Removes entries that can no longer be applied or undone. */
    public function purge(?int $now = null): void
    {
        $now = $now ?? time();

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $entry = json_decode((string) @file_get_contents($file), true);

            if (! is_array($entry) || ! $this->alive($entry, $now)) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function alive(array $entry, int $now): bool
    {
        if ($entry['applied_at'] !== null) {
            return $now - (int) $entry['applied_at'] <= self::UNDO_SECONDS;
        }

        return $now - (int) $entry['created_at'] <= self::PREVIEW_SECONDS;
    }

    /** @return array<string, mixed>|null */
    private function read(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || ! is_file($this->path($token))) {
            return null;
        }

        $entry = json_decode((string) file_get_contents($this->path($token)), true);

        return is_array($entry) ? $entry : null;
    }

    /** @param array<string, mixed> $entry */
    private function write(string $token, array $entry): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        file_put_contents($this->path($token), json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function path(string $token): string
    {
        return $this->directory . '/' . $token . '.json';
    }
}

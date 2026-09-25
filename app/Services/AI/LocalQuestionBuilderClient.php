<?php

namespace App\Services\AI;

use CodeIgniter\HTTP\CURLRequest;
use Config\Services;

class LocalQuestionBuilderClient implements QuestionGeneratorClientInterface
{
    protected string $baseUrl;

    protected int $timeoutSeconds;

    public function __construct(?string $baseUrl = null, int $timeoutSeconds = 120)
    {
        $this->baseUrl = rtrim(
            $baseUrl ?? env('questionBuilder.serviceUrl', 'http://127.0.0.1:8008'),
            '/'
        );

        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function isAvailable(): bool
    {
        try {

            $response = $this->client()->get('/health', [
                'timeout' => 5,
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Throwable $e) {

            return false;

        }
    }

    public function generate(array $params): array
    {
        try {

            $response = $this->client()->post('/generate', [
                'json' => $params,
                'timeout' => $this->timeoutSeconds,
                // Without this, CI4's CURLRequest throws on any non-2xx
                // status before we ever see the response body — which
                // would make the 409 "busy" branch below unreachable.
                'http_errors' => false,
            ]);

        } catch (\Throwable $e) {

            throw new QuestionGeneratorUnavailableException(
                'Unable to reach the question-builder service: ' . $e->getMessage()
            );

        }

        $status = $response->getStatusCode();

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($status === 409) {
            throw new QuestionGeneratorBusyException(
                $body['detail'] ?? 'Another generation job is already running.'
            );
        }

        if ($status !== 200) {
            throw new QuestionGeneratorUnavailableException(
                $body['detail'] ?? "Question-builder service returned HTTP {$status}."
            );
        }

        return $body;
    }

    public function extractText(string $filePath, string $mimeType): array
    {
        try {

            $response = $this->client()->post('/extract-text', [
                'multipart' => [
                    'file' => new \CURLFile($filePath, $mimeType, basename($filePath)),
                ],
                'timeout' => $this->timeoutSeconds,
                'http_errors' => false,
            ]);

        } catch (\Throwable $e) {

            throw new QuestionGeneratorUnavailableException(
                'Unable to reach the question-builder service: ' . $e->getMessage()
            );

        }

        $status = $response->getStatusCode();

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($status !== 200) {
            throw new QuestionGeneratorUnavailableException(
                $body['detail'] ?? "Text extraction failed (HTTP {$status})."
            );
        }

        return $body;
    }

    private function client(): CURLRequest
    {
        // getShared: false — this must never reuse a cached instance from
        // elsewhere in the app with a different baseURI.
        return Services::curlrequest(
            ['baseURI' => $this->baseUrl . '/'],
            null,
            null,
            false
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Config\Services;
use CodeIgniter\Email\Email;
use Throwable;

/**
 * Stands in for the mailer so tests never send mail. Every message that
 * would have gone out is kept, and install() makes service('email') return it.
 *
 * @internal
 */
final class FakeEmail extends Email
{
    /** @var list<array{to: string, subject: string, message: string}> */
    public array $sent = [];

    /** Set to true to behave like a machine with no mail server: send() fails. */
    public bool $failToSend = false;

    /** Set to make send() throw, like a mailer that cannot even open the connection. */
    public ?Throwable $throwOnSend = null;

    private string $capturedTo = '';

    private string $capturedSubject = '';

    private string $capturedBody = '';

    public static function install(): self
    {
        $fake = new self();

        Services::injectMock('email', $fake);

        return $fake;
    }

    public function setTo($to)
    {
        $this->capturedTo = is_array($to) ? implode(',', $to) : (string) $to;

        return parent::setTo($to);
    }

    public function setSubject($subject)
    {
        $this->capturedSubject = (string) $subject;

        return parent::setSubject($subject);
    }

    public function setMessage($body)
    {
        $this->capturedBody = (string) $body;

        return parent::setMessage($body);
    }

    public function send($autoClear = true)
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        if ($this->failToSend) {
            return false;
        }

        $this->sent[] =['to' => $this->capturedTo, 'subject' => $this->capturedSubject, 'message' => $this->capturedBody];

        return true;
    }

    /** The last six-digit code that was mailed, or null if nothing was sent. */
    public function lastCode(): ?string
    {
        $last = end($this->sent);

        if ($last === false || ! preg_match('/\b(\d{6})\b/', strip_tags($last['message']), $match)) {
            return null;
        }

        return $match[1];
    }
}

<?php

/**
 * A stand-in for sendmail during the end-to-end tests: CodeIgniter's mailer
 * pipes each message to it (the app is pointed here by E2E_MAIL_SINK, see
 * app/Config/Email.php). It files the message in writable/e2e-mail, where the
 * tests read sign-in codes and invitation links, and reports success.
 *
 * Arguments (-oi -f ... -t) are ignored.
 */

declare(strict_types=1);

$dir = (string) (getenv('E2E_MAIL_DIR') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'e2e-mail');

if (! is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$message = (string) stream_get_contents(STDIN);
$name    = sprintf('%020.6f-%s.eml', microtime(true), bin2hex(random_bytes(3)));

exit(file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $message) === false ? 1 : 0);

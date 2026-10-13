<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Config\Email as EmailConfig;

/**
 * Rules about *where* a system administrator account may be created, on top of
 * the field checks in SystemAdminService.
 *
 * A system administrator signs in with a code sent to their email, so on a real
 * server the address must be a real, reachable inbox and the app must be able
 * to send mail. Both are checked here so the spark command (and the demo seeder)
 * can refuse early instead of leaving an account nobody can sign in to.
 */
class AdminAccountPolicy
{
    /** Environments where throw-away addresses and a missing mail server are fine. */
    private const LOOSE_ENVIRONMENTS = ['development', 'testing'];

    /** Domains that can never receive mail (RFC 2606 / 6761), plus the common placeholders. */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org', 'localhost'];

    private const RESERVED_SUFFIXES = ['.test', '.example', '.invalid', '.localhost', '.local'];

    public function __construct(private readonly string $environment = ENVIRONMENT)
    {
    }

    /** True where demo data and unreachable addresses are acceptable. */
    public function isLoose(): bool
    {
        return in_array($this->environment, self::LOOSE_ENVIRONMENTS, true);
    }

    /**
     * Why this email cannot be used for a system administrator here, or null if it can.
     */
    public function emailProblem(string $email): ?string
    {
        if ($this->isLoose()) {
            return null;
        }

        $domain = strtolower(substr(strrchr(trim($email), '@') ?: '', 1));

        if ($domain === '') {
            return null; // not an address at all; SystemAdminService reports that
        }

        $reserved = in_array($domain, self::RESERVED_DOMAINS, true);

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            $reserved = $reserved || str_ends_with($domain, $suffix);
        }

        return $reserved
            ? "{$domain} is a placeholder domain that can never receive mail. In {$this->environment} the administrator signs in with a code sent by email, so use a real address you can open."
            : null;
    }

    /**
     * Why the demo seeder must not run here, or null if it may.
     */
    public function seederProblem(): ?string
    {
        return $this->environment === 'production'
            ? 'The demo administrator has a published password and must never exist in production. Create the real one with: php spark admin:create'
            : null;
    }

    /**
     * Why this server could not mail a sign-in code right now, or null if mail looks configured.
     * This only inspects the settings; `php spark mail:test` proves delivery.
     */
    public function mailProblem(?EmailConfig $config = null): ?string
    {
        if ($this->isLoose()) {
            return null;
        }

        $config ??= config(EmailConfig::class);

        if ($config->protocol !== 'smtp') {
            return 'Mail is not set to SMTP (email.protocol). Without it the sign-in code will not arrive. See docs/mail.md.';
        }

        if ($config->SMTPHost === '' || $config->SMTPUser === '' || $config->SMTPPass === '') {
            return 'The SMTP host, user or password is empty. Without them the sign-in code will not arrive. See docs/mail.md.';
        }

        return null;
    }
}

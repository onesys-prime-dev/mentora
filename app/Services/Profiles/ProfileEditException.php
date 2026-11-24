<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/** A profile edit that was refused; the message is written for the person, $field names the form field it belongs to. */
class ProfileEditException extends \DomainException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Groups;

/**
 * The one place the group limits live.
 */
final class GroupLimits
{
    public const MAX_GROUPS_PER_ACTIVITY = 50;
    public const MAX_MEMBERS             = 30;
    public const MAX_SETS_PER_OFFERING   = 20;
}

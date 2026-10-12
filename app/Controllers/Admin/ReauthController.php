<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\ReauthController as SharedReauthController;

/**
 * The system administrator's address for the confirm-your-password step
 * (admin/reauth). The behaviour is shared with every other administrative
 * area: see App\Controllers\ReauthController.
 */
class ReauthController extends SharedReauthController
{
}

<?php

declare(strict_types=1);

namespace App\Controllers\Parents;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;

/**
 * The parent's landing page. It shows one of three things: the portal is off
 * (and no child data is read at all), the parent is not linked to a student,
 * or their linked children. The real dashboard replaces the last in the next
 * branch.
 */
class ParentDashboardController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();
        $data = ['title' => 'Dashboard', 'page' => 'dashboard', 'user' => $user];

        if (! (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $user->id))) {
            return view('pages/parent/portal-off', $data);
        }

        $children = (new ParentAccessService())->children($user);

        if ($children === []) {
            return view('pages/parent/not-linked', $data);
        }

        return view('pages/parent/dashboard', $data + ['children' => $children]);
    }
}

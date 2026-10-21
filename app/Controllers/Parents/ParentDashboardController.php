<?php

declare(strict_types=1);

namespace App\Controllers\Parents;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentDashboardService;
use App\Services\Parents\ParentPortal;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The parent's dashboard for one child. It shows one of four things: the
 * portal is off (and no child data is read at all), the parent is not linked
 * to a student, or the dashboard of the chosen child (the first by name when
 * none is chosen). A child the parent is not linked to is the route filter's
 * 404 before this runs.
 */
class ParentDashboardController extends BaseController
{
    public function index(): string
    {
        return $this->show(null);
    }

    public function child(int $studentId): string
    {
        return $this->show($studentId);
    }

    private function show(?int $studentId): string
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

        $studentId ??= $children[0]['student_id'];
        $dashboard   = (new ParentDashboardService())->forChild($user, $studentId);

        if ($dashboard === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('pages/parent/dashboard', $data + ['children' => $children, 'dashboard' => $dashboard]);
    }
}

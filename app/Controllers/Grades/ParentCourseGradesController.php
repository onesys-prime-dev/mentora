<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Services\Grades\CourseBreakdownFragment;
use App\Services\Parents\ParentAccessService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * One course's small grade breakdown for the parent's Grades page: the child's own breakdown, the same fragment the
 * student loads. The `parentportal` and `parentchild` filters have answered for the portal and the link; the course
 * must be one the child is in, and a child who is also staff shows no grades.
 */
class ParentCourseGradesController extends BaseController
{
    public function show(int $studentId, int $offeringId): ResponseInterface
    {
        $parent = auth()->user();
        $access = new ParentAccessService();
        $child  = $access->childUser($parent, $studentId) ?? throw PageNotFoundException::forPageNotFound();

        if (! $access->canViewOffering($parent, $studentId, $offeringId) || ! ParentAccessService::readsAsStudent($child)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $html = (new CourseBreakdownFragment())->html($child, $offeringId, 'parent/children/' . $studentId, true) ?? throw PageNotFoundException::forPageNotFound();

        return $this->response->setJSON(['success' => true, 'html' => $html]);
    }
}

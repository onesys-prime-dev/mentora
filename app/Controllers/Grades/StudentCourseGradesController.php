<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Services\Grades\CourseBreakdownFragment;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * One course's small grade breakdown for the student's Grades page, fetched when its row is expanded (spec §5.3).
 *
 * The `coursemember:student` filter has already decided the student is in this course. A course without a
 * Published scheme has nothing to show and is a 404 (see CourseBreakdownFragment).
 */
class StudentCourseGradesController extends BaseController
{
    public function show(int $offeringId): ResponseInterface
    {
        $html = (new CourseBreakdownFragment())->html(auth()->user(), $offeringId, 'student') ?? throw PageNotFoundException::forPageNotFound();

        return $this->response->setJSON(['success' => true, 'html' => $html]);
    }
}

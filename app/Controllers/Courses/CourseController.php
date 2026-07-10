<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\CourseService;
use CodeIgniter\Exceptions\PageNotFoundException;

class CourseController extends BaseController
{
    protected CourseService $courseService;

    public function __construct()
    {
        $this->courseService = new CourseService();
    }

    public function index(): string
    {
        $user = auth()->user();

        $courses = $this->courseService->getCourseOfferings($user);

        foreach ($courses as &$course) {
            $course['slug'] = url_title(
                $course['course_title'],
                '-',
                true
            );
        }

        unset($course);

        return view('pages/courses/index', [
            'title'   => 'Courses',
            'page'    => 'courses',
            'user'    => $user,
            'courses' => $courses,
        ]);
    }

    public function view(int $offeringId, string $slug, string $feature = 'home'): string
    {
        $user = auth()->user();

        $course = $this->courseService
            ->getCourseOffering($user, $offeringId);

        if (! $course) {
            throw PageNotFoundException::forPageNotFound();
        }

        $course['slug'] = url_title(
            $course['course_title'],
            '-',
            true
        );

        return view('pages/courses/view', [
            'title'      => $course['course_code'],
            'page'       => 'courses',
            'user'       => $user,
            'course'     => $course,
            'activeTab'  => $feature,
        ]);
    }

    public function tab(int $offeringId, string $tab)
    {
        $user = auth()->user();

        $response = $this->courseService
            ->renderCourseTab(
                $user,
                $offeringId,
                $tab
            );

        return $this->response->setJSON($response);
    }
}
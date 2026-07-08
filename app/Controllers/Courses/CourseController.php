<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;

class CourseController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        return view('pages/courses/index', [
            'title' => 'Courses',
            'user' => $user,
        ]);
    }
}

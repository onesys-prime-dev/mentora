<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route option for the chairperson course API group: `['filter' => 'chairpersoncourseaudit']`.
 *
 * A chairperson has full instructor rights on every course in their
 * program, and every one of those actions must leave a trace (R7 of the
 * chairperson plan). Rather than threading the audit logger into every
 * announcement/module/assessment/grade controller individually, this logs
 * once per mutating request — after the controller has actually run, so a
 * request that 404s or fails validation is never recorded as having
 * happened.
 */
class ChairpersonCourseAudit implements FilterInterface
{
    private const MUTATING = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! in_array(strtoupper($request->getMethod()), self::MUTATING, true)) {
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            return null;
        }

        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $segments = $request->getUri()->getSegments();
        $coursesIndex = array_search('courses', $segments, true);

        $offeringId = ($coursesIndex !== false && isset($segments[$coursesIndex + 1]) && ctype_digit($segments[$coursesIndex + 1]))
            ? (int) $segments[$coursesIndex + 1]
            : null;

        service('auditLogger')->log('course.chairperson_action', $user, [
            'acting_role' => 'chairperson',
            'target_type' => 'course_offering',
            'target_id'   => $offeringId,
            'new'         => [
                'method' => strtoupper($request->getMethod()),
                'path'   => '/' . implode('/', $segments),
            ],
        ]);

        return null;
    }
}

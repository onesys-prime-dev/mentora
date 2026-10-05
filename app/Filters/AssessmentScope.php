<?php

namespace App\Filters;

use App\Services\Courses\Assessments\AssessmentScopeService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * For /…/api/courses/{offering}/… routes that name an assessment, submission,
 * question, rubric, criterion or generated question: the person must belong
 * to {offering} (its assigned instructor or an enrolled student), and every
 * id in the path must belong to {offering} too. Anything else is a 404 before
 * a controller runs, so a route can't be pointed at another section's work
 * by putting its own course in the URL.
 *
 * Routes that don't name any of those things pass through untouched.
 */
class AssessmentScope implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $segments = $request->getUri()->getSegments();

        $courses = array_search('courses', $segments, true);

        if ($courses === false || ! isset($segments[$courses + 1]) || ! ctype_digit($segments[$courses + 1])) {
            return null;
        }

        $scope = new AssessmentScopeService();

        $rest = array_slice($segments, $courses + 2);

        if (! $scope->appliesTo($rest)) {
            return null;
        }

        $offeringId = (int) $segments[$courses + 1];

        $user = auth()->user();

        if ($user === null || ! $scope->canAccess($user, $offeringId) || ! $scope->pathBelongsTo($rest, $offeringId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\RolePermissions;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * On the instructor's and chairperson's course API: a change asks the
 * permission of the part of the course it belongs to (audit decisions 7.4, D6).
 * Course content (`courses.manage`), the gradebook and grading
 * (`grading.manage`), announcements (`announcements.manage`) and moderating
 * discussions (`discussions.manage`). Taking part as anyone in the course does
 * (replying, reacting, posting and editing one's own discussion) needs none;
 * the discussion service itself checks deleting someone else's post and
 * marking someone else's answer. Looking needs nothing from this filter.
 *
 * `coursepermission:<permission>` asks that permission instead, for a course
 * change outside the course API (the calendar's announcement).
 */
class CoursePermissionFilter implements FilterInterface
{
    public const MESSAGE = "Your institution doesn't let your role do this.";

    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<array{string, ?string}> the first match decides; the path is the part after `/api/courses/<id>/` */
    public const RULES = [
        ['#^(announcements|discussions)/(\d+/)?(reply|react)$#', null],
        ['#^(announcements|discussions)/replies/\d+$#', null],
        ['#^discussions(/\d+)?(/answer)?$#', null],
        ['#^discussions/attachments$#', null],
        ['#^discussions/\d+/(pin|lock)$#', 'discussions.manage'],
        ['#^announcements(/|$)#', 'announcements.manage'],
        ['#^(grades|submissions)(/|$)#', 'grading.manage'],
    ];

    /** The permission a change at this path needs, or null when none. */
    public static function required(string $path): ?string
    {
        $path = trim($path, '/');

        foreach (self::RULES as [$pattern, $permission]) {
            if (preg_match($pattern, $path) === 1) {
                return $permission;
            }
        }

        return 'courses.manage';
    }

    /**
     * The role a change at this address acts as: the area it is in (instructor or chairperson). A person who
     * holds both is judged by the one they are using, so an institution's change to one of them holds.
     */
    public static function actingRole(string $path): ?string
    {
        $area = explode('/', trim($path, '/'))[0];

        return in_array($area, ['instructor', 'chairperson'], true) ? $area : null;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var IncomingRequest $request */
        if (in_array(strtoupper($request->getMethod()), self::SAFE, true) || ($user = auth()->user()) === null) {
            return null;
        }

        if (! empty($arguments)) {
            $permission = (string) $arguments[0];
        } elseif (preg_match('#/api/courses/\d+/(.+)$#', '/' . trim($request->getPath(), '/'), $match) === 1) {
            $permission = self::required($match[1]);
        } else {
            return null;
        }

        if ($permission === null || (new RolePermissions())->allows($user, $permission, self::actingRole($request->getPath()))) {
            return null;
        }

        if ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'permission_denied', 'message' => self::MESSAGE]);
        }

        return redirect()->back()->with('error', self::MESSAGE);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

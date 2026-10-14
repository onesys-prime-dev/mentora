<?php

namespace Config;

use App\Filters\AccountStateFilter;
use App\Filters\AiWorkerFilter;
use App\Filters\AjaxRedirectFilter;
use App\Filters\AssessmentScope;
use App\Filters\ChairpersonCourseAudit;
use App\Filters\ImpersonationFilter;
use App\Filters\LoginLockoutFilter;
use App\Filters\MaintenanceFilter;
use App\Filters\ClosedOfferingFilter;
use App\Filters\CourseMembershipFilter;
use App\Filters\InstitutionCourseFilter;
use App\Filters\ReadOnlyFilter;
use App\Filters\ReauthFilter;
use App\Filters\RoleFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'assessmentscope' => AssessmentScope::class,
        'reauth'        => ReauthFilter::class,
        'coursemember'  => CourseMembershipFilter::class,
        'ajaxredirect'  => AjaxRedirectFilter::class,
        'readonly'      => ReadOnlyFilter::class,
        'closedoffering' => ClosedOfferingFilter::class,
        'institutioncourse' => InstitutionCourseFilter::class,
        'role'          => RoleFilter::class,
        'accountstate'  => AccountStateFilter::class,
        'impersonation' => ImpersonationFilter::class,
        'loginlockout'  => LoginLockoutFilter::class,
        'maintenance'   => MaintenanceFilter::class,
        'chairpersoncourseaudit' => ChairpersonCourseAudit::class,
        'aiworker'      => AiWorkerFilter::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // 'honeypot',
            // 'csrf',
            // 'invalidchars',

            // A deactivated account, or a session an administrator ended, is turned away here.
            'maintenance',   // the platform's timezone, and only administrators in during maintenance
            'loginlockout',  // too many failed sign-ins: refuse the attempt
            'impersonation', // a system administrator viewing as someone: view-only, time-limited
            'accountstate',
        ],
        'after' => [
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}

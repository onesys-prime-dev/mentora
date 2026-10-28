<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every route fits its handler: the class and method exist, the route passes as many arguments as the method
 * takes (all the required ones, no more than it has), and a number parameter only ever gets a number from the
 * address. A route that sent a course slug into an int parameter crashed the dean's and the administrator's
 * lesson links (audit finding 5); this keeps that from coming back.
 *
 * @internal
 */
final class RouteHandlersTest extends CIUnitTestCase
{
    public function testEveryRouteFitsItsHandler(): void
    {
        $routes   = service('routes');
        $problems = [];

        $routes->loadRoutes();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach ($routes->getRoutes($verb) as $from => $to) {
                if (! is_string($to) || ! str_contains($to, '::')) {
                    continue;
                }

                [$class, $rest] = explode('::', ltrim($to, '\\'), 2);
                $parts  = explode('/', $rest);
                $method = array_shift($parts);
                $class  = class_exists($class) ? $class : 'App\\Controllers\\' . $class;
                $where  = "{$verb} {$from} -> {$to}";

                if (! method_exists($class, $method)) {
                    $problems[] = "{$where}: no such method";

                    continue;
                }

                $params = (new ReflectionMethod($class, $method))->getParameters();
                $given  = count($parts);

                if ($given < count(array_filter($params, static fn (ReflectionParameter $p): bool => ! $p->isOptional())) || $given > count($params)) {
                    $problems[] = "{$where}: passes {$given} argument(s) to a method taking " . count($params);

                    continue;
                }

                // What each $n placeholder of the address captures.
                preg_match_all('#\(([^()]*)\)#', (string) $from, $groups);

                foreach ($parts as $i => $argument) {
                    $type = $params[$i]->getType();

                    if (! $type instanceof ReflectionNamedType || $type->getName() !== 'int') {
                        continue;
                    }

                    $numeric = ctype_digit($argument)
                        || (preg_match('/^\$(\d+)$/', $argument, $m) === 1 && in_array($groups[1][(int) $m[1] - 1] ?? '', ['[0-9]+', '\d+'], true));

                    if (! $numeric) {
                        $problems[] = "{$where}: argument " . ($i + 1) . " ({$argument}) is not a number but \${$params[$i]->getName()} is an int";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}

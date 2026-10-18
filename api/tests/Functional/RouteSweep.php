<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/** What the router sweeps share: a path every placeholder of a route accepts, so a request reaches past the router. */
trait RouteSweep
{
    /**
     * Values a path placeholder takes, so a request reaches the firewall rather than a 404 from the router: the first
     * the route's own requirement accepts, as one name means a setting's key on one route and a report's on another.
     */
    private const array PLACEHOLDERS = [
        '_format' => 'json',
        'format' => 'csv',
        'index' => 'index',
        'key' => ['signup.enabled', 'takings'],
        'kind' => 'invoices.late_customer',
        'language' => 'fr',
        'moduleKey' => 'customers',
        'month' => '09',
        'page' => 'cookies',
        'status' => '404',
        'subject' => 'customers',
        'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'year' => '2026',
    ];

    /**
     * The same path for the same route every time, an id named after its placeholder, so two requests differ only by
     * what the caller gave.
     *
     * @param array<string, string> $given values that win over the placeholders, such as the company in the path
     */
    private static function pathOf(RouterInterface $router, string $name, Route $route, array $given = []): string
    {
        $values = [];
        /** @var list<string> $variables */
        $variables = $route->compile()->getPathVariables();
        foreach ($variables as $variable) {
            // A route that allows a few literal values only, such as the JSON-LD context's `_format` or a file's
            // `pdf|zip`, gets the first of them.
            $requirement = $route->getRequirement($variable);
            $values[$variable] = $given[$variable] ?? (null !== $requirement && 1 === preg_match('/^\w+(\|\w+)*$/', $requirement)
                ? explode('|', $requirement)[0]
                : self::placeholder($variable, $requirement));
        }

        return $router->generate($name, $values);
    }

    private static function placeholder(string $variable, ?string $requirement): string
    {
        $candidates = (array) (self::PLACEHOLDERS[$variable] ?? Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_OID), $variable)->toRfc4122());
        foreach ($candidates as $candidate) {
            if (null === $requirement || 1 === preg_match('#^(?:'.$requirement.')$#', $candidate)) {
                return $candidate;
            }
        }

        throw new \LogicException(\sprintf('No sample for {%s} matches %s: add one to PLACEHOLDERS.', $variable, $requirement));
    }

    /** @return list<string> the methods a route answers, HEAD left to GET */
    private static function methodsOf(Route $route): array
    {
        return array_values(array_filter($route->getMethods() ?: ['GET'], static fn (string $method): bool => 'HEAD' !== $method));
    }
}

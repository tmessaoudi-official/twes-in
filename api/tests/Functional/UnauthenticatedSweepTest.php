<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Review C12: every route under /api answers a caller with no session 401, but for the few the firewall opens on
 * purpose. The sweep walks the router, so a plain controller counts as much as an API Platform operation, and it sends
 * the CSRF header, which the CSRF listener checks before the firewall, so a 401 is the firewall's answer.
 */
final class UnauthenticatedSweepTest extends ApiTestCase
{
    /** security.yaml's PUBLIC_ACCESS paths, written out: opening a path to everyone is a change to this list. */
    private const array PUBLIC_PATHS = [
        '^/api/health$',
        '^/api/auth/login$',
        '^/api/docs',
        '^/api/invitations',
        '^/api/auth/mfa/verify$',
        '^/api/auth/mfa/passkey-login(/options)?$',
        '^/api/signup',
        '^/api/scan-pairings/claim$',
        '^/api/scan-pairings/[0-9a-f-]{36}/(scans|choices|realtime-token)$',
    ];

    /** A value each path placeholder accepts, so a request reaches the firewall rather than a 404 from the router. */
    private const array PLACEHOLDERS = [
        '_format' => 'json',
        'format' => 'csv',
        'index' => 'index',
        'key' => 'signup.enabled',
        'moduleKey' => 'customers',
        'month' => '09',
        'status' => '404',
        'subject' => 'customers',
        'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'year' => '2026',
    ];

    public function testThePublicPathsAreExactlyTheFirewallsOwn(): void
    {
        /** @var array{security: array{access_control: list<array{path: string, roles: string}>}} $config */
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/security.yaml');
        $public = array_values(array_map(
            static fn (array $rule): string => $rule['path'],
            array_filter($config['security']['access_control'], static fn (array $rule): bool => 'PUBLIC_ACCESS' === $rule['roles']),
        ));

        self::assertSame(self::PUBLIC_PATHS, $public);
    }

    public function testEveryOtherRouteAnswersACallerWithoutASession401(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $opened = [];
        $swept = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api')) {
                continue;
            }
            $values = [];
            /** @var list<string> $variables */
            $variables = $route->compile()->getPathVariables();
            foreach ($variables as $variable) {
                // A route that allows one literal value only, such as the JSON-LD context's `_format`, gets that value.
                $requirement = $route->getRequirement($variable);
                $values[$variable] = null !== $requirement && 1 === preg_match('/^\w+$/', $requirement)
                    ? $requirement
                    : self::PLACEHOLDERS[$variable] ?? Uuid::v4()->toRfc4122();
            }
            $path = $router->generate($name, $values);
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if ('HEAD' === $method) {
                    continue;
                }
                $this->client->restart();
                $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
                $this->sendJson($method, $path, 'GET' === $method ? null : []);
                $status = $this->client->getResponse()->getStatusCode();
                ++$swept;

                if ($this->isPublic($path)) {
                    self::assertLessThan(500, $status, "$method $path ($name) is public and answered $status");
                } elseif (Response::HTTP_UNAUTHORIZED !== $status) {
                    $opened[] = "$method $path ($name) answered $status";
                }
            }
        }

        self::assertGreaterThan(100, $swept, 'the sweep reached the routes');
        self::assertSame([], $opened, 'these routes answer a caller without a session');
    }

    private function isPublic(string $path): bool
    {
        foreach (self::PUBLIC_PATHS as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $path)) {
                return true;
            }
        }

        return false;
    }
}

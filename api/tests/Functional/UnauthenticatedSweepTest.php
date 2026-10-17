<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Review C12: every route under /api answers a caller with no session 401, but for the few the firewall opens on
 * purpose. The sweep walks the router, so a plain controller counts as much as an API Platform operation, and it sends
 * the CSRF header, which the CSRF listener checks before the firewall, so a 401 is the firewall's answer.
 */
final class UnauthenticatedSweepTest extends ApiTestCase
{
    use RouteSweep;

    /** security.yaml's PUBLIC_ACCESS paths, written out: opening a path to everyone is a change to this list. */
    private const array PUBLIC_PATHS = [
        '^/api/health$',
        '^/api/health/deep$',
        '^/api/client-errors$',
        '^/api/auth/login$',
        '^/api/auth/demo$',
        '^/api/docs',
        '^/api/invitations',
        '^/api/auth/mfa/verify$',
        '^/api/auth/mfa/passkey-login(/options)?$',
        '^/api/signup',
        '^/api/auth/password/(forgot|reset)$',
        '^/api/scan-pairings/claim$',
        '^/api/scan-pairings/[0-9a-f-]{36}/(scans|choices|photos|realtime-token)$',
        '^/api/legal/[a-z]+/[a-z]{2}$',
        '^/api/legal/security\.txt$',
        '^/api/notification-preferences/stop$',
    ];

    /**
     * Every route a caller without a session reaches, by method and name. The firewall's public paths are patterns, and
     * some are prefixes: a route added beneath one, or a method added to one, is public the moment it exists. Listing
     * them exactly makes that a decision taken here.
     */
    private const array PUBLIC_ROUTES = [
        'GET _api_/invitations/{token}_get',
        'GET _api_/legal/{page}/{language}_get',
        'GET api_auth_demo_availability',
        'GET api_doc',
        'GET api_health',
        'GET api_health_deep',
        'GET api_legal_security_txt',
        'GET signup_availability',
        'GET signup_link',
        'POST _api_/invitations/{token}/accept_post',
        'POST api_auth_demo',
        'POST api_auth_login',
        'POST api_auth_mfa_passkey_login',
        'POST api_auth_mfa_passkey_login_options',
        'POST api_auth_mfa_verify',
        'POST api_auth_password_forgot',
        'POST api_auth_password_reset',
        'POST api_client_errors',
        'POST api_notification_mail_stop',
        'POST api_scan_pairing_choice',
        'POST api_scan_pairing_claim',
        'POST api_scan_pairing_photo',
        'POST api_scan_pairing_realtime_token',
        'POST api_scan_pairing_scan',
        'POST signup_complete',
        'POST signup_request',
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
        $public = [];
        $swept = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api')) {
                continue;
            }
            $path = self::pathOf($router, $name, $route);
            foreach (self::methodsOf($route) as $method) {
                $this->client->restart();
                $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
                $this->sendJson($method, $path, 'GET' === $method ? null : []);
                $status = $this->client->getResponse()->getStatusCode();
                ++$swept;

                if ($this->isPublic($path)) {
                    self::assertLessThan(500, $status, "$method $path ($name) is public and answered $status");
                    $public[] = "$method $name";
                } elseif (Response::HTTP_UNAUTHORIZED !== $status) {
                    $opened[] = "$method $path ($name) answered $status";
                }
            }
        }

        self::assertGreaterThan(100, $swept, 'the sweep reached the routes');
        self::assertSame([], $opened, 'these routes answer a caller without a session');
        sort($public);
        self::assertSame(self::PUBLIC_ROUTES, $public, 'the routes a caller without a session reaches: list a new one here on purpose');
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

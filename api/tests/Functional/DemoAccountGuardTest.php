<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Infrastructure\Http\DemoAccountGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * A demo visitor never makes the throwaway account a real one nor reaches past the sandbox. Every route about the account
 * itself, or about who belongs to a company and what calls on the platform's operators, is refused to a visitor or
 * allowed on purpose: a new one reds here until it is placed in one list or the other.
 */
final class DemoAccountGuardTest extends ApiTestCase
{
    use MakesSandboxes;
    use RouteSweep;

    /** What a visitor may do with the account and the company, as anyone signed in does. */
    private const array ALLOWED = [
        '_api_/auth/me_get',
        '_api_/companies/{companyId}/closing_get',
        '_api_/companies/{companyId}/members/{userId}_delete',
        '_api_/companies/{companyId}/members_get_collection',
        '_api_/companies/{companyId}/subscription_get',
        '_api_/me/companies_get_collection',
        '_api_/me/company-at-sign-in_put',
        '_api_/me/company_post',
        'api_auth_customer_screen_leave',
        'api_auth_demo',
        'api_auth_demo_availability',
        'api_auth_login',
        'api_auth_logout',
        'api_auth_mfa_passkey_login',
        'api_auth_mfa_passkey_login_options',
        'api_auth_mfa_passkeys',
        'api_auth_mfa_verify',
        'api_auth_password_forgot',
        'api_auth_password_reset',
        'api_auth_sessions',
        'api_auth_sessions_end',
        'api_auth_sessions_end_others',
        'api_auth_step_up',
        'api_auth_step_up_passkey',
        'api_auth_step_up_passkey_options',
        'api_me_notification_preferences',
        'api_me_notification_preferences_change',
        'api_me_notification_read',
        'api_me_notifications',
        'api_me_notifications_read_all',
        'api_me_notifications_unread_by_company',
        'api_me_realtime_token',
        'api_support_access_end',
        'api_support_access_read',
    ];

    /** The account's own routes, and a company's that change who belongs to it or call on the platform's operators. */
    private const string SWEPT = '#^/api/(auth/|me/|companies$|companies/\{companyId\}/(members|support-access|close-down|closing|subscription|modules/\{moduleKey\}/interest))#';

    public function testEveryRouteOfTheAccountIsRefusedOrAllowedOnPurpose(): void
    {
        $swept = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            if (1 === preg_match(self::SWEPT, $route->getPath())) {
                $swept[] = $name;
            }
        }
        sort($swept);
        $placed = [...DemoAccountGuard::REFUSED_ROUTES, ...self::ALLOWED];
        sort($placed);

        self::assertSame($placed, $swept, 'place a new route of the account in the refused list or the allowed one');
    }

    public function testAVisitorIsRefusedWhatWouldMakeTheAccountRealOrReachPastTheSandbox(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        $company = $this->json()['company'] ?? null;
        $companyId = \is_array($company) && \is_string($company['id'] ?? null) ? $company['id'] : self::fail('The visitor works in the sandbox.');
        $router = static::getContainer()->get(RouterInterface::class);

        $answered = [];
        foreach (DemoAccountGuard::REFUSED_ROUTES as $name) {
            $route = $router->getRouteCollection()->get($name) ?? self::fail("No route $name");
            foreach (self::methodsOf($route) as $method) {
                $this->sendJson($method, self::pathOf($router, $name, $route, ['companyId' => $companyId]), []);
                $answered[$name] = [$this->client->getResponse()->getStatusCode(), $this->json()['error'] ?? null];
            }
        }

        self::assertSame(array_fill_keys(DemoAccountGuard::REFUSED_ROUTES, [Response::HTTP_FORBIDDEN, 'demo_refused']), $answered);
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful('a refusal leaves the visitor signed in');
    }

    public function testSomeoneElseIsNotTheGuardsToRefuse(): void
    {
        $company = $this->createCompany('Quincaillerie');
        $this->createUser('owner@example.com', 'password-1234', $company);
        $this->login('owner@example.com', 'password-1234');

        $this->sendJson('PUT', '/api/auth/password', ['currentPassword' => 'wrong-password', 'newPassword' => 'another-password-5678']);

        self::assertNotSame('demo_refused', $this->json()['error'] ?? null);
        self::assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }
}

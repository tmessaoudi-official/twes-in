<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Http;

use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A demo visitor tries everything a company does, inside the sandbox, but never makes the throwaway account a real one
 * nor reaches past the sandbox: no password, second factor or passkey of its own, no deleting it ahead of the sandbox,
 * no company outside it, no one invited into it, and nothing that calls on the platform's operators (support access, a
 * payment to confirm, a closing to keep, a module asked for). Matched on route names, which the router has set by now;
 * DemoAccountGuardTest holds every account and company route to this list or to the allowed one.
 *
 * After the firewall (8), so the account is known, and before API Platform reads anything (4).
 */
#[AsEventListener(priority: 5)]
final readonly class DemoAccountGuard
{
    public const string REFUSED = 'demo_refused';

    public const array REFUSED_ROUTES = [
        'api_auth_account_delete',
        'api_auth_password_change',
        'api_auth_mfa_enrolment',
        'api_auth_mfa_enrolment_confirm',
        'api_auth_mfa_authenticator_remove',
        'api_auth_mfa_passkeys_options',
        'api_auth_mfa_passkeys_register',
        'api_auth_mfa_passkeys_remove',
        'api_auth_mfa_recovery_codes',
        'api_auth_mfa_recovery_codes_passkey',
        'api_auth_mfa_recovery_codes_passkey_options',
        '_api_/companies_post',
        '_api_/companies/{companyId}/members_post',
        'api_support_access_grant',
        'api_company_close_down',
        '_api_/companies/{companyId}/closing_put',
        'declare_payment',
        '_api_/companies/{companyId}/modules/{moduleKey}/interest_put',
    ];

    public function __construct(private Security $security, private DemoAccounts $demoAccounts)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // A request bringing no session has no account to refuse, and asking who is signed in would make the lazy
        // firewall read a session for it, which turns a public answer's cache header private.
        if (!$event->isMainRequest() || !\in_array($request->attributes->get('_route'), self::REFUSED_ROUTES, true) || !$request->hasPreviousSession()) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser || !$this->demoAccounts->isDemo($account->getId())) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => self::REFUSED], Response::HTTP_FORBIDDEN));
    }
}

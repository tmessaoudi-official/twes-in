<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Http;

use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\ImportExport\Application\Export\ExportCatalogue;
use App\ImportExport\Application\Export\ExportForTheBooks;
use App\ImportExport\Application\Export\UnknownExportSubject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A demo visitor tries everything a company does, inside the sandbox, but never makes the throwaway account a real one
 * nor reaches past the sandbox: no password, second factor or passkey of its own, no deleting it ahead of the sandbox,
 * no company outside it, no one invited into it, and nothing that calls on the platform's operators (support access, a
 * payment to confirm, a closing to keep, a module asked for), nor any Factur-X file or export for the accountant's books,
 * each meant for real books and carrying no band saying the documents are worth nothing. Matched on route names, which
 * the router has set by now, and for an export on what its subject says it is; DemoAccountGuardTest holds every account
 * and company route to this list or to the allowed one, and every export to the books or the lists.
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
        'api_invoice_factur_x_xml',
        'api_invoice_factur_x_pdf',
        // The month's withholding declaration, taken in by the tax authority's platform as it is, with no band.
        'api_expense_tej_declaration',
    ];

    private const string EXPORT_ROUTE = 'api_export';

    public function __construct(private Security $security, private DemoAccounts $demoAccounts, private ExportCatalogue $exports)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // A request bringing no session has no account to refuse, and asking who is signed in would make the lazy
        // firewall read a session for it, which turns a public answer's cache header private.
        if (!$event->isMainRequest() || !$this->refused($request->attributes->get('_route'), $request->attributes->get('subject')) || !$request->hasPreviousSession()) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser || !$this->demoAccounts->isDemo($account->getId())) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => self::REFUSED], Response::HTTP_FORBIDDEN));
    }

    private function refused(mixed $route, mixed $subject): bool
    {
        if (self::EXPORT_ROUTE !== $route) {
            return \in_array($route, self::REFUSED_ROUTES, true);
        }
        try {
            return \is_string($subject) && $this->exports->declaration($subject) instanceof ExportForTheBooks;
        } catch (UnknownExportSubject) {
            // Nothing by that name: the export's own answer, a 404, is the right one.
            return false;
        }
    }
}

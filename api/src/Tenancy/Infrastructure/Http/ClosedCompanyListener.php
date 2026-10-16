<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Http\RoutedPath;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * The second wall of a closed company, whatever an endpoint's own permission says: its members read it, by GET, and do
 * nothing else, not even a read sent as a POST, such as a preview, or a phone paired to scan. The one way through is
 * its owner's reopening. A stranger is not told the company is closed: the endpoint answers them as for any company.
 *
 * After the firewall (8), so the account is known, and before API Platform reads anything (4); matched on paths, as
 * `SupportReadOnlyListener` is.
 */
final readonly class ClosedCompanyListener
{
    public const string REOPEN_ROUTE = 'api_company_reopen';
    private const string COMPANY_PATH = '#^/api/companies/([0-9a-fA-F-]{36})(/|$)#';

    public function __construct(private Security $security, private CompanyRepository $companies, private MembershipRepository $memberships)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !$request->hasPreviousSession() || self::REOPEN_ROUTE === $request->attributes->get('_route')
            || 1 !== preg_match(self::COMPANY_PATH, RoutedPath::of($request), $match) || !Uuid::isValid($match[1])) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            return;
        }
        $companyId = Uuid::fromString($match[1]);
        if (true !== $this->companies->ofId($companyId)?->isClosedDown() || null === $this->memberships->ofUserInCompany($account->getId(), $companyId)) {
            return;
        }
        $event->setResponse(new JsonResponse(['error' => CompanyGuard::COMPANY_CLOSED], Response::HTTP_FORBIDDEN));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * The second wall of « Accès support », whatever an endpoint's own permission says: under a grant an operator reads,
 * by GET, and nothing else, not even a read sent as a POST such as a preview, and never an export, which would carry
 * the company's data out whole. Each page read is then written in the activity log, once per quarter hour.
 *
 * After the firewall (8), so the account is known, and before API Platform reads anything (4); matched on paths, as
 * `CustomerScreenLockListener` is. The grant found is kept on the request, which lives and dies with it.
 */
final readonly class SupportReadOnlyListener
{
    private const string COMPANY_PATH = '#^/api/companies/([0-9a-fA-F-]{36})(/|$)#';
    private const string GRANT = '_support_grant';

    public function __construct(private Security $security, private SupportAccess $support, private RecordSupportVisit $visits)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || 1 !== preg_match(self::COMPANY_PATH, $request->getPathInfo(), $match)) {
            return;
        }
        $account = $this->security->getUser();
        // A member is never read through a grant: only an operator is asked about one.
        if (!$account instanceof SecurityUser || !$account->isPlatformOperator() || !Uuid::isValid($match[1])) {
            return;
        }
        $grant = $this->support->grantOf($account->getId(), Uuid::fromString($match[1]));
        if (null === $grant) {
            return;
        }
        if (!$request->isMethod('GET') || str_contains($request->getPathInfo(), '/exports/')) {
            $event->setResponse(new JsonResponse(['error' => SupportAccess::READ_ONLY], Response::HTTP_FORBIDDEN));

            return;
        }
        $request->attributes->set(self::GRANT, [$grant->getId(), $grant->getCompany()->getId(), $account->getId()]);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $read = $event->getRequest()->attributes->get(self::GRANT);
        if (!\is_array($read) || !$event->getResponse()->isSuccessful()) {
            return;
        }
        [$grantId, $companyId, $userId] = $read;
        $route = $event->getRequest()->attributes->get('_route');
        if ($grantId instanceof Uuid && $companyId instanceof Uuid && $userId instanceof Uuid && \is_string($route)) {
            $this->visits->read($grantId, $companyId, $userId, $route);
        }
    }
}

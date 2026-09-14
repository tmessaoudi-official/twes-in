<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use App\ModuleRegistry\Application\ModuleStates;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * The one place a switched-off module's resources stop answering (docs/SPEC.md § 3 Modules). It runs after the router
 * has named the resource and after the firewall, before API Platform reads or writes anything, and answers the same
 * 404 as a company that is none of the caller's business. The firewall's access control (ROLE_USER on /api) has
 * already answered 401 to an anonymous caller by then, so a company's modules say nothing without a session.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class DisabledModuleGuard
{
    public function __construct(private ModuleOwnership $ownership, private ModuleStates $states)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // An API Platform resource names its class; a plain controller of a module (a PDF download) is owned by its own.
        $resource = $request->attributes->get('_api_resource_class');
        $controller = $request->attributes->get('_controller');
        $owned = \is_string($resource) ? $resource : (\is_string($controller) ? explode('::', $controller)[0] : null);
        $companyId = $request->attributes->get('companyId');
        if (!$event->isMainRequest() || null === $owned || !\is_string($companyId) || !Uuid::isValid($companyId)) {
            return;
        }
        $module = $this->ownership->ownerOf($owned);
        if (null === $module) {
            return;
        }
        if (!$this->states->isEnabled(Uuid::fromString($companyId), $module)) {
            throw new NotFoundHttpException('No such company.');
        }
    }
}

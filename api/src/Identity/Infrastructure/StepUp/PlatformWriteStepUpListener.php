<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Http\RoutedPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every platform write asks the operator for a recent proof of who is at the screen (docs/SPEC.md § 7, the step-up
 * list): a stolen operator session reaches every company, so its writes are where a proof matters most. One wall over
 * the whole of /api/platform rather than a check in each handler, so a write added there later asks too; reads ask
 * nothing. Creating a company, the one platform write outside it, asks in its own processor.
 *
 * After the firewall (8), so the account is known, and before API Platform reads anything (4), as
 * `SupportReadOnlyListener` is. Only an operator is asked: anyone else meets the platform's own refusal as before.
 */
final readonly class PlatformWriteStepUpListener
{
    private const string PLATFORM_PATH = '#^/api/platform(/|$)#';

    public function __construct(private Security $security, private ConfirmStepUp $stepUp)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || 1 !== preg_match(self::PLATFORM_PATH, RoutedPath::of($request))) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser || !$account->isPlatformOperator() || $this->stepUp->isRecent($account->getId())) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => RecentProof::REQUIRED], Response::HTTP_FORBIDDEN));
    }
}

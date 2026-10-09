<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Http;

use App\Inbox\Application\NotificationKindNotOffered;
use App\Inbox\Application\StopNotificationMail;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A mail's stop link, confirmed on its page: with no session, the signed token alone says whose mail of which kind
 * to turn off. POST only, so a mail client or a link scanner opening the link changes nothing. A token not signed here
 * and a kind no longer told there answer the same 404; the client budget answers 429. Documented in InboxOpenApi.
 */
final readonly class NotificationMailStopController
{
    public function __construct(
        private StopNotificationMail $stop,
        #[Target('notification_mail_stop')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/notification-preferences/stop', name: 'api_notification_mail_stop', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = json_decode($request->getContent(), true);
        $token = \is_array($body) && \is_string($body['token'] ?? null) ? $body['token'] : '';

        try {
            $this->stop->handle($token);
        } catch (NotificationKindNotOffered) {
            return new JsonResponse(['error' => 'link_not_usable'], Response::HTTP_NOT_FOUND);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}

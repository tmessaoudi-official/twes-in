<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Inbox\Application\InboxItemNotFound;
use App\Inbox\Application\NotificationCentre;
use App\Inbox\Domain\InboxItem;
use App\Shared\Application\RealtimeTokens;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use App\Tenancy\Domain\Company;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The notification centre and the realtime connection token, always for the signed-in user
 * (docs/SPEC.md § 7, 2026-09-13). `access_control` requires a session on all of /api, and the CSRF listener
 * guards the two marks like any other unsafe request. Documented in InboxOpenApi.
 */
final readonly class NotificationsController
{
    public function __construct(
        private NotificationCentre $centre,
        private RealtimeTokens $tokens,
        private DescribeWorkingContext $workingContext,
        private Security $security,
    ) {
    }

    #[Route('/api/me/notifications', name: 'api_me_notifications', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $page = $this->centre->latest($this->currentUserId());

        return new JsonResponse([
            'items' => array_map(self::item(...), $page->items),
            'unread' => $page->unread,
        ]);
    }

    #[Route('/api/me/notifications/read-all', name: 'api_me_notifications_read_all', methods: ['POST'])]
    public function readAll(): Response
    {
        $this->centre->markAllRead($this->currentUserId());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/notifications/{id}/read', name: 'api_me_notification_read', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function read(string $id): Response
    {
        try {
            $this->centre->markRead($this->currentUserId(), Uuid::fromString($id));
        } catch (InboxItemNotFound) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** A new token for every connection and every company switch: the channels follow the session. */
    #[Route('/api/me/realtime-token', name: 'api_me_realtime_token', methods: ['GET'])]
    public function realtimeToken(): JsonResponse
    {
        $userId = $this->currentUserId();
        // The session only names a company: it is heard while the user is still its member and it is open to them, so
        // someone removed from it, or a member of a company since suspended, renews a token without its channel.
        $context = $this->workingContext->for($userId);
        $companyId = null !== $context && Company::STATUS_ACTIVE === $context->status ? Uuid::fromString($context->companyId) : null;
        $token = $this->tokens->issue($userId, $companyId);

        return new JsonResponse(['token' => $token->token, 'expiresAt' => $token->expiresAt->format(\DATE_ATOM)]);
    }

    /** @return array<string, mixed> */
    private static function item(InboxItem $item): array
    {
        return [
            'id' => $item->getId()->toRfc4122(),
            'type' => $item->getType(),
            // An object even when empty, so the client never has to tell [] from {}.
            'payload' => (object) $item->getPayload(),
            'companyId' => $item->getCompany()?->getId()->toRfc4122(),
            'createdAt' => $item->getCreatedAt()->format(\DATE_ATOM),
            'readAt' => $item->getReadAt()?->format(\DATE_ATOM),
        ];
    }

    private function currentUserId(): Uuid
    {
        $account = $this->security->getUser();

        if (!$account instanceof SecurityUser) {
            // access_control already requires ROLE_USER on /api, so this is a contradiction, not a user error.
            throw new \LogicException('The notification endpoints run behind the firewall.');
        }

        return $account->getId();
    }
}

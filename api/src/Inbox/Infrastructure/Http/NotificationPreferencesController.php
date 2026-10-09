<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Inbox\Application\NotificationChoice;
use App\Inbox\Application\NotificationKindNotOffered;
use App\Inbox\Application\NotificationPreferences;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * How the signed-in person is told each kind, in each of their companies: read whole, changed one kind at a time. The
 * CSRF listener guards the change like any other unsafe request. Documented in InboxOpenApi.
 */
final readonly class NotificationPreferencesController
{
    public function __construct(private NotificationPreferences $preferences, private Security $security)
    {
    }

    #[Route('/api/me/notification-preferences', name: 'api_me_notification_preferences', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['preferences' => array_map(static fn (NotificationChoice $choice): array => [
            'companyId' => $choice->companyId,
            'companyName' => $choice->companyName,
            'type' => $choice->type,
            'bell' => $choice->bell,
            'email' => $choice->email,
        ], $this->preferences->of($this->currentUserId()))]);
    }

    #[Route('/api/me/notification-preferences', name: 'api_me_notification_preferences_change', methods: ['PUT'])]
    public function change(Request $request): Response
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::invalid('The body is not JSON.');
        }
        if (!\is_array($body) || !\is_string($body['type'] ?? null) || !\is_bool($body['bell'] ?? null) || !\is_bool($body['email'] ?? null)) {
            return self::invalid('A choice names its type and says yes or no to the bell and to the e-mail.');
        }
        $companyId = $body['companyId'] ?? null;
        if (null !== $companyId && (!\is_string($companyId) || !Uuid::isValid($companyId))) {
            return self::invalid('companyId is a company id, or null for what is about the account.');
        }

        try {
            $this->preferences->change($this->currentUserId(), null === $companyId ? null : Uuid::fromString($companyId), $body['type'], $body['bell'], $body['email']);
        } catch (NotificationKindNotOffered) {
            return new JsonResponse(['detail' => 'No such kind of notification is told to you there.'], Response::HTTP_NOT_FOUND);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private static function invalid(string $detail): JsonResponse
    {
        return new JsonResponse(['detail' => $detail], Response::HTTP_UNPROCESSABLE_ENTITY);
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

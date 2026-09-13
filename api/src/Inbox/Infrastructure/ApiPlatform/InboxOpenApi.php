<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * NotificationsController's endpoints are plain controllers, unknown to API Platform; the SPA's types come from
 * this document, so they are added here with every always-present property required.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class InboxOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['NotificationItem'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['id', 'type', 'payload', 'companyId', 'createdAt', 'readAt'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'type' => ['type' => 'string', 'description' => 'A dotted code such as "membership.added"; the SPA translates it.'],
                'payload' => ['type' => 'object', 'additionalProperties' => ['anyOf' => [['type' => 'string'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'null']]]],
                'companyId' => ['anyOf' => [['type' => 'string', 'format' => 'uuid'], ['type' => 'null']], 'description' => 'Set when the notification was published to a company.'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'readAt' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
            ],
        ]);
        $schemas['NotificationPage'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['items', 'unread'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationItem'], 'description' => 'Newest first.'],
                'unread' => ['type' => 'integer', 'description' => 'Every unread notification, not only those on this page.'],
            ],
        ]);
        $schemas['RealtimeToken'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['token', 'expiresAt'],
            'properties' => [
                'token' => ['type' => 'string', 'description' => 'A Centrifugo connection token; its channels follow the session.'],
                'expiresAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ]);

        $json = static fn (string $schema, string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]));
        $signedOut = new Response('Not signed in');
        $csrf = new Response('CSRF check failed');

        $paths = $openApi->getPaths();
        $paths->addPath('/api/me/notifications', new PathItem(get: new Operation(
            operationId: 'listNotifications',
            tags: ['Notifications'],
            responses: ['200' => $json('NotificationPage', 'The newest notifications and the unread count'), '401' => $signedOut],
            summary: 'The signed-in user\'s notification centre',
        )));
        $paths->addPath('/api/me/notifications/read-all', new PathItem(post: new Operation(
            operationId: 'markAllNotificationsRead',
            tags: ['Notifications'],
            responses: ['204' => new Response('Every notification is read'), '401' => $signedOut, '403' => $csrf],
            summary: 'Mark every notification read',
        )));
        $paths->addPath('/api/me/notifications/{id}/read', new PathItem(post: new Operation(
            operationId: 'markNotificationRead',
            tags: ['Notifications'],
            responses: ['204' => new Response('Read'), '401' => $signedOut, '403' => $csrf, '404' => new Response('No such notification for this user')],
            summary: 'Mark one notification read',
            parameters: [new Parameter('id', 'path', 'The notification', true, schema: ['type' => 'string', 'format' => 'uuid'])],
        )));
        $paths->addPath('/api/me/realtime-token', new PathItem(get: new Operation(
            operationId: 'realtimeToken',
            tags: ['Notifications'],
            responses: ['200' => $json('RealtimeToken', 'A connection token for /connection/websocket'), '401' => $signedOut],
            summary: 'A token for the realtime connection',
        )));

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }
}

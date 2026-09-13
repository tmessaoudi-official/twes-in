<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The notification endpoints are plain controllers, so API Platform does not know them; the SPA's types still come
 * from the OpenAPI document, which therefore has to carry them with every always-present property required.
 */
final class InboxOpenApiTest extends KernelTestCase
{
    public function testTheNotificationCentreAndTheRealtimeTokenAreInTheContract(): void
    {
        self::bootKernel();
        $openApi = static::getContainer()->get(OpenApiFactoryInterface::class)();
        $paths = $openApi->getPaths();
        $schemas = $openApi->getComponents()->getSchemas();
        self::assertNotNull($schemas);

        self::assertSame('listNotifications', $paths->getPath('/api/me/notifications')?->getGet()?->getOperationId());
        self::assertSame('markAllNotificationsRead', $paths->getPath('/api/me/notifications/read-all')?->getPost()?->getOperationId());
        self::assertSame('markNotificationRead', $paths->getPath('/api/me/notifications/{id}/read')?->getPost()?->getOperationId());
        self::assertSame('realtimeToken', $paths->getPath('/api/me/realtime-token')?->getGet()?->getOperationId());

        self::assertSame(['items', 'unread'], $this->plain($schemas['NotificationPage'])['required']);
        self::assertSame(['id', 'type', 'payload', 'companyId', 'createdAt', 'readAt'], $this->plain($schemas['NotificationItem'])['required']);
        self::assertSame(['token', 'expiresAt'], $this->plain($schemas['RealtimeToken'])['required']);
    }

    /** @return array<string, mixed> */
    private function plain(mixed $schema): array
    {
        $plain = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($plain);
        $keyed = [];
        foreach ($plain as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
